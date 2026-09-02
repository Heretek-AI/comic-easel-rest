[# `/twitter-to-comic` — Claude Code skill

A Claude Code skill that turns a Twitter/X URL into a draft `comic` post on a
WordPress site running [`comic-easel-rest`](../../). The agent calls the
WordPress REST API and X API v2 directly with `curl` — no n8n or other
intermediary.

This skill is **project-local**: it loads only when Claude Code is running
inside this repository. Ship it with the plugin so anyone who with the plugin
gets the skill.

## What it does

1. Parses `<twitter-url>` into `{handle, tweet_id}`.
2. Looks up the artist in `references/shad-base-artists.json`.
3. Resolves the artist's WP user id (`GET /wp/v2/users?search=...`).
4. Resolves the X user id and paginates `GET /2/users/<id>/tweets` (stops at
   the target tweet).
5. For each image: downloads → `POST /wp/v2/media` → captures `{id, source_url}`.
6. Creates a draft comic (`POST /wp/v2/comic`) with `author`, `date`,
   `featured_media` = first image id.
7. Writes `source_tweet_id`, `source_url`, `ceo_html_below_comic` via the
   plugin's `POST /comic-easel/v1/comics/{id}/meta` endpoint.

Multi-image tweets: one comic per tweet. The first image is the hidden
thumbnail (`featured_media`); `ceo_html_below_comic` contains all images,
joined by `\n`, alt-text = the tweet text.

## Setup

### 1. Seed the artist list

Edit `.claude/skills/twitter-to-comic/references/shad-base-artists.json` with
one row per artist:

```json
[
  {
    "Wordpress_Username": "artist_handle",
    "Wordpress_Nickname": "Artist Display Name",
    "Twitter_Handle": "ArtistXHandle",
    "Twitter_URL": "https://x.com/ArtistXHandle"
  }
]
```

`Twitter_Handle` must match the `@handle` segment in the Twitter URL (no
leading `@`). This file is meant to be edited locally and committed — it
contains no secrets.

### 2. Set the env vars

On the host where Claude Code runs (your dev machine, the build server —
whatever invokes the agent), export:

| Variable | Example |
| --- | --- |
| `WP_BASE_URL` | `https://shad-base.com` |
| `WP_USER` | WordPress username for the Application Password |
| `WP_APP_PASSWORD` | That user's Application Password (`edit_posts` + `upload_files` capabilities) |
| `X_BEARER_TOKEN` | X API v2 bearer token (Basic tier or higher for history access) |

If `WP_BASE_URL` is unset, the skill defaults to `https://shad-base.com`.

### 3. Verify the plugin is live on the target site

The skill requires the `comic-easel-rest` plugin to be installed and the
`comic-easel/v1` REST namespace enabled. Confirm:

- `GET <WP_BASE_URL>/wp-json/comic-easel/v1/settings` returns the
  whitelisted settings (200 OK).
- The plugin's `enable_rest_namespace` option is `true` (default).

If `/comic-easel/v1/comics/{id}/meta` returns 404, the plugin isn't exposing
the namespace — the skill will fail at the meta-write step.

## Usage

Inside Claude Code, run from this repo's root:

```
/twitter-to-comic https://x.com/<handle>/status/<tweet_id>
```

The agent walks the seven steps in `SKILL.md`, prints each result, and
reports one line per comic created.

## Files

- `SKILL.md` — skill definition (frontmatter + body). This is what Claude
  Code reads when you invoke `/twitter-to-comic`.
- `references/shad-base-artists.json` — artist seed list (edit locally).
- `references/api-surface.md` — exact endpoint reference for the agent.
- `README.md` — this file.

## Verification

After invoking the skill against a known multi-image tweet:

1. **Comic exists** at `https://shad-base.com/wp-admin/post.php?post=<id>&action=edit`
   with `status = draft`, the tweet's `created_at` as `post_date`, the artist's
   WP user as `author`, and the first image's attachment as `featured_media`.
2. **Meta is set**: `source_tweet_id`, `source_url`, `ceo_html_below_comic`
   all populated in the comic's Custom Fields meta box.
3. **Multi-image**: `ceo_html_below_comic` contains one `<img>` per tweet
   image (first image included), joined by `\n`, alt-text = tweet text.

## Relationship to the n8n workflow

`/n8n-workflows/twitter-to-comic.sdk.ts` does the same job via n8n. Both
paths share:

- The artist list shape (`Wordpress_Username`, `Wordpress_Nickname`,
  `Twitter_Handle`, `Twitter_URL`).
- The X API walk algorithm (paginated, stops at target, photo-only filter,
  10-page cap).
- The WP API sequence (`/wp/v2/media` per image → `/wp/v2/comic` →
  `/comic-easel/v1/comics/{id}/meta`).

If you update one, update the other. The plugin's REST endpoints are the
single source of truth.

## Plugin references

- Meta-registration rationale (`show_in_rest` deliberately off):
  `comic-easel-rest.php` `cer_register_comic_meta_for_rest` docblock.
- Meta-write endpoint registration:
  `includes/class-rest-controller.php` `register_endpoint_set_meta`.
- Meta-write callback: `functions/settings.php` `cer_set_comic_meta`.](https://raw.githubusercontent.com/Heretek-AI/comic-easel-rest/refs/heads/master/.claude/skills/twitter-to-comic/README.md)
