<?php

namespace FluentCommunity\Modules\Course\Services\Insights;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

class CourseSummaryCard extends AbstractCard
{
    public function key()
    {
        return 'courses.summary';
    }

    public function page()
    {
        return 'courses';
    }

    public function type()
    {
        return 'table';
    }

    public function title()
    {
        return __('Courses', 'fluent-community');
    }

    public function description()
    {
        return __('For each course: active students at the end of the range, enrollments and completions in the range, the share of students enrolled for 30+ days who completed, and average progress. Metrics: course.students, course.enrollments, course.completions, course.completion_rate, course.avg_progress.', 'fluent-community');
    }

    public function order()
    {
        return 10;
    }

    public function supports()
    {
        return ['course_id', 'export'];
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        $courses = CourseMetrics::courses($scope);
        $courseIds = array_map('intval', array_column($courses, 'id'));

        $students = CourseMetrics::students($range, $scope, $courseIds);
        $enrollments = CourseMetrics::enrollments($range, $scope, $courseIds);
        $completions = CourseMetrics::completions($range, $scope, $courseIds);
        $rates = CourseMetrics::completionRates($range, $scope, $courseIds);

        $rows = [];
        foreach ($courses as $course) {
            $courseId = (int)$course['id'];
            $rows[] = [
                'course_id'       => $courseId,
                'title'           => $course['title'],
                'link'            => Helper::baseUrl('course/' . $course['slug']),
                'status'          => $course['status'],
                'students'        => isset($students[$courseId]) ? $students[$courseId] : 0,
                'enrollments'     => isset($enrollments[$courseId]) ? $enrollments[$courseId] : 0,
                'completions'     => isset($completions[$courseId]) ? $completions[$courseId] : 0,
                'completion_rate' => isset($rates[$courseId]) ? $rates[$courseId] : null,
                'avg_progress'    => CourseMetrics::averageProgress($courseId, $range, $scope),
            ];
        }

        usort($rows, function ($first, $second) {
            if ($second['students'] !== $first['students']) {
                return $second['students'] - $first['students'];
            }

            return $first['course_id'] - $second['course_id'];
        });

        return [
            'columns'  => [
                ['key' => 'title', 'label' => __('Course', 'fluent-community'), 'type' => 'link', 'link_key' => 'link'],
                ['key' => 'students', 'label' => __('Students', 'fluent-community'), 'type' => 'number', 'sortable' => true],
                ['key' => 'enrollments', 'label' => __('Enrollments', 'fluent-community'), 'type' => 'number', 'sortable' => true],
                ['key' => 'completions', 'label' => __('Completions', 'fluent-community'), 'type' => 'number', 'sortable' => true],
                ['key' => 'completion_rate', 'label' => __('Completion rate', 'fluent-community'), 'type' => 'percent', 'sortable' => true],
                ['key' => 'avg_progress', 'label' => __('Average progress', 'fluent-community'), 'type' => 'progress', 'sortable' => true],
            ],
            'rows'     => $rows,
            'total'    => count($rows),
            'page'     => 1,
            'per_page' => count($rows),
        ];
    }
}
