<?php

namespace FluentCommunity\Modules\Mcp\Tools;

use FluentCommunity\App\Services\Search\SearchService;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Mcp\Support\McpHelper;
use FluentCommunity\Modules\Mcp\Support\RestBridge;

defined('ABSPATH') || exit;

class SearchTools
{
    public static function search($params)
    {
        $term = trim((string) Arr::get($params, 'q', ''));

        if (mb_strlen($term) < 2) {
            return McpHelper::error('invalid_param', 'q needs at least 2 characters.', [ 'fields' => [ 'q' ] ]);
        }

        $available = array_keys(SearchService::getProviders());
        $types = array_values(array_filter((array) Arr::get($params, 'types', [])));

        if (!$types) {
            $types = $available;
        }

        $unknown = array_values(array_diff($types, $available));
        if ($unknown) {
            return McpHelper::error('invalid_param', 'types has an unknown value: ' . implode(', ', $unknown) . '.', [
                'fields'  => [ 'types' ],
                'allowed' => $available,
            ]);
        }

        $result = RestBridge::call('GET', '/search', array_filter([
            'q'        => $term,
            'types'    => $types,
            'per_type' => min(SearchService::MAX_PER_TYPE, max(1, (int) Arr::get($params, 'per_type', 5))),
            'page'     => max(1, (int) Arr::get($params, 'page', 1)),
            'space'    => sanitize_text_field((string) Arr::get($params, 'space', '')),
            'sort'     => Arr::get($params, 'sort', 'relevance'),
        ]));

        if (is_wp_error($result)) {
            return $result;
        }

        $data = [];
        $hasMore = [];
        $total = 0;

        foreach ((array) Arr::get($result, 'results', []) as $type => $group) {
            $items = (array) Arr::get($group, 'items', []);
            $data[$type] = array_map([ self::class, 'item' ], $items);
            $total += count($items);

            if (Arr::get($group, 'has_more')) {
                $hasMore[] = $type;
            }
        }

        return McpHelper::envelope(
            $total ? sprintf('%d results for "%s".', $total, $term) : sprintf('No results for "%s".', $term),
            $data,
            [
                'has_more'        => $hasMore,
                'content_warning' => McpHelper::CONTENT_WARNING,
            ]
        );
    }

    /**
     * Search items differ per type; this keeps the fields an agent can act on.
     */
    private static function item($item)
    {
        $row = array_filter([
            'id'       => (int) Arr::get($item, 'id', Arr::get($item, 'user_id', 0)),
            'title'    => Arr::get($item, 'title'),
            'name'     => Arr::get($item, 'display_name'),
            'username' => Arr::get($item, 'username'),
            'slug'     => Arr::get($item, 'slug'),
            'privacy'  => Arr::get($item, 'privacy'),
            'snippet'  => McpHelper::excerpt((string) Arr::get($item, 'snippet', '')),
            'space'    => Arr::get($item, 'space.slug'),
            'author'   => Arr::get($item, 'author.username'),
            'created'  => McpHelper::iso(Arr::get($item, 'created_at')),
        ]);

        if (Arr::get($item, 'matched_comment.id')) {
            $row['matched_comment'] = [
                'id'      => (int) Arr::get($item, 'matched_comment.id'),
                'snippet' => McpHelper::excerpt((string) Arr::get($item, 'matched_comment.snippet', '')),
            ];
        }

        return $row;
    }
}
