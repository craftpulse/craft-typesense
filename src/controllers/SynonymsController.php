<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\helpers\Locale;
use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro synonyms manager: CRUD for one-way and multi-way synonyms over the
 * dual-shape synonyms service. It is the Synonyms section of a control-panel-
 * managed collection's edit screen (reached from the collection sidebar nav);
 * a collection that declares its synonyms in config renders read-only with the
 * config notice. Gated by the edition and `typesense:manageSynonyms`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SynonymsController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the synonyms screens.
     */
    public const PERMISSION_MANAGE_SYNONYMS = 'typesense:manageSynonyms';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(self::PERMISSION_MANAGE_SYNONYMS);

        return true;
    }

    /**
     * The Synonyms section of a collection screen (control-panel-managed by uid,
     * or a config-file collection by name).
     *
     * @param string|null $uid
     * @param string|null $name
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionList(?string $uid = null, ?string $name = null): Response
    {
        $screen = CollectionsController::resolveSectionScreen($uid, $name);
        $collection = $screen['collection'];

        if ($collection === null) {
            return $this->_disabledShell($screen);
        }

        $synonyms = Typesense::$plugin->getSynonyms();
        $endpointParams = $uid !== null ? ['uid' => $uid] : ['name' => $name];

        return $this->renderTemplate('typesense/synonyms/_list', [
            'editable' => !$synonyms->isConfigOwned($collection),
            'editBase' => $screen['editBase'],
            'editKey' => $screen['editKey'],
            'screenName' => $screen['screenName'],
            'navItems' => $screen['navItems'],
            'tableDataEndpoint' => 'typesense/synonyms/table-data?' . http_build_query($endpointParams),
        ]);
    }

    /**
     * The VueAdminTable data endpoint for a collection's synonyms: a searchable,
     * paged JSON page source (server-side paging, so an unbounded synonym list
     * never ships in full). Same gate as the section screen. The collection is
     * read from the request (query params, which VueAdminTable appends), not from
     * a route segment.
     *
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionTableData(): Response
    {
        $uid = $this->request->getParam('uid');
        $name = $this->request->getParam('name');
        $screen = CollectionsController::resolveSectionScreen(
            is_string($uid) ? $uid : null,
            is_string($name) ? $name : null,
        );
        $collection = $screen['collection'];

        if ($collection === null) {
            return $this->asJson(['data' => [], 'pagination' => ['total' => 0]]);
        }

        $synonyms = Typesense::$plugin->getSynonyms();
        $editable = !$synonyms->isConfigOwned($collection);
        $rows = [];

        foreach ($synonyms->all($collection) as $synonym) {
            $synonymId = (string)($synonym['id'] ?? '');
            $root = (string)($synonym['root'] ?? '');
            $terms = ($root !== '' ? $root . ' -> ' : '') . implode(', ', $synonym['synonyms'] ?? []);
            $rows[] = [
                'id' => $screen['editKey'] . '|' . $synonymId,
                'title' => $synonymId,
                'url' => $editable ? UrlHelper::url($screen['editBase'] . '/synonyms/synonym/' . $synonymId) : null,
                'type' => $root !== '' ? Craft::t('typesense', 'One-way') : Craft::t('typesense', 'Multi-way'),
                'terms' => $terms,
            ];
        }

        return $this->paginatedTableData($rows, ['title', 'terms']);
    }

    /**
     * Deletes one synonym. The VueAdminTable row id is a "<screenKey>|<synonymId>"
     * pair so the delete (which posts only the row id) still carries the parent
     * collection the dual-shape service needs.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDeleteSynonym(): ?Response
    {
        $this->requirePostRequest();

        [$key, $synonymId] = $this->_splitRowId((string)$this->request->getRequiredBodyParam('id'));
        $collection = $this->_editableByKey($key);
        Typesense::$plugin->getSynonyms()->deleteOne($collection, $synonymId);

        Typesense::$plugin->getAudit()->record(Audit::EVENT_SYNONYMS_DELETED, [
            'collection' => $collection->getName(),
        ]);

        return $this->asSuccess(Craft::t('typesense', 'Synonym deleted.'));
    }

    /**
     * The synonym editor, a drill-down from the collection's Synonyms section.
     *
     * @param string|null $uid
     * @param string|null $name
     * @param string|null $synonymId
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditSynonym(?string $uid = null, ?string $name = null, ?string $synonymId = null): Response
    {
        $screen = CollectionsController::resolveSectionScreen($uid, $name);
        $collection = $screen['collection'];
        $synonym = null;

        if ($synonymId !== null && $collection !== null) {
            foreach (Typesense::$plugin->getSynonyms()->all($collection) as $existing) {
                if ((string)($existing['id'] ?? '') === $synonymId) {
                    $synonym = $existing;
                    break;
                }
            }
        }

        return $this->renderTemplate('typesense/synonyms/_edit', [
            'synonym' => $synonym,
            'isNew' => $synonym === null,
            'editBase' => $screen['editBase'],
            'editKey' => $screen['editKey'],
            'screenName' => $screen['screenName'],
        ]);
    }

    /**
     * Saves a synonym from the editor.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSaveSynonym(): ?Response
    {
        $this->requirePostRequest();

        $key = (string)$this->request->getRequiredBodyParam('screenKey');
        $collection = $this->_editableByKey($key);
        $id = trim((string)$this->request->getBodyParam('id', ''));
        $synonyms = $this->_terms();

        if ($id === '' || $synonyms === []) {
            return $this->asFailure(Craft::t('typesense', 'A synonym needs an id and at least one term.'));
        }

        Typesense::$plugin->getSynonyms()->upsert($collection, $id, $this->_synonymBody($synonyms));

        Typesense::$plugin->getAudit()->record(Audit::EVENT_SYNONYMS_SAVED, [
            'collection' => $collection->getName(),
        ]);

        return $this->asSuccess(
            Craft::t('typesense', 'Synonym saved.'),
            [],
            (string)$this->request->getRequiredBodyParam('editBase') . '/synonyms',
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders the section's disabled shell (nav plus a notice) for a
     * control-panel collection that is currently disabled and so has no runtime
     * collection to manage.
     *
     * @param array{editBase: string, editKey: string, screenName: string, navItems: array<string, mixed>, collection: ?Collection, disabled: bool} $screen
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _disabledShell(array $screen): Response
    {
        return $this->renderTemplate('typesense/collections/_disabled', [
            'screenName' => $screen['screenName'],
            'navItems' => $screen['navItems'],
            'selectedNavItem' => 'synonyms',
        ]);
    }

    /**
     * Resolves an editable collection from a screen key (a managed definition uid
     * or a "config:<name>" config-collection key) and asserts its synonyms are
     * control-panel-owned (editable).
     *
     * @param string $key
     * @return Collection
     * @throws NotFoundHttpException when unknown or config-owned (read-only)
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _editableByKey(string $key): Collection
    {
        $collection = CollectionsController::collectionForScreenKey($key);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        if (Typesense::$plugin->getSynonyms()->isConfigOwned($collection)) {
            throw new NotFoundHttpException('This collection’s synonyms are managed by config.');
        }

        return $collection;
    }

    /**
     * Splits a "<screenKey>|<id>" VueAdminTable row id into its parts.
     *
     * @param string $rowId
     * @return array{0: string, 1: string}
     * @author CraftPulse
     */
    private function _splitRowId(string $rowId): array
    {
        $parts = explode('|', $rowId, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Builds the Typesense synonym body from the editor's posted fields. A
     * non-empty root makes it a one-way synonym; otherwise it is multi-way.
     * An optional locale scopes the synonym to a language.
     *
     * @param array<int, string> $synonyms
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _synonymBody(array $synonyms): array
    {
        $body = ['synonyms' => $synonyms];
        $root = trim((string)$this->request->getBodyParam('root', ''));
        $locale = Locale::toTypesense((string)$this->request->getBodyParam('locale', ''));

        if ($root !== '') {
            $body['root'] = $root;
        }

        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        return $body;
    }

    /**
     * Normalises the posted terms (a newline- or comma-separated list) into a
     * clean list of synonym terms.
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _terms(): array
    {
        $raw = (string)$this->request->getBodyParam('synonyms', '');
        $terms = preg_split('/[\r\n,]+/', $raw) ?: [];
        $terms = array_map('trim', $terms);

        return array_values(array_filter($terms, static fn(string $term): bool => $term !== ''));
    }
}
