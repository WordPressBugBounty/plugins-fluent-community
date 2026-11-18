<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\Modules\Mcp\AbilitiesRegistrar;

defined('ABSPATH') || exit;

/**
 * The MCP tools FluentCommunity Pro adds (fluent-community-pro/app/Modules/Mcp/).
 * On a site without Pro, each is listed as a short stub with the same name, role and
 * feature rules: an agent sees what Pro does, and a call answers with how to get Pro
 * instead of failing as an unknown tool. With Pro active, Pro's real tools replace them.
 */
class ProTools
{
    /** Tool name => who may use it, the feature it needs, and what it does in Pro. */
    const TOOLS = [
        'list-moderation-queue' => [
            'requires' => 'moderator',
            'does'     => 'List reported posts and comments, pending members and join requests.',
        ],
        'review-report'         => [
            'requires' => 'moderator',
            'feature'  => 'content_moderation',
            'does'     => 'Approve or hide a reported post or comment.',
        ],
        'manage-member'         => [
            'requires' => 'moderator',
            'does'     => 'Approve, block or unblock a member.',
        ],
        'manage-space-members'  => [
            'requires' => 'manager',
            'does'     => 'Add or remove space members, set roles, approve join requests.',
        ],
        'list-students'         => [
            'requires' => 'course_creator',
            'feature'  => 'course_module',
            'does'     => 'List a course\'s students with their progress.',
        ],
        'manage-enrollment'     => [
            'requires' => 'course_creator',
            'feature'  => 'course_module',
            'does'     => 'Enroll, schedule or unenroll students, many at once.',
        ],
        'list-quiz-results'     => [
            'requires' => 'course_creator',
            'feature'  => 'course_module',
            'does'     => 'List quiz results and answers waiting for grading.',
        ],
        'manage-quiz-result'    => [
            'requires' => 'course_creator',
            'feature'  => 'course_module',
            'does'     => 'Grade written quiz answers or give an extra attempt.',
        ],
        'get-insights'          => [
            'requires' => 'site_admin',
            'does'     => 'Read Insights reports: growth, engagement and course numbers.',
        ],
    ];

    /**
     * Stub definitions for a site without Pro, in the AbilitiesRegistrar format.
     *
     * @param array $existing Definitions already on the server; a name there is left alone.
     * @return array
     */
    public static function stubs(array $existing)
    {
        if (defined('FLUENT_COMMUNITY_PRO')) {
            return [];
        }

        $stubs = [];
        foreach (self::TOOLS as $tool => $info) {
            $name = AbilitiesRegistrar::CATEGORY . '/' . $tool;
            if (isset($existing[$name])) {
                continue;
            }

            $stubs[$name] = array_filter([
                'label'            => ucwords(str_replace('-', ' ', $tool)) . ' (Pro)',
                'description'      => 'FluentCommunity Pro: ' . $info['does'] . ' Not on this site; a call explains how to get it.',
                // An empty array, not an object: WordPress core's validator indexes properties as an array.
                'input_schema'     => [ 'type' => 'object', 'properties' => [] ],
                'execute_callback' => [ self::class, 'notOnThisSite' ],
                'annotations'      => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ],
                'requires'         => $info['requires'],
                'feature'          => isset($info['feature']) ? $info['feature'] : null,
                'upsell'           => true,
            ]);
        }

        return $stubs;
    }

    /**
     * What a stub answers. An error, so the agent does not report the task as done.
     *
     * @param string $name Ability name.
     * @return \WP_Error
     */
    public static function upgradeAnswer($name)
    {
        $tool = substr((string) $name, strlen(AbilitiesRegistrar::CATEGORY . '/'));
        $does = isset(self::TOOLS[$tool]) ? self::TOOLS[$tool]['does'] : 'This tool';

        $url = self::purchaseUrl('mcp_agent');

        return McpHelper::error('pro_required', $does . ' This is part of FluentCommunity Pro, which this site does not have yet. Ask your human to purchase FluentCommunity Pro at ' . $url . ', then install and activate the Pro plugin on this site; this tool works right after.', [
            'upgrade_url' => $url,
            'next_step'   => 'Tell the person, in a friendly way, that this needs FluentCommunity Pro: they can purchase it at upgrade_url, then install and activate the Pro plugin. Then carry on with what the free tools can do.',
        ]);
    }

    /**
     * The FluentCommunity pricing page (https://fluentcommunity.co/pricing/), tagged so
     * sales that start from an AI agent can be counted.
     *
     * @param string $content utm_content value.
     * @return string
     */
    public static function purchaseUrl($content)
    {
        return Utility::getProUpgradeUrl($content);
    }

    /**
     * Never runs: AbilitiesRegistrar::run() answers stubs with upgradeAnswer() before
     * parameters are checked. Kept so a stub is a complete definition.
     */
    public static function notOnThisSite()
    {
        return self::upgradeAnswer('');
    }
}
