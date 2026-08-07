<?php

declare(strict_types=1);

/**
 * Bootstrap for TYPO3 CMS functional tests (`typo3/testing-framework`).
 */
require dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';

$testbase = new \TYPO3\TestingFramework\Core\Testbase();
$testbase->defineSitePath();
$testbase->defineOriginalRootPath();
