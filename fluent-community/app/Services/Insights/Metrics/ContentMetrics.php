<?php

namespace FluentCommunity\App\Services\Insights\Metrics;

use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * content.posts, content.comments, content.reactions, content.messages
 * (docs/reporting/03). Each returns bucket key => count for the range; the
 * scope's space applies to comments and reactions through their post.
 */
class ContentMetrics
{
    const KINDS = ['posts', 'comments', 'reactions'];

    /**
     * @param string $kind posts|comments|reactions
     * @param Range $range
     * @param Scope $scope
     * @param Bucketer $bucketer
     * @return array bucket key => count
     */
    public static function byBucket($kind, Range $range, Scope $scope, Bucketer $bucketer)
    {
        if ($kind === 'comments') {
            return self::comments($range, $scope, $bucketer);
        }

        if ($kind === 'reactions') {
            return self::reactions($range, $scope, $bucketer);
        }

        return self::posts($range, $scope, $bucketer);
    }

    public static function posts(Range $range, Scope $scope, Bucketer $bucketer)
    {
        $posts = Sql::table('fcom_posts');
        $bucket = $bucketer->sqlExpression('p.created_at');
        $space = $scope->spaceId() ? ' AND p.space_id = ' . $scope->spaceId() : '';

        return Sql::keyed(
            "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$posts} p
             WHERE " . Sql::isPost('p') . ' AND ' . Sql::inRange('p.created_at', $range) . $space . Sql::notExcluded('p.user_id', $scope) . '
             GROUP BY k'
        );
    }

    public static function comments(Range $range, Scope $scope, Bucketer $bucketer)
    {
        $comments = Sql::table('fcom_post_comments');
        $bucket = $bucketer->sqlExpression('c.created_at');
        $join = '';

        if ($scope->spaceId()) {
            $join = ' INNER JOIN ' . Sql::table('fcom_posts') . ' p ON p.id = c.post_id AND p.space_id = ' . $scope->spaceId();
        }

        return Sql::keyed(
            "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$comments} c{$join}
             WHERE " . Sql::isComment('c') . ' AND ' . Sql::inRange('c.created_at', $range) . Sql::notExcluded('c.user_id', $scope) . '
             GROUP BY k'
        );
    }

    public static function reactions(Range $range, Scope $scope, Bucketer $bucketer)
    {
        $reactions = Sql::table('fcom_post_reactions');
        $bucket = $bucketer->sqlExpression('r.created_at');
        $join = '';

        if ($scope->spaceId()) {
            $join = ' INNER JOIN ' . Sql::table('fcom_posts') . ' p ON p.id = ' . Sql::reactionPostId('r') . ' AND p.space_id = ' . $scope->spaceId();
        }

        return Sql::keyed(
            "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$reactions} r{$join}
             WHERE " . Sql::isReaction('r') . ' AND ' . Sql::inRange('r.created_at', $range) . Sql::notExcluded('r.user_id', $scope) . '
             GROUP BY k'
        );
    }

    /**
     * content.messages. Fluent Messaging chat; not space-scoped.
     *
     * @return array bucket key => count, empty when the table is missing
     */
    public static function messages(Range $range, Scope $scope, Bucketer $bucketer)
    {
        if (!Sql::hasChatTable() || $scope->spaceId()) {
            return [];
        }

        $messages = Sql::table('fcom_chat_messages');
        $bucket = $bucketer->sqlExpression('m.created_at');

        return Sql::keyed(
            "SELECT {$bucket} AS k, COUNT(*) AS n FROM {$messages} m
             WHERE " . Sql::inRange('m.created_at', $range) . Sql::notExcluded('m.user_id', $scope) . '
             GROUP BY k'
        );
    }
}
