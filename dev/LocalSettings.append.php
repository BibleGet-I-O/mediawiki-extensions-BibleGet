<?php
// Appended to the generated LocalSettings.php by setup.sh.
$wgShowExceptionDetails = true;
$wgDevelopmentWarnings = true;
$wgMainCacheType = CACHE_NONE;
$wgParserCacheType = CACHE_NONE;
wfLoadExtension( 'Echo' );
wfLoadExtension( 'BibleGet' );
// No BibleGet API key is needed: the test wiki talks to the public endpoint.
