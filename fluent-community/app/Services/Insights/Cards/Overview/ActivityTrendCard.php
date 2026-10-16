<?php

namespace FluentCommunity\App\Services\Insights\Cards\Overview;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Metrics\ContentMetrics;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class ActivityTrendCard extends AbstractCard
{
    public function key()
    {
        return 'overview.activity';
    }

    public function page()
    {
        return 'overview';
    }

    public function type()
    {
        return 'timeseries';
    }

    public function title()
    {
        return __('Activity', 'fluent-community');
    }

    public function description()
    {
        return __('Posts, comments and reactions per period, each on its own scale. Metrics: content.posts, content.comments, content.reactions.', 'fluent-community');
    }

    public function size()
    {
        return 'two_thirds';
    }

    public function order()
    {
        return 20;
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $bucketer = new Bucketer($range);
        $comparison = $this->comparisonRange($range, $options);
        $previousBucketer = $comparison ? new Bucketer($comparison, $bucketer->granularity(), $bucketer->weekStart()) : null;
        $length = count($bucketer->keys());

        $labels = [
            'posts'     => __('Posts', 'fluent-community'),
            'comments'  => __('Comments', 'fluent-community'),
            'reactions' => __('Reactions', 'fluent-community'),
        ];

        $series = [];
        foreach ($labels as $kind => $label) {
            $series[] = [
                'key'      => 'content.' . $kind,
                'label'    => $label,
                'values'   => $bucketer->fill(ContentMetrics::byBucket($kind, $range, $scope, $bucketer)),
                'previous' => $comparison ? $this->alignTo($previousBucketer->fill(ContentMetrics::byBucket($kind, $comparison, $scope, $previousBucketer)), $length) : null,
            ];
        }

        return $this->timeseries($bucketer, $series, 'count', ['chart' => 'line', 'layout' => 'multiples']);
    }
}
