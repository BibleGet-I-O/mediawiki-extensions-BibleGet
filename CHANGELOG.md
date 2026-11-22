# Changelog

All notable changes to this extension are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses semantic versioning.

## [2.0.0]

### Added

- Quotes are fetched from the BibleGet v4 API at parse time and kept in the parser cache and the object
  cache (`$wgBibleGetCacheExpiry`). Readers send no requests.
- Automatic registration with BibleGet (`$wgBibleGetClientKey`), unless a project key is set in
  `$wgBibleGetApiKey`. The token is stored in the new `bibleget_installation` table. Only a wiki whose
  `$wgCanonicalServer` is a public https address registers; any other wiki (http, localhost, a private IP
  address, an intranet name) needs a project key.
- Limit of 30 verses per quote (`$wgBibleGetMaxVersesPerQuote`) with a note when a quote is cut, and a limit
  of 50 quotes per page (`$wgBibleGetMaxQuotesPerPage`). A quote the API itself cut (its `VERSES_CAPPED`
  notice) gets the same note and tracking category.
- The `bibleget-fetch` rate limit for uncached fetches in web and API requests. Maintenance scripts such
  as `runJobs.php` are not limited. The defaults are per IP address, per newbie and per user; there is no
  wiki-wide `anon` limit.
- Each cached quote expires at a random point within 15% of `$wgBibleGetCacheExpiry`, so quotes fetched
  together do not all expire together.
- After an HTTP 429 from BibleGet, no quote is fetched until its `Retry-After` time has passed.
- Until `update.php` has created the tables, quotes show the fallback with the reason "BibleGet is not
  set up yet" (`bibleget-error-setup`) and pages render normally.
- Tracking categories for failed quotes and for quotes cut to the verse limit.
- Service notices from BibleGet for users with the new `bibleget-manage` right (sysops by default): Echo
  notifications, or a dismissible banner without Echo. Stored in the new `bibleget_notice` table.
- `Special:BibleGet`: for every reader, where the quotes come from (BibleGet, the copyright agreements, the
  editions quoted); for managers, also the status.
- Popup script and styles for inline quotes, shipped with the extension.
- Translations into Italian, Spanish, French, German and Portuguese.
- A Docker test wiki in `dev/`, PHPUnit tests and parser tests.
- `{{#biblequote:}}` accepts `inline` and `block` as the display argument.
- A source line at the end of every quote: the edition's full name and year (from BibleGet's answer), and a
  link to `Special:BibleGet` (or the page in `MediaWiki:Bibleget-source-page`). It replaces the edition's
  abbreviation that 1.0 showed above the quote.
- With `ref`, the tag's body is the text readers see (the inline reference and the fallback), so a template
  can send BibleGet one form of a reference and show readers another.

### Changed

- Requires MediaWiki 1.43 or later and PHP 8.1 or later. ext-curl is no longer required.
- The API base URL is `https://query.bibleget.io/v4/`.
- The extension sanitises the API's HTML with an allow-list before output.

### Removed

- The 1.0 file cache in `bibleQuotes/`.
- The unescaped insertion of API output.

### Breaking changes

- A clean break from 1.x: delete the `bibleQuotes/` directory, run `update.php` to create the new tables
  (required: until it runs, quotes show the fallback), and purge pages that use quotes.
- A tag or function with no reference shows an error instead of quoting John 3:16.
- MediaWiki below 1.43 and PHP below 8.1 are no longer supported.
- Quotes longer than 30 verses are cut; pages with more than 50 quotes show the fallback for the rest.
- Quotes are no longer cached forever: they expire after `$wgBibleGetCacheExpiry`.
- The wiki must be able to reach `query.bibleget.io` from the server; `$wgBibleGetClientKey` or
  `$wgBibleGetApiKey` is required for fetching.
