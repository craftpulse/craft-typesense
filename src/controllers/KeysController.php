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
use craft\helpers\DateTimeHelper;
use craft\web\assets\cp\CpAsset;
use craftpulse\typesense\assetbundles\keys\KeysAsset;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\KeyProfile;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro API-key management screen: drives Typesense's keys API (list, create,
 * delete) and manages named scoped-key profiles. A created key's full value is
 * shown EXACTLY ONCE in a copy-this-now modal and is never stored by the plugin.
 * Rotation forces the replacement to be created before the old key can be
 * deleted. Gated by the edition and `typesense:manageKeys`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class KeysController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the keys manager.
     */
    public const PERMISSION_MANAGE_KEYS = 'typesense:manageKeys';

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

        $this->requirePermission(self::PERMISSION_MANAGE_KEYS);

        return true;
    }

    /**
     * The key creation form. When `from` is a live key id, its scopes prefill the
     * form (the rotate flow: create the replacement, then delete the old key).
     *
     * @param string|null $from
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionCreate(?string $from = null): Response
    {
        $prefill = null;

        if ($from !== null) {
            foreach (Typesense::$plugin->getKeys()->all() as $key) {
                if ((string)($key['id'] ?? '') === $from) {
                    $prefill = $key;
                    break;
                }
            }
        }

        return $this->renderTemplate('typesense/keys/_create', [
            'actions' => Typesense::$plugin->getKeys()::ACTIONS,
            'collections' => array_keys(Typesense::$plugin->getCollectionRegistry()->getAll()),
            'prefill' => $prefill,
            'rotatingId' => $prefill !== null ? (int)($prefill['id'] ?? 0) : null,
        ]);
    }

    /**
     * Deletes an API key by server id.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionDeleteKey(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_KEYS);

        Typesense::$plugin->getKeys()->delete((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('typesense', 'Key deleted.'), [], 'typesense/keys');
    }

    /**
     * Deletes a scoped-key profile.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionDeleteProfile(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_KEYS);

        Typesense::$plugin->getKeys()->deleteProfile((string)$this->request->getRequiredBodyParam('handle'));

        return $this->asSuccess(Craft::t('typesense', 'Profile deleted.'), [], 'typesense/keys');
    }

    /**
     * The scoped-key profile editor.
     *
     * @param string|null $handle
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditProfile(?string $handle = null): Response
    {
        $profile = $handle !== null
            ? (Typesense::$plugin->getKeys()->getProfile($handle) ?? throw new NotFoundHttpException('Profile not found.'))
            : new KeyProfile();

        return $this->renderTemplate('typesense/keys/_profile', [
            'profile' => $profile,
            'isNew' => $handle === null,
        ]);
    }

    /**
     * The keys manager: existing server keys (prefixes only) and scoped-key
     * profiles.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/keys/index', [
            'keys' => Typesense::$plugin->getKeys()->all(),
            'profiles' => Typesense::$plugin->getKeys()->getProfiles(),
        ]);
    }

    /**
     * Creates an API key and renders the show-once modal with its full value.
     * The value is displayed exactly once and never stored by the plugin.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function actionSaveKey(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_KEYS);

        $created = Typesense::$plugin->getKeys()->create($this->_keySchema());

        if ($created === null || !isset($created['value'])) {
            $this->setFailFlash(Craft::t('typesense', 'Could not create the key.'));

            return $this->redirect('typesense/keys/new');
        }

        $rotatingId = (int)$this->request->getBodyParam('rotatingId', 0);
        $this->getView()->registerAssetBundle(CpAsset::class);
        $this->getView()->registerAssetBundle(KeysAsset::class);

        // The full value lives only in this render. It is never persisted.
        return $this->renderTemplate('typesense/keys/_created', [
            'key' => $created,
            'rotatingId' => $rotatingId > 0 ? $rotatingId : null,
        ]);
    }

    /**
     * Persists a scoped-key profile.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveProfile(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_KEYS);

        $request = $this->request;
        $profile = new KeyProfile();
        $profile->handle = (string)$request->getBodyParam('handle', '');
        $profile->name = (string)$request->getBodyParam('name', '');
        $profile->filterBy = (string)$request->getBodyParam('filterBy', '');
        $profile->includeFields = (string)$request->getBodyParam('includeFields', '');
        $profile->excludeFields = (string)$request->getBodyParam('excludeFields', '');
        $profile->expiresIn = (int)$request->getBodyParam('expiresIn', 0);
        $profile->limitHits = (int)$request->getBodyParam('limitHits', 0);

        if (!Typesense::$plugin->getKeys()->saveProfile($profile)) {
            return $this->asModelFailure($profile, Craft::t('typesense', 'Could not save the profile.'), 'profile');
        }

        return $this->asModelSuccess($profile, Craft::t('typesense', 'Profile saved.'), 'profile', [], 'typesense/keys');
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the Typesense key-creation schema from the posted form.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _keySchema(): array
    {
        $request = $this->request;
        $actions = $request->getBodyParam('actions', []);
        $collections = $request->getBodyParam('collections', []);

        $schema = [
            'description' => trim((string)$request->getBodyParam('description', '')) ?: 'Created in the Craft control panel',
            'actions' => is_array($actions) ? array_values(array_filter(array_map('strval', $actions))) : [],
            'collections' => is_array($collections) && $collections !== [] ? array_values(array_map('strval', $collections)) : ['*'],
        ];

        $expiresIn = (int)$request->getBodyParam('expiresIn', 0);

        if ($expiresIn > 0) {
            $schema['expires_at'] = DateTimeHelper::currentTimeStamp() + $expiresIn;
        }

        return $schema;
    }
}
