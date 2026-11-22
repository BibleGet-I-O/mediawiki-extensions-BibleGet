# Changelog

All notable changes to this extension are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses semantic versioning.

## [2.0.0]

### Added

- Quotes are fetched from the BibleGet v4 API at parse time and kept in the parser cache and the object
  cache (`$wgBibleGetCacheExpiry`). Readers send no requests.
- Automatic registration with BibleGet (`$wgBibleGetClientKey`), unless a project key is set in
  `$wgBibleGetApiKey`. The token is stored in the new `bibleget_installation` table.
- Limit of 30 verses per quote (`$wgBibleGetMaxVersesPerQuote`) with a note when a quote is cut, and a limit
  of 50 quotes per page (`$wgBibleGetMaxQuotesPerPage`).
- The `bibleget-fetch` rate limit for uncached fetches.
- Tracking categories for failed quotes and for quotes cut to the verse limit.
- Service notices from BibleGet for users with the new `bibleget-manage` right (sysops by default): Echo
  notifications, or a dismissible banner without Echo. Stored in the new `bibleget_notice` table.
- `Special:BibleGet`, a status page for managers.
- Popup script and styles for inline quotes, shipped with the extension.
- Translations into Italian, Spanish, French, German and Portuguese.
- A Docker test wiki in `dev/`, PHPUnit tests and parser tests.
- `{{#biblequote:}}` accepts `inline` and `block` as the display argument.

### Changed

- Requires MediaWiki 1.43 or later and PHP 8.1 or later. ext-curl is no longer required.
- The API base URL is `https://query.bibleget.io/v4/`.
- The extension sanitises the API's HTML with an allow-list before output.

### Removed

- The 1.0 file cache in `bibleQuotes/`.
- The unescaped insertion of API output.

### Breaking changes

- A clean break from 1.x: delete the `bibleQuotes/` directory, run `update.php` to create the new tables,
  and purge pages that use quotes.
- MediaWiki below 1.43 and PHP below 8.1 are no longer supported.
- Quotes longer than 30 verses are cut; pages with more than 50 quotes show the fallback for the rest.
- Quotes are no longer cached forever: they expire after `$wgBibleGetCacheExpiry`.
- The wiki must be able to reach `query.bibleget.io` from the server; `$wgBibleGetClientKey` or
  `$wgBibleGetApiKey` is required for fetching.
