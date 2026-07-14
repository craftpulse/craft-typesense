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

    // Legacy 5.8.3 source (percipiolondon\typesense namespace) is skipped until
    // the clean rebuild rewrites it to house standard. Each file below fails
    // OrderedImportsFixer and/or BinaryOperatorSpacesFixer; fixing them now is
    // rebuild work, not preparation. New files added to src are still checked.
    $ecsConfig->skip([
        __DIR__ . '/src/Typesense.php',
        __DIR__ . '/src/config.php',
        __DIR__ . '/src/controllers/SynonymController.php',
        __DIR__ . '/src/jobs/SyncSynonymsJob.php',
        __DIR__ . '/src/migrations/m241210_132929_synonyms.php',
        __DIR__ . '/src/models/SynonymModel.php',
        __DIR__ . '/src/records/CollectionRecord.php',
        __DIR__ . '/src/services/SynonymService.php',
    ]);

    $ecsConfig->parallel();
};
