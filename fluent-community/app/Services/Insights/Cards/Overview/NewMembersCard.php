<?php

namespace FluentCommunity\App\Services\Insights\Cards\Overview;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Metrics\MemberMetrics;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class NewMembersCard extends AbstractCard
{
    public function key()
    {
        return 'overview.new_members';
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
        return __('New members', 'fluent-community');
    }

    public function description()
    {
        return __('Members who joined in each period. The headline is the number of active members at the end of the range. Metrics: members.new, members.total.', 'fluent-community');
    }

    public function size()
    {
        return 'half';
    }

    public function order()
    {
        return 50;
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $bucketer = new Bucketer($range);
        $comparison = $this->comparisonRange($range, $options);
        $previous = null;

        if ($comparison) {
            $previousBucketer = new Bucketer($comparison, $bucketer->granularity(), $bucketer->weekStart());
            $previous = $this->alignTo($previousBucketer->fill(MemberMetrics::newByBucket($comparison, $scope, $previousBucketer)), count($bucketer->keys()));
        }

        return $this->timeseries($bucketer, [
            [
                'key'      => 'members.new',
                'label'    => __('New members', 'fluent-community'),
                'values'   => $bucketer->fill(MemberMetrics::newByBucket($range, $scope, $bucketer)),
                'previous' => $previous,
            ],
        ], 'count', [
            'chart'    => 'column',
            'headline' => [
                'label'  => __('Total members', 'fluent-community'),
                'value'  => MemberMetrics::totalAt($range->to(), $scope),
                'format' => 'number',
            ],
        ]);
    }
}
