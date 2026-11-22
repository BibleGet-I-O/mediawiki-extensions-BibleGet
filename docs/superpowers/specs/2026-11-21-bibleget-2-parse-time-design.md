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
  wiki's own credential.
- A new wiki works with no sign-up: the extension registers itself with BibleGet on first use (author's
  decision, 2026-11-22). A wiki that has a project key sets it and never registers.
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
- **At most 30 verses per tag** (author's rule): the number of tags on a wiki is unlimited, but one tag
  never shows more than 30 verses, whatever the edition. The API enforces 30 only for copyrighted editions:
  tested 2026-11-22 with the SeminaVerbi key on the dev address, Mt 5 (48 verses) returned 30 verses in
  NABRE, CEI2008, BLPD and NVBSE, with no error or notice of the cut, and all 48 in DRB and VGCL. So the
  extension enforces the limit itself.
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

- `$wgBibleGetApiBase`: the API's base address, default `https://query.bibleget.io/v4/`; the extension
  calls `quote` and `installations` under it. SeminaVerbi sets `https://query.bibleget.io/dev/` until v4
  is released.
- `$wgBibleGetApiKey`: optional project key (`bibleget_live_…`), default null. When set, it is the only
  credential used and the extension never registers. LocalSettings may `require` it from a file outside
  the web root; SeminaVerbi's file returns `['url'=>…, 'key'=>…]`, so both settings are set from it.
- `$wgBibleGetClientKey`: the client key (`bgclient_…`) used only to register. Its default is the key
  bundled in that release's `extension.json`; an operator overrides it only to test against a
  development endpoint with a `_test_` client key.
- `$wgBibleGetContactEmail`: optional, default empty. When set, it is sent to BibleGet so the operator can
  be told about breaking changes (below). Never filled from `$wgEmergencyContact` or any other setting.
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
- **Verse limit.** `$wgBibleGetMaxVersesPerQuote` (default 30). When an answer holds more verses than the
  limit (a public-domain edition), only the first 30 are shown. When a copyrighted edition's answer stops at
  exactly 30 while the reference asks for more, the API has cut it. In both cases the quote ends with a note
  in the wiki's language ("only the first 30 verses are shown"), and the page joins the tracking category
  `bibleget-toolong-category`, so editors split the reference into several tags. The limit counts verses
  in the answer, not in the reference (the extension does not re-implement the citation grammar). Whether
  "more was asked" is known from the answer alone is left to the builder.
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

### Credentials and registration

The extension follows BibleGet's own credential design (endpoint
`docs/superpowers/specs/2026-11-17-api-keys-installations-design.md`, "Installation registration"), the
same model as the WordPress plugin:

- **Which credential a request uses:** `$wgBibleGetApiKey` if set; else the stored installation token;
  else the extension registers first. The credential goes in the `X-Api-Key` header only.
- **Registering:** `POST {base}installations` with `X-Api-Key: <client key>` and JSON
  `{ "install_id": "<uuid>", "plugin_version": "<extension version>", "site_url": "$wgCanonicalServer" }`.
  `201` or `200` returns `{ "token": "bginst_…", "installation_id": "…" }`. The `install_id` is a UUID the
  extension generates once and keeps; the `token` is stored with it.
- **Storage:** a one-row table, `bibleget_installation` (`install_id`, `token`, `installation_id`,
  `registered_at`), created by the `LoadExtensionSchemaUpdates` hook (`update.php`). One wiki database,
  one installation: each SeminaVerbi language wiki would register on its own, but SeminaVerbi uses the
  project key instead. The token is secret: nothing exposes the table (no API, no special page, no log).
- **When the token stops working** (`401` on a data route): register again once with the same
  `install_id` (the API issues a new token and keeps the count), store it, retry the quote once. A second
  `401` is a failure (fallback, short expiry).
- **One registration at a time:** parallel parses take a lock (database or object-cache lock) before
  registering, and re-read the table after getting it, so two parses never register in turn and invalidate
  each other's tokens.
- **Registration refused:** `429` (the client key's hourly cap; `Retry-After`) or `401` (the client key
  was revoked) is a failure like a failed quote: fallback, short expiry, and no new registration attempt
  before `Retry-After` (or an hour) has passed, remembered in the object cache. With a revoked client
  key, the fix is a newer release or a project key; the failure reason says so.
- The 30-verse limit is the same with an installation token or a project key: only BibleGet's own reader
  key goes past it.

### Reaching the wiki's operators (author's decision, 2026-11-22)

A registered wiki has no developer account, so BibleGet has no address for it. Two routes, both in this
release:

- **Opt-in email.** With `$wgBibleGetContactEmail` set, the extension sends it with
  `PATCH {base}installations/self` (installation token; `contact_email`, and `plugin_version`) after
  registering, and again when the setting or the extension version changes (the last values sent are kept
  in `bibleget_installation`). Emptying the setting sends `""`, which removes the address. With a project
  key the extension sends nothing: the project's owner is already known.
- **Notices in the wiki.** BibleGet adds service notices to its answers (endpoint work, requested
  2026-11-22; the shape is the endpoint's to decide): standard `Deprecation` / `Sunset` headers and a
  service-notice entry beside the per-quote `notices`. The extension:
  - keeps the notices from the latest answer (a small store, e.g. the installation table or a
    `bibleget_notice` table: id, text, link, date first seen), so they survive cache expiry;
  - sends an Echo notification once per new notice to the users with the right `bibleget-manage`
    (granted to `sysop` by default), when Echo is installed (it is on Cathopedia and SeminaVerbi);
  - without Echo, shows those users a dismissible banner until they dismiss it;
  - lists them on `Special:BibleGet` (restricted to `bibleget-manage`), which also shows whether the wiki
    uses a project key or a registration (never the key or token itself), the last failure and its date,
    and the verse and rate limits in force.
  - Limit: notices arrive only with answers, so a wiki that fetches nothing new hears nothing until it
    does; BibleGet announces breaking changes well ahead, and by email to those who opted in.
- Notice text from the API is escaped, and a link is shown only if it is `https:`.
- Until the endpoint ships service notices, the extension works without them (no notices to show).

### Front end

- A ResourceLoader module with the popup script and stylesheet, loaded only on pages that have a quote.
  It replaces `MediaWiki:BibleGet.js` and the Bible-quote rules in SeminaVerbi's `MediaWiki:Global.css`.
- No fetching from the browser at all.

### Messages

- English source and the five SeminaVerbi languages (it, es, fr, de, pt) for the description, the error
  reasons, the tracking category and the popup's close button; magic words kept for all of them.

### Rollout on SeminaVerbi (after the extension is released)

0. Before the release, the BibleGet admin mints a client key for the MediaWiki extension
   (`POST /admin/client-keys`) and it goes into `extension.json` for that release.
1. Install 2.0 on SeminaVerbi; set `$wgBibleGetApiBase` and `$wgBibleGetApiKey` from the existing key
   file, so SeminaVerbi does not register. Run `update.php` (the table is created even if unused).
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
- The verse limit: a 48-verse public-domain answer shows 30 verses, the note and the category; a 30-verse
  answer to a 30-verse reference shows no note.
- The rate limit: an anonymous parse over the limit renders the fallback and makes no request.
- Registration, with a fake HTTP factory: first fetch with no token registers, stores the token and
  quotes; a set `$wgBibleGetApiKey` never registers; a `401` re-registers once with the same `install_id`
  and retries; a second `401`, a `429` and a revoked client key give the fallback and no further attempt
  before the wait; two parses at once register only once.
- Operators: a set contact email is sent once after registering, again when it changes, `""` when
  emptied, never with a project key; a new service notice gives one Echo notification per manager and is
  not repeated on the next answer; without Echo the banner shows to managers only; `Special:BibleGet`
  refuses users without `bibleget-manage`; a notice with a `javascript:` link shows no link.
- A check that the key or token never appears in rendered output or exception messages.

## Left to the builder

- The Echo event type, banner dismissal storage and `Special:BibleGet` layout.

- Class names, element structure and CSS (for example `<details>`-style or focusable markup for the
  no-JavaScript case), as long as the reader experience above holds; `span.bibleQuoteRef` is not required.
- The default rate-limit numbers and `$wgBibleGetMaxQuotesPerPage`.
- Cache key format, sanitiser implementation, the exact fallback markup.
- How the version list is obtained and cached, if one is needed.
- The order of work and how to split it into pull requests.
- Whether to send `query_lang` once BibleGet supports it (a later release; not required now).
