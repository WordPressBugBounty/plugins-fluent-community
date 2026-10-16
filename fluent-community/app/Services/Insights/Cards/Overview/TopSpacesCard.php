<?php

namespace FluentCommunity\App\Services\Insights\Cards\Overview;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Metrics\SpaceMetrics;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class TopSpacesCard extends AbstractCard
{
    public function key()
    {
        return 'overview.top_spaces';
    }

    public function page()
    {
        return 'overview';
    }

    public function type()
    {
        return 'table';
    }

    public function title()
    {
        return __('Top spaces', 'fluent-community');
    }

    public function description()
    {
        return __('The five spaces with the most posts in the range, and how many members took part in each (posted, commented or reacted). Metrics: space.posts, space.contributors.', 'fluent-community');
    }

    public function order()
    {
        return 60;
    }

    public function supports()
    {
        return ['export'];
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $rows = array_map(function ($row) {
            return [
                'space_id'     => $row['space_id'],
                'title'        => $row['title'],
                'link'         => Helper::baseUrl('space/' . $row['slug'] . '/home'),
                'posts'        => $row['posts'],
                'contributors' => $row['contributors'],
            ];
        }, SpaceMetrics::topByPosts($range, $scope->withSpace(null), 5));

        return [
            'columns'  => [
                ['key' => 'title', 'label' => __('Space', 'fluent-community'), 'type' => 'link', 'link_key' => 'link'],
                ['key' => 'posts', 'label' => __('Posts', 'fluent-community'), 'type' => 'bar'],
                ['key' => 'contributors', 'label' => __('Contributors', 'fluent-community'), 'type' => 'number'],
            ],
            'layout'   => 'ranked',
            'rows'     => $rows,
            'total'    => count($rows),
            'page'     => 1,
            'per_page' => 5,
        ];
    }
}
