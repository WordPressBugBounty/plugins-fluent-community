<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Auth\Classes\Invitation;
use FluentCommunity\Modules\Course\Model\Course;

/**
 * The "Getting started" checklist a community admin sees at the top of the main feed.
 *
 * Every step is detected from data, never ticked by hand, so a step done anywhere
 * (wp-admin, the portal, WP-CLI, a host's provisioning code) still counts.
 */
class GettingStartedService
{
    const USER_META_KEY = '_fcom_getting_started';

    /**
     * The checklist for one admin: the steps, the progress, and the admin's card state.
     *
     * @param int $userId
     * @return array
     */
    public static function getChecklist($userId)
    {
        $steps = self::getSteps($userId);
        $summary = self::summarize($steps);
        $state = self::getState($userId);

        // Remember that this admin has seen unfinished work. A site that is already set
        // up when the checklist ships has nothing to celebrate, so it never sees one.
        if (!$summary['complete'] && empty($state['started_at'])) {
            $state = self::updateState($userId, [ 'started_at' => time() ]);
        }

        return [
            'steps'   => $steps,
            'summary' => $summary,
            'state'   => [
                'dismissed' => !empty($state['dismissed_at']),
                // Once celebrated, the checklist stays away, even if a step opens again later.
                'finished'  => !empty($state['celebrated_at']),
                'celebrate' => $summary['complete'] && !empty($state['started_at']) && empty($state['celebrated_at']),
            ],
        ];
    }

    /**
     * The steps, after hosts and add-ons had their say.
     *
     * @param int $userId
     * @return array
     */
    public static function getSteps($userId)
    {
        return self::filterSteps(
            self::buildSteps(self::collectFacts($userId), self::getActionLinks($userId)),
            $userId
        );
    }

    /**
     * Run the steps through the public filter, then keep only well-formed steps.
     *
     * @param array $steps
     * @param int   $userId
     * @return array
     */
    public static function filterSteps(array $steps, $userId)
    {
        /**
         * Filter the "Getting started" checklist steps.
         *
         * Add, remove, reorder, or change steps. Each step is an array:
         *
         *     [
         *         'key'         => 'branding',          // string, unique, [a-z0-9_-]
         *         'title'       => 'Add your logo',     // string, short, translated
         *         'description' => 'One line of help.', // string, translated
         *         'done'        => true,                // bool, detected from data
         *         'optional'    => false,               // bool, optional steps never count toward progress
         *         'action'      => [                    // array|null, what the step's button does
         *             'label' => 'Upload logo',         // string, button text
         *             'type'  => 'url',                 // 'url' | 'route' | 'event'
         *             'url'   => 'https://…',           // for 'url': a full URL, opened in the same tab
         *             'route' => [ 'name' => 'update_profile', 'params' => [ 'username' => 'jane' ] ],
         *                                               // for 'route': a portal (vue-router) location
         *             'event' => 'createSpaceModal',    // for 'event': a portal event bus event to emit
         *         ],
         *     ]
         *
         * Core keys, in order: branding, brand_color (optional, Pro only), spaces, welcome_post,
         * profile, invite_members, first_course (optional, course module only).
         *
         * Steps with a missing key or title, or a key already used, are dropped. Unknown
         * fields are dropped. An action with a missing label or target becomes null, and
         * the step shows without a button. Progress counts the required steps only.
         *
         * @param array $steps  The steps, in display order.
         * @param int   $userId The admin who views the checklist.
         */
        $steps = apply_filters('fluent_community/getting_started_steps', $steps, $userId);

        return self::normalizeSteps(is_array($steps) ? $steps : []);
    }

    /**
     * The data each step is detected from. A few option reads and EXISTS queries.
     *
     * @param int $userId
     * @return array
     */
    public static function collectFacts($userId)
    {
        $settings = Helper::generalSettings(false);
        $colorConfig = Utility::getColorConfig('view');
        $profile = XProfile::where('user_id', $userId)->first();
        $hasCourses = Helper::isFeatureEnabled('course_module');

        return [
            'has_logo'          => !empty($settings['logo']),
            // The option, not get_site_icon_url(): a host may filter in a placeholder icon,
            // and a placeholder is not the owner's favicon.
            'has_site_icon'     => (int)get_option('site_icon') > 0,
            // Saving colors needs Pro (it owns the `color-config` POST route).
            'can_brand_colors'  => defined('FLUENT_COMMUNITY_PRO'),
            'has_brand_colors'  => Arr::get($colorConfig, 'light_schema', 'default') !== 'default'
                || Arr::get($colorConfig, 'dark_schema', 'default') !== 'default',
            'has_own_spaces'    => self::hasOwnSpaces(),
            'has_post'          => Feed::where('user_id', $userId)->where('status', 'published')->exists(),
            // The raw column: the accessor falls back to Gravatar or a placeholder.
            'has_avatar'        => $profile && !empty(Arr::get($profile->getAttributes(), 'avatar')),
            'has_bio'           => $profile && trim((string)$profile->short_description) !== '',
            'has_other_members' => XProfile::where('user_id', '!=', $userId)->exists(),
            'has_invitation'    => Invitation::exists(),
            'has_course_module' => $hasCourses,
            'has_course'        => $hasCourses && Course::where('status', 'published')->exists(),
        ];
    }

    /**
     * Turn facts into steps. No database access here, so tests can feed it facts.
     *
     * @param array $facts See collectFacts().
     * @param array $links See getActionLinks().
     * @return array
     */
    public static function buildSteps(array $facts, array $links)
    {
        $steps = [];

        $steps[] = [
            'key'         => 'branding',
            'title'       => __('Add your logo and favicon', 'fluent-community'),
            'description' => __('Your logo shows in the header. The favicon shows in the browser tab.', 'fluent-community'),
            'done'        => !empty($facts['has_logo']) && !empty($facts['has_site_icon']),
            'optional'    => false,
            'action'      => [
                'label' => __('Open branding', 'fluent-community'),
                'type'  => 'url',
                'url'   => Arr::get($links, 'general_settings', ''),
            ],
        ];

        if (!empty($facts['can_brand_colors'])) {
            $steps[] = [
                'key'         => 'brand_color',
                'title'       => __('Choose your brand color', 'fluent-community'),
                'description' => __('Optional. Pick colors that match your brand.', 'fluent-community'),
                'done'        => !empty($facts['has_brand_colors']),
                'optional'    => true,
                'action'      => [
                    'label' => __('Customize colors', 'fluent-community'),
                    'type'  => 'url',
                    'url'   => Arr::get($links, 'color_customizer', ''),
                ],
            ];
        }

        $steps[] = [
            'key'         => 'spaces',
            'title'       => __('Set up your spaces', 'fluent-community'),
            'description' => __('Spaces group posts by topic. Create one, or rename a starter space.', 'fluent-community'),
            'done'        => !empty($facts['has_own_spaces']),
            'optional'    => false,
            'action'      => [
                'label' => __('Create a space', 'fluent-community'),
                'type'  => 'event',
                'event' => 'createSpaceModal',
            ],
        ];

        $steps[] = [
            'key'         => 'welcome_post',
            'title'       => __('Write your welcome post', 'fluent-community'),
            'description' => __('Tell new members what this community is for.', 'fluent-community'),
            'done'        => !empty($facts['has_post']),
            'optional'    => false,
            'action'      => [
                'label' => __('Write a post', 'fluent-community'),
                'type'  => 'event',
                'event' => 'openPostComposer',
            ],
        ];

        $steps[] = [
            'key'         => 'profile',
            'title'       => __('Complete your profile', 'fluent-community'),
            'description' => __('Add a photo and a short bio, so members know who runs the community.', 'fluent-community'),
            'done'        => !empty($facts['has_avatar']) && !empty($facts['has_bio']),
            'optional'    => false,
            'action'      => [
                'label' => __('Edit profile', 'fluent-community'),
                'type'  => 'route',
                'route' => Arr::get($links, 'profile_route', []),
            ],
        ];

        $inviteRoute = Arr::get($links, 'invite_route', []);
        $steps[] = [
            'key'         => 'invite_members',
            'title'       => __('Invite your first members', 'fluent-community'),
            'description' => __('A community starts with a few people. Invite them by email.', 'fluent-community'),
            'done'        => !empty($facts['has_other_members']) || !empty($facts['has_invitation']),
            'optional'    => false,
            'action'      => [
                'label' => __('Invite members', 'fluent-community'),
                'type'  => 'route',
                'route' => $inviteRoute ? $inviteRoute : [ 'name' => 'all_members' ],
            ],
        ];

        if (!empty($facts['has_course_module'])) {
            $steps[] = [
                'key'         => 'first_course',
                'title'       => __('Create your first course', 'fluent-community'),
                'description' => __('Optional. Publish a course to teach your members.', 'fluent-community'),
                'done'        => !empty($facts['has_course']),
                'optional'    => true,
                'action'      => [
                    'label' => __('Create a course', 'fluent-community'),
                    'type'  => 'url',
                    'url'   => Arr::get($links, 'manage_courses', ''),
                ],
            ];
        }

        return $steps;
    }

    /**
     * Where each step's button goes.
     *
     * @param int $userId
     * @return array
     */
    public static function getActionLinks($userId)
    {
        $profile = XProfile::where('user_id', $userId)->first();

        // Members are invited from a space's members page. Use the first space.
        $firstSpace = Space::orderBy('serial', 'ASC')->orderBy('id', 'ASC')->first();

        return [
            'general_settings' => Helper::baseUrl('admin/settings'),
            'color_customizer' => Helper::baseUrl('/?customizer_panel=1'),
            'manage_courses'   => Helper::baseUrl('admin/manage-courses'),
            'profile_route'    => $profile ? [
                'name'   => 'update_profile',
                'params' => [ 'username' => $profile->username ],
            ] : [],
            'invite_route'     => $firstSpace ? [
                'name'   => 'space_members',
                'params' => [ 'space' => $firstSpace->slug ],
            ] : [],
        ];
    }

    /**
     * True when a space exists that is more than an untouched starter space.
     *
     * A fresh install has no spaces, or only the starter spaces that
     * OnboardingService creates from a template. A starter space counts once it is
     * renamed. Any other space counts as soon as it exists. Comparing slug and title
     * is cheap (one EXISTS query) and does not depend on timestamps, which other
     * writes (member counts, settings) also change.
     *
     * @return bool
     */
    public static function hasOwnSpaces()
    {
        $templates = OnboardingService::getTemplateSpaceTitles();

        return Space::query()
            ->where(function ($query) use ($templates) {
                $query->whereNotIn('slug', array_keys($templates));
                foreach ($templates as $slug => $title) {
                    $query->orWhere(function ($q) use ($slug, $title) {
                        $q->where('slug', $slug)->where('title', '!=', $title);
                    });
                }
            })
            ->exists();
    }

    /**
     * Keep only well-formed steps and known fields, so a bad filter cannot break the card.
     *
     * @param array $steps
     * @return array
     */
    public static function normalizeSteps(array $steps)
    {
        $normalized = [];
        $seenKeys = [];

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $key = sanitize_key((string)Arr::get($step, 'key', ''));
            $title = sanitize_text_field((string)Arr::get($step, 'title', ''));

            if (!$key || !$title || isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;

            $normalized[] = [
                'key'         => $key,
                'title'       => $title,
                'description' => sanitize_text_field((string)Arr::get($step, 'description', '')),
                'done'        => (bool)Arr::get($step, 'done', false),
                'optional'    => (bool)Arr::get($step, 'optional', false),
                'action'      => self::normalizeAction(Arr::get($step, 'action')),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $action
     * @return array|null
     */
    protected static function normalizeAction($action)
    {
        if (!is_array($action)) {
            return null;
        }

        $type = Arr::get($action, 'type', '');
        $label = sanitize_text_field((string)Arr::get($action, 'label', ''));

        if (!$label) {
            return null;
        }

        if ($type === 'url') {
            $url = esc_url_raw((string)Arr::get($action, 'url', ''));
            return $url ? [ 'type' => 'url', 'label' => $label, 'url' => $url ] : null;
        }

        if ($type === 'route') {
            $route = Arr::get($action, 'route');
            $name = is_array($route) ? sanitize_text_field((string)Arr::get($route, 'name', '')) : '';
            if (!$name) {
                return null;
            }

            $params = [];
            foreach ((array)Arr::get($route, 'params', []) as $paramKey => $paramValue) {
                if (is_scalar($paramValue)) {
                    $params[sanitize_key((string)$paramKey)] = sanitize_text_field((string)$paramValue);
                }
            }

            return [
                'type'  => 'route',
                'label' => $label,
                'route' => [ 'name' => $name, 'params' => (object)$params ],
            ];
        }

        if ($type === 'event') {
            $event = preg_replace('/[^A-Za-z0-9_:-]/', '', (string)Arr::get($action, 'event', ''));
            return $event ? [ 'type' => 'event', 'label' => $label, 'event' => $event ] : null;
        }

        return null;
    }

    /**
     * Progress over the required steps. Optional steps never count.
     *
     * @param array $steps Normalized steps.
     * @return array{total: int, done: int, complete: bool}
     */
    public static function summarize(array $steps)
    {
        $total = 0;
        $done = 0;

        foreach ($steps as $step) {
            if (!empty($step['optional'])) {
                continue;
            }
            ++$total;
            if (!empty($step['done'])) {
                ++$done;
            }
        }

        return [
            'total'    => $total,
            'done'     => $done,
            'complete' => $total > 0 && $done === $total,
        ];
    }

    /**
     * @param int $userId
     * @return array
     */
    public static function getState($userId)
    {
        $state = get_user_meta($userId, self::USER_META_KEY, true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param int   $userId
     * @param array $changes Keys to set. A null value removes the key.
     * @return array The new state.
     */
    public static function updateState($userId, array $changes)
    {
        $state = self::getState($userId);

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }

        update_user_meta($userId, self::USER_META_KEY, $state);

        return $state;
    }
}
