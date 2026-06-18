<?php

namespace launchmind\blog\variables;

use launchmind\blog\Plugin;

/**
 * Template-facing API, available as `craft.launchmind` in any site template.
 *
 * For site owners who want full control over markup, pull the data directly:
 *
 *   {% set posts = craft.launchmind.posts({ limit: 6 }) %}
 *   {% for post in posts %}
 *     <a href="/{{ craft.launchmind.blogPath }}/{{ post.slug }}">{{ post.title }}</a>
 *   {% endfor %}
 */
class LaunchmindVariable
{
    /**
     * @return array<int,array> Normalized posts.
     */
    public function posts(array $args = []): array
    {
        return Plugin::getInstance()->api->getPosts($args);
    }

    public function post(string $slug, ?string $lang = null): ?array
    {
        return Plugin::getInstance()->api->getPost($slug, $lang);
    }

    public function blogPath(): string
    {
        return Plugin::getInstance()->getSettings()->getBlogPath();
    }
}
