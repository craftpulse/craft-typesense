<?php

namespace percipiolondon\typesense\helpers;

use Craft;
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

class FileLog
{
    // Public Static Methods
    // =========================================================================

    /**
     * Create an additional file log target named $filename.log that logs messages
     * in the category $category
     *
     * @param string $fileName
     * @param string $category
     * @return void
     */
    public static function create(string $fileName, string $category): void
    {
        // Create a new file target
        $fileTarget = new MonologTarget([
            'name' => $fileName,
            'categories' => [$category],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
            'formatter' => new LineFormatter(
                format: "%datetime% [%channel%.%level_name%] [%extra.yii_category%] %message% %context% %extra%\n",
                dateFormat: 'Y-m-d H:i:s',
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true,
            ),
        ]);
        // Add the new target file target to the dispatcher
        Craft::getLogger()->dispatcher->targets[] = $fileTarget;
    }
}
