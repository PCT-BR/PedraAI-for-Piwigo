<?php

/**
 * HTTP client for the Pedra AI REST API.
 * No Piwigo dependencies — can be tested standalone.
 */
class PedraApiClient
{
  private string $api_key;
  private string $base_url  = 'https://app.pedra.ai/api';
  private int    $timeout    = 120;
  private int    $connect_to = 15;

  public function __construct(string $api_key)
  {
    $this->api_key = $api_key;
  }

  /**
   * Send an image to a Pedra AI endpoint and return the result URLs.
   *
   * @param string $operation  e.g. "enhance", "furnish", "renovation"
   * @param string $image_b64  Complete data URI: "data:image/jpeg;base64,..."
   * @param array  $extra      Operation-specific parameters (e.g. ['prompt' => '...'])
   * @return array{success: bool, urls: string[], error: string}
   */
  public function process(string $operation, string $image_url, array $extra = []): array
  {
    if (!function_exists('curl_init')) {
      return ['success' => false, 'urls' => [], 'error' => 'cURL extension not available'];
    }

    $endpoint = $this->base_url . '/' . $operation;

    $body = array_merge([
      'apiKey'   => $this->api_key,
      'imageUrl' => $image_url,
    ], $extra);

    $json_body = json_encode($body);
    if ($json_body === false) {
      return ['success' => false, 'urls' => [], 'error' => 'Failed to encode request body'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $json_body,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Length: ' . strlen($json_body),
      ],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $this->timeout,
      CURLOPT_CONNECTTIMEOUT => $this->connect_to,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 3,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || !empty($err)) {
      return ['success' => false, 'urls' => [], 'error' => 'cURL error: ' . $err];
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return ['success' => false, 'urls' => [], 'error' => 'Invalid JSON response (HTTP ' . $code . '): ' . substr($raw, 0, 200)];
    }

    if ($code !== 200) {
      $msg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $code);
      return ['success' => false, 'urls' => [], 'error' => $msg];
    }

    if (empty($data['output'])) {
      return ['success' => false, 'urls' => [], 'error' => 'Empty output in response. Raw: ' . substr($raw, 0, 300)];
    }

    $raw_urls = is_array($data['output']) ? $data['output'] : [$data['output']];

    // Pedra may return URL strings or objects — normalize to strings
    $urls = array_values(array_filter(array_map(function($item) {
      if (is_string($item)) return $item;
      if (is_array($item)) {
        return $item['url'] ?? $item['imageUrl'] ?? $item['outputUrl'] ?? (is_string(reset($item)) ? reset($item) : null);
      }
      return null;
    }, $raw_urls)));

    if (empty($urls)) {
      return ['success' => false, 'urls' => [], 'error' => 'Could not extract URL from output. Raw: ' . substr($raw, 0, 300)];
    }

    return ['success' => true, 'urls' => $urls, 'error' => ''];
  }
}
