# BibleGet test wiki (MediaWiki 1.43, Docker)

    dev/setup.sh                      # start the wiki, install it, install core's dev dependencies
    dev/test.sh phpunit [path]        # PHPUnit (default: extensions/BibleGet/tests/phpunit)
    dev/test.sh parser [file]         # parser tests (default: extensions/BibleGet/tests/parser/bibleget.txt)
    dev/test.sh lint                  # composer test and npm test in the extension

Wiki at http://localhost:8043 (user `Admin`, password `testwiki-admin-pass`), SQLite in `dev/data/`
(git-ignored). The repository is mounted at `extensions/BibleGet`; Echo is loaded from the image.
The compose project is named `bibleget-dev`, so it does not clash with other test wikis.
No BibleGet API key is needed.
