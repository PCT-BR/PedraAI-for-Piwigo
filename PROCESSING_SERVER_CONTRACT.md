# Pedra AI — Processing Server Integration Contract

**Version:** 1.0  
**Date:** 2026-06-01  
**Scope:** Defines the HTTP interface between the Piwigo plugin (`pedra_ai`) and the optional async processing server required for video support.

---

## Overview

The Piwigo plugin handles photo processing synchronously (direct PHP → Pedra AI call).  
For video — where files are 50–500 MB and processing takes 2–10 minutes — a dedicated async server is required.

```
Piwigo plugin          Processing server           Pedra AI
──────────────────────────────────────────────────────────
1. POST /api/jobs ────►
                       2. Fetch source file (signed URL)
                                          3. POST /video ──►
                                          ◄── result URL ───
                       4. Download result
   ◄── POST /webhook ─
5. Save file + update job in DB
```

When `pedra_ai_server_url` and `pedra_ai_server_token` are configured in the plugin settings, the server mode is active. When blank, the plugin operates standalone (photos only, current behaviour).

---

## 1. Job submission — Piwigo → Server

### Endpoint

```
POST {SERVER_URL}/api/jobs
Authorization: Bearer {SERVER_TOKEN}
Content-Type: application/json
```

### Request body

```json
{
  "job_id": "piwigo-42-1717500000",
  "type": "video",
  "source_url": "https://mypiwigo.com/plugins/pedra_ai/serve.php?image_id=42&expires=1717503600&token=abc123",
  "operation": "video_furnish",
  "params": {
    "style": "Modern",
    "roomType": "Living room"
  },
  "webhook": {
    "url": "https://mypiwigo.com/plugins/pedra_ai/webhook.php",
    "token": "secret-webhook-token-xyz"
  },
  "meta": {
    "image_id": 42,
    "piwigo_url": "https://mypiwigo.com"
  }
}
```

### Field reference

| Field | Type | Required | Description |
|---|---|---|---|
| `job_id` | string | ✓ | Unique job ID from Piwigo. Format: `piwigo-{image_id}-{timestamp}` |
| `type` | `"photo"` \| `"video"` | ✓ | Determines processing behaviour |
| `source_url` | string | ✓ | Time-limited signed URL to the source file (see §3) |
| `operation` | string | ✓ | Pedra operation to apply (e.g. `video_furnish`, `furnish`, `renovation`) |
| `params` | object | — | Operation-specific parameters (same keys as Pedra API) |
| `webhook.url` | string | ✓ | Piwigo endpoint to call on completion |
| `webhook.token` | string | ✓ | Shared secret for HMAC signature verification |
| `meta.image_id` | integer | ✓ | Piwigo image ID — echoed back in the webhook |
| `meta.piwigo_url` | string | — | Base URL of the Piwigo instance (informational) |

### Response — success (202 Accepted)

```json
{
  "status": "queued",
  "server_job_id": "srv-job-abc123",
  "estimated_seconds": 180
}
```

### Response — error (4xx/5xx)

```json
{
  "status": "error",
  "error": "Invalid token"
}
```

---

## 2. Signed source URL — Piwigo file access

The server must be able to download the source file **without a Piwigo session**, even from a private album.  
Piwigo generates a time-limited signed URL per job.

### URL format

```
https://mypiwigo.com/plugins/pedra_ai/serve.php
  ?image_id=42
  &expires=1717503600
  &token=HMAC-SHA256(image_id:expires, SERVER_TOKEN)
```

### Piwigo `serve.php` validation logic

```
1. Check expires > time()                          → 403 if expired
2. Recompute HMAC and compare to ?token param      → 403 if mismatch
3. Look up image path from DB via image_id         → 404 if not found
4. Stream file with correct Content-Type header
```

### PHP generation helper (inside the plugin)

```php
function pedra_ai_signed_url(int $image_id, int $ttl = 3600): string {
    global $conf;
    $expires = time() + $ttl;
    $secret  = $conf['pedra_ai_server_token'];
    $token   = hash_hmac('sha256', $image_id . ':' . $expires, $secret);
    return get_absolute_root_url()
         . 'plugins/pedra_ai/serve.php'
         . '?image_id=' . $image_id
         . '&expires='  . $expires
         . '&token='    . $token;
}
```

**Recommended TTL:** 1 hour. The server must begin the download before expiry.

---

## 3. Webhook — Server → Piwigo

When a job completes (or fails), the server calls back Piwigo.

### Endpoint (Piwigo)

```
POST https://mypiwigo.com/plugins/pedra_ai/webhook.php
Content-Type: application/json
X-Pedra-Signature: HMAC-SHA256(raw_body, webhook.token)
```

Piwigo must respond with `200 OK` within **10 seconds**. Heavy work (file download, DB updates) must be deferred or fast.

### Payload — success

```json
{
  "job_id": "piwigo-42-1717500000",
  "server_job_id": "srv-job-abc123",
  "status": "done",
  "result_url": "https://cdn.pedra.ai/results/video-output-xyz.mp4",
  "result_mime": "video/mp4",
  "duration_seconds": 142
}
```

### Payload — error

```json
{
  "job_id": "piwigo-42-1717500000",
  "server_job_id": "srv-job-abc123",
  "status": "error",
  "error": "Pedra API error: Input video too long (max 60s)"
}
```

### Webhook payload field reference

| Field | Type | Description |
|---|---|---|
| `job_id` | string | The `job_id` sent in the original job submission |
| `server_job_id` | string | Server-side job identifier (for support/debugging) |
| `status` | `"done"` \| `"error"` | Final job status |
| `result_url` | string | (done only) Public CDN URL of the processed file |
| `result_mime` | string | (done only) MIME type of the result — `image/webp`, `video/mp4`, etc. |
| `duration_seconds` | integer | (done only) Total processing time |
| `error` | string | (error only) Human-readable error message |

### Piwigo `webhook.php` processing logic

```
1. Read raw body + X-Pedra-Signature header
2. Recompute HMAC-SHA256(body, webhook_token) and compare  → 403 if mismatch
3. Decode JSON body
4. Look up job in DB via job_id
5. If status === "done":
   a. Download result_url to temp file
   b. Detect actual MIME type of downloaded file
   c. If image/*  → pedra_ai_save_as_new_image() or pedra_ai_overwrite_image()
      If video/*  → pedra_ai_save_as_new_video()  [to implement]
   d. Update job row: status=done, new_image_id=X
6. If status === "error":
   a. Update job row: status=error, error_msg=…
7. Return 200 OK immediately
```

---

## 4. Job status polling (fallback)

If the webhook is not received (network issue, server crash), Piwigo can poll for job status.  
The plugin's existing ⚡ widget uses this to display live progress.

### Endpoint

```
GET {SERVER_URL}/api/jobs/{server_job_id}
Authorization: Bearer {SERVER_TOKEN}
```

### Response

Same structure as the webhook payload, with an additional `"processing"` status:

```json
{
  "job_id": "piwigo-42-1717500000",
  "server_job_id": "srv-job-abc123",
  "status": "processing",
  "progress_pct": 65,
  "estimated_remaining_seconds": 45
}
```

The `progress_pct` and `estimated_remaining_seconds` fields are optional but recommended for display in the ⚡ widget.

---

## 5. Plugin configuration (new fields)

Two new settings to add to the Piwigo plugin admin page:

| Config key | Type | Default | Description |
|---|---|---|---|
| `pedra_ai_server_url` | string | `""` | Base URL of the processing server, no trailing slash |
| `pedra_ai_server_token` | string | `""` | Shared secret — used for both `Authorization` header and HMAC signing |

When both fields are empty, the plugin operates in standalone mode (direct Pedra calls, photos only).  
When both are set, video operations become available and processing is routed via the server.

---

## 6. Files to create in the Piwigo plugin

| File | Purpose |
|---|---|
| `plugins/pedra_ai/serve.php` | Signed URL file streaming endpoint |
| `plugins/pedra_ai/webhook.php` | Webhook receiver from the processing server |
| `plugins/pedra_ai/include/functions.inc.php` | Add `pedra_ai_signed_url()` and `pedra_ai_save_as_new_video()` |
| `plugins/pedra_ai/admin/tpl/pedra_ai_config.tpl` | Add `pedra_ai_server_url` + `pedra_ai_server_token` fields |
| `plugins/pedra_ai/admin/pedra_ai_config.php` | Save/load new config fields |
| `plugins/pedra_ai/ajax.php` | Route to server when `pedra_ai_server_url` is configured |

---

## 7. Processing server — expected behaviour

The server is a separate repository (`pedra-piwigo-server`). The Piwigo plugin does not dictate its internal implementation, only this HTTP interface.

**Minimum contract the server must honour:**

- `POST /api/jobs` — accept job, return 202 immediately (do not wait for Pedra)
- `GET /api/jobs/{id}` — return current job status
- Download source file from `source_url` before it expires (within 1h of submission)
- Call `webhook.url` on completion with correct `X-Pedra-Signature` header
- Retry webhook on failure: 3 attempts, backoff 1 min → 5 min → 30 min
- Persist job state across restarts (SQLite or equivalent)

**Recommended stack:** Node.js + Express + BullMQ (Redis) or a simple SQLite job queue.  
**Recommended deploy targets:** Railway, Render, Scaleway Serverless.

---

## 8. Constraints and limits

| Constraint | Value |
|---|---|
| Signed URL TTL | 1 hour |
| Webhook response timeout (Piwigo) | 10 seconds |
| Webhook retry policy (server) | 3 attempts at 1 min / 5 min / 30 min |
| Max video source size | TBD with Pedra (estimate: 500 MB) |
| Max video duration | TBD with Pedra (estimate: 60–120 seconds) |
| Result file formats | `image/jpeg`, `image/webp`, `image/png`, `video/mp4`, `video/webm` |
| HMAC algorithm | HMAC-SHA256, hex-encoded |
