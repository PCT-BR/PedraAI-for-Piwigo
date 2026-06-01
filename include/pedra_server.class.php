<?php

/**
 * HTTP client for the PedraServer async processing server.
 * Handles video job submission and status polling.
 * No Piwigo dependencies beyond what is passed in.
 */
class PedraServerClient
{
  private string $base_url;
  private string $token;
  private int    $timeout    = 15;
  private int    $connect_to = 5;

  public function __construct(string $base_url, string $token)
  {
    $this->base_url = rtrim($base_url, '/');
    $this->token    = $token;
  }

  /**
   * Submit a video job to the processing server.
   *
   * @param array $payload {
   *   job_id:     string     Unique external job ID (e.g. "piwigo-42-1717500000")
   *   type:       string     "video"
   *   source_url: string|null Signed URL to fetch the source file (if no params.images)
   *   operation:  string     Pedra operation (e.g. "create_video")
   *   params:     array      Operation-specific params (images[], isVertical, etc.)
   *   webhook:    array      {url: string, token: string}
   *   meta:       array      Optional metadata echoed in callbacks
   * }
   * @return array{success: bool, server_job_id: string, error: string}
   */
  public function submitJob(array $payload): array
  {
    return $this->request('POST', '/api/jobs', $payload);
  }

  /**
   * Poll job status by server_job_id.
   *
   * @return array{success: bool, data: array, error: string}
   */
  public function getJob(string $server_job_id): array
  {
    return $this->request('GET', '/api/jobs/' . urlencode($server_job_id));
  }

  /**
   * Health check — returns true if the server is reachable.
   */
  public function health(): bool
  {
    $result = $this->request('GET', '/health');
    return $result['success'] && ($result['data']['status'] ?? '') === 'ok';
  }

  // ── Private ──────────────────────────────────────────────────────────────

  private function request(string $method, string $path, ?array $body = null): array
  {
    if (!function_exists('curl_init')) {
      return $this->fail('cURL extension not available');
    }

    $url      = $this->base_url . $path;
    $json     = $body !== null ? json_encode($body) : null;
    $headers  = [
      'Authorization: Bearer ' . $this->token,
      'Accept: application/json',
    ];
    if ($json !== null) {
      $headers[] = 'Content-Type: application/json';
      $headers[] = 'Content-Length: ' . strlen($json);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $this->timeout,
      CURLOPT_CONNECTTIMEOUT => $this->connect_to,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($method === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
      if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || !empty($err)) {
      return $this->fail('cURL error: ' . $err);
    }

    $data = $raw !== '' ? json_decode($raw, true) : [];
    if (json_last_error() !== JSON_ERROR_NONE) {
      return $this->fail('Invalid JSON from server (HTTP ' . $code . '): ' . substr($raw, 0, 200));
    }

    if ($code >= 400) {
      return $this->fail(($data['error'] ?? $data['message'] ?? 'HTTP ' . $code), $data);
    }

    return ['success' => true, 'data' => $data ?? [], 'error' => ''];
  }

  private function fail(string $error, array $data = []): array
  {
    return ['success' => false, 'data' => $data, 'error' => $error];
  }
}
