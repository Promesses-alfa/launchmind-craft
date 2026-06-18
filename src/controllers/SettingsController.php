<?php

namespace launchmind\blog\controllers;

use Craft;
use craft\web\Controller;
use launchmind\blog\Plugin;
use yii\web\Response;

/**
 * Control-panel AJAX endpoints for the settings page.
 */
class SettingsController extends Controller
{
    /**
     * "Test connection" button on the settings page. Validates the currently
     * SAVED credentials against the Launchmind API.
     */
    public function actionTestConnection(): Response
    {
        $this->requireAcceptsJson();
        $this->requireAdmin();

        [$success, $message] = Plugin::getInstance()->api->testConnection();

        return $this->asJson([
            'success' => $success,
            'message' => $message,
        ]);
    }

    /**
     * "Clear cache" button on the settings page.
     */
    public function actionClearCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        Plugin::getInstance()->cache->clearAll();

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('launchmind-blog', 'Cache cleared.'),
        ]);
    }
}
