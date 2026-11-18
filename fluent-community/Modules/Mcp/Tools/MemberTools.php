<?php

namespace FluentCommunity\Modules\Mcp\Tools;

use FluentCommunity\App\Models\XProfile;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Mcp\Support\McpHelper;
use FluentCommunity\Modules\Mcp\Support\Projector;
use FluentCommunity\Modules\Mcp\Support\RestBridge;

defined('ABSPATH') || exit;

class MemberTools
{
    public static function listMembers($params)
    {
        $paging = McpHelper::pagination($params, 20, 50);
        $space = sanitize_text_field((string) Arr::get($params, 'space', ''));
        $status = Arr::get($params, 'status');

        if ($space && $status && $status !== 'pending' && $status !== 'active') {
            return McpHelper::error('invalid_param', 'With space, status can only be active or pending.', [ 'fields' => [ 'status' ] ]);
        }

        $query = array_filter([
            'page'     => $paging['page'],
            'per_page' => $paging['per_page'],
            'search'   => sanitize_text_field((string) Arr::get($params, 'search', '')),
            'status'   => $status,
            'sort_by'  => Arr::get($params, 'sort_by'),
            'sort_dir' => Arr::get($params, 'sort_dir'),
        ]);

        $inactiveDays = (int) Arr::get($params, 'inactive_days', 0);
        $hasPosted = array_key_exists('has_posted', $params) ? (bool) $params['has_posted'] : null;
        $hook = $space ? 'fluent_community/space_members_query_ref' : 'fluent_community/members_query_ref';

        $constraint = function ($membersQuery) use ($inactiveDays, $hasPosted, $space) {
            $apply = function ($profileQuery) use ($inactiveDays, $hasPosted) {
                if ($inactiveDays > 0) {
                    $before = wp_date('Y-m-d H:i:s', time() - $inactiveDays * DAY_IN_SECONDS);
                    $profileQuery->where(function ($activityQuery) use ($before) {
                        $activityQuery->where('last_activity', '<', $before)->orWhereNull('last_activity');
                    });
                }

                if ($hasPosted === true) {
                    $profileQuery->whereHas('posts');
                } elseif ($hasPosted === false) {
                    $profileQuery->whereDoesntHave('posts');
                }
            };

            // The space route lists membership rows; the profile is one relation away.
            if ($space) {
                $membersQuery->whereHas('xprofile', $apply);
            } else {
                $apply($membersQuery);
            }
        };

        $filtered = $inactiveDays > 0 || $hasPosted !== null;

        if ($filtered) {
            add_action($hook, $constraint);
        }

        try {
            $result = RestBridge::call('GET', $space ? '/spaces/' . rawurlencode($space) . '/members' : '/members', $query);
        } finally {
            if ($filtered) {
                remove_action($hook, $constraint);
            }
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $rows = [];
        foreach ((array) Arr::get($result, 'members.data', []) as $item) {
            if ($space) {
                $row = Projector::member((array) Arr::get($item, 'xprofile', []));
                $row['space_role'] = Arr::get($item, 'role');
                $row['space_status'] = Arr::get($item, 'status');
            } else {
                $row = Projector::member($item);
            }
            $rows[] = $row;
        }

        $rows = self::withEmails($rows);
        $total = (int) Arr::get($result, 'members.total', count($rows));

        return McpHelper::envelope(
            sprintf('%d of %d members, page %d.', count($rows), $total, $paging['page']),
            [ 'members' => $rows ],
            [
                'paging' => [
                    'total'    => $total,
                    'page'     => $paging['page'],
                    'per_page' => $paging['per_page'],
                    'has_more' => $paging['page'] < (int) Arr::get($result, 'members.last_page', 1),
                ],
            ]
        );
    }

    public static function getMember($params)
    {
        $username = sanitize_text_field((string) Arr::get($params, 'username', ''));

        if (!$username && !empty($params['id'])) {
            $username = (string) XProfile::where('user_id', (int) $params['id'])->value('username');
        }

        if (!$username) {
            return McpHelper::error('not_found', 'No member matches. Pass id or username.', [ 'fields' => [ 'id' ] ]);
        }

        $route = '/profile/' . rawurlencode($username);
        $result = RestBridge::call('GET', $route);

        if (is_wp_error($result)) {
            return $result;
        }

        $profile = (array) Arr::get($result, 'profile', []);

        $member = array_merge(Projector::member($profile), array_filter([
            'email'     => Arr::get($profile, 'email'),
            'about'     => McpHelper::excerpt((string) Arr::get($profile, 'short_description', ''), 400),
            'website'   => Arr::get($profile, 'website'),
            'points'    => (int) Arr::get($profile, 'total_points', 0),
            'badges'    => (array) Arr::get($profile, 'badge_slugs', []),
            'followers' => Arr::get($profile, 'followers_count'),
            'url'       => McpHelper::excerpt((string) Arr::get($profile, 'profile_navs.0.url', ''), 300),
        ]));

        $include = (array) Arr::get($params, 'include', []);

        if (in_array('spaces', $include, true)) {
            $spaces = RestBridge::call('GET', $route . '/spaces');
            $member['spaces'] = is_wp_error($spaces) ? [] : array_map(function ($space) {
                return [
                    'slug'  => Arr::get($space, 'slug'),
                    'title' => Arr::get($space, 'title'),
                    'role'  => Arr::get($space, 'pivot.role'),
                ];
            }, (array) Arr::get($spaces, 'spaces', []));
        }

        if (in_array('recent_posts', $include, true)) {
            $posts = PostTools::listPosts([ 'user_id' => $member['id'], 'per_page' => 5 ]);
            $member['recent_posts'] = is_wp_error($posts) ? [] : $posts['data']['posts'];
        }

        if (in_array('recent_comments', $include, true)) {
            $comments = RestBridge::call('GET', $route . '/comments', [ 'per_page' => 5 ]);
            $member['recent_comments'] = is_wp_error($comments) ? [] : array_map(function ($comment) {
                return [
                    'id'      => (int) Arr::get($comment, 'id'),
                    'post_id' => (int) Arr::get($comment, 'post_id'),
                    'message' => McpHelper::excerpt((string) Arr::get($comment, 'message', '')),
                    'created' => McpHelper::iso(Arr::get($comment, 'created_at')),
                ];
            }, (array) Arr::get($comments, 'comments.data', []));
        }

        return McpHelper::envelope(
            sprintf('%s (@%s), status %s.', $member['name'], $member['username'], $member['status']),
            $member,
            [ 'content_warning' => McpHelper::CONTENT_WARNING ]
        );
    }

    /**
     * Email is shown under the same rule as the member search: WordPress list_users.
     */
    private static function withEmails($rows)
    {
        if (!$rows || !current_user_can('list_users')) {
            return $rows;
        }

        $emails = [];
        $users = get_users([
            'include' => array_column($rows, 'id'),
            'fields'  => [ 'ID', 'user_email' ],
        ]);

        foreach ($users as $user) {
            $emails[(int) $user->ID] = $user->user_email;
        }

        foreach ($rows as $index => $row) {
            if (isset($emails[$row['id']])) {
                $rows[$index]['email'] = $emails[$row['id']];
            }
        }

        return $rows;
    }
}
