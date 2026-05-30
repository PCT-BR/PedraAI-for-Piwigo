<?php

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Convert a Piwigo image to a base64 data URI for the Pedra AI API.
 * Uses the physical file path from the images table `path` column.
 *
 * @param array $image_info Row from IMAGES_TABLE (result of get_image_infos())
 * @return string Full data URI: "data:image/jpeg;base64,..."
 * @throws RuntimeException if the file cannot be read
 */
function pedra_ai_image_to_base64(array $image_info): string
{
  // The path stored in DB has the PHPWG_ROOT_PATH prefix stripped (see functions_upload.inc.php:380)
  // Reconstructing: PHPWG_ROOT_PATH . $image_info['path'] gives e.g. './upload/2025/05/30/file.jpg'
  $relative_path = PHPWG_ROOT_PATH . $image_info['path'];
  $abs_path      = realpath($relative_path);

  if ($abs_path === false || !is_file($abs_path)) {
    throw new RuntimeException('Image file not found: ' . $relative_path);
  }
  if (!is_readable($abs_path)) {
    throw new RuntimeException('Image file not readable: ' . $abs_path);
  }

  $mime = mime_content_type($abs_path);
  if (empty($mime)) {
    // Fallback: infer from extension
    $ext  = strtolower(get_extension($image_info['file'] ?? $image_info['path']));
    $mime = match($ext) {
      'jpg', 'jpeg' => 'image/jpeg',
      'png'         => 'image/png',
      'webp'        => 'image/webp',
      'gif'         => 'image/gif',
      default       => 'image/jpeg',
    };
  }

  $data = base64_encode(file_get_contents($abs_path));

  return 'data:' . $mime . ';base64,' . $data;
}

/**
 * Download a URL to a temporary file. Used to fetch Pedra AI output images.
 *
 * @param string $url Public URL of the processed image
 * @return string Path to the downloaded temporary file
 * @throws RuntimeException on download failure
 */
function pedra_ai_download_url(string $url): string
{
  $tmp = tempnam(sys_get_temp_dir(), 'pedra_');
  $fp  = fopen($tmp, 'wb');

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);

  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  fclose($fp);

  if (!empty($err) || $code !== 200) {
    @unlink($tmp);
    throw new RuntimeException('Failed to download result image (HTTP ' . $code . '): ' . $err);
  }

  return $tmp;
}

/**
 * Overwrite the original image file with the processed version.
 * Clears all derivative thumbnails so they regenerate from the new source.
 *
 * @param int    $image_id  Piwigo image ID
 * @param string $tmp_path  Path to the temporary processed image file
 */
function pedra_ai_overwrite_image(int $image_id, string $tmp_path): void
{
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php');
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_metadata.php');

  $image_info = get_image_infos($image_id);
  if (!$image_info) {
    @unlink($tmp_path);
    throw new RuntimeException('Image #' . $image_id . ' not found in database');
  }

  $dest_path = PHPWG_ROOT_PATH . $image_info['path'];

  // Replace the physical file (rename works within same filesystem; fallback to copy)
  if (!@rename($tmp_path, $dest_path)) {
    if (!copy($tmp_path, $dest_path)) {
      @unlink($tmp_path);
      throw new RuntimeException('Could not write processed image to: ' . $dest_path);
    }
    @unlink($tmp_path);
  }

  @chmod($dest_path, 0644);

  // Remove all cached thumbnail sizes so they regenerate from the new file
  delete_element_derivatives($image_info, 'all');

  // Re-read dimensions and filesize from the new file
  $abs_dest  = realpath($dest_path);
  $file_info = pwg_image_infos($abs_dest);
  $new_md5   = md5_file($abs_dest);

  single_update(
    IMAGES_TABLE,
    [
      'filesize'       => $file_info['filesize'],
      'width'          => $file_info['width'],
      'height'         => $file_info['height'],
      'md5sum'         => $new_md5,
      'date_available' => date('Y-m-d H:i:s'),
    ],
    ['id' => $image_id]
  );

  // Refresh EXIF/IPTC metadata from new file
  sync_metadata([$image_id]);
}

/**
 * Save the processed image as a new Piwigo photo entry,
 * associated to the same albums as the source image.
 *
 * @param int    $source_image_id  Original image ID to copy album associations from
 * @param string $tmp_path         Path to the processed temporary file
 * @param string $suffix           Suffix to append to the filename (e.g. "_pedra")
 * @return int  New image ID
 */
function pedra_ai_save_as_new_image(int $source_image_id, string $tmp_path, string $suffix): int
{
  include_once(PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php');

  $source_info = get_image_infos($source_image_id);
  if (!$source_info) {
    @unlink($tmp_path);
    throw new RuntimeException('Source image #' . $source_image_id . ' not found');
  }

  // Build display filename: originalname_pedra.jpg
  $original_file = $source_info['file'];
  $ext           = get_extension($original_file);
  $base          = get_filename_wo_extension($original_file);
  $new_filename  = $base . $suffix . '.' . $ext;

  // add_uploaded_file handles: path computation, DB insert, derivative priming, metadata sync
  $new_image_id = add_uploaded_file(
    $tmp_path,
    $new_filename,
    null,  // categories: assigned below
    null,  // level: inherit default
    null,  // image_id: new record
    null   // md5sum: computed by the function
  );

  if (!$new_image_id) {
    throw new RuntimeException('Failed to register new image in database');
  }

  // Copy album associations from source image
  $query = '
SELECT category_id
  FROM ' . IMAGE_CATEGORY_TABLE . '
  WHERE image_id = ' . $source_image_id . '
;';
  $cat_ids = query2array($query, null, 'category_id');

  if (!empty($cat_ids)) {
    associate_images_to_categories([$new_image_id], $cat_ids);
  }

  return $new_image_id;
}

/**
 * Log a Pedra AI job to the tracking table.
 */
function pedra_ai_log_job(int $image_id, string $operation, string $status, ?string $url, ?string $error): void
{
  $url_sql   = $url   ? '"' . pwg_db_real_escape_string($url) . '"'   : 'NULL';
  $error_sql = $error ? '"' . pwg_db_real_escape_string(substr($error, 0, 500)) . '"' : 'NULL';

  $query = '
INSERT INTO ' . PEDRA_AI_JOBS_TABLE . '
  (image_id, operation, status, result_url, error_msg, created_at)
  VALUES(
    ' . $image_id . ',
    "' . pwg_db_real_escape_string($operation) . '",
    "' . $status . '",
    ' . $url_sql . ',
    ' . $error_sql . ',
    NOW()
  )
;';

  pwg_query($query);
}
