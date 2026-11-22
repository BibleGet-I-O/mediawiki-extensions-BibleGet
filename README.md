# BibleGet

A MediaWiki extension that inserts Bible quotes from the [BibleGet](https://www.bibleget.io) service.
It adds a `<biblequote>` tag and a `{{#biblequote:}}` parser function.

Since 2.0 the quote is fetched while the page is parsed (from the BibleGet v4 API), sanitised, and stored
in the parser cache. Readers never send a request to BibleGet, and no key reaches the browser.

Requires MediaWiki 1.43 or later and PHP 8.1 or later. The cURL extension is not needed. Echo is optional.

## Installation

1. Put the extension in `extensions/BibleGet`.
2. Add to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'BibleGet' );
   ```

3. Create the database tables: run `php maintenance/run.php update` (`update.php`). The tables are
   `bibleget_installation` and `bibleget_notice`. Until you do, quotes are not fetched and
   `Special:BibleGet` tells you the tables are missing.

## Usage

```
<biblequote ref="Jn 3,16" version="NABRE" inline="true">fallback text</biblequote>
<biblequote>Jn 3,16</biblequote>
{{#biblequote:Jn 3,16|inline|NABRE}}
```

- The tag takes the reference from `ref`, or from its body when there is no `ref`. `version` is optional
  and falls back to `$wgBibleGetDefaultBibleVersion`. `inline="true"` shows the reference as text with the
  quote in a popup; otherwise the quote is a block.
- The parser function takes `reference|display|version`. `display` is `inline` or `block`; `true` and
  `false`, as 1.0 accepted, still work.
- A quote that cannot be fetched shows the reference as plain text with a short note, and the page is
  put in a tracking category.

## Settings

Set these in `LocalSettings.php`, after `wfLoadExtension`.

| Setting | Default | Meaning |
|---|---|---|
| `$wgBibleGetApiBase` | `https://query.bibleget.io/v4/` | Base URL of the BibleGet API. |
| `$wgBibleGetApiKey` | `null` | A BibleGet project key. When set, the wiki does not register. |
| `$wgBibleGetClientKey` | `""` | Client key bundled with the extension, used only to register. |
| `$wgBibleGetDefaultBibleVersion` | `NABRE` | Version used when a quote names none. |
| `$wgBibleGetCacheExpiry` | `2592000` (30 days) | Seconds a fetched quote stays in the object cache. |
| `$wgBibleGetMaxQuotesPerPage` | `50` | Quotes rendered on one page; later ones show the fallback. |
| `$wgBibleGetMaxVersesPerQuote` | `30` | Verses rendered for one quote. |

`BibleGetClientKey` ships as a placeholder (empty) until the BibleGet administrator mints the client key
for this release. Until then, registration cannot work: set `$wgBibleGetApiKey` instead.

## Registration or a project key

- **Registration (default).** With no `$wgBibleGetApiKey`, the wiki registers itself with BibleGet the first
  time it needs to fetch a quote, using the client key and a random installation id. BibleGet returns a
  token, which is kept in the `bibleget_installation` table. If BibleGet rejects the token, the wiki
  registers again and retries once. The site URL (`$wgCanonicalServer`) is sent; nothing else is.
  If registration is refused, the extension waits (the `Retry-After` time, or an hour) before trying again.
- **Project key.** If you have a BibleGet project key, set `$wgBibleGetApiKey`. It is used as is and the
  wiki never registers.

## Limits

- **30 verses per quote.** A longer quote is cut after `$wgBibleGetMaxVersesPerQuote` verses, with a note
  saying how many are shown, and the page goes in the "cut to the verse limit" tracking category.
- **Rate limit.** Each uncached fetch counts against the `bibleget-fetch` rate limit (MediaWiki's
  `$wgRateLimits`). The defaults, in `extension.json`, are 30 per 60 seconds for anonymous users, IP
  addresses and newbies, and 120 per 60 seconds for users. Quotes already cached never count. Over the
  limit, the quote shows the fallback. Override it as usual:

  ```php
  $wgRateLimits['bibleget-fetch']['anon'] = [ 10, 60 ];
  ```

- **Quotes per page.** `$wgBibleGetMaxQuotesPerPage` (50).
- A failure is remembered briefly (minutes) so a broken reference or a service outage does not make every
  parse send a new request, and the page is re-parsed after about an hour.

## Tracking categories

- "Pages with failed Bible quotes": the quote could not be fetched, or the reference or version was
  not recognised.
- "Pages with Bible quotes cut to the verse limit": a quote was cut at the verse limit.

The category names are messages (`bibleget-tracking-category`, `bibleget-toolong-category`) and can be
changed on the wiki.

## Service notices

BibleGet can send notices (a deprecation, a planned shutdown) in its answers. They are stored in
`bibleget_notice` and shown once to users with the `bibleget-manage` right, which sysops have by
default. If Echo is installed, managers get an Echo notification; otherwise a dismissible banner appears
on pages they view. Dismissals are kept in the `bibleget-dismissed` user option.

## Special:BibleGet

A status page for managers (`bibleget-manage`). It shows whether the wiki uses a project key or is
registered, the verse and quote limits, the rate limits, the last failure, and the service notices
received. It never shows the key or the token.

## Upgrading from 1.0

2.0 is a clean break:

1. Update the extension.
2. Delete the `bibleQuotes/` directory 1.0 used to cache quotes. It is no longer read.
3. Run `php maintenance/run.php update` (`update.php`).
4. Purge pages that use quotes (or wait for the parser cache to expire).

Changes that may affect you: the MediaWiki minimum is 1.43 and the PHP minimum 8.1; ext-curl is no longer
needed; quotes come from the v4 API; the `ref`, `version` and `inline` attributes and the
`{{#biblequote:}}` arguments are unchanged. `$wgBibleGetDefaultBibleVersion` keeps its meaning. See `CHANGELOG.md`.

## Development

`dev/` holds a Docker test wiki (see `dev/README.md`). Run `dev/test.sh lint`, `dev/test.sh phpunit` and
`dev/test.sh parser`.

## License

Apache-2.0 (see `LICENSE`).
