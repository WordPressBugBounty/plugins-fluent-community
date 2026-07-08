<?php

namespace FluentCommunity\Modules\Course\Services;

use FluentCommunity\App\Models\Reaction;
use FluentCommunity\Framework\Support\Arr;

/**
 * Video-gated lesson completion.
 *
 * When a lesson's feature media is a FluentPlayer video and
 * `require_video_completion` is enabled in the lesson meta, students must
 * watch the video up to `video_completion_threshold` percent (default 80,
 * the `ended` event always satisfies it) before the lesson can be marked
 * as completed.
 *
 * The watched state is stored as a Reaction row — the same convention used
 * for `lesson_completed`:
 *   object_type = lesson_video_watched, object_id = lesson, parent_id = course
 */
class LessonVideoGateService
{
    const WATCHED_OBJECT_TYPE = 'lesson_video_watched';

    const DEFAULT_THRESHOLD = 80;

    const AUTO_COMPLETE_DELAY = 3;

    // Per-request watched lesson ids, keyed by "{course_id}_{user_id}"
    private static $watchedCache = [];

    public static function isFluentPlayerActive()
    {
        return defined('FLUENT_PLAYER_VERSION');
    }

    public static function isGatedLesson($lesson)
    {
        // Without FluentPlayer the tracker can never run, so never enforce
        if (!self::isFluentPlayerActive()) {
            return false;
        }

        $meta = $lesson->meta;

        if (Arr::get($meta, 'require_video_completion') != 'yes') {
            return false;
        }

        if (Arr::get($meta, 'enable_media') != 'yes') {
            return false;
        }

        return Arr::get($meta, 'media.type') == 'fluent_player';
    }

    public static function isAutoCompleteEnabled($lesson)
    {
        if (!self::isFluentPlayerActive()) {
            return false;
        }

        $meta = $lesson->meta;

        if (Arr::get($meta, 'auto_complete_on_video_end') != 'yes') {
            return false;
        }

        if (Arr::get($meta, 'enable_media') != 'yes') {
            return false;
        }

        return Arr::get($meta, 'media.type') == 'fluent_player';
    }

    public static function getAutoCompleteDelay()
    {
        $delay = (int) apply_filters('fluent_community/lesson_video_gate/auto_complete_delay', self::AUTO_COMPLETE_DELAY);

        return $delay >= 0 ? $delay : self::AUTO_COMPLETE_DELAY;
    }

    public static function getDefaultThreshold()
    {
        $default = (int) apply_filters('fluent_community/lesson_video_gate/default_threshold', self::DEFAULT_THRESHOLD);

        return $default > 0 ? min(100, $default) : self::DEFAULT_THRESHOLD;
    }

    public static function getThreshold($lesson)
    {
        $threshold = absint(Arr::get($lesson->meta, 'video_completion_threshold', 0));

        return $threshold > 0 ? min(100, $threshold) : self::getDefaultThreshold();
    }

    public static function isCompletionBlockedFor($lesson, $user)
    {
        if (!$user || !self::isGatedLesson($lesson)) {
            return false;
        }

        // Course admins bypass everywhere; course creators only on their own courses
        $course = $lesson->course;
        if ($course && $course->isCourseAdmin($user)) {
            return false;
        }

        return !self::hasWatched($lesson, $user->ID);
    }

    public static function hasWatched($lesson, $userId)
    {
        if (!$userId) {
            return false;
        }

        $cacheKey = $lesson->space_id . '_' . $userId;

        if (!isset(self::$watchedCache[$cacheKey])) {
            self::$watchedCache[$cacheKey] = Reaction::where('user_id', $userId)
                ->where('parent_id', $lesson->space_id)
                ->where('object_type', self::WATCHED_OBJECT_TYPE)
                ->where('type', 'watched')
                ->pluck('object_id')
                ->toArray();
        }

        return in_array($lesson->id, self::$watchedCache[$cacheKey]);
    }

    public static function markWatched($lesson, $userId)
    {
        $existing = Reaction::where('user_id', $userId)
            ->where('object_id', $lesson->id)
            ->where('object_type', self::WATCHED_OBJECT_TYPE)
            ->first();

        if ($existing) {
            if ($existing->type != 'watched') {
                $existing->type = 'watched';
                $existing->save();
                unset(self::$watchedCache[$lesson->space_id . '_' . $userId]);
            }
            return $existing;
        }

        $reaction = Reaction::create([
            'user_id'     => $userId,
            'object_id'   => $lesson->id,
            'object_type' => self::WATCHED_OBJECT_TYPE,
            'parent_id'   => $lesson->space_id,
            'type'        => 'watched'
        ]);

        unset(self::$watchedCache[$lesson->space_id . '_' . $userId]);

        do_action('fluent_community/lesson/video_watched', $lesson, $userId);

        return $reaction;
    }

    public static function resetWatchedForCourse($courseId, $userId)
    {
        Reaction::where('user_id', $userId)
            ->where('parent_id', $courseId)
            ->where('object_type', self::WATCHED_OBJECT_TYPE)
            ->delete();

        unset(self::$watchedCache[$courseId . '_' . $userId]);
    }

    public static function resetWatchedForLesson($lesson, $userId)
    {
        Reaction::where('user_id', $userId)
            ->where('object_id', $lesson->id)
            ->where('object_type', self::WATCHED_OBJECT_TYPE)
            ->delete();

        unset(self::$watchedCache[$lesson->space_id . '_' . $userId]);
    }

    public static function deleteWatchedForLesson($lessonId)
    {
        Reaction::where('object_id', $lessonId)
            ->where('object_type', self::WATCHED_OBJECT_TYPE)
            ->delete();

        self::$watchedCache = [];
    }
}
