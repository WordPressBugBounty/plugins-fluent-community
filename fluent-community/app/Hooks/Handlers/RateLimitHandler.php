<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\RateLimiter;

class RateLimitHandler
{
    public function register()
    {
        add_action('fluent_community/check_rate_limit/create_post', [ $this, 'maybeLimitPost' ], 10, 1);
        add_action('fluent_community/check_rate_limit/create_comment', [ $this, 'maybeLimitComment' ], 10, 1);
        add_action('fluent_community/check_rate_limit/media_upload', [ $this, 'maybeLimitMediaUpload' ], 10, 1);
        add_action('fluent_community/check_rate_limit/oembed', [ $this, 'maybeLimitOembed' ], 10, 1);
        add_action('fluent_community/check_rate_limit/giphy', [ $this, 'maybeLimitGiphy' ], 10, 1);
    }

    public function maybeLimitPost(User $user)
    {
        if (Helper::isSiteAdmin($user->ID, $user)) {
            return;
        }

        // Check how many posts user has created in last 5 minutes
        $postsCount = Feed::query()->withoutGlobalScopes()->where('user_id', $user->ID)
            ->where('created_at', '>', gmdate('Y-m-d H:i:s', current_time('timestamp') - 300))
            ->count();

        $limitPer5Minutes = apply_filters('fluent_community/rate_limit/posts_per_5_minutes', 5);

        if ($postsCount >= $limitPer5Minutes) {
            throw new \Exception(esc_html__('You have reached the limit of posting. Please try after some time', 'fluent-community'));
        }
    }

    public function maybeLimitComment(User $user)
    {
        if (Helper::isSiteAdmin($user->ID, $user)) {
            return;
        }

        // Check how many comments user has created in last 1 minute
        $commentsCount = Comment::query()->withoutGlobalScopes()->where('user_id', $user->ID)
            ->where('created_at', '>', gmdate('Y-m-d H:i:s', current_time('timestamp') - 60))
            ->count();

        $limitPerMinute = apply_filters('fluent_community/rate_limit/comments_per_minute', 5);

        if ($commentsCount >= $limitPerMinute) {
            throw new \Exception(esc_html__('You have reached the limit of commenting. Please try after some time', 'fluent-community'));
        }
    }

    public function maybeLimitMediaUpload(User $user)
    {
        if (Helper::isSiteAdmin($user->ID, $user)) {
            return;
        }
        
        // A locked counter, not a row count: the media row is only written after the
        // file is processed, so parallel uploads would all pass a row count.
        $limitPerMinute = apply_filters('fluent_community/rate_limit/media_upload_per_minute', 10);

        if (RateLimiter::hit('fcom_media_upload_rate_limit_' . $user->ID, $limitPerMinute, MINUTE_IN_SECONDS)) {
            throw new \Exception(esc_html__('You have reached the limit of media uploads. Please try after some time', 'fluent-community'));
        }
    }

    public function maybeLimitOembed(User $user)
    {
        if (Helper::isSiteAdmin($user->ID, $user)) {
            return;
        }

        $limitPerMinute = apply_filters('fluent_community/rate_limit/oembed_per_minute', 20);

        if (RateLimiter::hit('fcom_oembed_rate_limit_' . $user->ID, $limitPerMinute, MINUTE_IN_SECONDS)) {
            throw new \Exception(esc_html__('You have reached the limit of link previews. Please try after some time', 'fluent-community'));
        }
    }

    public function maybeLimitGiphy(User $user)
    {
        if (Helper::isSiteAdmin($user->ID, $user)) {
            return;
        }

        $limitPerMinute = apply_filters('fluent_community/rate_limit/giphy_per_minute', 30);

        if (RateLimiter::hit('fcom_giphy_rate_limit_' . $user->ID, $limitPerMinute, MINUTE_IN_SECONDS)) {
            throw new \Exception(esc_html__('You have reached the limit of GIF searches. Please try after some time', 'fluent-community'));
        }
    }
}
