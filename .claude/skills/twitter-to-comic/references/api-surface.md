# API Surface — twitter-to-comic skill

This document lists every HTTP call the skill makes. The agent should follow
each section verbatim: method, URL template, headers, request body shape, and
response shape. All WP calls use Application Password auth. All X calls use
bearer-token auth.

## Auth

### WordPress (Basic auth)

```
Authorization: Basic base64(WP_USER:WP_APP_PASSWORD)
```

`curl` shorthand:

```bash
curl -sS -u "$WP_USER:$WP_APP_PASSWORD" ...
```

The credential user must have `edit_posts` and `upload_files` capabilities.

### X (Bearer token)

```
Authorization: Bearer $X_BEARER_TOKEN
```

---

## WordPress endpoints

### Resolve WP user

```
GET $WP_BASE_URL/wp-json/wp/v2/users?search=$WORDPRESS_USERNAME&per_page=1
Headers:
  Authorization: Basic ...
```

Response (`200`):

```json
[
  {
    "id": 42,
    "name": "Artist Display Name",
    "slug": "artist-handle",
    "description": "..."
  }
]
```

Take `id` from the first item.

Errors:
- `[]` → user not found; stop.
- `403` → Application Password wrong or user lacks `edit_posts`.

### Upload image

```
POST $WP_BASE_URL/wp-json/wp/v2/media
Headers:
  Authorization: Basic ...
  Content-Type: <mime>                # image/jpeg | image/png | image/gif | image/webp
  Content-Disposition: attachment; filename="<filename>"
Body:
  <raw image bytes, --data-binary @/path/to/file>
```

Response (`201`):

```json
{
  "id": 678,
  "source_url": "https://shad-base.com/wp-content/uploads/2026/08/HQ86pw_WQAA9a-w.jpg",
  "media_details": { ... },
  "mime_type": "image/jpeg",
  ...
}
```

Capture `id` and `source_url`.

Errors:
- `400` → invalid file (not an image, or zero bytes).
- `403` → Application Password lacks `upload_files`.

### Create draft comic

```
POST $WP_BASE_URL/wp-json/wp/v2/comic
Headers:
  Authorization: Basic ...
  Content-Type: application/json
Body:
  {
    "title":          "Comic by Example Artist - Aug 12, 2026",
    "status":         "draft",
    "date":           "2026-08-12T18:34:02Z",   // ISO 8601, tweet's created_at
    "author":         42,                         // WP user id from Resolve WP user
    "featured_media": 678                         // attachment id of the FIRST uploaded image
  }
```

> **Do not** pass `meta` here. `source_tweet_id`, `source_url`, and
> `ceo_html_below_comic` are intentionally NOT exposed via the standard
> `wp/v2/comic` `meta` field (see `comic-easel-rest.php`
> `cer_register_comic_meta_for_rest` docblock). Writing them via `meta`
> would silently drop them and trip the WP 6.9 / 7.x block-editor iframe
> regression that the plugin deliberately avoids.

Response (`201`):

```json
{
  "id": 1234,
  "status": "draft",
  "title": { "rendered": "Comic by ..." },
  "featured_media": 678,
  "meta": [], //   // intentionally empty — fields below are written via the next endpoint
  ...
}
```

Capture `id` as `$COMIC_ID`.

Errors:
- `400` with `rest_invalid_param` on `author` / `featured_media` / `date` —
  check the param value (numeric, ISO 8601, etc).
- `403` → user lacks the required capability.

### Write meta (plugin endpoint)

```
POST $WP_BASE_URL/wp-json/comic-easel/v1/comics/$COMIC_ID/meta
Headers:
  Authorization: Basic ...
  Content-Type: application/json
Body:
  {
    "source_tweet_id":      "1234567890123456789",
    "source_url":            "https://x.com/handle/status/1234567890123456789",
    "ceo_html_below_comic":  "<img src=\"...\" alt=\"...\" class=\"alignnone\" />\n<img src=\"...\" ... />"
  }
```

All three keys are optional, but supply at least one. The endpoint writes
each supplied key via `update_post_meta`, which works regardless of whether
`show_in_rest` is on (it isn't, by design).

Response (`200`):

```json
{
  "id": 1234,
  "updated": ["source_tweet_id", "source_url", "ceo_html_below_comic"]
}
```

Errors:
- `404 cer_invalid_comic` → comic doesn't exist or isn't the right CPT.
- `400 cer_no_meta` → no recognised keys supplied.
- `403` → user lacks `edit_posts` or `upload_files`.

### Search for an existing comic (idempotency, approximate)

```
GET $WP_BASE_URL/wp-json/wp/v2/comic?search=$SOURCE_URL&per_page=1
Headers:
  Authorization: Basic ...
```

WP's default search checks title, excerpt, and content. The tweet's
`source_url` is unlikely to be in any of those for an untouched draft, but
this is the closest we can get without exposing `source_url` as a REST meta
field. If the response has items, the comic already exists — surface its id
and skip the create steps.

---

## X API v2 endpoints

### Resolve user id

```
GET https://api.twitter.com/2/users/by/username/$HANDLE
Headers:
  Authorization: Bearer $X_BEARER_TOKEN
```

Response (`200`):

```json
{
  "data": {
    "id": "12345",
    "name": "Display Name",
    "username": "ExampleArtist"
  }
}
```

Capture `data.id`. Errors:
- `404` or empty `data` → handle not found; stop.

### Fetch tweets (paginated)

```
GET https://api.twitter.com/2/users/$X_USER_ID/tweets?max_results=100&tweet.fields=created_at,attachments&expansions=attachments.media_keys&media.fields=media_key,type,url,preview_image_url&pagination_token=$NEXT_TOKEN
Headers:
  Authorization: Bearer $X_BEARER_TOKEN
```

Per-page response (`200`):

```json
{
  "data": [
    {
      "id": "1234567890123456789",
      "text": "tweet text",
      "created_at": "2026-08-12T18:34:02.000Z",
      "attachments": {
        "media_keys": ["3_1234567890123456789", "3_9876543210987654321"]
      }
    }
  ],
  "includes": {
    "media": [
      {
        "media_key": "3_1234567890123456789",
        "type": "photo",
        "url": "https://pbs.twimg.com/media/....jpg"
      },
      {
        "media_key": "3_9876543210987654321",
        "type": "video",
        "preview_image_url": "https://pbs.twimg.com/ext_tw_video_thumb/....jpg"
      }
    ]
  },
  "meta": {
    "next_token": "abcdef...",
    "result_count": 10,
    "newest_id": "...",
    "oldest_id": "..."
  }
}
```

### Walk algorithm

1. Start with no `pagination_token`.
2. Accumulate every `includes.media[]` entry into `media_by_key` keyed by
   `media_key`. (The `includes.media` array is per-page only — pages don't
   carry over.)
3. For each tweet in `data[]`:
   - If `id === $TWEET_ID`, set `found_target = true`.
   - Take `attachments.media_keys`, look each up in `media_by_key`. **Keep
     only entries where `type === "photo"` AND `url` is non-empty.**
     Drop `video` and `animated_gif` (they have only `preview_image_url`).
4. If `found_target`, stop paginating. Emit any photo-bearing tweets found
   in the current page (and any earlier pages already accumulated).
5. Otherwise, if `meta.next_token` exists, set
   `pagination_token=<next_token>` and loop. Hard cap at **10 pages**.
6. If `found_target` was never set after 10 pages, stop and ask the user —
   the tweet is older than the most recent ~1000.

---

## Response shape cheatsheet

| What you need | Where to get it |
| --- | --- |
| WP user id | `GET /wp/v2/users` → `[0].id` |
| Attachment id | `POST /wp/v2/media` → `.id` |
| Attachment URL | `POST /wp/v2/media` → `.source_url` |
| Comic id | `POST /wp/v2/comic` → `.id` |
| X user id | `GET /2/users/by/username/{handle}` → `.data.id` |
| Tweet text | `GET /2/users/{id}/tweets` → `.data[].text` |
| Tweet `created_at` | `GET /2/users/{id}/tweets` → `.data[].created_at` |
| Photo URL | `GET /2/users/{id}/tweets` → `.includes.media[]` filtered to `type === "photo"`, take `.url` |
| Next page | `GET /2/users/{id}/tweets` → `.meta.next_token` |