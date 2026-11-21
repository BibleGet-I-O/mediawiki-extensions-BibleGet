# BibleGet 2.0: Bible quotes rendered at parse time

## Intent

Today the SeminaVerbi wikis show Bible quotes through three pieces that live in three places:

- `{{SV Bible quote}}` (Lua, in the seminaverbi-templates repository) writes an empty `bibleQuoteRef`
  element with `data-ref`, `data-version` and `data-inline`;
- `MediaWiki:BibleGet.js`, a copy on each wiki, finds those elements and calls `w/bgetHelper.php` from
  the reader's browser, then shows the quote in a block or a hover popup;
- `bgetHelper.php` exists only on the server (in no repository). It calls the BibleGet v4 API with the
  wikis' key and caches each answer forever in `w/bibleQuotes/{md5}.html`.

The helper is an open proxy for the key: anyone can send it any reference, spending the key's quota and
filling the disk. It also pastes the reference into the POST body unencoded. Meanwhile the BibleGet
extension that is loaded (1.0.0, this repository) still calls the v3 address with no key. It writes
reference text into HTML unescaped, and it caches into a directory relative to the web root.

BibleGet 2.0 replaces all of it with one extension that any MediaWiki wiki can install. Cathopedia
(it.cathopedia.org, MediaWiki 1.43.12, Scribunto, no Bible-quote tool today) is the second intended user.
The quote is fetched on the server when the page is parsed and goes into the page HTML. It is kept by the
parser cache, so readers send no requests and the key never leaves the server.

## Goals

- A tagged reference renders as the quote's text in the page HTML, fetched from BibleGet v4 with the
  wiki's own key.
- It works on any wiki with no Lua, templates or site JavaScript: `<biblequote>` and `{{#biblequote:}}`
  are enough.
- It keeps today's reader experience on SeminaVerbi: a block quote in the text, or an inline reference
  with a hover/tap popup (the placement rules now in `MediaWiki:BibleGet.js`: beside the reference on wide
  screens, below it on narrow ones, sticky with a close button when taller than the screen, a tap outside
  closes it).
- A reference that cannot be quoted is visible: it shows as the plain reference with a marker, and the
  page joins a tracking category.
- `{{SV Bible quote}}` keeps its parameters; no article changes.

## Non-goals

- **No detection of untagged references.** Only references an editor marks are quoted (author's decision).
  Plain "Mt 5,3" text is never turned into a quote.
- No reader-side choice of Bible version.
- No translation or renumbering of references; the API resolves them.
- No upgrade path from 1.0: a clean break. `requires` becomes MediaWiki >= 1.43; the old `bibleQuotes/`
  directory is ignored (deleted by hand on SeminaVerbi).
- No translations beyond the six languages (others arrive through translatewiki-style contributions).
- No Lua library in this release. Scribunto modules call the tag with `frame:extensionTag`.

## Anti-goals (would make it a failure even if it works)

- Any route by which a reader's request makes the server call BibleGet with an arbitrary reference (the
  current helper's flaw). Only parsing a saved page or an editor's preview may fetch.
- The key appearing in page HTML, JavaScript config, API output, logs or error messages.
- Reference or API text reaching the page unescaped or unsanitised.
- A slow or down BibleGet making page saves fail or hang beyond a short timeout.

## Constraints

- MediaWiki 1.43 or later (Cathopedia 1.43.12; SeminaVerbi 1.44). PHP as required by 1.43.
- License stays Apache-2.0; the repository stays `BibleGet-I-O/mediawiki-extensions-BibleGet`, version 2.0.0.
- HTTP through MediaWiki's `HttpRequestFactory` (timeouts, proxy settings), not raw curl.
- Follows MediaWiki coding conventions (the repository's phpcs, phan, eslint, stylelint, banana).
- The BibleGet v4 API: POST `query`, `version`, `return=html`; header `X-Api-Key`. The URL is
  configurable (SeminaVerbi uses the dev address `https://query.bibleget.io/dev/quote` until v4 is
  released). v4 has no `appid` parameter (endpoint `src/`), so none is sent.
- What v4 returns (endpoint `src/Handlers/QuoteHandler.php`, `buildHtmlResponse`): `div.results.bibleQuote`,
  then `div.errors.bibleQuote` (a table of `td.errNum` / `errMessageVal` rows), then `div.info.bibleQuote`
  with hidden `<input>`s, one of them `bibleVersionsInfo` (JSON). A bad reference or an unknown version is
  an HTTP 200 with rows in the errors table. Real non-200 answers: authentication (401/403) and 429 with
  `Retry-After`. Poetic lines and small caps already come as real HTML (`FormattedText::toHtml`).
- Hebrew psalm numbering for NVBSE and other quirks are the API's concern, not the extension's.

## Approaches considered

1. **Parse time (chosen by the author).** The parser hook fetches and renders the quote; results go in
   MediaWiki's object cache and the parser cache. Readers make no requests. Works without JavaScript, in
   search snippets and print. A changed translation shows only after the page is re-parsed.
2. **Browser fetch through a proper API module.** Keeps today's lazy loading behind an `action=` module
   with rate limits and a size-limited cache. Closer to today's code. Still costs a request per reader and
   keeps a public route to the key's quota.
3. **Patch the current helper** (encode input, check referer, cap cache). Smallest change. Still lives
   outside any repository and cannot be installed by another wiki.

## Design (decided)

### Syntax (kept from 1.0)

- `<biblequote ref="Mt 5,3-12" version="NVBSE" inline="true" />`, or `<biblequote>Mt 5,3-12</biblequote>`
  (the content is the reference when `ref` is absent).
- `{{#biblequote:Mt 5,3-12|inline|NVBSE}}`: reference, then display mode, then version. The display
  argument accepts what 1.0 accepted for `inline` (true/false), plus `inline`/`block`.
- No reference gives an error message (`bibleget-error-noref`), not 1.0's silent `John3:16` default.

### Configuration

- `$wgBibleGetApiUrl`: the quote endpoint.
- `$wgBibleGetApiKey`: the key. LocalSettings may `require` it from a file outside the web root.
  SeminaVerbi's file returns `['url'=>…, 'key'=>…]`, so the two variables are set from it.
- `$wgBibleGetDefaultBibleVersion`: as in 1.0.
- `$wgBibleGetCacheExpiry`: object-cache lifetime of a fetched quote (default 30 days).
- `$wgBibleGetMaxQuotesPerPage`: fetches allowed in one parse (default 50); further tags render as the
  fallback and the page is tracked.

### Rendering

- Block: the quote HTML from the API in a `div.bibleQuote` wrapper (class names may change from today's,
  but the stylesheet ships with the extension).
- Inline: `span.bibleQuoteRef` with the reference text as the visible link-like element, plus the quote in
  a hidden popup element that the extension's script positions. Without JavaScript, the reference stays
  readable and the quote is reachable (e.g. revealed on focus or printed), never lost.
- Only the `div.results` part of the API's HTML goes on the page, through a sanitiser allowing only the
  elements and classes BibleGet produces; the info inputs and the errors table never reach the page. The
  1.0 `&lt;sm&gt;`… conversions are dropped unless the dev endpoint's real answers still need them (the
  builder checks against captured answers, below).
- The reference text an editor wrote is always escaped.
- Each quote is cached in the main object cache (WANObjectCache), keyed by API URL + version + reference
  with whitespace removed (the helper's normalisation), so switching from the dev address to production
  does not serve old answers.
- Failure (network error, timeout, a non-200 status, rows in the errors table, or an empty `div.results`): the plain escaped reference with a `[!]` marker whose tooltip gives the reason in the wiki's
  language, the tracking category `bibleget-tracking-category` ("Pages with failed Bible quotes"), and a
  shortened parser cache expiry (about an hour) so the page retries. Failures are not stored in the
  object cache longer than that.
- An unknown version is detected from the API's answer (its errors table), not from 1.0's hard-coded six
  versions. A 429 is a failure like the others (short expiry, retried on a later parse); no queue.
- **Who can make it fetch.** Any parse may fetch: a save, a preview, a reader's view after the parser cache
  expired or the page was purged, and the API's `action=parse`. Saved pages only hold references editors
  wrote; the public routes that take arbitrary text (preview, `action=parse`, ExpandTemplates) are bounded
  by the per-parse limit and by a MediaWiki rate limit, `$wgRateLimits['bibleget-fetch']`, checked with the
  user's `pingLimiter` before each uncached fetch (defaults for anonymous users and IPs set in
  `extension.json`). Cached quotes never count against it. Over the limit, the tag renders the fallback.

### Front end

- A ResourceLoader module with the popup script and stylesheet, loaded only on pages that have a quote.
  It replaces `MediaWiki:BibleGet.js` and the Bible-quote rules in SeminaVerbi's `MediaWiki:Global.css`.
- No fetching from the browser at all.

### Messages

- English source and the five SeminaVerbi languages (it, es, fr, de, pt) for the description, the error
  reasons, the tracking category and the popup's close button; magic words kept for all of them.

### Rollout on SeminaVerbi (after the extension is released)

1. Install 2.0 on SeminaVerbi; set the new configuration from the existing key file.
2. Change `Module:SV/BibleQuote` (seminaverbi-templates PR) to emit `frame:extensionTag('biblequote', …)`
   with the mapped `query` reference, version and display mode. It keeps the display text, the
   Nova Vulgata note and the missing-reference error. Sync it.
3. The author removes `MediaWiki:BibleGet.js` and its loader, and the old Global.css rules, and deletes
   `bgetHelper.php` and `w/bibleQuotes/` on the server (the bot has no interface-admin rights).
   The author also converts the `<biblequote>` examples on the author's en and it user pages, which will
   keep working with 2.0 unchanged.
4. Purge the pages that use `{{SV Bible quote}}`, in small batches with a pause, so the key's rate limit is
   not hit (a 429 only delays a quote to a later parse).

### Tests

- PHPUnit with a fake HTTP factory: request fields and key header; encoding of a reference containing `&`
  and `=`; escaping of a reference containing `<script>`; sanitiser drops a `<script>` or `onclick`
  coming from the API; cache hit makes no request; each failure kind gives the fallback, the category and
  the short expiry; the per-page limit.
- Parser tests for the tag and the function in both modes.
- Fixtures: real answers captured once from the dev endpoint (a good quote, a poetic passage, a bad
  reference, an unknown version), with the info inputs kept, so the sanitiser and failure detection are
  tested against what the API really sends. Capturing them uses the SeminaVerbi key on the server only.
- The rate limit: an anonymous parse over the limit renders the fallback and makes no request.
- A check that the key never appears in rendered output or exception messages.

## Left to the builder

- Class names, element structure and CSS (for example `<details>`-style or focusable markup for the
  no-JavaScript case), as long as the reader experience above holds; `span.bibleQuoteRef` is not required.
- The default rate-limit numbers and `$wgBibleGetMaxQuotesPerPage`.
- Cache key format, sanitiser implementation, the exact fallback markup.
- How the version list is obtained and cached, if one is needed.
- The order of work and how to split it into pull requests.
- Whether to send `query_lang` once BibleGet supports it (a later release; not required now).
