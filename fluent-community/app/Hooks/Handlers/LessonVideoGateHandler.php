<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Services\LessonVideoGateService;

/**
 * Hook wiring for video-gated lesson completion.
 * The gate/watched-state logic lives in LessonVideoGateService.
 */
class LessonVideoGateHandler
{
    public function register()
    {
        add_filter('fluent_community/is_allowed_to_complete_lesson', [$this, 'gateLessonCompletion'], 11, 3);

        add_filter('fluent_community/lesson/get_public_meta', [$this, 'appendPublicMeta'], 10, 2);

        add_filter('fluent_community/portal_data_vars', [$this, 'injectPortalAssets'], 11);

        // A progress reset means the student starts pristine — watched videos included
        add_action('fluent_community/course/progress_reset', [$this, 'resetWatchedRecords'], 10, 2);

        // Un-completing a gated lesson requires re-watching the video
        add_action('fluent_community/course/lesson_marked_incomplete', [$this, 'resetLessonWatchedRecord'], 10, 2);

        // Deleting a lesson removes every student's watched record with it
        add_action('fluent_community/lesson/before_deleted', [$this, 'deleteLessonWatchedRecords']);
    }

    public function gateLessonCompletion($isAllowed, $lesson, $state = '')
    {
        // Un-completing a lesson is never gated
        if (!$isAllowed || $state == 'incomplete') {
            return $isAllowed;
        }

        return !LessonVideoGateService::isCompletionBlockedFor($lesson, Helper::getCurrentUser());
    }

    public function appendPublicMeta($meta, $lesson)
    {
        $isGated = LessonVideoGateService::isGatedLesson($lesson);

        $meta['require_video_completion'] = Arr::get($meta, 'require_video_completion') == 'yes' ? 'yes' : 'no';
        $meta['video_completion_threshold'] = LessonVideoGateService::getThreshold($lesson);
        $meta['is_video_gated'] = $isGated;
        $meta['video_auto_complete'] = LessonVideoGateService::isAutoCompleteEnabled($lesson);
        $meta['video_watched'] = $isGated && LessonVideoGateService::hasWatched($lesson, get_current_user_id());

        return $meta;
    }

    public function injectPortalAssets($data)
    {
        // The portal tracker is only useful when FluentPlayer can render
        if (!LessonVideoGateService::isFluentPlayerActive()) {
            return $data;
        }

        $data['js_files']['fcom_lesson_video_gate'] = [
            'url'  => FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Course/js/lesson-video-gate.js',
            'deps' => []
        ];

        if (!isset($data['js_vars'])) {
            $data['js_vars'] = [];
        }

        $data['js_vars']['fcomLessonVideoGate'] = [
            'rest_url'          => rest_url('fluent-community/v2'),
            'nonce'             => wp_create_nonce('wp_rest'),
            'default_threshold'   => LessonVideoGateService::getDefaultThreshold(),
            'auto_complete_delay' => LessonVideoGateService::getAutoCompleteDelay(),
            'i18n'              => [
                /* translators: %d is the watched percentage required to complete the lesson */
                'watch_notice' => __('Watch at least %d%% of this video to be able to complete this lesson.', 'fluent-community'),
                'watch_to_end' => __('Watch this video to the end to be able to complete this lesson.', 'fluent-community'),
                'watched_done' => __('Video watched — you can now complete this lesson.', 'fluent-community'),
                'watch_failed' => __('Could not record your watch progress. Please reload the page and try again.', 'fluent-community')
            ]
        ];

        return $data;
    }

    public function resetWatchedRecords($course, $userId)
    {
        LessonVideoGateService::resetWatchedForCourse($course->id, $userId);
    }

    public function resetLessonWatchedRecord($lesson, $userId)
    {
        if (!LessonVideoGateService::isGatedLesson($lesson)) {
            return;
        }

        LessonVideoGateService::resetWatchedForLesson($lesson, $userId);
    }

    public function deleteLessonWatchedRecords($lesson)
    {
        LessonVideoGateService::deleteWatchedForLesson($lesson->id);
    }

}
