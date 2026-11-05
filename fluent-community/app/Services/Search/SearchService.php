<?php

namespace FluentCommunity\App\Services\Search;

/**
 * Runs the global search. Each result type comes from a provider registered on
 * the fluent_community/search/providers filter, so a module adds its own type
 * only while it is active (the Course module adds "courses").
 */
class SearchService
{
    const MAX_PER_TYPE = 20;

    const MAX_PAGE = 10;

    const SORTS = [ 'relevance', 'latest', 'popular' ];

    /**
     * @return array<string, callable> Result type => callable(SearchQuery $query, $user, array $args): array
     */
    public static function getProviders()
    {
        $posts = new Providers\PostSearchProvider();

        $providers = apply_filters('fluent_community/search/providers', [
            'posts'    => [ $posts, 'searchPosts' ],
            'comments' => [ $posts, 'searchComments' ],
            'people'   => [ new Providers\MemberSearchProvider(), 'search' ],
            'spaces'   => [ new Providers\SpaceSearchProvider(), 'search' ],
        ]);

        return is_array($providers) ? array_filter($providers, 'is_callable') : [];
    }

    /**
     * @param SearchQuery $query
     * @param string[] $types
     * @param \FluentCommunity\App\Models\User|null $user
     * @param int $perType
     * @param array $options { page: int, space: string (space slug, posts and comments only), sort: relevance|latest|popular }
     * @return array
     */
    public static function search(SearchQuery $query, array $types, $user, $perType, array $options = [])
    {
        if (!$query->isValid()) {
            return [];
        }

        $sort = isset($options['sort']) && in_array($options['sort'], self::SORTS, true) ? $options['sort'] : 'relevance';
        $args = [
            'limit' => max(1, min(self::MAX_PER_TYPE, (int) $perType)),
            'page'  => max(1, min(self::MAX_PAGE, (int) ($options['page'] ?? 1))),
            'space' => isset($options['space']) ? sanitize_title($options['space']) : '',
            'sort'  => $sort,
        ];

        $providers = self::getProviders();
        $results = [];

        foreach (array_unique($types) as $type) {
            if (!isset($providers[$type])) {
                continue;
            }

            $result = call_user_func($providers[$type], $query, $user, $args);

            // The last allowed page never offers another one
            $results[$type] = $args['page'] >= self::MAX_PAGE ? self::withoutMore($result) : $result;
        }

        return $results;
    }

    private static function withoutMore($result)
    {
        if (!is_array($result)) {
            return $result;
        }

        if (array_key_exists('has_more', $result)) {
            $result['has_more'] = false;
        }

        foreach ($result as $key => $value) {
            if (is_array($value) && array_key_exists('has_more', $value)) {
                $result[$key]['has_more'] = false;
            }
        }

        return $result;
    }
}
