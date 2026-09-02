# Twitter URL → shad-base.com Comic — n8n workflow

This workflow accepts a Twitter/X URL via a webhook and creates one draft `comic`
post on shad-base.com for the matching tweet, with the artist's media attached,
attribution, and the original tweet's date preserved.

## What it does

1. Receives a `POST` to `/webhook/twitter-to-comic` with `{"twitter_url": "https://x.com/<handle>/status/<id>"}`.
2. Extracts the handle and tweet id from the URL.
3. Looks up the artist in the `shad-base-artists` n8n data table by `Twitter_Handle`.
4. Resolves the artist's WordPress user id (`GET /wp/v2/users?search=<wp_username>`).
5. Resolves the X user id (`GET /2/users/by/username/<handle>`).
6. Walks the X user-tweets endpoint (`GET /2/users/:id/tweets`) up to 10 pages, stopping as soon as the target tweet id is found. Only tweets whose attachments include photos are kept.
7. For each image-bearing tweet:
   - Downloads each image and uploads it to WordPress via `POST /wp/v2/media` (Application Password auth).
   - The first uploaded image is set as `featured_media` (a hidden thumbnail — the comic page itself does not render it).
   - Creates a draft `comic` via `POST /wp/v2/comic` with the artist's WP user as `author` and the tweet's `created_at` as the post date.
   - Updates the comic via `POST /comic-easel/v1/comics/{id}/meta` (this plugin) to set `source_tweet_id`, `source_url`, and `ceo_html_below_comic`.
   - `ceo_html_below_comic` contains one `<img>` per image (first image included, alt text is the tweet text).
8. Returns `{ "created": [{ "tweet_id", "comic_id", "image_count", "source_url" }, ...] }` to the webhook caller.

For multi-image tweets: a single comic is created. The first image is the
featured (hidden thumbnail) media, and the ceo_html_below_comic block contains
**all** images, including the first.

## Setup

### 1. Import (or re-deploy) the workflow

The workflow can be deployed two ways:

**a) Import the JSON file** — in your n8n instance, **Workflows → Import from
File…** and select `twitter-to-comic.json`. Open the imported workflow and
you'll be prompted to assign credentials to the two HTTP Request nodes that
need them (see step 2 below).

**b) Re-deploy via the n8n MCP** — the file in this repo is also a snapshot of
the live workflow (id `t3AFUdqnm0kikFsm`,
URL `https://node.heretek.one/workflow/t3AFUdqnm0kikFsm`). To rebuild it from
the SDK source the workflow was authored against, see
`twitter-to-comic.sdk.ts` — run `n8n-mcp-validate_workflow` against it, then
`n8n-mcp-create_workflow_from_code`.

After import/deploy, open the workflow in the n8n editor and assign credentials
to:

- **Resolve WP User** — uses an `httpBasicAuth` credential. Configure it with a
  WordPress username and Application Password that has `edit_posts` /
  `upload_files` capabilities. The Application Password is fine to be one
  shared admin's password — the comic is authored as the artist via the
  `author` field in the `POST` body.

- **Resolve X User ID** — uses an `httpHeaderAuth` credential. Set the header
  name to `Authorization` and the value to `Bearer <your X API bearer token>`.
  (Alternatively, store the bearer token in an env var as described below and
  swap to `headerAuth` with a generic value.)

If you re-deploy via the MCP, the two HTTP Request credentials are NOT
auto-assigned (no existing credentials of those types to reuse). Configure
them manually after the MCP deploys the workflow.

### 2. Environment variables

Set the following on the n8n host so the Code nodes can read them:

| Variable | Value |
| --- | --- |
| `X_BEARER_TOKEN` | The X / Twitter API v2 bearer token. |
| `WP_USER` | WordPress username for the Application Password used by the API calls. |
| `WP_APP_PASSWORD` | That user's Application Password. |

In the imported workflow, both the `Fetch Tweets with Images` and `Process
Tweet` Code nodes reference these via `$env.X_BEARER_TOKEN`, `$env.WP_USER`,
and `$env.WP_APP_PASSWORD`.

### 3. Data table

Create a data table called `shad-base-artists` with the following columns
(case-sensitive, the workflow matches exact column names):

| Column | Example |
| --- | --- |
| `Wordpress_Username` | `artist_handle` |
| `Wordpress_Nickname` | `Artist Display Name` |
| `Twitter_Handle` | `ArtistXHandle` (no leading `@`) |
| `Twitter_URL` | `https://x.com/ArtistXHandle` |

Add one row per artist. The `Twitter_Handle` column is the join key — it must
match the `@handle` segment in the incoming Twitter URL.

### 4. Required plugin change

The companion plugin (`comic-easel-rest`) must be running with the
`comic-easel/v1` REST namespace enabled. This workflow uses the new
`POST /comic-easel/v1/comics/{id}/meta` endpoint shipped in this repo to write
the `source_tweet_id`, `source_url`, and `ceo_html_below_comic` meta fields
without going through the public `wp/v2/comic` `meta` field (which is
intentionally not exposed to avoid the block-editor iframe regression on
WP 6.9 / 7.x). The plugin already wires up `cer_register_comic_meta_for_rest()`
in `cer_boot()`; the new endpoint is registered when the REST namespace is
enabled.

Make sure the REST namespace and CPT-args shim options are enabled (they are
by default).

### 5. Test the workflow

In the editor, click **Execute Workflow** and POST to the test webhook URL
with a payload like:

```json
{ "twitter_url": "https://x.com/ArtistXHandle/status/1234567890123456789" }
```

You should see one or more draft `comic` posts appear on shad-base.com with
the correct author, dates, and image attachments.

## Error behaviour

- If the handle is not in the data table, the workflow throws in
  `Fetch Tweets with Images` and the webhook returns a 500.
- If the tweet id isn't found within 10 pages, the workflow throws "No tweets
  with images found for tweet id …".
- Per-tweet failures inside `Process Tweet` (image upload or comic create
  fail) abort the run. Wrap the workflow in a parent workflow or add an
  error-trigger workflow if you want per-tweet resilience.

## Verifying the result

After a successful run, the created comic should have:

- `status = draft`
- `post_date` equal to the tweet's `created_at`
- `author` = the WordPress user resolved from `Wordpress_Username`
- `featured_media` = attachment of the first image
- meta `source_tweet_id`, `source_url` set
- meta `ceo_html_below_comic` containing one `<img>` per tweet image