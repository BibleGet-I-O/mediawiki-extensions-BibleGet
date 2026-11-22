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
   `bibleget_installation` and `bibleget_notice`. Until you do, a wiki without a project key shows every quote
   as the fallback, with the reason "BibleGet is not set up yet: run update.php", and `Special:BibleGet`
   tells you the tables are missing. Pages still render.

## Usage

```
<biblequote ref="John 3,16" version="NABRE" inline="true">Jn 3,16</biblequote>
<biblequote>Jn 3,16</biblequote>
{{#biblequote:Jn 3,16|inline|NABRE}}
```

- The tag takes the reference from `ref`, or from its body when there is no `ref`. With `ref`, a
  non-empty body is the text readers see, as the inline reference and in the fallback (for example the
  wiki's own abbreviation, while `ref` holds a form BibleGet knows); it is literal text, like the
  reference. `version` is optional and falls back to `$wgBibleGetDefaultBibleVersion`. `inline="true"`
  shows the reference as text with the quote in a popup; otherwise the quote is a block.
- The parser function takes `reference|display|version`. `display` is `inline` or `block`; `true` and
  `false`, as 1.0 accepted, still work.
- A quote that cannot be fetched shows the reference (or the body given with `ref`) as plain text,
  followed by a `[!]` marker whose tooltip gives the reason, and the page is put in a tracking category.
- A tag or function with no reference shows an error ("No Bible reference was given").

### Source line

Every quote ends with a line naming the edition and where the text comes from, after an em dash as in a
citation:

> — New American Bible - Revised Edition (2011) · Source: BibleGet

- **Edition:** the full name and year come from BibleGet's answer. Until BibleGet has given them for an
  edition (for a quote cached by an older release), its abbreviation (`NABRE`) stands in.
- **The link:** "BibleGet" links to `Special:BibleGet` on the wiki itself, not to an outside site. That
  page, which every reader can open, says where the quotes come from (see below). Nothing has to be set up.
- **Changing it:** a wiki with its own page on the subject can link there instead, through the message
  `MediaWiki:Bibleget-source-page` (for example `Project:BibleGet`); `-` means no link. A wiki page is
  recorded as a link of every page with a quote, so creating or moving it re-renders those pages; the
  default special page is not. The line itself is `MediaWiki:Bibleget-source`.

## Settings

Set these in `LocalSettings.php`, after `wfLoadExtension`.

| Setting | Default | Meaning |
|---|---|---|
| `$wgBibleGetApiBase` | `https://query.bibleget.io/v4/` | Base URL of the BibleGet API. |
| `$wgBibleGetApiKey` | `null` | A BibleGet project key. When set, the wiki does not register. |
| `$wgBibleGetClientKey` | `""` | Client key bundled with the extension, used only to register. |
| `$wgBibleGetDefaultBibleVersion` | `NABRE` | Version used when a quote names none. |
| `$wgBibleGetCacheExpiry` | `2592000` (30 days) | Seconds a fetched quote stays in the object cache, give or take 15% per quote. |
| `$wgBibleGetMaxQuotesPerPage` | `50` | Quotes rendered on one page; later ones show the fallback. |
| `$wgBibleGetMaxVersesPerQuote` | `30` | Verses rendered for one quote. |

`BibleGetClientKey` ships as a placeholder (empty) until the BibleGet administrator mints the client key
for this release. Until then, registration cannot work: set `$wgBibleGetApiKey` instead.

## Registration or a project key

- **Registration (default).** With no `$wgBibleGetApiKey`, the wiki registers itself with BibleGet the first
  time it needs to fetch a quote, using the client key. BibleGet returns a token, which is kept in the
  `bibleget_installation` table. If BibleGet rejects the token, the wiki registers again and retries once.
  Registration sends the site URL (`$wgCanonicalServer`), a random installation id and the extension
  version; no email address or other personal data is sent.
- **Only public https wikis register.** Registration counts real installations, so a wiki whose
  `$wgCanonicalServer` is not a public https address never registers and never sends its address: an
  `http://` address, `localhost`, a private or reserved IP address, a name without a dot, or a name under
  a non-public domain (`.local`, `.lan`, `.internal`, `.test`, `.home.arpa` and similar). Quotes on such a
  wiki (a test or intranet wiki) show the fallback until you request a project key on the BibleGet
  website and set `$wgBibleGetApiKey`; `Special:BibleGet` says so.
- **When registration fails.** A refusal with HTTP 429 waits for the `Retry-After` time (an hour when
  there is none). A revoked client key (HTTP 401) waits an hour; update the extension or set a project
  key. Other failures, such as a network error, are retried after a short pause (a few minutes).
- **Project key.** If you have a BibleGet project key, set `$wgBibleGetApiKey`. It is used as is and the
  wiki never registers.

## Limits

- **30 verses per quote.** A longer quote is cut after `$wgBibleGetMaxVersesPerQuote` verses, with a note
  saying how many are shown, and the page goes in the "cut to the verse limit" tracking category.
- **Rate limit.** Each uncached fetch counts against the `bibleget-fetch` rate limit (MediaWiki's
  `$wgRateLimits`). The defaults, in `extension.json`, are 30 per 60 seconds per IP address (`ip`) and for
  newbies, and 120 per 60 seconds for users. Quotes already cached never count. Over the limit, the quote
  shows the fallback, and that page is not stored in the parser cache, so the next view fetches it. There
  is deliberately no `anon` default: MediaWiki counts `anon` once for all anonymous users of the wiki, and
  web requests also run queued jobs (`$wgJobRunRate`) as an anonymous user, so after a template edit the
  reparses of every page would share 30 fetches a minute. Override the limits as usual:

  ```php
  $wgRateLimits['bibleget-fetch']['ip'] = [ 10, 60 ];
  ```

  The limit applies to web and API requests, and so to the user whose view or edit reparses a page:
  when a page holds more uncached quotes than that user's limit (30 for anonymous readers by default),
  the quotes over the limit show the fallback until a later parse. Raise the limit if your pages hold
  many quotes, for example `$wgRateLimits['bibleget-fetch']['ip'] = [ 100, 60 ];`.
  Maintenance scripts are not limited: `runJobs.php` reparses (after a template edit, for example) run
  as one user for the whole process, so the limit would otherwise turn their quotes into fallbacks.

- **BibleGet's own limit.** BibleGet limits each registered wiki (300 requests an hour by default). When
  it answers HTTP 429, the wiki fetches no quote at all until its `Retry-After` time has passed (a minute
  when there is none, an hour at most); quotes already cached still render.
- **Quotes per page.** `$wgBibleGetMaxQuotesPerPage` (50).
- **Cut by the API.** For copyrighted editions BibleGet itself returns at most 30 verses and says so in
  its answer. Such a quote gets the same note and tracking category as one cut by the wiki.
- A failure is remembered briefly (minutes) so a broken reference or a service outage does not make every
  parse send a new request, and the page is re-parsed after about an hour.

## Tracking categories

- "Pages with failed Bible quotes": the quote could not be fetched, or the reference or version was
  not recognised.
- "Pages with Bible quotes cut to the verse limit": a quote was cut at the verse limit, by the wiki or
  by BibleGet.

The category names are messages (`bibleget-tracking-category`, `bibleget-toolong-category`) and can be
changed on the wiki.

## Service notices

BibleGet can send notices (a deprecation, a planned shutdown) in its answers. They are stored in
`bibleget_notice` and shown once to users with the `bibleget-manage` right, which sysops have by
default. If Echo is installed, managers get an Echo notification; otherwise a dismissible banner appears
on pages they view. Dismissals are kept in the `bibleget-dismissed` user option.

## Special:BibleGet

Every quote's source line links here. For every reader it says where the wiki's Bible quotes come from:
BibleGet, a project of the Catholic Digital Commons Foundation, and that the copyrighted editions are
provided under agreements between BibleGet and their copyright holders, with a link to the copyright
notices on the BibleGet website (`MediaWiki:Bibleget-copyright-url`). It lists the editions the wiki has
quoted (each quote rendered adds its edition, cached or not), with their full name, year and copyright
holder as BibleGet gives them. For an edition no answer has named yet, the wiki asks BibleGet for its list
of editions, at most once an hour for the whole farm.

Managers (`bibleget-manage`) also see the status: whether the wiki uses a project key or is registered, the
verse and quote limits, the rate limits, the last failure, and the service notices received. It never shows
the key or the token.

## Upgrading from 1.0

2.0 is a clean break:

1. Update the extension.
2. Delete the `bibleQuotes/` directory 1.0 used to cache quotes. It is no longer read.
3. Run `php maintenance/run.php update` (`update.php`).
4. Purge pages that use quotes (or wait for the parser cache to expire).

Changes that may affect you: the MediaWiki minimum is 1.43 and the PHP minimum 8.1; ext-curl is no longer
needed; quotes come from the v4 API; the `ref`, `version` and `inline` attributes and the
`{{#biblequote:}}` arguments are unchanged. `$wgBibleGetDefaultBibleVersion` keeps its meaning.

- `update.php` is required. Until it runs, quotes show the fallback with the reason "BibleGet is not set
  up yet: run update.php" (on a wiki without a project key).
- A tag or function with no reference now shows an error instead of quoting John 3:16.

See `CHANGELOG.md`.

## Development

`dev/` holds a Docker test wiki (see `dev/README.md`). Run `dev/test.sh lint`, `dev/test.sh phpunit` and
`dev/test.sh parser`.

## License

Apache-2.0 (see `LICENSE`).
