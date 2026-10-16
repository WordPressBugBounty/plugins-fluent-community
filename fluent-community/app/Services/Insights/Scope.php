<?php

namespace FluentCommunity\App\Services\Insights;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\Framework\Support\Arr;

/**
 * Who and what a card is computed for: an optional space or course, and the
 * members left out of every metric (staff, bots).
 */
class Scope
{
    /** @var int|null */
    private $spaceId;

    /** @var int|null */
    private $courseId;

    /** @var int[] */
    private $excludedUserIds;

    /**
     * @param int|null $spaceId
     * @param int|null $courseId
     * @param int[]|null $excludedUserIds null = the site default
     */
    public function __construct($spaceId = null, $courseId = null, $excludedUserIds = null)
    {
        $this->spaceId = $spaceId ? (int)$spaceId : null;
        $this->courseId = $courseId ? (int)$courseId : null;
        $this->excludedUserIds = self::normaliseIds($excludedUserIds === null ? self::defaultExcludedUserIds() : $excludedUserIds);
    }

    /**
     * @param array $params space_id, course_id
     * @return self
     */
    public static function fromRequest(array $params)
    {
        return new self(
            absint(Arr::get($params, 'space_id', 0)),
            absint(Arr::get($params, 'course_id', 0))
        );
    }

    /**
     * Members left out of every metric. Defaults to the leaderboard's exclusion
     * list, which admins already keep to hold staff off the rankings.
     *
     * @return int[]
     */
    public static function defaultExcludedUserIds()
    {
        $leaderboardExcluded = Utility::getOption('fcom_leaderboard_excluded_user_ids', []);

        /**
         * Members to leave out of every Insights metric.
         *
         * @param int[] $userIds
         */
        $userIds = apply_filters('fluent_community/insights/excluded_user_ids', is_array($leaderboardExcluded) ? $leaderboardExcluded : []);

        return self::normaliseIds($userIds);
    }

    /** @return int|null */
    public function spaceId()
    {
        return $this->spaceId;
    }

    /** @return int|null */
    public function courseId()
    {
        return $this->courseId;
    }

    /** @return int[] */
    public function excludedUserIds()
    {
        return $this->excludedUserIds;
    }

    /**
     * @param int|null $spaceId
     * @return self
     */
    public function withSpace($spaceId)
    {
        return new self($spaceId, $this->courseId, $this->excludedUserIds);
    }

    /** @return string */
    public function cacheKey()
    {
        return 's' . (int)$this->spaceId . '_c' . (int)$this->courseId . '_x' . md5(implode(',', $this->excludedUserIds));
    }

    private static function normaliseIds($userIds)
    {
        if (!is_array($userIds)) {
            return [];
        }

        $userIds = array_values(array_unique(array_filter(array_map('absint', $userIds))));
        sort($userIds);

        return $userIds;
    }
}
