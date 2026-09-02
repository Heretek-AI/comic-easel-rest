---
name: twitter-to-comic
description: Turn a Twitter/X URL into a draft comic on shad-base.com by calling the WordPress REST API and X API v2 directly. Use when the user asks to "publish this tweet", "import this tweet", "post this tweet as a comic", "create a draft comic from <url>", "twitter to comic", "x to shad-base", or supplies a Twitter/X URL and asks to add it to the site. Reads WP_BASE_URL, WP_USER, WP_APP_PASSWORD, X_BEARER_TOKEN from env, looks up the artist in references/shad-base-artists.json, fetches the tweet + media via X API v2, uploads each image to WP media, creates a draft comic, and writes source_tweet_id/source_url/ceo_html_below_comic meta via the comic-easel-rest plugin's dedicated endpoint.
argument-hint: "<twitter-url>"
---

# twitter-to-comic

Take one Twitter/X URL and turn it into a draft `comic` post on a WordPress site
running `comic-easel-rest`. No n8n, no intermediary — call the REST APIs with
`curl` (or `WebFetch` for GETs).

## Inputs

- **`<twitter-url>`** (required): `https://x.com/<handle>/status/<tweet_id>` or
  `https://twitter.com/<handle>/status/<tweet_id>`. `mobile.twitter.com` also
  accepted. The path `/<handle>/status/<digits>` is what matters.
- **Env vars** (all required — stop early with a clear error if any are
  missing):
  - `WP_BASE_URL` — base of the WordPress site, e.g. `https://shad-base.com`.
    Default to `https://shad-base.com` if unset.
  - `WP_USER` — WordPress username for the Application Password.
  - `WP_APP_PASSWORD` — that user's Application Password (needs `edit_posts`
    and `upload_files` capabilities).
  - `X_BEARER_TOKEN` — X API v2 bearer token (Basic tier or higher; Free tier
    only sees the last 7 days of tweets).
- **Artist seed**: read `references/shad-base-artists.json` next to this
  `SKILL.md`. Each row maps a Twitter handle to a WP username + display
  nickname. If the incoming handle isn't there, list the known handles and
  ask the user to add one or pick from the list.

## Flow

Run these steps in order. Use `curl -sS` for everything; capture each response
with `2>&1 | tee /tmp/step-N.log` so you can debug failures.

### 1. Parse the URL

Extract `handle` and `tweet_id` from the path. Reject with a clear error if
the URL doesn't match `/<handle>/status/<digits>`. The handle is the segment
between the host and `/status/`.

### 2. Look up the artist

Read `references/shad-base-artists.json`, find the row whose `Twitter_Handle`
equals the parsed handle (case-insensitive match). Capture `Wordpress_Username`
and `Wordpress_Nickname`. If not found, stop and ask the user.

### 3. Resolve the WP user id

```bash
curl -sS -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_BASE_URL/wp-json/wp/v2/users?search=$WORDPRESS_USERNAME&per_page=1"
```

Take the first item's `id`. If the response is `[]`, the WP user doesn't
exist — stop and ask.

### 4. Resolve the X user id

```bash
curl -sS -H "Authorization: Bearer $X_BEARER_TOKEN" \
  "https://api.twitter.com/2/users/by/username/$HANDLE"
```

Capture `data.id`. If the response is missing `data` or `data.id`, the
handle isn't on X — stop and ask.

### 5. Fetch tweets (paginated, stop at target)

```bash
QS="max_results=100&tweet.fields=created_at,attachments&expansions=attachments.media_keys&media.fields=media_key,type,url,preview_image_url"
URL="https://api.twitter.com/2/users/$X_USER_ID/tweets?$QS"
```

Loop:

- GET the URL.
- Accumulate every `includes.media[]` entry into a `media_by_key` map keyed by
  `media_key`.
- For each tweet in `data[]`:
  - If its `id` matches the target `tweet_id`, mark `found_target = true`.
  - Take its `attachments.media_keys`, look each up in `media_by_key`, and
    keep only entries where `type === "photo"` AND `url` is a non-empty string.
    Drop video and animated_gif (they only have `preview_image_url`).
- If `found_target`, stop paginating.
- Otherwise, if `meta.next_token` exists, set
  `pagination_token=<next_token>` and continue. Hard cap at **10 pages** to
  avoid burning quota on accidental infinite loops.

Emit one record per tweet that has at least one photo URL:

```
{
  tweet_id, text, image_urls: [...], source_url: "https://x.com/$HANDLE/status/$tweet_id",
  created_at: <ISO 8601 from X>,>
  Wordpress_Nickname, wp_user_id
}
```

If no photo-bearing tweet was found in the page that contained the target,
stop and report.

### 6. For each tweet, upload images → create comic → write meta

**6a. (optional, idempotency) Skip if already created.** WordPress's REST
search checks title + content + excerpt. The default title format
`Comic by <Nickname> - <MMM d, yyyy>` rarely matches a tweet id verbatim,
but searching for the `source_url` works most of the time:

```bash
curl -sS -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_BASE_URL/wp-json/wp/v2/comic?search=$SOURCE_URL&per_page=1"
```

If the response has items, surface the existing post id and skip the create
steps for that tweet. Note the limitation in your final summary: the plugin
intentionally does NOT expose `source_tweet_id` / `source_url` as a REST
`meta` field (see `comic-easel-rest.php` `cer_register_comic_meta_for_rest`
docblock), so a precise idempotency check would need direct DB access.

**6b. Download each image to a local file.**

```bash
curl -sSL -o /tmp/img-1.jpg "$IMAGE_URL_1"
curl -sSL -o /tmp/img-2.jpg "$IMAGE_URL_2"
```

(Use the URL's last path segment as the filename; fall back to
`tweet-<tweet_id>-N.jpg`.)

**6c. Upload each to WP media.** Capture `id` and `source_url` from each
response. Use `-H "Content-Disposition: attachment; filename=\"<file>\""` so
WP keeps the original filename.

```bash
for f in /tmp/img-*.jpg; do
  curl -sS -u "$WP_USER:$WP_APP_PASSWORD" \
    -H "Content-Disposition: attachment; filename=\"$(basename "$f")\"" \
    -H "Content-Type: image/jpeg" \
    --data-binary "@$f" \
    "$WP_BASE_URL/wp-json/wp/v2/media"
done
```

Note: if Twitter served a PNG, GIF, or WEBP, change `Content-Type` to match.
The X API v2 `media.fields=url` for a photo returns the direct image — the
extension on the URL hints at the MIME type.

**6d. Create the draft comic.** Use the first uploaded image's `id` as
`featured_media`. The comic CPT slug is `comic` (per
`cer_resolve_comic_slug` in the plugin). Pass the tweet's `created_at` as
`date` (ISO 8601 with `Z`).

```bash
TITLE="Comic by $WORDPRESS_NICKNAME - $(date -u -d "$CREATED_AT" '+%b %-d, %Y')"
curl -sS -u "$WP_USER:$WP_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d "$(jq -n \
    --arg title "$TITLE" \
    --arg date "$CREATED_AT" \
    --argjson author "$WP_USER_ID" \
    --argjson featured "$FIRST_MEDIA_ID" \
    '{title:$title, status:"draft", date:$date, author:$author, featured_media:$featured}')" \
  "$WP_BASE_URL/wp-json/wp/v2/comic"
```

Capture the response's `id` as `$COMIC_ID`. Do **not** try to set the meta
fields here — `show_in_rest` is intentionally off for them.

**6f. Write `source_tweet_id`, `source_url`, `ceo_html_below_comic`** via
the plugin's dedicated endpoint (registered in
`includes/class-rest-controller.php` `register_endpoint_set_meta`, callback
`cer_set_comic_meta` in `functions/settings.php`).

Build `ceo_html_below_comic` — one `<img>` per uploaded image, **including
the first**:

```bash
CEO_HTML=""
for u in "${SOURCE_URLS[@]}"; do
  CEO_HTML+="<img src=\"$u\" alt=\"$(echo "$TWEET_TEXT" | sed 's/&/&amp;/g;s/"/\&quot;/g;s/</\&lt;/g;s/>/\&gt;/g' | cut -c1-200)\" class=\"alignnone\" />"$'\n'
done
```

Then:

```bash
curl -sS -u "$WP_USER:$WP_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d "$(jq -n \
    --arg tid "$TWEET_ID" \
    --arg url "$SOURCE_URL" \
    --arg html "$CEO_HTML" \
    '{source_tweet_id:$tid, source_url:$url, ceo_html_below_comic:$html}')" \
  "$WP_BASE_URL/wp-json/comic-easel/v1/comics/$COMIC_ID/meta"
```

### 7. Report

Print one line per comic created:

```
created comic_id=123 tweet_id=... source_url=... image_count=N
```

If the workflow was triggered by a batch (one URL with multiple photo tweets
is possible — the X API returns the target tweet plus any later photos in the
same page), repeat the report line per comic.

## Multi-image behaviour

- One comic per photo-bearing tweet.
- The first image is `featured_media` — it's the hidden thumbnail the comic
  page uses for archive rendering but does not display on the page itself.
- `ceo_html_below_comic` contains one `<img>` tag **per image**, including
  the first. The tweet text is used as the alt text (truncated to 200 chars,
  HTML-escaped). Tags are joined by `\n`.

## Auth header cheat sheet

- WP: `Authorization: Basic $(printf '%s:%s' "$WP_USER" "$WP_APP_PASSWORD" | base64)` —
  curl's `-u user:pass` flag does this for you.
- X: `Authorization: Bearer $X_BEARER_TOKEN`.

## Failure modes

| Symptom | Cause | Action |
| --- | --- | --- |
| `WP_BASE_URL` / `WP_USER` / `WP_APP_PASSWORD` / `X_BEARER_TOKEN` unset | host missing the env var | stop, name the missing var(s) in the error |
| `references/shad-base-artists.json` has no row for the handle | artist not seeded | list known handles, ask user to add or stop |
| X `401 Unauthorized` | bearer token expired / wrong project | stop |
| `X` `429 Too Many Requests` | rate-limited | retry with exponential backoff (X Basic: 100 req / 15 min) |
| X `data` empty on `by/username` | handle doesn't exist on X | stop, ask user |
| X walk didn't find the tweet in 10 pages | tweet older than ~1000 most-recent | stop, ask user |
| WP `wp/v2/users` returns `[]` | WP username wrong | stop, ask user |
| WP `403` on any call | Application Password wrong / user lacks caps | stop, surface WP error body |
| WP `404` on `/comic-easel/v1/comics/{id}/meta` | plugin not installed or REST namespace disabled | stop, ask user to verify `enable_rest_namespace` is true |
| WP `400` on `POST /wp/v2/comic` | author id missing/non-numeric, date not ISO 8601, featured_media non-numeric | inspect the WP error and retry |

## Reference

- `references/shad-base-artists.json` — artist seed (edit locally; never
  commit credentials).
- `references/api-surface.md` — exact endpoint reference for every WP and X
  call this skill makes, with response shapes.