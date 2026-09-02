---
name: twitter-to-comic
description: Ingests webcomics, manga panels, and art threads from X (Twitter) using LobeChat's Twitter connector tools and publishes them to WordPress via the Comic Easel REST API.
---

# Twitter to Comic Easel Pipeline

Autonomous workflow for fetching artwork and comic strips from X (Twitter), preparing webcomic post metadata and media assets, and publishing entries to WordPress using Comic Easel CPT (`comic`).

## Environment & Credentials

The agent requires access to the following environment variables:

| Variable | Description |
| :--- | :--- |
| `WORDPRESS_USER` | WordPress username with publish permissions |
| `WORDPRESS_APP_PASS` | WordPress Application Password (basic auth) |
| `WORDPRESS_BASE_URL` | Site root URL (e.g., `https://example.com`) |

HTTP Basic Authentication header generation:
```text
Authorization: Basic base64(${WORDPRESS_USER}:${WORDPRESS_APP_PASS})

```

---

## Tool Registry

### Primary Twitter Connector Tools

* `get_tweet`: Fetch tweet payload, full text, author metadata, and media entities by Tweet ID.
* `get_bookmarks` / `get_users_bookmarks`: Poll saved comics queued for processing.
* `get_user` / `get_users_by_username`: Retrieve creator display name, handle, and bio details.
* `like_tweet`: Mark processed tweets to prevent re-ingestion loops.
* `remove_bookmark`: Eject completed items from the ingestion queue.
* `post_tweet`: Optionally reply with a published permalink or attribution note.

### Extended Connector Reference

Use these secondary actions when managing expanded queues, user scraping, or list searches:
`search_tweets`, `get_users_timeline`, `get_users_posts`, `search_news`, `get_posts_reposted_by`, `get_posts_liking_users`, `get_usage_credits`, `get_me`, `get_users_me`, `get_users_bookmarks_by_folder_id`, `get_dm_events`, `get_conversation_messages`, `get_posts_by_id`, `get_posts_by_ids`, `get_posts_quoted_posts`, `get_users_by_usernames`, `search_users`, `get_users_by_id`, `get_users_bookmark_folders`, `get_users_mentions`, `get_home_timeline`, `get_user_tweets`, `get_following`, `get_followers`, `get_dm_with_user`, `unlike_tweet`, `block_user`, `unblock_user`, `mute_user`, `unmute_user`, `send_dm`, `create_group_dm`, `send_dm_to_conversation`, `retweet`, `unretweet`, `follow_user`, `unfollow_user`, `bookmark_tweet`, `create_users_bookmark`, `delete_tweet`, `delete_users_bookmark`.

---

## REST Endpoints & Schemas

All requests against WordPress must include the `Authorization` header.

* **Media Upload**: `POST ${WORDPRESS_BASE_URL}/wp-json/wp/v2/media`
* Headers: `Content-Disposition: attachment; filename="<slug>.jpg"`, `Content-Type: image/jpeg`


* **Comic Creation**: `POST ${WORDPRESS_BASE_URL}/wp-json/wp/v2/comic`
* Headers: `Content-Type: application/json`
* Body payload:
```json
{
  "title": "Comic Title",
  "content": "<p>Attribution and description HTML</p>",
  "status": "publish",
  "featured_media": 1234,
  "meta": {
    "_twitter_source_id": "1892345678901234567"
  }
}

```




* **Taxonomy Assignment**: `POST ${WORDPRESS_BASE_URL}/wp-json/wp/v2/comic/{id}`
* Custom taxonomies supported by Comic Easel: `comic-series`, `comic-tag`, `comic-character`.



---

## Ingestion Protocol

### 1. Identify & Ingest Tweet

1. Extract the numeric tweet status ID from the incoming prompt, bookmark, or search hit.
2. Call `get_tweet(id: "<TWEET_ID>")`.
3. Extract:
* Status text and timestamp
* Author handle (`username`) and display name (`name`)
* Attached media objects (filtering for photos/images, capturing high-res URLs and alt text)



### 2. Deduplication Check

* Query WordPress before downloading media:
```text
GET ${WORDPRESS_BASE_URL}/wp-json/wp/v2/comic?meta_key=_twitter_source_id&meta_value=<TWEET_ID>

```


* If a post is returned, abort ingestion and output the existing comic permalink.

### 3. Media Ingestion

* Iterate through attached images in sequential panel order.
* Download each image stream and upload to `/wp-json/wp/v2/media`.
* Set image `alt_text` using the tweet's native image description when provided.
* Record the resulting WordPress attachment IDs.
* **Panel 1**: Designate as the `featured_media` (the primary Comic Easel display image).
* **Panels 2+**: Append sequentially as standard `<img>` tags inside the post content or assign to Comic Easel multi-comic metadata if configured.



### 4. Content Assembly

* **Post Title**: Use the first concise sentence of the tweet text (strip hashtags, URLs, and limit to 60 characters). If empty, fall back to:
```text
{Artist Name} - YYYY-MM-DD

```


* **Post Body**: Assemble standard attribution HTML:
```html
<p>{Cleaned tweet body text}</p>
<hr />
<p><strong>Artist:</strong> <a href="[https://x.com/](https://x.com/){author_username}">{author_name} (@{author_username})</a></p>
<p><strong>Original Source:</strong> <a href="[https://x.com/](https://x.com/){author_username}/status/{tweet_id}" target="_blank" rel="noopener noreferrer">View on X</a></p>

```



### 5. Publish & Sync Queue

1. Send `POST` to `/wp-json/wp/v2/comic` with the compiled title, HTML body, `featured_media`, and metadata.
2. Mark the tweet as ingested via `like_tweet(tweetId: "<TWEET_ID>")`.
3. If running via a bookmark queue, call `remove_bookmark(tweetId: "<TWEET_ID>")`.
4. Output a status summary containing the new WordPress Post ID, title, artist, media count, and permalink.

---

## Edge Cases & Rules

* **Multiple Panels**: Never discard panels beyond image 1. If Comic Easel is configured for single-image display, embed secondary panels in the post content so readers can view the complete sequence.
* **Sensitive Content**: Verify media URLs are accessible. If Twitter flags the tweet as restricted, flag the error to the user rather than creating a post with missing media.
* **Rate Limits**: Introduce a 1.5–2.0 second pause between uploading multiple media files to low-resource WordPress instances to avoid hitting memory limits or max execution timeouts.

```
