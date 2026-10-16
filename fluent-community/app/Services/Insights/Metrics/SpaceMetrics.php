<?php

namespace FluentCommunity\App\Services\Insights\Metrics;

use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * space.posts and space.contributors (docs/reporting/03) for community spaces.
 */
class SpaceMetrics
{
    /**
     * The spaces with the most posts in the range, ranked in SQL before the
     * limit (ranking after a limit was a bug in the old reports).
     *
     * @param Range $range
     * @param Scope $scope
     * @param int $limit
     * @return array rows: space_id, title, slug, posts, contributors
     */
    public static function topByPosts(Range $range, Scope $scope, $limit = 5)
    {
        $posts = Sql::table('fcom_posts');
        $spaces = Sql::table('fcom_spaces');

        $rows = Sql::rows(
            "SELECT s.id AS space_id, s.title, s.slug, COUNT(*) AS posts
             FROM {$posts} p INNER JOIN {$spaces} s ON s.id = p.space_id AND s.type = 'community'
             WHERE " . Sql::isPost('p') . ' AND ' . Sql::inRange('p.created_at', $range) . Sql::notExcluded('p.user_id', $scope) . '
             GROUP BY s.id, s.title, s.slug
             ORDER BY posts DESC, s.id ASC
             LIMIT ' . (int)$limit
        );

        if (!$rows) {
            return [];
        }

        $contributors = self::contributors($range, $scope, array_column($rows, 'space_id'));

        foreach ($rows as &$row) {
            $row['space_id'] = (int)$row['space_id'];
            $row['posts'] = (int)$row['posts'];
            $row['contributors'] = isset($contributors[$row['space_id']]) ? $contributors[$row['space_id']] : 0;
        }

        return $rows;
    }

    /**
     * space.contributors: distinct members with a post, comment or reaction in
     * the space during the range.
     *
     * @param Range $range
     * @param Scope $scope
     * @param int[] $spaceIds
     * @return array space id => count
     */
    public static function contributors(Range $range, Scope $scope, array $spaceIds)
    {
        $spaceIds = array_filter(array_map('intval', $spaceIds));
        if (!$spaceIds) {
            return [];
        }

        $ids = implode(',', $spaceIds);
        $posts = Sql::table('fcom_posts');
        $comments = Sql::table('fcom_post_comments');
        $reactions = Sql::table('fcom_post_reactions');

        $rows = Sql::rows(
            "SELECT x.space_id, COUNT(DISTINCT x.user_id) AS n FROM (
                SELECT p.space_id, p.user_id FROM {$posts} p WHERE " . Sql::isPost('p') . ' AND ' . Sql::inRange('p.created_at', $range) . " AND p.space_id IN ({$ids})
                UNION ALL
                SELECT p.space_id, c.user_id FROM {$comments} c INNER JOIN {$posts} p ON p.id = c.post_id AND p.space_id IN ({$ids}) WHERE " . Sql::isComment('c') . ' AND ' . Sql::inRange('c.created_at', $range) . "
                UNION ALL
                SELECT p.space_id, r.user_id FROM {$reactions} r INNER JOIN {$posts} p ON p.id = " . Sql::reactionPostId('r') . " AND p.space_id IN ({$ids}) WHERE " . Sql::isReaction('r') . ' AND ' . Sql::inRange('r.created_at', $range) . '
             ) x
             WHERE x.user_id IS NOT NULL AND x.user_id > 0' . Sql::notExcluded('x.user_id', $scope) . '
             GROUP BY x.space_id'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['space_id']] = (int)$row['n'];
        }

        return $map;
    }
}
