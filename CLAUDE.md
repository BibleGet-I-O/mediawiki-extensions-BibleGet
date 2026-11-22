# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BibleGet is a MediaWiki extension (2.0) that inserts Bible quotes from the BibleGet v4 API (https://query.bibleget.io/v4/). It registers a `<biblequote>` tag and a `{{#biblequote:}}` parser function. Quotes are fetched at **parse time**, sanitised and left in the parser cache; readers never call the API and no key reaches the browser.

Requires MediaWiki >= 1.43, PHP >= 8.1. No ext-curl (uses `HttpRequestFactory`). Echo is optional.

## Development Commands

The test wiki is Docker based (see `dev/README.md`); run from the repository root.

```bash
dev/setup.sh                  # start the wiki (http://localhost:8043), install it
dev/test.sh lint              # composer test (parallel-lint, phpcs, minus-x) and npm test (eslint, stylelint, banana)
dev/test.sh phpunit [path]    # PHPUnit in the wiki (tests/phpunit)
dev/test.sh parser [file]     # parser tests (tests/parser/bibleget.txt)
composer fix                  # auto-fix PHP style
grunt eslint --fix            # fix JavaScript style
```

## Architecture

Namespace `MediaWiki\Extension\BibleGet\`, in `src/`. Services are wired in `src/ServiceWiring.php` (`BibleGet.*`).

- `Hooks.php` - `ParserFirstCallInit` (tag and function), `BeforePageDisplay` (resource modules, notice banner for managers without Echo), `GetPreferences` (`bibleget-dismissed`).
- `QuoteRenderer` - validates arguments, enforces `BibleGetMaxQuotesPerPage`, builds block or inline (popup) HTML, adds tracking categories and the fallback on failure.
- `QuoteFetcher` - object-cache lookup (WANObjectCache), `pingLimiter` rate limit (`bibleget-fetch`), short failure caching and a fail-fast window while the API is unreachable, then asks the client; records service notices after the response.
- `CredentialProvider` - the credential to send: `BibleGetApiKey` (project key) or the token from registration, stored in `InstallationStore`; registers under a lock, honours `Retry-After`, re-registers when a token is rejected.
- `BibleGetClient` - HTTP calls (`quote`, `register`) returning `ApiResult`.
- `AnswerParser` - turns the API's HTML into a `QuoteAnswer`: allow-list sanitiser, reads errors/notices, cuts to `BibleGetMaxVersesPerQuote`.
- `ServiceNotices`, `NoticeStore`, `Notifications/` - service notices (body and `Deprecation`/`Sunset` headers), their storage, Echo event and manager lookup (`bibleget-manage` right).
- `Special/SpecialBibleGet` - status page for managers. `SchemaHooks` - creates the tables via `update.php`.

### Request flow

`parser hook -> QuoteRenderer -> QuoteFetcher -> CredentialProvider / BibleGetClient -> AnswerParser`, then back up as a `QuoteResult` rendered to HTML.

### Database

`sql/tables.json` (abstract schema, generated per DBMS in `sql/{mysql,postgres,sqlite}`): `bibleget_installation` (one row: install id, token, installation id) and `bibleget_notice` (service notices seen). Regenerate the per-DBMS files with core's `generateSchemaSql.php` after editing `tables.json`.

### Front end

`resources/`: `ext.bibleGet` (popup script and styles), `ext.bibleGet.styles`, `ext.bibleGet.notices` (banner dismissal through the API).

### Internationalization

- `i18n/*.json` - every key in `en.json` is translated in it, es, fr, de, pt and documented in `qqq.json` (Banana checks this).
- `BibleGet.i18n.magic.php` - magic word translations; `BibleGet.alias.php` - special page alias.

### Configuration

`$wgBibleGetApiBase`, `ApiKey`, `ClientKey` (placeholder until the BibleGet admin mints it), `DefaultBibleVersion` (NABRE), `CacheExpiry` (30 days), `MaxQuotesPerPage` (50), `MaxVersesPerQuote` (30). Rate limit defaults are in `extension.json` (`RateLimits`).

### Tests

`tests/phpunit/unit` and `integration` use fixtures of real v4 answers (`tests/phpunit/fixtures`) and `FakeQuoteFetcher`; no test calls the API. `tests/parser/bibleget.txt` runs through `ParserTestHooks`.
