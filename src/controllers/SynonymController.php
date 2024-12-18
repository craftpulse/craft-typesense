<?php

namespace percipiolondon\typesense\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use percipiolondon\typesense\db\Table;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\services\SynonymService;
use percipiolondon\typesense\Typesense;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Synonym Controller controller
 */
class SynonymController extends Controller
{
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function init(): void
    {
        parent::init();

        $this->requirePermission('typesense:synonyms');
    }

    public function actionIndex(): Response
    {
        $variables = $this->_getInfo();

        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            $element = $index->criteria->one();
            $synonyms = Typesense::$plugin->synonyms->getSynonymsByIndex($index->indexName);
            $synonyms = [
                'count' => $synonyms ? count($synonyms) : 0,
                'direction' => $index?->schema['synonym_direction'] ?? 'multi-way',
            ];

            switch ($index->elementType) {
                case 'craft\elements\Asset':
                    $volume = $element->getVolume() ?? null;

                    if ($volume) {
                        $variables['sections'][] = [
                            'id' => 1,
                            'name' => $volume->name,
                            'handle' => $volume->handle,
                            'type' => $volume->handle,
                            'index' => $index->indexName,
                            'synonyms' => $synonyms,
                        ];
                    }
                    break;

                case 'craft\elements\Entry':
                    $section = $element->section ?? null;

                    if ($section) {
                        $variables['sections'][] = [
                            'id' => $section->id,
                            'name' => $section->name,
                            'handle' => $section->handle,
                            'type' => $element->type->handle,
                            'index' => $index->indexName,
                            'synonyms' => $synonyms,
                        ];
                    }
                    break;

                case 'craft\commerce\elements\Product':
                    $type = $element->type ?? null;
                    if ($type) {
                        $variables['sections'][] = [
                            'id' => $type->id,
                            'name' => $type->name,
                            'handle' => $type->handle,
                            'type' => 'Product: ' . $element->type->handle,
                            'entryCount' => $index->criteria->count(),
                            'index' => $index->indexName,
                            'synonyms' => $synonyms,
                        ];
                    }
                    break;

                case 'craft\commerce\elements\Variant':
                    $type = $element->product->type ?? null;
                    if ($type) {
                        $variables['sections'][] = [
                            'id' => $type->id,
                            'name' => $type->name,
                            'handle' => $type->handle,
                            'type' => 'Variant: ' . $type->handle,
                            'entryCount' => $index->criteria->count(),
                            'index' => $index->indexName,
                            'synonyms' => $synonyms,
                        ];
                    }
                    break;
            }
        }

        return $this->renderTemplate('typesense/synonyms/index', $variables);
    }

    public function actionSynonyms(string $index): Response
    {
        $templateTitle = Craft::t('typesense', "Synonyms for ${index}");
        $variables = $this->_getInfo();
        $variables['synonyms'] = null;
        $variables['index'] = $index;
        array_push($variables['crumbs'], [
            'label' => $index,
        ]);


        try {
            $collection = CollectionHelper::getCollection($index);
        } catch(\Exception $e) {
            Craft::error($e->getMessage(), 'typesense');
            return $this->redirect('typesense/synonyms');
        }

        if ($collection) {
            $variables['synonyms'] = Typesense::$plugin->synonyms->getSynonymsByIndex($index);
        }

        $variables['title'] = $templateTitle;

        return $this->renderTemplate('typesense/synonyms/detail', $variables);
    }

    /**
     * Save the mappings
     *
     * @return null|Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws MethodNotAllowedHttpException
     * @throws JsonException
     * @throws Throwable
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $synonyms = $request->getBodyParam('synonyms');
        $index = $request->getBodyParam('index');
        $hasErrors = false;
        $errors = [];

        // Validation logic and generate ID based on root
        if (empty($synonyms)) {
            Craft::$app->getSession()->setError(Craft::t('typesense','At least one synonym entry is required.'));
            array_push($errors, Craft::t('typesense','At least one synonym entry is required.'));
            $hasErrors = true;
        } else {
            foreach ($synonyms as $i => &$row) {

                // clean the synonyms
                $arrSynonyms = explode(',',$row['synonyms']);
                $row['synonyms'] = implode(',', Typesense::$plugin->synonyms->cleanSynonymData($arrSynonyms));

                // generate ID based on root
                if (empty($row['id']) && !empty($row['root'])) {
                    $row['id'] = "synonyms-" . strtolower($row['root']) . '-' . $i;
                }

                // validate
                if (empty($row['root'])) {
                    Craft::$app->getSession()->setError(Craft::t('typesense', "Errors saving the synonyms"));
                    array_push($errors, "The 'Root' field in row " . ($i + 1) . " is required.");
                    $hasErrors = true;
                }

                if (empty($row['synonyms'])) {
                    Craft::$app->getSession()->setError(Craft::t('typesense', "Errors saving the synonyms"));
                    array_push($errors, "The 'Synonyms' field in row " . ($i + 1) . " is required.");
                    $hasErrors = true;
                }
            }
        }

        // If validation fails, render the same template with the submitted data
        if (!$hasErrors) {
            $success = Typesense::$plugin->synonyms->saveSynonyms($index, $synonyms);

            if ($success) {
                Craft::$app->getSession()->setSuccess(Craft::t('typesense','Synonyms has been saved.'));
                return $this->redirectToPostedUrl();
            }
        }

        // error
        $variables = $this->_getInfo();
        $variables['synonyms'] = $synonyms;
        $variables['errors'] = $errors;
        $variables['index'] = $index;
        $variables['title'] = Craft::t('typesense', "Synonyms for ${index}");

        return $this->renderTemplate('typesense/synonyms/detail', $variables);
    }

    private function _getInfo(): array
    {
        $variables = [];

        $pluginName = Typesense::$plugin->getSettings()->pluginName;
        $templateTitle = Craft::t('typesense', 'Synonyms');

        $variables['controllerHandle'] = 'synonym';
        $variables['fullPageForm'] = true;
        $variables['pluginName'] = $pluginName;
        $variables['title'] = $templateTitle;
        $variables['docTitle'] = "{$pluginName} - {$templateTitle}";
        $variables['selectedSubnavItem'] = 'synonyms';

        $variables['crumbs'] = [
            [
                'label' => $pluginName,
                'url' => UrlHelper::cpUrl('typesense'),
            ],
            [
                'label' => $templateTitle,
                'url' => UrlHelper::cpUrl('typesense/synonyms'),
            ],
        ];

        return $variables;
    }
}
