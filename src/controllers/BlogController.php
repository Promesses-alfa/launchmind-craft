<?php

namespace launchmind\blog\controllers;

use Craft;
use craft\web\Controller;
use launchmind\blog\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end rendering for the bundled blog routes registered in Plugin.php:
 *   /<blogPath>            → actionIndex (paginated list)
 *   /<blogPath>/<slug>     → actionPost  (single article)
 *
 * Both render templates under the `launchmind-blog` namespace, which site
 * owners can override by copying them into templates/launchmind-blog/ in their
 * own project.
 */
class BlogController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    private const PER_PAGE = 12;

    public function actionIndex(): Response
    {
        $page = max(1, (int) Craft::$app->getRequest()->getParam('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $posts = Plugin::getInstance()->api->getPosts([
            'limit' => self::PER_PAGE,
            'offset' => $offset,
        ]);

        return $this->renderTemplate('launchmind-blog/blog/index', [
            'posts' => $posts,
            'page' => $page,
            'hasNextPage' => count($posts) === self::PER_PAGE,
            'blogPath' => Plugin::getInstance()->getSettings()->getBlogPath(),
        ]);
    }

    public function actionPost(string $slug): Response
    {
        $post = Plugin::getInstance()->api->getPost($slug);

        if ($post === null) {
            throw new NotFoundHttpException(Craft::t('launchmind-blog', 'Article not found.'));
        }

        // Server-side page-view beacon (best-effort, non-blocking, bot-filtered).
        Plugin::getInstance()->api->trackView($post['slug'] ?: $slug);

        return $this->renderTemplate('launchmind-blog/blog/post', [
            'post' => $post,
            'blogPath' => Plugin::getInstance()->getSettings()->getBlogPath(),
            'canonical' => Craft::$app->getRequest()->getAbsoluteUrl(),
        ]);
    }
}
