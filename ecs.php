<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

// craftcms/ecs:dev-main pins symplify/easy-coding-standard to ^10.3.3,
// which uses the closure-based ECSConfig API rather than the v11+
// chainable builder.

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);

    $ecsConfig->parallel();
};
