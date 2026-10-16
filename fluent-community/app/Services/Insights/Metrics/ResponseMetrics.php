<?php

namespace FluentCommunity\App\Services\Insights\Metrics;

use FluentCommunity\App\Services\Insights\Clock;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * response.* (docs/reporting/03). Over non-sticky F_POST posts created in the
 * range; a reply is the first F_COMMENT by someone other than the author, at
 * any time. Posts younger than 24 h at the end of the range are left out so a
 * range ending today is not penalised for posts nobody has seen yet.
 *
 * Staff posts are left out (excluded members); a staff reply still counts as
 * a reply, because the member did get an answer.
 */
class ResponseMetrics
{
    /**
     * @param Range $range
     * @param Scope $scope
     * @return array eligible, replied, within_24h, reply_rate, within_24h_rate, median_minutes, minutes[]
     */
    public static function stats(Range $range, Scope $scope)
    {
        $replies = self::firstReplyMinutes($range, $scope);

        return self::summarise($replies['minutes'], $replies['eligible']);
    }

    /**
     * The first-reply delay of every eligible post that got one.
     *
     * @param Range $range
     * @param Scope $scope
     * @return array ['minutes' => int[], 'eligible' => int]
     */
    public static function firstReplyMinutes(Range $range, Scope $scope)
    {
        $posts = Sql::table('fcom_posts');
        $comments = Sql::table('fcom_post_comments');

        $end = $range->to()->modify('+1 second');
        $now = Clock::now();
        $cutoff = ($end < $now ? $end : $now)->modify('-24 hours')->format('Y-m-d H:i:s');

        if ($cutoff <= $range->sqlFrom()) {
            return ['minutes' => [], 'eligible' => 0];
        }

        $space = $scope->spaceId() ? ' AND p.space_id = ' . $scope->spaceId() : '';

        $rows = Sql::rows(
            "SELECT p.id, TIMESTAMPDIFF(MINUTE, p.created_at, MIN(c.created_at)) AS m
             FROM {$posts} p
             LEFT JOIN {$comments} c ON c.post_id = p.id AND " . Sql::isComment('c') . ' AND NOT (c.user_id <=> p.user_id)
             WHERE ' . Sql::isPost('p') . ' AND p.is_sticky = 0 AND ' . Sql::between('p.created_at', $range->sqlFrom(), $cutoff) . $space . Sql::notExcluded('p.user_id', $scope) . '
             GROUP BY p.id'
        );

        $minutes = [];
        foreach ($rows as $row) {
            if ($row['m'] !== null) {
                $minutes[] = max(0, (int)$row['m']);
            }
        }

        return ['minutes' => $minutes, 'eligible' => count($rows)];
    }

    /**
     * @param int[] $minutes first-reply delay of each replied post
     * @param int $eligible posts considered
     * @return array
     */
    public static function summarise(array $minutes, $eligible)
    {
        sort($minutes);
        $replied = count($minutes);
        $within = count(array_filter($minutes, function ($minute) {
            return $minute <= 1440;
        }));

        return [
            'eligible'        => $eligible,
            'replied'         => $replied,
            'within_24h'      => $within,
            'reply_rate'      => $eligible ? round($replied / $eligible, 4) : null,
            'within_24h_rate' => $eligible ? round($within / $eligible, 4) : null,
            'median_minutes'  => self::median($minutes),
        ];
    }

    /**
     * @param int[] $sorted ascending
     * @return float|null
     */
    public static function median(array $sorted)
    {
        $count = count($sorted);
        if (!$count) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 ? (float)$sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2.0;
    }
}
