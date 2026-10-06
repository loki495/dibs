<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/app', __DIR__.'/database', __DIR__.'/routes'])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true, codeQuality: true)
    // The codebase doesn't use #[\Override]; tagging only Laravel's convention properties
    // ($guarded, $signature, $model, ...) would be noise without methods to match.
    ->withSkip([AddOverrideAttributeToOverriddenPropertiesRector::class]);
