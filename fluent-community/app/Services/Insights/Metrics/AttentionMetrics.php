<?php

namespace FluentCommunity\App\Services\Insights\Metrics;

use FluentCommunity\App\Services\Insights\Clock;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * "Needs attention" counts. These describe now, not the selected range.
 */
class AttentionMetrics
{
    const UNANSWERED_LOOKBACK_DAYS = 30;

    /**
     * response.unanswered: published, non-sticky posts from the last 30 days,
     * older than 24 h, with no reply from anyone but the author. Newest first.
     *
     * @param Scope $scope
     * @param int $limit
     * @return array ['count' => int, 'posts' => rows]
     */
    public static function unanswered(Scope $scope, $limit = 5)
    {
        $posts = Sql::table('fcom_posts');
        $comments = Sql::table('fcom_post_comments');

        $now = Clock::now();
        $where = Sql::isPost('p') . ' AND p.is_sticky = 0 AND '
            . Sql::between('p.created_at', $now->modify('-' . self::UNANSWERED_LOOKBACK_DAYS . ' days')->format('Y-m-d H:i:s'), $now->modify('-24 hours')->format('Y-m-d H:i:s'))
            . ($scope->spaceId() ? ' AND p.space_id = ' . $scope->spaceId() : '')
            . Sql::notExcluded('p.user_id', $scope)
            . " AND NOT EXISTS (SELECT 1 FROM {$comments} c WHERE c.post_id = p.id AND " . Sql::isComment('c') . ' AND NOT (c.user_id <=> p.user_id))';

        $count = (int)Sql::value("SELECT COUNT(*) FROM {$posts} p WHERE {$where}");

        $rows = $count && $limit ? Sql::rows(
            "SELECT p.id, p.title, p.message, p.slug, p.space_id, p.user_id, p.created_at FROM {$posts} p WHERE {$where} ORDER BY p.created_at DESC, p.id DESC LIMIT " . (int)$limit
        ) : [];

        return ['count' => $count, 'posts' => $rows];
    }

    /**
     * Posts and comments waiting for approval now.
     *
     * @param Scope $scope
     * @return int
     */
    public static function pendingContent(Scope $scope)
    {
        $posts = Sql::table('fcom_posts');
        $comments = Sql::table('fcom_post_comments');

        if ($scope->spaceId()) {
            $spaceId = $scope->spaceId();

            return (int)Sql::value("SELECT COUNT(*) FROM {$posts} p WHERE p.type = 'text' AND p.status = 'pending' AND p.space_id = {$spaceId}")
                + (int)Sql::value("SELECT COUNT(*) FROM {$comments} c INNER JOIN {$posts} p ON p.id = c.post_id AND p.space_id = {$spaceId} WHERE c.type = 'comment' AND c.status = 'pending'");
        }

        return (int)Sql::value("SELECT COUNT(*) FROM {$posts} p WHERE p.type = 'text' AND p.status = 'pending'")
            + (int)Sql::value("SELECT COUNT(*) FROM {$comments} c WHERE c.type = 'comment' AND c.status = 'pending'");
    }
}
