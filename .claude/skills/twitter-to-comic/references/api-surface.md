Assuming you need the reference specification file (`api-surface.md`) for the `twitter-to-comic` Claude skill targeting the `comic-easel-rest` repository, the complete API contract and schema specification are structured below.

---

# Comic Easel REST API Surface

This document defines the REST API endpoints, schemas, authentication requirements, and data mapping protocols used by the `twitter-to-comic` skill to ingest Twitter/X media and publish it as serialized comic posts in WordPress via Comic Easel.

## Authentication & Headers

Requests require authentication via WordPress Application Passwords or standard Bearer JWT tokens.

* **Authorization Header**: `Authorization: Basic <base64(username:application_password)>` or `Authorization: Bearer <jwt_token>`
* **Content-Type**: `application/json` (except media binary uploads: `image/png`, `image/jpeg`, `image/webp`)
* **Base Path**: `https://<site-domain>/wp-json`

---

## Core Endpoints

### 1. Deduplication Check

Verify whether a tweet has already been ingested before processing media or creating records.

* **Method**: `GET`
* **Route**: `/wp-json/wp/v2/comic`
* **Query Parameters**:
* `meta_key=twitter_source_id`
* `meta_value=<tweet_id>`
* `_fields=id,link,slug,status`


* **Response `200 OK**`:
```json
[
  {
    "id": 1042,
    "link": "https://example.com/comic/episode-42",
    "slug": "episode-42",
    "status": "publish"
  }
]

```



---

### 2. Media Upload

Upload raw comic strip images downloaded from the tweet payload before generating the comic post.

* **Method**: `POST`
* **Route**: `/wp-json/wp/v2/media`
* **Headers**:
* `Content-Type`: `image/png` (or image MIME type)
* `Content-Disposition`: `attachment; filename="comic_<tweet_id>_<index>.png"`


* **Body**: Binary image payload
* **Response `201 Created**`:
```json
{
  "id": 4821,
  "source_url": "https://example.com/wp-content/uploads/2026/09/comic_182937492_0.png",
  "media_details": {
    "width": 1200,
    "height": 1800
  }
}

```



---

### 3. Comic Post Creation

Create the primary Comic Easel custom post type (`comic`).

* **Method**: `POST`
* **Route**: `/wp-json/comic-easel/v1/comics` (fallback: `/wp-json/wp/v2/comic`)
* **Headers**: `Content-Type: application/json`
* **Payload Schema**:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `title` | `string` | Yes | Comic post title (derived from tweet or series convention). |
| `content` | `string` | No | HTML or Markdown body/transcript. |
| `status` | `string` | Yes | Target post status: `"publish"`, `"draft"`, or `"future"`. |
| `date` | `string` | No | ISO 8601 UTC timestamp of the original tweet. |
| `featured_media` | `integer` | Yes | WordPress media ID returned from the media upload endpoint. |
| `chapters` | `integer[]` | No | Array of taxonomy term IDs for the storyline/chapter. |
| `characters` | `integer[]` | No | Array of taxonomy term IDs for tagged characters. |
| `comic-tag` | `integer[]` | No | Array of taxonomy term IDs for tags. |
| `meta` | `object` | Yes | Comic Easel custom metadata fields. |

* **Request Example**:
```json
{
  "title": "Strip #48: Morning Coffee",
  "status": "publish",
  "date": "2026-09-01T14:32:00Z",
  "featured_media": 4821,
  "chapters": [12],
  "characters": [4, 9],
  "meta": {
    "comic_hovertext": "Alt-text extracted from tweet or custom prompt punchline.",
    "comic_transcript": "Panel 1: Character drinks coffee. Panel 2: Realization hits.",
    "twitter_source_id": "18301928471928374",
    "twitter_author": "artist_handle",
    "twitter_source_url": "https://x.com/artist_handle/status/18301928471928374"
  }
}

```


* **Response `201 Created**`:
```json
{
  "id": 1043,
  "slug": "strip-48-morning-coffee",
  "status": "publish",
  "link": "https://example.com/comic/strip-48-morning-coffee",
  "featured_media": 4821,
  "chapters": [12],
  "meta": {
    "comic_hovertext": "Alt-text extracted from tweet or custom punchline.",
    "twitter_source_id": "18301928471928374"
  }
}

```



---

### 4. Taxonomy & Chapter Management

Query and resolve chapters or storylines before assignment.

* **Method**: `GET` / `POST`
* **Route**: `/wp-json/wp/v2/chapters`
* **Query Parameters (`GET`)**: `search=<chapter_name>&per_page=10`
* **Payload (`POST`)**:
```json
{
  "name": "Chapter 3: The Heist",
  "slug": "chapter-3-the-heist",
  "parent": 0,
  "description": "Third story arc."
}

```



---

## Twitter Payload Mapping Matrix

| Twitter Source Field | Comic Easel Target Field | Transformation / Notes |
| --- | --- | --- |
| `tweet.id_str` | `meta.twitter_source_id` | Stored as string to prevent 64-bit integer overflow. |
| `tweet.entities.media[0]` | `featured_media` | Download largest resolution (`?name=orig`), upload to media endpoint. |
| `tweet.full_text` | `meta.comic_hovertext` / `content` | Parse: text without t.co links maps to hovertext or post body. |
| `tweet.created_at` | `date` | Convert Twitter timestamp format to ISO 8601 (`YYYY-MM-DDTHH:MM:SSZ`). |
| `tweet.entities.hashtags` | `comic-tag` | Match against existing tag slugs or create missing terms. |

---

## Error Handling

* **`400 Bad Request`**: Missing required parameters (`featured_media`, `title`) or malformed JSON.
* **`401 Unauthorized`**: Invalid application password or missing authorization header.
* **`403 Forbidden`**: User account lacks `publish_posts` or `upload_files` capabilities.
* **`409 Conflict`**: Comic with the specified `twitter_source_id` already exists.

---
