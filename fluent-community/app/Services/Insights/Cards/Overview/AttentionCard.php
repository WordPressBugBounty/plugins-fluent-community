<?php

namespace FluentCommunity\App\Services\Insights\Cards\Overview;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Insights\Cards\AbstractCard;
use FluentCommunity\App\Services\Insights\Metrics\AttentionMetrics;
use FluentCommunity\App\Services\Insights\Metrics\MemberMetrics;
use FluentCommunity\App\Services\Insights\Metrics\Sql;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * What an admin should act on now. Not bound to the selected range.
 */
class AttentionCard extends AbstractCard
{
    public function key()
    {
        return 'overview.attention';
    }

    public function page()
    {
        return 'overview';
    }

    public function type()
    {
        return 'attention';
    }

    public function title()
    {
        return __('Needs attention', 'fluent-community');
    }

    public function description()
    {
        return __('Posts from the last 30 days still waiting for a reply after 24 hours, content waiting for review and members waiting for approval, right now. Metrics: response.unanswered, mod.pending_content, members.pending.', 'fluent-community');
    }

    public function size()
    {
        return 'third';
    }

    public function order()
    {
        return 30;
    }

    public function supports()
    {
        return ['space_id', 'export'];
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        // Without Pro there is no list to send these rows to, so they stay unlinked.
        $hasPro = Utility::insightsLevel() === 'full';

        $items = [
            [
                'key'      => 'response.unanswered',
                'label'    => __('Unanswered posts', 'fluent-community'),
                'action'   => __('Reply', 'fluent-community'),
                'severity' => 'warn',
                'hint'     => __('No reply after 24 hours, last 30 days', 'fluent-community'),
                'count'    => AttentionMetrics::unanswered($scope, 0)['count'],
                'link'     => $hasPro ? Helper::baseUrl('admin/insights/content?card=content.unanswered') : '',
                'preview'  => [],
            ],
            [
                'key'      => 'mod.pending_content',
                'label'    => __('Posts and comments waiting for review', 'fluent-community'),
                'action'   => __('Review', 'fluent-community'),
                'severity' => 'warn',
                'hint'     => __('Held for approval', 'fluent-community'),
                'count'    => AttentionMetrics::pendingContent($scope),
                'link'     => $hasPro ? Helper::baseUrl('?status=pending') : '',
                'preview'  => [],
            ],
            [
                'key'      => 'members.pending',
                'label'    => $scope->spaceId() ? __('Join requests waiting for approval', 'fluent-community') : __('Members waiting for approval', 'fluent-community'),
                'action'   => __('Approve', 'fluent-community'),
                'severity' => 'info',
                'hint'     => $scope->spaceId() ? __('Pending join requests', 'fluent-community') : __('Pending profiles', 'fluent-community'),
                'count'    => MemberMetrics::pendingCount($scope),
                'link'     => $scope->spaceId() ? $this->spaceMembersUrl($scope->spaceId()) : Helper::baseUrl('members?status=pending'),
                'preview'  => [],
            ],
        ];

        /**
         * Add rows to "Needs attention" (Pro adds open moderation reports).
         *
         * @param array $items each: key, label, action, severity (crit|warn|info), hint, count, link, preview[]
         * @param Scope $scope
         */
        $items = apply_filters('fluent_community/insights/attention_items', $items, $scope);

        return ['items' => array_values($items)];
    }

    private function spaceMembersUrl($spaceId)
    {
        $slug = Sql::value('SELECT slug FROM ' . Sql::table('fcom_spaces') . " WHERE type = 'community' AND id = " . (int)$spaceId);

        return $slug ? Helper::baseUrl('space/' . $slug . '/members') : Helper::baseUrl();
    }
}
