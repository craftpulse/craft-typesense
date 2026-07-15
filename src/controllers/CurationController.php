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
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro curation manager: CRUD for curation rules over the dual-shape curation
 * service, plus the entry-sidebar quick-pin. It is the Curation section of a
 * control-panel-managed collection's edit screen (reached from the collection
 * sidebar nav); a collection that declares its curation in config renders
 * read-only with the config notice. Every write refreshes the element-to-rule
 * lookup index (through the curation service). Gated by the edition and
 * `typesense:manageCuration`.
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
     * The Curation section of a control-panel-managed collection's edit screen.
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionList(string $uid): Response
    {
        $definition = $this->_requireDefinition($uid);
        $collection = $this->_collection($definition);
        $curation = Typesense::$plugin->getCuration();

        return $this->renderTemplate('typesense/curation/_rules', [
            'definition' => $definition,
            'rules' => $curation->all($collection),
            'editable' => !$curation->isConfigOwned($collection),
            'navItems' => CollectionsController::editScreenNavItems($definition),
        ]);
    }

    /**
     * Deletes a curation rule. The row id is a "<uid>|<ruleId>" pair so the
     * VueAdminTable delete (which posts only the row id) still carries the
     * parent-collection context the dual-shape service needs.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDeleteRule(): ?Response
    {
        $this->requirePostRequest();

        [$uid, $ruleId] = $this->_splitRowId((string)$this->request->getRequiredBodyParam('id'));
        $collection = $this->_editableCollection($uid);
        Typesense::$plugin->getCuration()->deleteRule($collection, $ruleId);

        return $this->asSuccess(Craft::t('typesense', 'Rule deleted.'));
    }

    /**
     * The rule editor, a drill-down from the collection's Curation section.
     *
     * @param string $uid
     * @param string|null $ruleId
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditRule(string $uid, ?string $ruleId = null): Response
    {
        $definition = $this->_requireDefinition($uid);
        $collection = $this->_collection($definition);
        $rule = null;

        if ($ruleId !== null) {
            foreach (Typesense::$plugin->getCuration()->all($collection) as $existing) {
                if ((string)($existing['id'] ?? '') === $ruleId) {
                    $rule = $existing;
                    break;
                }
            }
        }

        return $this->renderTemplate('typesense/curation/_edit', [
            'definition' => $definition,
            'rule' => $rule,
            'isNew' => $rule === null,
        ]);
    }

    /**
     * Pins an element for a query (the entry sidebar quick-pin path). Resolves
     * the element's document id for the collection and adds an include. This
     * path is keyed by collection name, since the entry sidebar knows the
     * collection by name, not by definition uid.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionPin(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->_editableCollectionByName((string)$this->request->getRequiredBodyParam('collection'));
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

        $uid = (string)$this->request->getRequiredBodyParam('uid');
        $collection = $this->_editableCollection($uid);
        $id = trim((string)$this->request->getBodyParam('id', ''));
        $query = trim((string)$this->request->getBodyParam('query', ''));

        if ($id === '' || $query === '') {
            return $this->asFailure(Craft::t('typesense', 'A rule needs an id and a query.'));
        }

        Typesense::$plugin->getCuration()->upsert($collection, $id, $this->_ruleBody());

        return $this->asSuccess(Craft::t('typesense', 'Rule saved.'), [], 'typesense/collections/' . $uid . '/curation');
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves the runtime collection for a control-panel-managed definition.
     *
     * @param CollectionDefinition $definition
     * @return Collection
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _collection(CollectionDefinition $definition): Collection
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($definition->name);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $collection;
    }

    /**
     * Loads a collection by its definition uid and asserts its curation is
     * control-panel-owned (editable).
     *
     * @param string $uid
     * @return Collection
     * @throws NotFoundHttpException when unknown or config-owned (read-only)
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _editableCollection(string $uid): Collection
    {
        return $this->_assertEditable($this->_collection($this->_requireDefinition($uid)));
    }

    /**
     * Loads a collection by name and asserts its curation is control-panel-owned
     * (the entry-sidebar quick-pin path, which is keyed by name).
     *
     * @param string $handle
     * @return Collection
     * @throws NotFoundHttpException when unknown or config-owned (read-only)
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _editableCollectionByName(string $handle): Collection
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $this->_assertEditable($collection);
    }

    /**
     * Asserts a collection's curation is control-panel-owned (editable), or
     * throws.
     *
     * @param Collection $collection
     * @return Collection
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _assertEditable(Collection $collection): Collection
    {
        if (Typesense::$plugin->getCuration()->isConfigOwned($collection)) {
            throw new NotFoundHttpException('This collection’s curation is managed by config.');
        }

        return $collection;
    }

    /**
     * Loads a control-panel-managed collection definition by uid or throws.
     *
     * @param string $uid
     * @return CollectionDefinition
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _requireDefinition(string $uid): CollectionDefinition
    {
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $definition;
    }

    /**
     * Splits a "<uid>|<id>" VueAdminTable row id into its parts.
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
