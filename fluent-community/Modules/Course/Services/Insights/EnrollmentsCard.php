<?php

namespace FluentCommunity\Modules\Course\Services\Insights;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class EnrollmentsCard extends AbstractCard
{
    public function key()
    {
        return 'courses.enrollments';
    }

    public function page()
    {
        return 'courses';
    }

    public function type()
    {
        return 'timeseries';
    }

    public function title()
    {
        return __('Enrollments', 'fluent-community');
    }

    public function description()
    {
        return __('Students who enrolled in a course in each period. Metric: course.enrollments.', 'fluent-community');
    }

    public function order()
    {
        return 20;
    }

    public function supports()
    {
        return ['compare', 'course_id', 'export'];
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $bucketer = new Bucketer($range);
        $values = $bucketer->fill(CourseMetrics::enrollmentsByBucket($range, $scope, $bucketer));

        $comparison = $this->comparisonRange($range, $options);
        $previous = null;
        if ($comparison) {
            $previousBucketer = new Bucketer($comparison, $bucketer->granularity(), $bucketer->weekStart());
            $previous = $this->alignTo($previousBucketer->fill(CourseMetrics::enrollmentsByBucket($comparison, $scope, $previousBucketer)), count($values));
        }

        return $this->timeseries($bucketer, [
            [
                'key'      => 'course.enrollments',
                'label'    => __('Enrollments', 'fluent-community'),
                'values'   => $values,
                'previous' => $previous,
            ],
        ], 'count', [
            'chart'    => 'column',
            'headline' => ['label' => __('Enrollments', 'fluent-community'), 'value' => array_sum($values), 'format' => 'number'],
        ]);
    }
}
