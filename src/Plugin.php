<?php
/**
 * Launchmind Blog plugin for Craft CMS 5.x
 *
 * Pulls a customer's Launchmind-generated articles from the Launchmind API and
 * renders them on the Craft site at /<blogPath> (list) and /<blogPath>/<slug>
 * (single). Articles are NOT stored as Craft entries — they are fetched and
 * cached, so the customer's Craft database stays clean and content always
 * reflects the latest from Launchmind.
 *
 * @link      https://launchmind.io
 * @copyright Copyright (c) Launchmind
 */

namespace launchmind\blog;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use launchmind\blog\models\Settings;
use launchmind\blog\services\Api;
use launchmind\blog\services\Cache;
use launchmind\blog\variables\LaunchmindVariable;
use yii\base\Event;

/**
 * @property-read Api $api
 * @property-read Cache $cache
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PLATFORM = 'craft';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    /**
     * Register the plugin's services as Yii components so they are reachable
     * via Plugin::getInstance()->api and ->cache.
     */
    public static function config(): array
    {
        return [
            'components' => [
                'api' => Api::class,
                'cache' => Cache::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerTemplateRoots();
        $this->registerVariable();
        $this->registerSiteRoutes();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('launchmind-blog/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    /**
     * Expose the plugin's front-end templates under the `launchmind-blog`
     * namespace so the controller can render them, and so site owners can
     * override any of them by creating templates/launchmind-blog/... in their
     * own project (project roots take precedence over the plugin's).
     */
    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['launchmind-blog'] = __DIR__ . '/templates';
            }
        );
    }

    /**
     * Register `craft.launchmind.*` for template authors who want to pull the
     * raw data into their own templates instead of using the bundled routes.
     */
    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('launchmind', LaunchmindVariable::class);
            }
        );
    }

    /**
     * Map the configured blog path to the bundled rendering controller.
     * Site owners who only use the `craft.launchmind` variable in their own
     * templates can leave these routes unused without harm.
     */
    private function registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $base = $this->getSettings()->getBlogPath();
                $event->rules[$base] = 'launchmind-blog/blog/index';
                $event->rules[$base . '/<slug:[^\/]+>'] = 'launchmind-blog/blog/post';
            }
        );
    }
}
