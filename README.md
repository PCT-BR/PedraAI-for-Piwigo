# Pedra AI for Piwigo `v1.1.0`

A Piwigo plugin that integrates the [Pedra AI](https://pedra.ai) API for real estate photo processing — virtual staging, renovation simulation, photo enhancement, sky replacement, background blur, and more.

---

## Features

- **9 operations**: furnish, empty room, renovation, edit via prompt, remove object, enhance, enhance + perspective correction, sky blue, blur
- **Batch processing**: select multiple photos and process them in one click from the gallery action bar
- **Per-photo parameters**: when processing a batch, each photo gets its own style, room type, or prompt — no need to run operations one by one
- **Private album support**: images are sent as base64 data URIs so Pedra never needs to fetch a protected URL
- **Result saved as new photo** (or overwrite — your choice), with MIME-aware extension detection (WebP output handled automatically)
- **Persistent job tracker**: a ⚡ widget in the topbar shows job status across page navigation, with a direct link to each processed photo
- **Credit counter**: manually track your remaining Pedra credits; the plugin decrements automatically after each successful job
- **English UI** with French translation included (`en_UK` / `fr_FR`)

---

## Requirements

- Piwigo 13+ (tested on 17.0.0)
- PHP 8.0+
- `curl` PHP extension
- A [Pedra AI](https://app.pedra.ai) account and API key

---

## Installation

1. Download or clone this repository into your Piwigo `plugins/` directory:
   ```
   plugins/pedra_ai/
   ```
2. In Piwigo admin → **Plugins**, activate **Pedra AI**.
3. Click **Settings** next to the plugin, enter your API key, and save.

---

## Configuration

Go to **Admin → Plugins → Pedra AI → Settings**.

| Setting | Description |
|---|---|
| **API Key** | Your Pedra AI API key (`app.pedra.ai → Settings → API`) |
| **Remaining credits** | Optional manual counter. Leave blank to disable tracking. |
| **Default operation** | Pre-selected operation in the Batch Manager |
| **Save mode** | Save as new photo (default) or overwrite the original |
| **New photo suffix** | Suffix appended to the filename when saving as new (default: `_pedra`) |

---

## Usage

### Process multiple photos (gallery)

1. Hover over any photo thumbnail — a checkbox appears in the top-left corner.
2. Select one or more photos.
3. Click **⚡ Pedra AI** in the blue action bar at the bottom.
4. Choose an operation and set parameters:
   - For **furnish** and **renovation**: each selected photo gets its own style and room type. Use **Apply to all ▶** to fill all rows at once, then adjust individually.
   - For **edit via prompt** and **blur**: each photo gets its own text field.
5. Click **Launch processing**. Jobs are submitted and tracked in the ⚡ widget in the topbar.

### Process a single photo (photo page)

1. Open any photo.
2. Click the ⚡ button in the top navigation bar.
3. Choose an operation, set parameters, and click **Launch processing**.

### Job tracker (⚡ widget)

The widget in the topbar polls for job status every 3 seconds while jobs are active, or every 30 seconds when idle. Each completed job shows a **View photo →** link. The widget persists across page navigation.

---

## Operation reference

| Operation | Parameters | Credits |
|---|---|---|
| `furnish` | style, room type, creativity | Medium: 2 — High: 1 |
| `renovation` | style, creativity, preserve windows, add furniture | Medium: 2 — High: 1 |
| `empty_room` | — | 1 |
| `edit_via_prompt` | prompt (required) | 1 |
| `remove_object` | — | 1 |
| `enhance` | preserve original framing | 1 |
| `enhance_and_correct_perspective` | preserve original framing | 1 |
| `sky_blue` | — | 1 |
| `blur` | objects to blur (required, e.g. "faces, license plates") | 1 |

---

## Architecture

### Standalone mode (current)

The plugin calls the Pedra AI API directly from PHP. Images are base64-encoded before sending, so no public URL is required. Suitable for all photo operations.

```
Piwigo (PHP) ──base64──► Pedra AI ──result URL──► Piwigo saves photo
```

### Server mode (planned — video support)

Video files are too large for base64 and processing can take several minutes. An optional async processing server handles these jobs:

```
Piwigo ──job request──► Processing server ──► Pedra AI
Piwigo ◄──webhook────── Processing server (on completion)
```

The server contract is defined in [`PROCESSING_SERVER_CONTRACT.md`](PROCESSING_SERVER_CONTRACT.md) (not included in this release).  
Configure `pedra_ai_server_url` and `pedra_ai_server_token` in the plugin settings to enable this mode.

---

## Database

The plugin creates one table on activation:

```sql
CREATE TABLE {prefix}pedra_ai_jobs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  image_id     MEDIUMINT UNSIGNED NOT NULL,
  operation    VARCHAR(50) NOT NULL,
  status       ENUM('pending','processing','done','error') NOT NULL,
  result_url   VARCHAR(512),
  new_image_id INT(11) DEFAULT NULL,
  error_msg    TEXT,
  created_at   DATETIME NOT NULL,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

Jobs older than 24 hours are not returned by the widget but remain in the table.

---

## License

MIT — see [LICENSE](LICENSE) if present, otherwise use freely with attribution.

---

## Credits

Built for the [Lumière](https://github.com/PCT-BR) real estate gallery theme.  
Powered by [Pedra AI](https://pedra.ai).
