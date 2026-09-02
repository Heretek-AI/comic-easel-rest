// Stripped SDK code — HTML escapes from subagent transcription are removed
const webhookTrigger = trigger({
  type: 'n8n-nodes-base.webhook',
  version: 2.1,
  config: {
    name: 'Webhook Trigger',
    parameters: {
      httpMethod: 'POST',
      path: 'twitter-to-comic',
      responseMode: 'responseNode',
    },
    position: [240, 300],
  },
  output: [{ body: { twitter_url: 'https://x.com/handle/status/1234567890' } }],
});

const parseTwitterUrl = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: {
    name: 'Parse Twitter URL',
    parameters: {
      mode: 'runOnceForEachItem',
      jsCode: 'const twitterUrl = $json.body && $json.body.twitter_url || $json.twitter_url || "";\nconst match = twitterUrl.match(/^https?:\\/\\/(?:www\\.|mobile\\.)?(?:twitter|x)\\.com\\/([^/]+)\\/status\\/(\\d+)/i);\nif (!match) throw new Error("Invalid Twitter URL: " + twitterUrl);\nconst handle = match[1];\nconst tweetId = match[2];\nreturn [{ json: { handle: handle, tweet_id: tweetId, source_url: twitterUrl } }];',
    },
    position: [540, 300],
  },
  output: [{ handle: 'handle', tweet_id: '1234567890', source_url: 'https://x.com/handle/status/1234567890' }],
});

const lookupArtist = node({
  type: 'n8n-nodes-base.dataTable',
  version: 1.1,
  config: {
    name: 'Lookup Artist',
    parameters: {
      resource: 'row',
      operation: 'get',
      dataTableId: { __rl: true, value: 'shad-base-artists', mode: 'name' },
      mustMatch: 'all',
      conditions: {
        conditions: [
          { columnName: 'Twitter_Handle', value: expr('{{ $json.handle }}'), operator: 'equals' }
        ],
      },
      returnAll: false,
      limit: 1,
    },
    position: [840, 300],
  },
  output: [{ Wordpress_Username: 'handle', Wordpress_Nickname: 'Artist Name', Twitter_Handle: 'handle', Twitter_URL: 'https://x.com/handle' }],
});

const resolveWpUser = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.3,
  config: {
    name: 'Resolve WP User',
    parameters: {
      method: 'GET',
      url: expr('https://shad-base.com/wp-json/wp/v2/users?search={{ $json.Wordpress_Username }}&per_page=1'),
      options: { response: { response: { responseFormat: 'json' } } },
    },
    credentials: { httpBasicAuth: newCredential('WP Basic') },
    position: [1140, 300],
  },
  output: [{ id: 1, name: 'handle', slug: 'handle' }],
});

const resolveXUserId = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.3,
  config: {
    name: 'Resolve X User ID',
    parameters: {
      method: 'GET',
      url: expr('https://api.twitter.com/2/users/by/username/{{ $("Parse Twitter URL").item.json.handle }}'),
      options: { response: { response: { responseFormat: 'json' } } },
    },
    credentials: { httpHeaderAuth: newCredential('X API') },
    position: [1340, 300],
  },
  output: [{ data: { id: '12345' } }],
});

const fetchTweetsWithImages = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: {
    name: 'Fetch Tweets with Images',
    parameters: {
      mode: 'runOnceForEachItem',
      jsCode: 'const xUserId = $("Resolve X User ID").first().json.data.id;\nconst wpUserArr = $("Resolve WP User").first().json;\nconst wpUserId = wpUserArr[0].id;\nconst nickname = $("Lookup Artist").first().json.Wordpress_Nickname;\nconst handle = $("Parse Twitter URL").first().json.handle;\nconst targetTweetId = $("Parse Twitter URL").first().json.tweet_id;\n\nconst bearer = $env.X_BEARER_TOKEN;\nif (!bearer) throw new Error("X_BEARER_TOKEN env var is not set");\n\nconst mediaByKey = {};\nlet paginationToken = null;\nlet foundTarget = false;\nconst MAX_PAGES = 10;\nlet pages = 0;\nconst allTweets = [];\n\nwhile (pages < MAX_PAGES && !foundTarget) {\n  pages++;\n  const params = new URLSearchParams({\n    max_results: "100",\n    "tweet.fields": "created_at,attachments",\n    expansions: "attachments.media_keys",\n    "media.fields": "media_key,type,url,preview_image_url",\n  });\n  if (paginationToken) params.set("pagination_token", paginationToken);\n\n  const page = await this.helpers.httpRequest({\n    method: "GET",\n    url: "https://api.twitter.com/2/users/" + xUserId + "/tweets?" + params.toString(),\n    headers: { Authorization: "Bearer " + bearer },\n    json: true,\n  });\n\n  const tweets = page.data || [];\n  const media = (page.includes && page.includes.media) || [];\n  for (const m of media) mediaByKey[m.media_key] = m;\n\n  for (const t of tweets) {\n    allTweets.push(t);\n    if (String(t.id) === String(targetTweetId)) foundTarget = true;\n  }\n\n  if (foundTarget) break;\n  if (!page.meta || !page.meta.next_token) break;\n  paginationToken = page.meta.next_token;\n}\n\nif (!foundTarget) throw new Error("Tweet " + targetTweetId + " not found within " + MAX_PAGES + " pages");\n\nconst items = [];\nfor (const tweet of allTweets) {\n  const keys = tweet.attachments && tweet.attachments.media_keys;\n  if (!keys) continue;\n  const urls = [];\n  for (const k of keys) {\n    const m = mediaByKey[k];\n    if (m && m.type === "photo" && m.url) urls.push(m.url);\n  }\n  if (urls.length === 0) continue;\n  items.push({\n    json: {\n      tweet_id: tweet.id,\n      text: tweet.text || "",\n      created_at: tweet.created_at,\n      image_urls: urls,\n      source_url: "https://x.com/" + handle + "/status/" + tweet.id,\n      Wordpress_Nickname: nickname,\n      wp_user_id: wpUserId,\n    },\n  });\n}\n\nif (items.length === 0) throw new Error("No image-bearing tweets in window");\nreturn items;',
    },
    position: [1540, 300],
  },
  output: [{ tweet_id: '1234567890', text: 'hi', created_at: '2025-01-01T00:00:00Z', image_urls: ['https://pbs.twimg.com/media/example.jpg'], source_url: 'https://x.com/handle/status/1234567890', Wordpress_Nickname: 'Artist Name', wp_user_id: 1 }],
});

const perTweetSib = splitInBatches({
  version: 3,
  config: {
    name: 'Per Tweet',
    parameters: { batchSize: 1 },
    position: [1740, 300],
  },
  output: [{ tweet_id: '1234567890', text: 'hi', created_at: '2025-01-01T00:00:00Z', image_urls: ['https://pbs.twimg.com/media/example.jpg'], source_url: 'https://x.com/handle/status/1234567890', Wordpress_Nickname: 'Artist Name', wp_user_id: 1 }],
});

const processTweet = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: {
    name: 'Process Tweet',
    parameters: {
      mode: 'runOnceForEachItem',
      jsCode: 'const item = $input.first().json;\nconst tweetId = item.tweet_id;\nconst text = item.text || "";\nconst createdAt = item.created_at;\nconst imageUrls = item.image_urls;\nconst sourceUrl = item.source_url;\nconst wpUserId = item.wp_user_id;\nconst nickname = item.Wordpress_Nickname;\n\nconst wpUser = $env.WP_USER;\nconst wpAppPassword = $env.WP_APP_PASSWORD;\nif (!wpUser || !wpAppPassword) throw new Error("WP_USER and WP_APP_PASSWORD env vars are required");\nconst auth = Buffer.from(wpUser + ":" + wpAppPassword).toString("base64");\nconst wpHeaders = { Authorization: "Basic " + auth };\n\nconst uploaded = [];\nfor (const imageUrl of imageUrls) {\n  const img = await this.helpers.httpRequest({ method: "GET", url: imageUrl, encoding: "arraybuffer", json: false });\n  const buf = Buffer.from(img.body || img);\n  const filename = imageUrl.split("/").pop().split("?")[0] || "image.jpg";\n  const media = await this.helpers.httpRequest({\n    method: "POST",\n    url: "https://shad-base.com/wp-json/wp/v2/media",\n    headers: { ...wpHeaders, "Content-Disposition": "attachment; filename=\\"" + filename + "\\"", "Content-Type": "image/jpeg" },\n    body: buf,\n    json: true,\n  });\n  uploaded.push({ id: media.id, source_url: media.source_url });\n}\n\nconst created = new Date(createdAt);\nconst title = "Comic by " + nickname + " - " + created.toLocaleString("en-US", { month: "short", day: "numeric", year: "numeric", timeZone: "UTC" });\n\nconst comic = await this.helpers.httpRequest({\n  method: "POST",\n  url: "https://shad-base.com/wp-json/wp/v2/comic",\n  headers: { ...wpHeaders, "Content-Type": "application/json" },\n  body: { title: title, status: "draft", date: created.toISOString(), author: wpUserId, featured_media: uploaded[0] && uploaded[0].id },\n  json: true,\n});\n\nconst altText = text.replace(/\\s+/g, " ").trim().slice(0, 200) || nickname;\nfunction escapeAttr(s) { return String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }\nconst imgs = uploaded.map(function (m) { return "<img src=\\"" + m.source_url + "\\" alt=\\"" + escapeAttr(altText) + "\\" class=\\"alignnone\\" />"; });\nconst ceoHtml = imgs.join("\\n");\n\nawait this.helpers.httpRequest({\n  method: "POST",\n  url: "https://shad-base.com/wp-json/comic-easel/v1/comics/" + comic.id + "/meta",\n  headers: { ...wpHeaders, "Content-Type": "application/json" },\n  body: { source_tweet_id: String(tweetId), source_url: sourceUrl, ceo_html_below_comic: ceoHtml },\n  json: true,\n});\n\nreturn [{ json: { tweet_id: tweetId, comic_id: comic.id, image_count: uploaded.length, source_url: sourceUrl } }];',
    },
    position: [1940, 400],
  },
  output: [{ tweet_id: '1234567890', comic_id: 999, image_count: 4, source_url: 'https://x.com/handle/status/1234567890' }],
});

const respondToWebhook = node({
  type: 'n8n-nodes-base.respondToWebhook',
  version: 1.1,
  config: {
    name: 'Respond to Webhook',
    parameters: {
      respondWith: 'json',
      responseBody: expr('{"created": {{ JSON.stringify($("Process Tweet").all().map(i => i.json)) }}}'),
    },
    position: [1940, 200],
  },
  output: [{ created: [{ tweet_id: '1234567890', comic_id: 999, image_count: 4, source_url: 'https://x.com/handle/status/1234567890' }] }],
});

export default workflow('twitter-to-comic', 'Twitter URL to shad-base.com Comic')
  .add(webhookTrigger)
  .to(parseTwitterUrl)
  .to(lookupArtist)
  .to(resolveWpUser)
  .to(resolveXUserId)
  .to(fetchTweetsWithImages)
  .to(perTweetSib
    .onEachBatch(processTweet.to(nextBatch(perTweetSib)))
    .onDone(respondToWebhook)
  );
