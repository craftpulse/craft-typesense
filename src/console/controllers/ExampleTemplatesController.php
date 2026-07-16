<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\console\controllers;

use Craft;
use craft\helpers\FileHelper;
use craftpulse\typesense\Typesense;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Installs Typesense's example front-end templates into the project's templates
 * directory, the same convenience Craft Commerce ships as
 * `commerce/example-templates`. Run:
 *
 * ```
 * php craft typesense/example-templates
 * ```
 *
 * The bundle is copied to `templates/<folder>` (default `typesense`). Choosing a
 * different folder name rewrites the bundle's internal `typesense/...` template
 * paths and URLs to match, so the copy works wherever it lands. The demos ship
 * wired to the `heroes` collection; pass `--collection=<handle>` to wire them to
 * another, or let the command auto-detect the first search-enabled collection.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ExampleTemplatesController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $defaultAction = 'install';

    /**
     * @var string|null The target folder under `templates/` the bundle is copied
     * to. Prompted for when omitted; defaults to `typesense`.
     */
    public ?string $folderName = null;

    /**
     * @var bool Whether to overwrite an existing folder. Must be passed when a
     * folder with the chosen name already exists.
     */
    public bool $overwrite = false;

    /**
     * @var string|null The collection the demos query. When omitted, the first
     * search-enabled collection configured on this install is detected and wired
     * in (the bundle ships wired to `heroes`, the playground's demo collection).
     */
    public ?string $collection = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'folderName';
        $options[] = 'overwrite';
        $options[] = 'collection';

        return $options;
    }

    /**
     * Copies the example front-end templates into the project's templates
     * directory.
     *
     * @return int a `yii\console\ExitCode` value
     * @author CraftPulse
     */
    public function actionInstall(): int
    {
        $source = FileHelper::normalizePath(
            dirname((string)Typesense::$plugin->getBasePath()) . DIRECTORY_SEPARATOR . 'example-templates' . DIRECTORY_SEPARATOR . 'typesense',
        );

        if (!is_dir($source)) {
            $this->stderr("The example templates were not found at {$source}." . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $folderName = $this->folderName;

        if ($folderName === null) {
            $this->stdout('The example templates will be copied into your templates directory.' . PHP_EOL);
            $folderName = (string)$this->prompt('Choose a folder name:', ['required' => true, 'default' => 'typesense']);
        }

        $folderName = trim($folderName, "/ \t\n\r");

        if ($folderName === '' || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $folderName)) {
            $this->stderr('The folder name may only contain letters, numbers, underscores, hyphens, and slashes.' . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $destination = FileHelper::normalizePath(
            Craft::$app->getPath()->getSiteTemplatesPath() . DIRECTORY_SEPARATOR . $folderName,
        );

        if (is_dir($destination) && !$this->overwrite) {
            $this->stderr("The folder {$destination} already exists. Pass --overwrite to replace it." . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $collection = $this->_resolveCollection();

        try {
            $this->_copy($source, $destination, $folderName, $collection);
        } catch (Throwable $e) {
            $this->stderr('Could not install the example templates: ' . $e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("The example templates were installed at {$destination}, wired to the \"{$collection}\" collection." . PHP_EOL, Console::FG_GREEN);
        $this->stdout(PHP_EOL . "Next step: visit /{$folderName}/search to see the demo." . PHP_EOL);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Copies the bundle into place, rewriting its internal `typesense/...`
     * template paths and URLs when a different folder name was chosen, and its
     * `heroes` collection references when a different collection was chosen. The
     * rewrite happens in a temp copy first, so a failure never leaves a
     * half-rewritten destination.
     *
     * @param string $source the bundled example-templates path
     * @param string $destination the target folder under the templates path
     * @param string $folderName the chosen folder name
     * @param string $collection the collection the demos should query
     * @throws \yii\base\Exception if a directory cannot be created
     * @throws \yii\base\ErrorException if a template cannot be rewritten
     * @author CraftPulse
     */
    private function _copy(string $source, string $destination, string $folderName, string $collection): void
    {
        $temp = Craft::$app->getPath()->getTempPath()
            . DIRECTORY_SEPARATOR . 'typesense-example-templates-' . md5(uniqid((string)mt_rand(), true));

        FileHelper::copyDirectory($source, $temp);

        $renameFolder = $folderName !== 'typesense';
        $rewireCollection = $collection !== 'heroes';

        if ($renameFolder || $rewireCollection) {
            foreach (FileHelper::findFiles($temp, ['only' => ['*.twig']]) as $file) {
                $contents = (string)file_get_contents($file);

                // The bundle's paths and URLs are root-relative to its canonical
                // folder name; a rename rewrites every quoted 'typesense/...
                // reference.
                if ($renameFolder) {
                    $contents = str_replace("'typesense/", "'{$folderName}/", $contents);
                }

                // The demos ship wired to `heroes`; rewire the collection handle
                // wherever it appears as a quoted literal (single or double).
                if ($rewireCollection) {
                    $contents = str_replace(
                        ["'heroes'", '"heroes"'],
                        ["'{$collection}'", "\"{$collection}\""],
                        $contents,
                    );
                }

                FileHelper::writeToFile($file, $contents);
            }
        }

        if (is_dir($destination)) {
            FileHelper::removeDirectory($destination);
        }

        FileHelper::copyDirectory($temp, $destination);
        FileHelper::removeDirectory($temp);
    }

    /**
     * Resolves the collection the demos query: the explicit `--collection` when
     * given, otherwise the first search-enabled collection configured on this
     * install, otherwise `heroes` (the bundle's shipped default).
     *
     * @return string
     * @author CraftPulse
     */
    private function _resolveCollection(): string
    {
        if ($this->collection !== null && trim($this->collection) !== '') {
            return trim($this->collection);
        }

        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $name => $collection) {
            if ($collection->isSearchable()) {
                return (string)$name;
            }
        }

        return 'heroes';
    }
}
