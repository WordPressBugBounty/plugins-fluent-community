<?php

namespace FluentCommunity\Modules\Course\Services\Insights;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Metrics\Sql;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;
use FluentCommunity\Modules\Course\Services\CourseHelper;

/**
 * course.* metrics (docs/reporting/03). A course is an fcom_spaces row with
 * type = 'course'; students are its space_user rows with role = 'student'.
 */
class CourseMetrics
{
    /** Students enrolled at least this long before the range end form the completion-rate denominator. */
    const COMPLETION_WINDOW_DAYS = 30;

    /**
     * @param Scope $scope course_id narrows to one course
     * @return array rows: id, title, slug, status
     */
    public static function courses(Scope $scope)
    {
        $spaces = Sql::table('fcom_spaces');
        $where = "s.type = 'course'" . ($scope->courseId() ? ' AND s.id = ' . $scope->courseId() : '');

        return Sql::rows("SELECT s.id, s.title, s.slug, s.status FROM {$spaces} s WHERE {$where} ORDER BY s.id ASC");
    }

    /**
     * course.students: active students at the end of the range.
     *
     * @return array course id => count
     */
    public static function students(Range $range, Scope $scope, array $courseIds)
    {
        return self::perCourse(
            'SELECT su.space_id AS id, COUNT(*) AS n FROM ' . Sql::table('fcom_space_user') . " su
             WHERE su.space_id IN (%ids%) AND su.role = 'student' AND su.status = 'active' AND su.created_at < '" . $range->sqlToExclusive() . "'"
            . Sql::notExcluded('su.user_id', $scope) . ' GROUP BY su.space_id',
            $courseIds
        );
    }

    /**
     * course.enrollments: students who enrolled during the range.
     *
     * @return array course id => count
     */
    public static function enrollments(Range $range, Scope $scope, array $courseIds)
    {
        return self::perCourse(
            'SELECT su.space_id AS id, COUNT(*) AS n FROM ' . Sql::table('fcom_space_user') . " su
             WHERE su.space_id IN (%ids%) AND su.role = 'student' AND " . Sql::inRange('su.created_at', $range)
            . Sql::notExcluded('su.user_id', $scope) . ' GROUP BY su.space_id',
            $courseIds
        );
    }

    /**
     * course.enrollments per bucket, over every course or the scope's course.
     *
     * @return array bucket key => count
     */
    public static function enrollmentsByBucket(Range $range, Scope $scope, Bucketer $bucketer)
    {
        $members = Sql::table('fcom_space_user');
        $spaces = Sql::table('fcom_spaces');
        $bucket = $bucketer->sqlExpression('su.created_at');
        $course = $scope->courseId() ? ' AND su.space_id = ' . $scope->courseId() : '';

        return Sql::keyed(
            "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$members} su
             INNER JOIN {$spaces} s ON s.id = su.space_id AND s.type = 'course'
             WHERE su.role = 'student' AND " . Sql::inRange('su.created_at', $range) . $course . Sql::notExcluded('su.user_id', $scope) . '
             GROUP BY k'
        );
    }

    /**
     * course.completions: course_completed activities in the range. The
     * activity keeps the course id in feed_id.
     *
     * @return array course id => count of distinct students
     */
    public static function completions(Range $range, Scope $scope, array $courseIds)
    {
        return self::perCourse(
            'SELECT a.feed_id AS id, COUNT(DISTINCT a.user_id) AS n FROM ' . Sql::table('fcom_user_activities') . " a
             WHERE a.action_name = 'course_completed' AND a.feed_id IN (%ids%) AND " . Sql::inRange('a.created_at', $range)
            . Sql::notExcluded('a.user_id', $scope) . ' GROUP BY a.feed_id',
            $courseIds
        );
    }

    /**
     * course.completion_rate: of the active students who enrolled at least
     * 30 days before the range end, the share who completed by then.
     *
     * @return array course id => fraction (0..1) or null when nobody qualifies
     */
    public static function completionRates(Range $range, Scope $scope, array $courseIds)
    {
        $enrolledBefore = $range->to()->modify('-' . self::COMPLETION_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');
        $activities = Sql::table('fcom_user_activities');

        $rows = self::rowsPerCourse(
            'SELECT su.space_id AS id, COUNT(*) AS eligible,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM ' . $activities . " a WHERE a.action_name = 'course_completed' AND a.feed_id = su.space_id AND a.user_id = su.user_id AND a.created_at < '" . $range->sqlToExclusive() . "'
                ) THEN 1 ELSE 0 END) AS completed
             FROM " . Sql::table('fcom_space_user') . " su
             WHERE su.space_id IN (%ids%) AND su.role = 'student' AND su.status = 'active' AND su.created_at < '{$enrolledBefore}'"
            . Sql::notExcluded('su.user_id', $scope) . ' GROUP BY su.space_id',
            $courseIds
        );

        $rates = [];
        foreach ($rows as $row) {
            $rates[(int)$row['id']] = (int)$row['eligible'] ? round((int)$row['completed'] / (int)$row['eligible'], 4) : null;
        }

        return $rates;
    }

    /**
     * course.avg_progress: mean of each active student's progress, using the
     * same formula as the course pages (CourseHelper::getBulkCourseProgress).
     *
     * @param int $courseId
     * @param Range $range
     * @param Scope $scope
     * @return float|null percent, null without students
     */
    public static function averageProgress($courseId, Range $range, Scope $scope)
    {
        $userIds = array_map('intval', array_column(Sql::rows(
            'SELECT su.user_id FROM ' . Sql::table('fcom_space_user') . ' su
             WHERE su.space_id = ' . (int)$courseId . " AND su.role = 'student' AND su.status = 'active' AND su.created_at < '" . $range->sqlToExclusive() . "'"
            . Sql::notExcluded('su.user_id', $scope)
        ), 'user_id'));

        if (!$userIds) {
            return null;
        }

        $total = 0;
        foreach (array_chunk($userIds, 1000) as $chunk) {
            $total += array_sum(CourseHelper::getBulkCourseProgress((int)$courseId, $chunk));
        }

        return round($total / count($userIds), 1);
    }

    private static function perCourse($sql, array $courseIds)
    {
        $map = [];
        foreach (self::rowsPerCourse($sql, $courseIds) as $row) {
            $map[(int)$row['id']] = (int)$row['n'];
        }

        return $map;
    }

    private static function rowsPerCourse($sql, array $courseIds)
    {
        $courseIds = array_filter(array_map('intval', $courseIds));
        if (!$courseIds) {
            return [];
        }

        return Sql::rows(str_replace('%ids%', implode(',', $courseIds), $sql));
    }
}
