<?php

// Checks that composer.json requires what the agent uses (`composer deps`).
// laravel/framework is the platform: what it brings along itself (Symfony's
// HTTP components, the extensions it requires) needs no line of its own.

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration)
    // Optional integrations: read only where the app has them, stubbed in the tests.
    ->ignoreUnknownClassesRegex('~^(Laravel\\\\Horizon|Spatie\\\\Health)\\\\~')
    ->ignoreErrorsOnPackages(['symfony/http-foundation', 'symfony/http-kernel'], [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtensions(['ext-ctype', 'ext-filter', 'ext-mbstring'], [ErrorType::SHADOW_DEPENDENCY])
    // Only used where the app has them: a database connection, OPcache, zip for the PHP code.
    ->ignoreErrorsOnExtensions(['ext-pdo', 'ext-zend-opcache', 'ext-zip'], [ErrorType::SHADOW_DEPENDENCY])
    // The tests: Testbench brings its core and Symfony's console, posix is checked before use.
    ->ignoreErrorsOnPackagesAndPaths(['orchestra/testbench-core', 'symfony/console'], [__DIR__.'/tests'], [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtensionAndPath('ext-posix', __DIR__.'/tests', [ErrorType::SHADOW_DEPENDENCY]);
