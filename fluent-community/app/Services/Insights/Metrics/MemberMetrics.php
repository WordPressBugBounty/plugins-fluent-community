<?php

namespace FluentCommunity\App\Services\Insights\Metrics;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;
use FluentCommunity\Database\Migrations\InsightsMigrator;

/**
 * members.new, members.total, members.active, members.contributing,
 * members.pending (docs/reporting/03).
 */
class MemberMetrics
{
    /**
     * members.new: profiles created in the range (any status). With a space,
     * memberships of that space created in the range.
     *
     * @return array bucket key => count
     */
    public static function newByBucket(Range $range, Scope $scope, Bucketer $bucketer)
    {
        if ($scope->spaceId()) {
            $members = Sql::table('fcom_space_user');
            $bucket = $bucketer->sqlExpression('su.created_at');

            return Sql::keyed(
                "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$members} su
                 WHERE su.space_id = " . $scope->spaceId() . ' AND ' . Sql::inRange('su.created_at', $range) . Sql::notExcluded('su.user_id', $scope) . '
                 GROUP BY k'
            );
        }

        $profiles = Sql::table('fcom_xprofile');
        $bucket = $bucketer->sqlExpression('x.created_at');

        return Sql::keyed(
            "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$profiles} x
             WHERE " . Sql::inRange('x.created_at', $range) . Sql::notExcluded('x.user_id', $scope) . '
             GROUP BY k'
        );
    }

    /**
     * members.total: active members who existed at the end of $date. A point
     * in time, not a count "in range". With a space: active memberships.
     *
     * @param \DateTimeImmutable $date local date
     * @param Scope $scope
     * @return int
     */
    public static function totalAt(\DateTimeImmutable $date, Scope $scope)
    {
        $before = $date->modify('+1 day')->format('Y-m-d 00:00:00');

        if ($scope->spaceId()) {
            $members = Sql::table('fcom_space_user');

            return (int)Sql::value(
                "SELECT COUNT(*) FROM {$members} su
                 WHERE su.space_id = " . $scope->spaceId() . " AND su.status = 'active' AND su.created_at < '{$before}'" . Sql::notExcluded('su.user_id', $scope)
            );
        }

        $profiles = Sql::table('fcom_xprofile');

        return (int)Sql::value(
            "SELECT COUNT(*) FROM {$profiles} x
             WHERE x.status = 'active' AND x.created_at < '{$before}'" . Sql::notExcluded('x.user_id', $scope)
        );
    }

    /**
     * members.active: distinct members who contributed (F_CONTRIB) or, once
     * visit tracking covers the day, visited. members.contributing when
     * $withVisits is false.
     *
     * @param Range $range
     * @param Scope $scope
     * @param Bucketer $bucketer
     * @param bool $withVisits
     * @return array ['buckets' => key => distinct count, 'total' => distinct over the range]
     */
    public static function activeByBucket(Range $range, Scope $scope, Bucketer $bucketer, $withVisits = true)
    {
        $union = self::contributionUnion($range, $scope);

        if ($withVisits && !$scope->spaceId()) {
            $daily = Sql::table('fcom_activity_daily');
            $union[] = "SELECT d.user_id, d.stat_date AS created_at FROM {$daily} d
                        WHERE d.visits > 0 AND d.stat_date >= '" . $range->fromDate() . "' AND d.stat_date <= '" . $range->toDate() . "'";
        }

        $bucket = $bucketer->sqlExpression('a.created_at');

        $rows = Sql::rows(
            "SELECT {$bucket} AS k, COUNT(DISTINCT a.user_id) AS n FROM (" . implode(' UNION ALL ', $union) . ") a
             WHERE a.user_id IS NOT NULL AND a.user_id > 0" . Sql::notExcluded('a.user_id', $scope) . '
             GROUP BY k WITH ROLLUP'
        );

        $buckets = [];
        $total = 0;
        foreach ($rows as $row) {
            if ($row['k'] === null) {
                $total = (int)$row['n'];
                continue;
            }
            $buckets[$row['k']] = (int)$row['n'];
        }

        return ['buckets' => $buckets, 'total' => $total];
    }

    /**
     * True when visits were tracked for every day of the range, so "active"
     * means "visited or contributed" for the whole of it.
     *
     * @param Range $range
     * @return bool
     */
    public static function visitsCover(Range $range)
    {
        $since = get_option(InsightsMigrator::TRACKING_SINCE_OPTION);

        return $since && $since <= $range->fromDate();
    }

    /**
     * members.pending: waiting for approval now. With a space: join requests.
     *
     * @param Scope $scope
     * @return int
     */
    public static function pendingCount(Scope $scope)
    {
        if ($scope->spaceId()) {
            $members = Sql::table('fcom_space_user');

            return (int)Sql::value("SELECT COUNT(*) FROM {$members} su WHERE su.space_id = " . $scope->spaceId() . " AND su.status = 'pending'");
        }

        $profiles = Sql::table('fcom_xprofile');

        return (int)Sql::value("SELECT COUNT(*) FROM {$profiles} x WHERE x.status = 'pending'");
    }

    /**
     * F_CONTRIB rows as (user_id, created_at) selects, one per source.
     *
     * @param Range $range
     * @param Scope $scope
     * @return string[]
     */
    public static function contributionUnion(Range $range, Scope $scope)
    {
        $posts = Sql::table('fcom_posts');
        $comments = Sql::table('fcom_post_comments');
        $reactions = Sql::table('fcom_post_reactions');

        if ($scope->spaceId()) {
            $spaceId = $scope->spaceId();

            return [
                "SELECT p.user_id, p.created_at FROM {$posts} p WHERE " . Sql::isPost('p') . ' AND ' . Sql::inRange('p.created_at', $range) . " AND p.space_id = {$spaceId}",
                "SELECT c.user_id, c.created_at FROM {$comments} c INNER JOIN {$posts} p ON p.id = c.post_id AND p.space_id = {$spaceId} WHERE " . Sql::isComment('c') . ' AND ' . Sql::inRange('c.created_at', $range),
                "SELECT r.user_id, r.created_at FROM {$reactions} r INNER JOIN {$posts} p ON p.id = " . Sql::reactionPostId('r') . " AND p.space_id = {$spaceId} WHERE " . Sql::isReaction('r') . ' AND ' . Sql::inRange('r.created_at', $range),
            ];
        }

        $union = [
            "SELECT p.user_id, p.created_at FROM {$posts} p WHERE " . Sql::isPost('p') . ' AND ' . Sql::inRange('p.created_at', $range),
            "SELECT c.user_id, c.created_at FROM {$comments} c WHERE " . Sql::isComment('c') . ' AND ' . Sql::inRange('c.created_at', $range),
            "SELECT r.user_id, r.created_at FROM {$reactions} r WHERE (" . Sql::isReaction('r') . ' OR ' . Sql::isLessonCompletion('r') . ') AND ' . Sql::inRange('r.created_at', $range),
        ];

        if (Sql::hasChatTable()) {
            $union[] = 'SELECT m.user_id, m.created_at FROM ' . Sql::table('fcom_chat_messages') . ' m WHERE ' . Sql::inRange('m.created_at', $range);
        }

        return $union;
    }
}
