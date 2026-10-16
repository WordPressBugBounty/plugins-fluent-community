<?php

namespace FluentCommunity\App\Services\Insights;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Cards\LockedCard;
use FluentCommunity\App\Services\Insights\Cards\Overview;
use FluentCommunity\App\Services\Insights\Contracts\Card;

/**
 * Every Insights page and card. Free registers the Overview cards (and the
 * Course module its own); Pro adds the rest through
 * `fluent_community/insights/cards`. Without Pro, its pages are filled with
 * LockedCard stubs so the UI can show what an upgrade adds.
 */
class CardRegistry
{
    /** @var AbstractCard[]|null */
    private static $cards = null;

    /**
     * @return array page key => [key, title, tier, order, available]
     */
    public static function pageDefinitions()
    {
        $pages = [
            'overview'   => ['title' => __('Overview', 'fluent-community'), 'tier' => 'free', 'order' => 10],
            'engagement' => ['title' => __('Engagement', 'fluent-community'), 'tier' => 'pro', 'order' => 20],
            'members'    => ['title' => __('Members', 'fluent-community'), 'tier' => 'pro', 'order' => 30],
            'spaces'     => ['title' => __('Spaces', 'fluent-community'), 'tier' => 'pro', 'order' => 40],
            'content'    => ['title' => __('Content', 'fluent-community'), 'tier' => 'pro', 'order' => 50],
            'courses'    => ['title' => __('Courses', 'fluent-community'), 'tier' => 'free', 'order' => 60, 'available' => Helper::isFeatureEnabled('course_module')],
            'moderation' => ['title' => __('Moderation', 'fluent-community'), 'tier' => 'pro', 'order' => 70, 'available' => Helper::isFeatureEnabled('content_moderation')],
        ];

        /**
         * Insights pages. Add one with a unique key, a title and an order.
         *
         * @param array $pages key => [title, tier, order, available]
         */
        $pages = apply_filters('fluent_community/insights/pages', $pages);

        $normalised = [];
        foreach ($pages as $key => $page) {
            if (!preg_match('/^[a-z0-9_-]+$/', (string)$key) || empty($page['title'])) {
                continue;
            }

            $normalised[$key] = [
                'key'       => $key,
                'title'     => $page['title'],
                'tier'      => isset($page['tier']) ? $page['tier'] : 'free',
                'order'     => isset($page['order']) ? (int)$page['order'] : 100,
                'available' => !isset($page['available']) || $page['available'],
            ];
        }

        uasort($normalised, function ($first, $second) {
            return $first['order'] - $second['order'];
        });

        return $normalised;
    }

    /**
     * @return AbstractCard[] key => card, only cards whose feature is on
     */
    public static function cards()
    {
        if (self::$cards !== null) {
            return self::$cards;
        }

        $cards = self::coreCards();
        if (Utility::insightsLevel() !== 'full') {
            $cards = array_merge($cards, self::lockedCards());
        }

        /**
         * Register or remove Insights cards.
         *
         * @param Card[] $cards
         */
        $cards = apply_filters('fluent_community/insights/cards', $cards);

        $pages = self::pageDefinitions();
        $byKey = [];
        foreach ((array)$cards as $card) {
            if (!$card instanceof AbstractCard || !preg_match('/^[a-z0-9_.-]+$/', $card->key())) {
                continue;
            }

            if (empty($pages[$card->page()]) || !$pages[$card->page()]['available'] || !$card->isAvailable()) {
                continue;
            }

            $byKey[$card->key()] = $card;
        }

        uasort($byKey, function ($first, $second) {
            return $first->order() - $second->order();
        });

        self::$cards = $byKey;

        return self::$cards;
    }

    /**
     * @param string $key
     * @return AbstractCard|null
     */
    public static function find($key)
    {
        $cards = self::cards();

        return isset($cards[$key]) ? $cards[$key] : null;
    }

    /**
     * @param string $page
     * @param Scope $scope
     * @return AbstractCard[]
     */
    public static function forPage($page, Scope $scope)
    {
        return array_values(array_filter(self::cards(), function ($card) use ($page, $scope) {
            return $card->page() === $page && ($card->isLocked() || $card->canView($scope));
        }));
    }

    /**
     * Pages that have at least one card the user can see.
     *
     * @param Scope $scope
     * @return array
     */
    public static function visiblePages(Scope $scope)
    {
        $pages = [];

        foreach (self::pageDefinitions() as $key => $page) {
            if (!$page['available']) {
                continue;
            }

            $cards = self::forPage($key, $scope);
            if (!$cards) {
                continue;
            }

            $locked = count(array_filter($cards, function ($card) {
                return $card->isLocked();
            })) === count($cards);

            $pages[] = [
                'key'    => $key,
                'title'  => $page['title'],
                'order'  => $page['order'],
                'locked' => $locked,
            ];
        }

        return $pages;
    }

    /**
     * Tests only: forget the per-request card list.
     */
    public static function reset()
    {
        self::$cards = null;
    }

    private static function coreCards()
    {
        return [
            new Overview\KpiCard(),
            new Overview\AttentionCard(),
            new Overview\ActivityTrendCard(),
            new Overview\ActiveMembersCard(),
            new Overview\NewMembersCard(),
            new Overview\TopSpacesCard(),
        ];
    }

    private static function lockedCards()
    {
        return [
            new LockedCard('engagement.dau_wau_mau', 'engagement', 'timeseries', __('Daily, weekly and monthly active members', 'fluent-community'), '', 'full', 10),
            new LockedCard('engagement.participation', 'engagement', 'distribution', __('Participation tiers', 'fluent-community'), '', 'half', 20),
            new LockedCard('engagement.responsiveness', 'engagement', 'kpi', __('Responsiveness', 'fluent-community'), '', 'half', 30),
            new LockedCard('engagement.heatmap', 'engagement', 'heatmap', __('When members are active', 'fluent-community'), '', 'full', 40),
            new LockedCard('members.growth', 'members', 'timeseries', __('Growth', 'fluent-community'), '', 'full', 10),
            new LockedCard('members.cohorts', 'members', 'cohort', __('Retention cohorts', 'fluent-community'), '', 'full', 20),
            new LockedCard('members.top_contributors', 'members', 'table', __('Top contributors', 'fluent-community'), '', 'full', 30),
            new LockedCard('spaces.comparison', 'spaces', 'table', __('Space comparison', 'fluent-community'), '', 'full', 10),
            new LockedCard('content.top_posts', 'content', 'table', __('Top posts', 'fluent-community'), '', 'full', 10),
            new LockedCard('content.topics', 'content', 'distribution', __('Topics', 'fluent-community'), '', 'full', 20),
            new LockedCard('courses.lesson_funnel', 'courses', 'funnel', __('Lesson funnel', 'fluent-community'), '', 'full', 30),
            new LockedCard('moderation.queue', 'moderation', 'kpi', __('Moderation queue', 'fluent-community'), '', 'full', 10),
            new LockedCard('moderation.reports', 'moderation', 'timeseries', __('Reports received', 'fluent-community'), '', 'full', 20),
        ];
    }
}
