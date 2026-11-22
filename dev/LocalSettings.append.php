<?php
// Appended to the generated LocalSettings.php by setup.sh.
$wgShowExceptionDetails = true;
$wgDevelopmentWarnings = true;
$wgMainCacheType = CACHE_NONE;
$wgParserCacheType = CACHE_NONE;
wfLoadExtension( 'Echo' );
wfLoadExtension( 'BibleGet' );
// No BibleGet credential is set: without a project key ($wgBibleGetApiKey) or a client key, quotes on the
// test wiki's pages show the fallback. The PHPUnit and parser tests never call the API (FakeQuoteFetcher
// and fake HTTP); set $wgBibleGetApiKey here to try real quotes.
