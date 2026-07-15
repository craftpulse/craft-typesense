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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro curation manager: CRUD for curation rules over the dual-shape curation
 * service, plus the entry-sidebar quick-pin. Control-panel-managed curation
 * scopes are editable; config-managed scopes render read-only with the override
 * notice. Every write refreshes the element-to-rule lookup index (through the
 * curation service). Gated by the edition and `typesense:manageCuration`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CurationController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the curation manager.
     */
    public const PERMISSION_MANAGE_CURATION = 'typesense:manageCuration';

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

        $this->requirePermission(self::PERMISSION_MANAGE_CURATION);

        return true;
    }

    /**
     * Deletes a curation rule.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDeleteRule(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->_editableCollection((string)$this->request->getRequiredBodyParam('collection'));
        Typesense::$plugin->getCuration()->deleteRule($collection, (string)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('typesense', 'Rule deleted.'));
    }

    /**
     * The rule editor for a collection.
     *
     * @param string $collection
     * @param string|null $ruleId
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditRule(string $collection, ?string $ruleId = null): Response
    {
        $model = $this->_requireCollection($collection);
        $rule = null;

        if ($ruleId !== null) {
            foreach (Typesense::$plugin->getCuration()->all($model) as $existing) {
                if ((string)($existing['id'] ?? '') === $ruleId) {
                    $rule = $existing;
                    break;
                }
            }
        }

        return $this->renderTemplate('typesense/curation/_edit', [
            'handle' => $collection,
            'rule' => $rule,
            'isNew' => $rule === null,
        ]);
    }

    /**
     * The collection picker for the curation manager.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/curation/index', [
            'collections' => array_keys(Typesense::$plugin->getCollectionRegistry()->getAll()),
        ]);
    }

    /**
     * Pins an element for a query (the entry sidebar quick-pin path). Resolves
     * the element's document id for the collection and adds an include.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionPin(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->_editableCollection((string)$this->request->getRequiredBodyParam('collection'));
        $query = trim((string)$this->request->getRequiredBodyParam('query'));
        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $position = (int)$this->request->getBodyParam('position', 1);

        /** @phpstan-ignore-next-line the element type is not known ahead of time */
        $element = Craft::$app->getElements()->getElementById($elementId);

        if ($element === null || $query === '') {
            return $this->asFailure(Craft::t('typesense', 'Could not pin the element.'));
        }

        $siteId = (int)$element->siteId;
        $built = Typesense::$plugin->getDocuments()->build($collection, $element, $siteId);
        $documentId = (string)($built->documents[0]['id'] ?? $elementId);

        Typesense::$plugin->getCuration()->pin($collection, $query, $documentId, 'exact', max(1, $position));

        return $this->asSuccess(Craft::t('typesense', 'Pinned for “{query}”.', ['query' => $query]));
    }

    /**
     * Lists the rules for a collection.
     *
     * @param string $collection
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionRules(string $collection): Response
    {
        $model = $this->_requireCollection($collection);
        $curation = Typesense::$plugin->getCuration();

        return $this->renderTemplate('typesense/curation/_rules', [
            'handle' => $collection,
            'rules' => $curation->all($model),
            'editable' => $curation->getManagedBy($model) === Settings::MANAGED_BY_CP,
            'overridden' => $curation->isConfigOverridden($model),
        ]);
    }

    /**
     * Saves a curation rule from the editor.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSaveRule(): ?Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getRequiredBodyParam('collection');
        $collection = $this->_editableCollection($handle);
        $id = trim((string)$this->request->getBodyParam('id', ''));
        $query = trim((string)$this->request->getBodyParam('query', ''));

        if ($id === '' || $query === '') {
            return $this->asFailure(Craft::t('typesense', 'A rule needs an id and a query.'));
        }

        Typesense::$plugin->getCuration()->upsert($collection, $id, $this->_ruleBody());

        return $this->asSuccess(Craft::t('typesense', 'Rule saved.'), [], 'typesense/curation/' . $handle);
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads a collection and asserts its curation is control-panel-managed.
     *
     * @param string $handle
     * @return Collection
     * @throws NotFoundHttpException when unknown or config-managed (read-only)
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _editableCollection(string $handle): Collection
    {
        $collection = $this->_requireCollection($handle);

        if (Typesense::$plugin->getCuration()->getManagedBy($collection) !== Settings::MANAGED_BY_CP) {
            throw new NotFoundHttpException('This collection’s curation is managed by config.');
        }

        return $collection;
    }

    /**
     * Loads a collection or throws.
     *
     * @param string $handle
     * @return Collection
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _requireCollection(string $handle): Collection
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $collection;
    }

    /**
     * Builds the Typesense curation rule body from the editor's posted fields.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _ruleBody(): array
    {
        $request = $this->request;
        $rule = [
            'rule' => [
                'query' => trim((string)$request->getBodyParam('query', '')),
                'match' => $request->getBodyParam('match') === 'contains' ? 'contains' : 'exact',
            ],
            'includes' => $this->_rows('includes', true),
            'excludes' => $this->_rows('excludes', false),
        ];

        $ruleFilterBy = trim((string)$request->getBodyParam('ruleFilterBy', ''));

        if ($ruleFilterBy !== '') {
            $rule['rule']['filter_by'] = $ruleFilterBy;
        }

        foreach (['filterBy' => 'filter_by', 'sortBy' => 'sort_by'] as $from => $to) {
            $value = trim((string)$request->getBodyParam($from, ''));

            if ($value !== '') {
                $rule[$to] = $value;
            }
        }

        foreach (['validFrom' => 'effective_from_ts', 'validUntil' => 'effective_to_ts'] as $from => $to) {
            $value = trim((string)$request->getBodyParam($from, ''));

            if ($value !== '') {
                $rule[$to] = (int)strtotime($value);
            }
        }

        return $rule;
    }

    /**
     * Normalises an editable-table body param into a list of rows, dropping
     * blank rows and (for includes) casting the position to an int.
     *
     * @param string $param
     * @param bool $withPosition
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _rows(string $param, bool $withPosition): array
    {
        $raw = $this->request->getBodyParam($param, []);
        $rows = [];

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $row) {
            $id = trim((string)($row['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $entry = ['id' => $id];

            if ($withPosition) {
                $entry['position'] = max(1, (int)($row['position'] ?? 1));
            }

            $rows[] = $entry;
        }

        return $rows;
    }
}
