# Captured BibleGet answers

Captured 2026-11-22 03:47 UTC from the BibleGet v4 dev address (`https://query.bibleget.io/dev/quote`),
POST `return=html`, with the SeminaVerbi project key (used on the server only; no credential appears in
these files). Each file is the raw response body.

| File | `query` | `version` | HTTP |
|---|---|---|---|
| good-nabre-jn3-16.html | Jn3,16 | NABRE | 200 |
| poetic-ps23.html | Ps23 | NABRE | 200 |
| capped-nabre-mt5.html | Mt5 | NABRE | 200 (30 of 48 verses: the API's copyright cap, no notice yet) |
| full-drb-mt5.html | Mt5 | DRB | 200 (all 48 verses) |
| multichapter-drb-mt5-48-6-2.html | Mt5,48-6,2 | DRB | 200 |
| bad-reference.html | Xyz99,1 | NABRE | 200 (errors table, empty results) |
| unknown-version.html | Jn3,16 | ZZZZ | 422 (problem+json, not HTML) |
