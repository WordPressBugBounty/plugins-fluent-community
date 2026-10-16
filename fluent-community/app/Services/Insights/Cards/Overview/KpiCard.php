<?php

namespace FluentCommunity\App\Services\Insights\Cards\Overview;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Metrics\ContentMetrics;
use FluentCommunity\App\Services\Insights\Metrics\MemberMetrics;
use FluentCommunity\App\Services\Insights\Metrics\ResponseMetrics;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class KpiCard extends AbstractCard
{
    public function key()
    {
        return 'overview.kpis';
    }

    public function page()
    {
        return 'overview';
    }

    public function type()
    {
        return 'kpi';
    }

    public function title()
    {
        return __('Community health', 'fluent-community');
    }

    public function description()
    {
        return __('Active members, new members, posts, comments, reactions and the share of posts that got a reply, compared with the previous period. Metrics: members.active, members.new, content.posts, content.comments, content.reactions, response.reply_rate.', 'fluent-community');
    }

    public function order()
    {
        return 10;
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $bucketer = new Bucketer($range);
        $comparison = $this->comparisonRange($range, $options);
        $previousBucketer = $comparison ? new Bucketer($comparison, $bucketer->granularity(), $bucketer->weekStart()) : null;

        // Same definition on both sides: visits only when tracking covers both.
        $withVisits = !$scope->spaceId() && MemberMetrics::visitsCover($comparison ? $comparison : $range) && MemberMetrics::visitsCover($range);

        $active = MemberMetrics::activeByBucket($range, $scope, $bucketer, $withVisits);
        $previousActive = $comparison ? MemberMetrics::activeByBucket($comparison, $scope, $previousBucketer, $withVisits)['total'] : null;

        $items = [
            $this->kpiItem(
                'members.active',
                $withVisits ? __('Active members', 'fluent-community') : __('Contributing members', 'fluent-community'),
                $active['total'],
                $previousActive,
                'number',
                'count',
                $bucketer->fill($active['buckets'])
            ),
        ];

        $newMembers = MemberMetrics::newByBucket($range, $scope, $bucketer);
        $items[] = $this->kpiItem(
            'members.new',
            __('New members', 'fluent-community'),
            array_sum($newMembers),
            $comparison ? array_sum(MemberMetrics::newByBucket($comparison, $scope, $previousBucketer)) : null,
            'number',
            'count',
            $bucketer->fill($newMembers)
        );

        $labels = [
            'posts'     => __('Posts', 'fluent-community'),
            'comments'  => __('Comments', 'fluent-community'),
            'reactions' => __('Reactions', 'fluent-community'),
        ];

        foreach ($labels as $kind => $label) {
            $current = ContentMetrics::byBucket($kind, $range, $scope, $bucketer);
            $items[] = $this->kpiItem(
                'content.' . $kind,
                $label,
                array_sum($current),
                $comparison ? array_sum(ContentMetrics::byBucket($kind, $comparison, $scope, $previousBucketer)) : null,
                'number',
                'count',
                $bucketer->fill($current)
            );
        }

        $response = ResponseMetrics::stats($range, $scope);
        $items[] = $this->kpiItem(
            'response.reply_rate',
            __('Posts answered', 'fluent-community'),
            $response['reply_rate'],
            $comparison ? ResponseMetrics::stats($comparison, $scope)['reply_rate'] : null,
            'percent',
            'rate'
        );

        return ['items' => $items];
    }
}
