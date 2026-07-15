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
use craftpulse\typesense\models\Experiment;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro A/B testing composition screen: define an experiment (a collection and
 * weighted variants, each with a scoped-key profile, a search preset, and an
 * analytics tag), persisted to project config. Traffic is split by the derived
 * per-variant keys. The click-through / no-result comparison across variants is
 * the analytics dashboard's job (a later phase); this screen defines and
 * orchestrates. Gated by the edition and `typesense:manageCollections`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ExperimentsController extends ProController
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
     * Deletes an experiment.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        Typesense::$plugin->getExperiments()->delete((string)$this->request->getRequiredBodyParam('handle'));

        return $this->asSuccess(Craft::t('typesense', 'Experiment deleted.'), [
            'redirect' => 'typesense/experiments',
        ]);
    }

    /**
     * The experiment editor.
     *
     * @param string|null $handle
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEdit(?string $handle = null): Response
    {
        $experiment = $handle !== null
            ? (Typesense::$plugin->getExperiments()->get($handle) ?? throw new NotFoundHttpException('Experiment not found.'))
            : new Experiment();

        return $this->renderTemplate('typesense/experiments/_edit', [
            'experiment' => $experiment,
            'isNew' => $handle === null,
            'collections' => array_keys(Typesense::$plugin->getCollectionRegistry()->getAll()),
            'profiles' => array_keys(Typesense::$plugin->getKeys()->getProfiles()),
        ]);
    }

    /**
     * The experiments list.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/experiments/index', [
            'experiments' => Typesense::$plugin->getExperiments()->getAll(),
        ]);
    }

    /**
     * Persists an experiment.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        $request = $this->request;
        $experiment = new Experiment();
        $experiment->handle = (string)$request->getBodyParam('handle', '');
        $experiment->name = (string)$request->getBodyParam('name', '');
        $experiment->collection = (string)$request->getBodyParam('collection', '');
        $experiment->enabled = (bool)$request->getBodyParam('enabled', true);
        $experiment->variants = $this->_variants();

        if (!Typesense::$plugin->getExperiments()->save($experiment)) {
            return $this->asModelFailure($experiment, Craft::t('typesense', 'Could not save the experiment.'), 'experiment');
        }

        return $this->asModelSuccess($experiment, Craft::t('typesense', 'Experiment saved.'), 'experiment', [
            'redirect' => 'typesense/experiments',
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Normalises the posted variants (an editable-table body param), dropping
     * rows without a handle.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _variants(): array
    {
        $raw = $this->request->getBodyParam('variants', []);
        $variants = [];

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $row) {
            $handle = trim((string)($row['handle'] ?? ''));

            if ($handle === '') {
                continue;
            }

            $variants[] = [
                'handle' => $handle,
                'name' => trim((string)($row['name'] ?? '')),
                'weight' => max(1, (int)($row['weight'] ?? 1)),
                'profile' => trim((string)($row['profile'] ?? '')),
                'preset' => trim((string)($row['preset'] ?? '')),
                'analyticsTag' => trim((string)($row['analyticsTag'] ?? '')),
            ];
        }

        return $variants;
    }
}
