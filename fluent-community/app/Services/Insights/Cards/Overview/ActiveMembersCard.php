<?php

namespace FluentCommunity\App\Services\Insights\Cards\Overview;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Metrics\MemberMetrics;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class ActiveMembersCard extends AbstractCard
{
    public function key()
    {
        return 'overview.active_members';
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
        return __('Active members', 'fluent-community');
    }

    public function description()
    {
        return __('Distinct members who posted, commented, reacted, completed a lesson or sent a message in each period, plus those who visited once visit tracking covers the period. The headline counts each member once over the whole range. Metric: members.active.', 'fluent-community');
    }

    public function size()
    {
        return 'half';
    }

    public function order()
    {
        return 40;
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $bucketer = new Bucketer($range);
        $comparison = $this->comparisonRange($range, $options);

        // Same definition on both sides; visits are not space-specific.
        $withVisits = !$scope->spaceId() && MemberMetrics::visitsCover($range) && MemberMetrics::visitsCover($comparison ? $comparison : $range);
        $active = MemberMetrics::activeByBucket($range, $scope, $bucketer, $withVisits);

        $previous = null;
        if ($comparison) {
            $previousBucketer = new Bucketer($comparison, $bucketer->granularity(), $bucketer->weekStart());
            $previousActive = MemberMetrics::activeByBucket($comparison, $scope, $previousBucketer, $withVisits);
            $previous = $this->alignTo($previousBucketer->fill($previousActive['buckets']), count($bucketer->keys()));
        }

        $label = $withVisits ? __('Active members', 'fluent-community') : __('Contributing members', 'fluent-community');

        return $this->timeseries($bucketer, [
            [
                'key'      => 'members.active',
                'label'    => $label,
                'values'   => $bucketer->fill($active['buckets']),
                'previous' => $previous,
            ],
        ], 'count', [
            'chart'          => 'area',
            'headline'       => ['label' => $label, 'value' => $active['total'], 'format' => 'number'],
            'visits_tracked' => $withVisits,
        ]);
    }
}
