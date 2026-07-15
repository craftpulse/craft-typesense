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
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro relevance-tuning editor: per control-panel-managed collection, authors
 * the search preset (raw Typesense parameters), additive boost rules (compiled
 * to the indexed boost_score at index time), text-match buckets, result grouping,
 * and MMR diversification. Version-sensitive controls (buckets, MMR) are hidden
 * on servers that do not support them. Config-file collections tune relevance in
 * fluent config, so only CP-managed collections are editable here. Gated by the
 * edition and `typesense:manageCollections`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class RelevanceController extends ProController
{
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

        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        return true;
    }

    /**
     * The relevance editor for a CP-managed collection.
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEdit(string $uid): Response
    {
        $definition = $this->_requireDefinition($uid);
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

        return $this->renderTemplate('typesense/relevance/_edit', [
            'definition' => $definition,
            'relevance' => $definition->relevance,
            'supportsBuckets' => $capabilities?->textMatchBuckets() ?? false,
            'supportsMmr' => $capabilities?->mmr() ?? false,
        ]);
    }

    /**
     * The collection picker for the relevance editor (CP-managed collections).
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/relevance/index', [
            'definitions' => Typesense::$plugin->getManagedCollections()->getAll(),
        ]);
    }

    /**
     * Persists the relevance definition for a CP-managed collection.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        $definition = $this->_requireDefinition((string)$this->request->getRequiredBodyParam('uid'));
        $definition->relevance = $this->_relevanceBody();

        Typesense::$plugin->getManagedCollections()->save($definition);

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Relevance saved. Re-sync the collection to apply boost changes.'), 'definition', [
            'redirect' => 'typesense/relevance/{uid}',
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Normalises the posted boost rules (an editable-table body param) into the
     * stored rule shape. Each row is a single-condition rule (field, operator,
     * value, weight); the condition builder translates directly to a boost that
     * bakes into boost_score. Multi-condition rules remain a fluent-config
     * capability. Rows without a field are dropped.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _boostRules(): array
    {
        $raw = $this->request->getBodyParam('boostRules', []);
        $rules = [];

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $row) {
            $field = trim((string)($row['field'] ?? ''));

            if ($field === '') {
                continue;
            }

            $rules[] = [
                'label' => trim((string)($row['label'] ?? '')),
                'weight' => (float)($row['weight'] ?? 0),
                'match' => 'all',
                'conditions' => [[
                    'field' => $field,
                    'operator' => (string)($row['operator'] ?? 'eq'),
                    'value' => (string)($row['value'] ?? ''),
                ]],
            ];
        }

        return $rules;
    }

    /**
     * Builds the relevance definition from the editor's posted fields.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _relevanceBody(): array
    {
        $request = $this->request;
        $searchPreset = [];

        foreach (['num_typos', 'prefix', 'drop_tokens_threshold', 'typo_tokens_threshold', 'min_len_1typo', 'min_len_2typo'] as $key) {
            $value = trim((string)$request->getBodyParam($key, ''));

            if ($value !== '') {
                $searchPreset[$key] = $value;
            }
        }

        return [
            'boostRules' => $this->_boostRules(),
            'textMatchBuckets' => max(0, (int)$request->getBodyParam('textMatchBuckets', 0)),
            'grouping' => [
                'field' => trim((string)$request->getBodyParam('groupBy', '')),
                'limit' => max(1, (int)$request->getBodyParam('groupLimit', 1)),
            ],
            'mmr' => [
                'enabled' => (bool)$request->getBodyParam('mmrEnabled', false),
                'lambda' => (float)$request->getBodyParam('mmrLambda', 0),
            ],
            'searchPreset' => $searchPreset,
        ];
    }

    /**
     * Loads a CP-managed collection definition or throws.
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
}
