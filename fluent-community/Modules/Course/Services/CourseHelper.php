<?php

namespace FluentCommunity\Modules\Course\Services;

use FluentCommunity\App\Models\Activity;
use FluentCommunity\App\Models\Meta;
use FluentCommunity\App\Models\Reaction;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\App\Models\Term;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\CustomSanitizer;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Libs\EmailComposer;
use FluentCommunity\App\Services\LockscreenService;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Services\SmartCodeParser;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Model\CourseLesson;
use FluentCommunity\Modules\Course\Model\CourseTopic;

class CourseHelper
{
    protected static $completedLessonsCache = [];

    protected static $enrollmentCache = [];

    public static function getCourseProgressTrack($courseId, $userId = null)
    {
        $isEnrolled = self::isEnrolled($courseId, $userId);
        $scheduled = $isEnrolled ? null : self::getScheduledEnrollment($courseId, $userId);

        return [
            'completed_lessons' => $isEnrolled ? self::getCompletedLessonIds($courseId, $userId) : [],
            // A scheduled member shows as enrolled, with `opens_at`, so a client that predates
            // scheduled enrollments shows the course as theirs (its lessons stay locked) and not
            // a buy or enroll button
            'isEnrolled'        => $isEnrolled || (bool) $scheduled,
            'progress'          => $isEnrolled ? self::getCourseProgress($courseId, $userId) : 0,
            'opens_at'          => $scheduled ? $scheduled->starts_at : null,
        ];
    }

    /**
     * Enroll a member whose access starts on a given date. Membership plugins use this to drip a course.
     *
     * - A future date stores a 'scheduled' enrollment. It gives no access, and
     *   `fluent_community/course/enrolled` does not fire, until the date arrives
     *   (activateDueEnrollments, hourly, or the member's next visit to the course).
     * - A past or current date enrolls the member now. The date still anchors the structured drip,
     *   so a member imported three months into a membership finds three months of sections open.
     * - A member who already has an active enrollment is left as is (returns false), so a renewal
     *   does not restart their drip. Call leaveCourse() first to restart it.
     * - Calling it again for a scheduled member moves the date.
     *
     * @param Course|int $course
     * @param int $userId
     * @param string $startsAt site time, 'Y-m-d H:i:s' (the same clock as created_at)
     * @param string $by the source, passed on to `fluent_community/course/enrolled`
     * @return string|false 'scheduled', 'enrolled', or false when nothing changed or the input is invalid
     */
    public static function scheduleEnrollment($course, $userId, $startsAt, $by = 'by_admin')
    {
        if (is_numeric($course)) {
            $course = Course::find($course);
        }

        $userId = (int) $userId;
        $startsAt = self::normalizeSiteDate($startsAt);

        if (!$course || !$course instanceof Course || !$userId || !$startsAt) {
            return false;
        }

        $user = User::find($userId);
        if (!$user) {
            return false;
        }

        $user->syncXProfile();

        $existing = SpaceUserPivot::where('space_id', $course->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing && $existing->status === 'active') {
            return false;
        }

        $isDue = $startsAt <= current_time('mysql');

        if (!$existing) {
            $existing = SpaceUserPivot::create([
                'space_id'  => $course->id,
                'user_id'   => $userId,
                'role'      => 'student',
                'status'    => SpaceUserPivot::STATUS_SCHEDULED,
                'starts_at' => $startsAt,
                'meta'      => [ 'enrolled_by' => $by ],
            ]);
        } else {
            $meta = is_array($existing->meta) ? $existing->meta : [];
            $meta['enrolled_by'] = $by;

            $existing->status = SpaceUserPivot::STATUS_SCHEDULED;
            $existing->starts_at = $startsAt;
            $existing->meta = $meta;
            if (!in_array($existing->role, [ 'admin', 'moderator' ], true)) {
                $existing->role = 'student';
            }
            $existing->save();
        }

        self::resetEnrollmentCache($course->id, $userId);

        if ($isDue) {
            return self::activateScheduledEnrollment($existing) ? 'enrolled' : false;
        }

        /*
         * The member has bought or been given the course, but cannot open it before $startsAt.
         * Use this for a "your course opens on..." message. `fluent_community/course/enrolled`
         * fires later, when access starts.
         */
        do_action('fluent_community/course/enrollment_scheduled', $course, $userId, $startsAt, $by, $existing);

        return 'scheduled';
    }

    /**
     * Turn a scheduled enrollment active and fire `fluent_community/course/enrolled`.
     *
     * The status flip is one conditional UPDATE, so the hourly sweep and a member's visit
     * cannot both fire the enrolled hooks for the same row.
     *
     * @param SpaceUserPivot $enrollment
     * @return bool true when this call activated it
     */
    public static function activateScheduledEnrollment($enrollment)
    {
        if (!$enrollment || $enrollment->status !== SpaceUserPivot::STATUS_SCHEDULED) {
            return false;
        }

        /*
         * created_at becomes the moment access starts, so every report that dates enrollments by
         * created_at (Insights, "Start Date" sorting) counts the member when the course opens.
         * Keeping the purchase date there would add the member to a month that is already closed.
         * The purchase date moves to meta.scheduled_on.
         */
        $meta = is_array($enrollment->meta) ? $enrollment->meta : [];
        $meta['scheduled_on'] = (string) $enrollment->created_at;
        $now = current_time('mysql');

        $updated = SpaceUserPivot::where('id', $enrollment->id)
            ->where('status', SpaceUserPivot::STATUS_SCHEDULED)
            ->update([
                'status'     => 'active',
                'created_at' => $now,
                'meta'       => maybe_serialize($meta),
            ]);

        if (!$updated) {
            return false;
        }

        $enrollment->status = 'active';
        $enrollment->created_at = $now;
        $enrollment->meta = $meta;
        self::resetEnrollmentCache($enrollment->space_id, $enrollment->user_id);

        $user = User::find($enrollment->user_id);
        if ($user) {
            $user->cacheAccessSpaces();
        }

        $course = Course::find($enrollment->space_id);
        if ($course) {
            $by = Arr::get(is_array($enrollment->meta) ? $enrollment->meta : [], 'enrolled_by', 'scheduled');
            do_action('fluent_community/course/enrolled', $course, (int) $enrollment->user_id, $by, $enrollment);
        }

        return true;
    }

    const ACTIVATE_DUE_HOOK = 'fluent_community/course/activate_due_enrollments';

    /**
     * Activate the scheduled enrollments whose start date has passed. Runs hourly.
     *
     * Each activation runs the `course/enrolled` listeners (emails, CRM, Insights), so a cohort that
     * opens on one date is done in batches: the run stops after $limit rows or $timeBudget seconds,
     * and queues an async follow-up while due rows remain. A member who visits first opens their own row.
     *
     * @param int $limit rows per run
     * @param int $timeBudget seconds per run
     * @return int the number activated
     */
    public static function activateDueEnrollments($limit = 100, $timeBudget = 20)
    {
        $startedAt = microtime(true);

        $dueEnrollments = SpaceUserPivot::where('status', SpaceUserPivot::STATUS_SCHEDULED)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', current_time('mysql'))
            ->orderBy('starts_at', 'ASC')
            ->limit((int) $limit)
            ->get();

        $count = 0;
        $stoppedEarly = false;
        foreach ($dueEnrollments as $enrollment) {
            if (microtime(true) - $startedAt > $timeBudget) {
                $stoppedEarly = true;
                break;
            }

            if (self::activateScheduledEnrollment($enrollment)) {
                ++$count;
            }
        }

        $mayHaveMore = $stoppedEarly || count($dueEnrollments) >= (int) $limit;
        if ($mayHaveMore && function_exists('as_enqueue_async_action') && !self::hasPendingActivationBatch()) {
            as_enqueue_async_action(self::ACTIVATE_DUE_HOOK, [], 'fluent-community');
        }

        return $count;
    }

    /**
     * Whether a follow-up batch is already queued. Pending only: as_next_scheduled_action() and
     * as_has_scheduled_action() also count the running action, so a follow-up batch would find
     * itself and never queue the next one, and a large cohort would stop after two batches.
     *
     * @return bool
     */
    protected static function hasPendingActivationBatch()
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }

        return (bool) as_get_scheduled_actions([
            'hook'     => self::ACTIVATE_DUE_HOOK,
            'status'   => 'pending',
            'per_page' => 1,
        ], 'ids');
    }

    /**
     * The member's scheduled (not yet open) enrollment in a course, or null.
     *
     * @return SpaceUserPivot|null
     */
    public static function getScheduledEnrollment($courseId, $userId = null)
    {
        $row = self::getEnrollmentRow($courseId, $userId);

        return ($row && $row->status === SpaceUserPivot::STATUS_SCHEDULED) ? $row : null;
    }

    /**
     * The date the member's access starts: `starts_at` when set, else the enrollment date. Site time.
     *
     * @param SpaceUserPivot $enrollment
     * @return string
     */
    public static function getAccessStartDate($enrollment)
    {
        return $enrollment->starts_at ? $enrollment->starts_at : (string) $enrollment->created_at;
    }

    public static function resetEnrollmentCache($courseId = null, $userId = null)
    {
        if ($courseId === null) {
            self::$enrollmentCache = [];
            return;
        }

        unset(self::$enrollmentCache[(int) $courseId . '_' . (int) $userId]);
    }

    protected static function normalizeSiteDate($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);

        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $value;
    }

    public static function resolveLessonAccess($initialCanView, $lesson, $course, $user, $ctx = [])
    {
        $canView = apply_filters('fluent_community/course/can_view_lesson', $initialCanView, $lesson, $course, $user);
        $access  = apply_filters('fluent_community/course/lesson_access_info', [
            'can_view'  => $canView,
            'lock_type' => '',
        ], $lesson, $course, $user, $ctx);

        if (!empty($access['can_view'])) {
            $access['lock_type'] = '';
        }

        return $access;
    }

    /**
     * Whether the member has access to the course now. A scheduled enrollment does not count.
     */
    public static function isEnrolled($courseId, $userId = null)
    {
        return (bool) self::getCourseEnrollment($courseId, $userId);
    }

    /**
     * The enrollment that gives the member access to the course, or null.
     *
     * A scheduled enrollment (see scheduleEnrollment) gives no access, so it returns null here.
     * When its date has passed, it is activated first.
     *
     * @return SpaceUserPivot|null
     */
    public static function getCourseEnrollment($courseId, $userId = null)
    {
        $row = self::getEnrollmentRow($courseId, $userId);

        if (!$row || $row->status !== SpaceUserPivot::STATUS_SCHEDULED) {
            return $row;
        }

        if ($row->starts_at && $row->starts_at <= current_time('mysql')) {
            self::activateScheduledEnrollment($row);
            $row = self::getEnrollmentRow($courseId, $userId);

            return ($row && $row->status !== SpaceUserPivot::STATUS_SCHEDULED) ? $row : null;
        }

        return null;
    }

    /**
     * The member's enrollment row in any status, cached for the request.
     *
     * @return SpaceUserPivot|null
     */
    protected static function getEnrollmentRow($courseId, $userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return null;
        }

        $key = (int) $courseId . '_' . (int) $userId;
        if (array_key_exists($key, self::$enrollmentCache)) {
            return self::$enrollmentCache[$key];
        }

        self::$enrollmentCache[$key] = SpaceUserPivot::where('space_id', $courseId)
            ->where('user_id', $userId)
            ->first();

        return self::$enrollmentCache[$key];
    }

    public static function getCoursePublishedLessonIds($courseId)
    {
        static $counts = [];

        if (isset($counts[$courseId])) {
            return $counts[$courseId];
        }

        $counts[$courseId] = CourseLesson::where('space_id', $courseId)
            ->where('status', 'published')
            ->pluck('id')
            ->toArray();

        return $counts[$courseId];
    }

    public static function getCourseProgress($courseId, $userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return 0;
        }

        $progressMap = self::getBulkCourseProgress($courseId, [ $userId ]);

        return $progressMap[$userId] ?? 0;
    }

    public static function getBulkCourseProgress($courseId, $userIds = [])
    {
        if (empty($userIds)) {
            return [];
        }

        $progressMap = array_fill_keys($userIds, 0);

        $lessonIds = self::getCoursePublishedLessonIds($courseId);
        $totalLessons = count($lessonIds);

        if (!$totalLessons) {
            return $progressMap;
        }

        $completionCounts = Reaction::whereIn('user_id', $userIds)
            ->whereIn('object_id', $lessonIds)
            ->where('object_type', 'lesson_completed')
            ->where('type', 'completed')
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as completed_count')
            ->pluck('completed_count', 'user_id');

        foreach ($completionCounts as $userId => $completed) {
            $result = floor(($completed / $totalLessons) * 100);
            $progressMap[$userId] = min($result, 100);
        }

        return $progressMap;
    }

    /**
     * One user's progress (0-100) in many courses, keyed by course id. Same math as
     * getCourseProgress(), in two queries instead of two per course.
     */
    public static function getUserCoursesProgress($courseIds, $userId)
    {
        if (!$courseIds || !$userId) {
            return [];
        }

        $progressMap = array_fill_keys($courseIds, 0);

        $lessonCourseMap = CourseLesson::whereIn('space_id', $courseIds)
            ->where('status', 'published')
            ->pluck('space_id', 'id')
            ->toArray();

        if (!$lessonCourseMap) {
            return $progressMap;
        }

        $totals = array_count_values($lessonCourseMap);

        $completedLessonIds = Reaction::where('user_id', $userId)
            ->whereIn('object_id', array_keys($lessonCourseMap))
            ->where('object_type', 'lesson_completed')
            ->where('type', 'completed')
            ->pluck('object_id')
            ->toArray();

        $completed = [];
        foreach ($completedLessonIds as $lessonId) {
            $courseId = $lessonCourseMap[$lessonId];
            $completed[$courseId] = ($completed[$courseId] ?? 0) + 1;
        }

        foreach ($completed as $courseId => $count) {
            $progressMap[$courseId] = min(floor(($count / $totals[$courseId]) * 100), 100);
        }

        return $progressMap;
    }

    /**
     * Section, lesson and student counts for many courses, keyed by course id.
     * Same counts as the per-course queries on the course list, in three queries.
     */
    public static function getCoursesCounts($courseIds)
    {
        if (!$courseIds) {
            return [ 'sections' => [], 'lessons' => [], 'students' => [] ];
        }

        $countBySpace = function ($query) use ($courseIds) {
            return $query->whereIn('space_id', $courseIds)
                ->selectRaw('space_id, COUNT(*) as total')
                ->groupBy('space_id')
                ->pluck('total', 'space_id')
                ->toArray();
        };

        return [
            'sections' => $countBySpace(CourseTopic::query()),
            'lessons'  => $countBySpace(CourseLesson::query()),
            // Scheduled enrollments have no access yet, so they do not count as students
            'students' => $countBySpace(SpaceUserPivot::where('status', '!=', SpaceUserPivot::STATUS_SCHEDULED)),
        ];
    }

    public static function getCompletedLessonIds($courseId, $userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return [];
        }

        $key = $courseId . '_' . $userId;
        if (isset(self::$completedLessonsCache[$key])) {
            return self::$completedLessonsCache[$key];
        }

        self::$completedLessonsCache[$key] = Reaction::where('user_id', $userId)
            ->where('parent_id', $courseId)
            ->where('object_type', 'lesson_completed')
            ->where('type', 'completed')
            ->pluck('object_id')
            ->toArray();

        return self::$completedLessonsCache[$key];
    }

    public static function overallCourseProgressAverage($course)
    {
        $lessonsCount = CourseLesson::where('space_id', $course->id)
            ->where('status', 'published')
            ->count();

        if (!$lessonsCount) {
            return 0;
        }

        $allCompletedCount = Reaction::where('object_type', 'lesson_completed')
            ->where('type', 'completed')
            ->where('parent_id', $course->id)
            ->count();

        $studentsCount = $course->students_count ? $course->students_count : $course->students()->count();

        if (!$studentsCount) {
            return 0;
        }

        // calculate the average of all students
        return ceil(($allCompletedCount / ($studentsCount * $lessonsCount)) * 100);
    }

    public static function updateLessonCompletion($lesson, $userId, $state = 'completed')
    {
        // The lesson-access check may have cached the list before this write
        unset(self::$completedLessonsCache[$lesson->space_id . '_' . $userId]);

        $reaction = Reaction::where('user_id', $userId)
            ->where('object_id', $lesson->id)
            ->where('object_type', 'lesson_completed')
            ->first();

        if ($reaction) {
            if ($state !== 'completed') {
                $reaction->type = 'incomplete';
                $reaction->save();
                do_action('fluent_community/course/lesson_marked_incomplete', $lesson, $userId);
            } else {
                $reaction->type = 'completed';
                $reaction->save();
            }
            return true;
        }

        // Nothing to unmark: creating a row here would record the lesson as completed
        if ($state !== 'completed') {
            return true;
        }

        Reaction::create([
            'user_id'     => $userId,
            'object_id'   => $lesson->id,
            'object_type' => 'lesson_completed',
            'parent_id'   => $lesson->space_id,
            'type'        => 'completed',
        ]);

        do_action('fluent_community/course/lesson_completed', $lesson, $userId);

        // check if the whole module is completed
        $allTopicLessonIds = CourseLesson::where('space_id', $lesson->space_id)
            ->where('parent_id', $lesson->parent_id)
            ->where('status', 'published')
            ->pluck('id')
            ->toArray();

        $completedLessonCount = Reaction::where('user_id', $userId)
            ->whereIn('object_id', $allTopicLessonIds)
            ->where('object_type', 'lesson_completed')
            ->where('type', 'completed')
            ->count();

        if (count($allTopicLessonIds) === (int) $completedLessonCount) {
            $topic = CourseTopic::find($lesson->parent_id);
            do_action('fluent_community/course/topic_completed', $topic, $userId, $lesson);
        }

        return true;
    }

    public static function getCourseMeta($key, $default = false, $withModel = false)
    {
        $meta = Meta::where('meta_key', $key)
            ->where('object_type', 'course')
            ->first();

        if ($withModel) {
            return $meta;
        }

        return $meta ? $meta->value : $default;
    }

    public static function updateCourseMeta($key, $value)
    {
        $meta = Meta::where('meta_key', $key)
            ->where('object_type', 'course')
            ->first();

        if ($meta) {
            $meta->update([
                'value' => $value,
            ]);
            return $meta;
        }

        return Meta::create([
            'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'value'       => $value,
            'object_type' => 'course',
        ]);
    }

    public static function completeCourse($course, $lesson, $userId)
    {
        $activity = Activity::where('feed_id', $course->id)
            ->where('user_id', $userId)
            ->where('action_name', 'course_completed')
            ->first();

        if ($activity) {
            if (strtotime($lesson->scheduled_at) > strtotime($activity->updated_at)) {
                $activity->updated_at = current_time('mysql');
                $activity->save();
                do_action('fluent_community/course/completed', $course, $userId);
                return true;
            }
            return false;
        }

        Activity::create([
            'feed_id'     => $course->id,
            'user_id'     => $userId,
            'action_name' => 'course_completed',
        ]);

        do_action('fluent_community/course/completed', $course, $userId);

        return true;
    }

    public static function resetCourseProgress($courseId, $userId)
    {
        $course = Course::find($courseId);
        if (!$course || !$userId) {
            return false;
        }

        do_action('fluent_community/course/before_progress_reset', $course, $userId);

        try {
            Helper::dbTransaction(function () use ($userId, $courseId) {
                // Wipe completion state entirely (both 'completed' and 'incomplete' types) so the student starts pristine.
                Reaction::where('user_id', $userId)
                    ->where('parent_id', $courseId)
                    ->where('object_type', 'lesson_completed')
                    ->delete();

                Activity::where('user_id', $userId)
                    ->where('feed_id', $courseId)
                    ->where('action_name', 'course_completed')
                    ->delete();
            });
        } catch (\Exception $e) {
            return false;
        }

        unset(self::$completedLessonsCache[$courseId . '_' . $userId]);

        do_action('fluent_community/course/progress_reset', $course, $userId);

        return true;
    }

    public static function enrollCourse($course, $userId = null, $by = 'self', $skipSync = false)
    {
        return Helper::addToSpace($course, $userId, 'student', $by, $skipSync);
    }

    public static function enrollCourses($courseIds, $userId = null, $by = 'self')
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        $enrolledCourseIds = [];

        foreach ($courseIds as $courseId) {
            $course = Course::find($courseId);
            if ($course) {
                if (self::enrollCourse($course, $userId, $by)) {
                    $enrolledCourseIds[] = $courseId;
                }
            }
        }

        return $enrolledCourseIds;
    }

    public static function leaveCourse($course, $userId = null, $by = 'self')
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return false;
        }

        return Helper::removeFromSpace($course, $userId, $by);
    }

    public static function leaveCourses($courseIds, $userId = null, $by = 'self')
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        $processedCourseIds = [];

        foreach ($courseIds as $courseId) {
            $course = Course::find($courseId);
            if ($course) {
                if (self::leaveCourse($course, $userId, $by)) {
                    $processedCourseIds[] = $courseId;
                }
            }

        }

        return $processedCourseIds;
    }

    public static function getEnrolledCourseIds($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return [];
        }

        return SpaceUserPivot::where('user_id', $userId)
            ->where('role', 'student')
            ->pluck('space_id')
            ->toArray();

    }

    public static function getSectionAccessDate($section, $courseType, $enrollment = null)
    {
        if ($courseType === 'self_paced') {
            return null;
        }

        if ($courseType === 'scheduled') {
            return $section->scheduled_at;
        }

        // This is for structured course
        if (!$enrollment) {
            return null;
        }

        $accessAfterEnrollment = $section->reactions_count;

        // The drip counts from the day the member's access starts (scheduleEnrollment), else the enrollment date
        $start = strtotime(self::getAccessStartDate($enrollment));

        return gmdate('Y-m-d H:i:s', $start + $accessAfterEnrollment * 86400);
    }

    public static function sanitizeLessonMeta($meta, $lesson)
    {
        $validFields = [
            'enable_comments',
            'enable_media',
            'media',
            'video_length',
            'featured_image_id',
            'free_preview_lesson',
            'require_video_completion',
            'video_completion_threshold',
            'auto_complete_on_video_end',
        ];

        if ($lesson->isQuizType()) {
            $quizFields = [ 'quiz_questions', 'passing_score', 'enable_passing_score', 'enforce_passing_score', 'hide_result', 'shuffle_options', 'shuffle_questions', 'layout', 'limit_attempts', 'max_attempts', 'enable_time_limit', 'time_limit' ];
            $validFields = wp_parse_args($validFields, $quizFields);
        }

        $meta = Arr::only($meta, $validFields);

        $yesNoFields = [ 'enable_comments', 'enable_media', 'free_preview_lesson', 'require_video_completion', 'auto_complete_on_video_end', 'enable_passing_score', 'enforce_passing_score', 'hide_result', 'shuffle_options', 'shuffle_questions', 'limit_attempts', 'enable_time_limit' ];

        foreach ($yesNoFields as $field) {
            $meta[$field] = Arr::get($meta, $field, 'no') === 'yes' ? 'yes' : 'no';
        }

        if ($lesson->isQuizType()) {
            $meta['layout'] = Arr::get($meta, 'layout') === 'one_by_one' ? 'one_by_one' : 'all';
            $meta['max_attempts'] = min(100, max(1, absint(Arr::get($meta, 'max_attempts', 3))));
            $meta['time_limit'] = min(600, max(1, absint(Arr::get($meta, 'time_limit', 20)))); // minutes
        }

        if (Arr::get($meta, 'enable_media') === 'yes') {
            $media = Arr::get($meta, 'media', []);
            if ($media) {
                $meta['media'] = self::sanitizeMedia($media);
            }
        } else {
            $meta['media'] = [
                'provider' => '',
            ];
        }

        $numericFields = [ 'video_length', 'passing_score', 'video_completion_threshold' ];

        foreach ($numericFields as $field) {
            $meta[$field] = absint(Arr::get($meta, $field, 0));
        }

        $meta['passing_score'] = min(100, $meta['passing_score']);

        // 0 means "not set" — the gate falls back to its default threshold
        if ($meta['video_completion_threshold']) {
            $meta['video_completion_threshold'] = min(100, max(1, $meta['video_completion_threshold']));
        }

        return apply_filters('fluent_community/lesson/sanitize_meta', $meta, $lesson);
    }

    public static function sanitizeMedia($media)
    {
        return array_filter([
            'type'         => sanitize_text_field(Arr::get($media, 'type', '')),
            'url'          => sanitize_url(Arr::get($media, 'url', '')),
            'content_type' => sanitize_text_field(Arr::get($media, 'content_type', '')),
            'provider'     => sanitize_url(Arr::get($media, 'provider', '')),
            'title'        => sanitize_text_field(Arr::get($media, 'title', '')),
            'author_name'  => sanitize_text_field(Arr::get($media, 'author_name', '')),
            'html'         => CustomSanitizer::sanitizeRichText(Arr::get($media, 'html', '')),
            'image'        => sanitize_url(Arr::get($media, 'image', '')),
        ]);
    }

    public static function getCourseCategories()
    {
        $terms = Term::whereHas('base_spaces', function ($q) {
            $q->where('type', 'course');
        })->orderBy('title', 'ASC')->get();
        // Respect the admin-defined order (settings.serial)
        $terms = $terms->sort(function ($a, $b) {
            $serialA = Arr::get($a->settings, 'serial');
            $serialB = Arr::get($b->settings, 'serial');
            if ($serialA === null && $serialB === null) {
                return 0;
            }
            if ($serialA === null) {
                return 1;
            }
            if ($serialB === null) {
                return -1;
            }
            return (int) $serialA <=> (int) $serialB;
        })->values();

        $formattedTerms = [];

        foreach ($terms as $term) {
            $formattedTerms[] = [
                'id'    => $term->id,
                'title' => $term->title,
                'slug'  => $term->slug,
            ];
        }

        return $formattedTerms;
    }

    public static function getUserCourses($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return null;
        }

        // Every enrollment, scheduled ones included: the FluentCRM profile shows each one with its status
        $courseIds = SpaceUserPivot::where('user_id', $userId)
            ->where('role', 'student')
            ->pluck('space_id')
            ->toArray();

        return Course::whereIn('id', $courseIds ? $courseIds : [ 0 ])
            ->with('enrollment', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->get();
    }

    public static function santizeLessonBody($body)
    {
        return CustomSanitizer::santizeEditorBody($body);
    }

    public static function formatLessonData($course, $lesson, $user = null, $config = [])
    {
        $canViewLesson = Arr::get($config, 'can_view', false);
        $parseContent = Arr::get($config, 'parse_content', true);

        $inlineCss = '';
        if ($parseContent && $canViewLesson) {
            $content = $lesson->message_rendered;

            // message_rendered still holding block markup never went through the_content
            if (!$content || has_blocks($content)) {
                $source = $lesson->message ? $lesson->message : $content;

                if ($source) {
                    $content = CustomSanitizer::renderEditorBody($source, $lesson->user_id);
                    if (function_exists('wp_style_engine_get_stylesheet_from_context')) {
                        $core_styles_keys = array( 'block-supports' );
                        // Adds comment if code is prettified to identify core styles sections in debugging.
                        foreach ($core_styles_keys as $style_key) {
                            $inlineCss .= wp_style_engine_get_stylesheet_from_context($style_key, []);
                        }
                    }
                }
            }

            if (!$content) {
                $content = '';
            }
            $content = (new SmartCodeParser())->parse($content, $user);
        } else {
            $content = '';
        }

        $formattedLesson = [
            'id'             => $lesson->id,
            'title'          => $lesson->title,
            'content'        => $content,
            'slug'           => $lesson->slug,
            'course_id'      => $lesson->space_id,
            'section_id'     => $lesson->parent_id,
            'created_at'     => $lesson->created_at->format('Y-m-d H:i:s'),
            'content_type'   => $lesson->content_type,
            'featured_image' => $lesson->featured_image,
            'meta'           => $lesson->getPublicLessonMeta($canViewLesson),
            'comments_count' => $lesson->comments_count,
            'is_locked'      => Arr::get($config, 'is_locked', false),
            'unlock_date'    => Arr::get($config, 'unlock_date', ''),
            'lock_type'      => Arr::get($config, 'lock_type', ''),
            'can_view'       => $canViewLesson,
            'inline_css'     => $inlineCss,
        ];

        if (!$canViewLesson) {
            $formattedLesson['access_message'] = static::getAccessMessage($course, $lesson, $config);
        }

        return $formattedLesson;
    }

    public static function copyLessonDocuments($lesson, $newLesson)
    {
        if (!$lesson || !$newLesson) {
            return;
        }

        $newLessonMeta = $newLesson->meta;
        $newLessonMeta['document_lists'] = [];
        foreach ($lesson->media as $media) {
            if (!$media->is_active) {
                continue;
            }
            $newMedia = $media->replicate();
            $newMedia->feed_id = $newLesson->id;
            $newMedia->save();
            if ($media->object_source === 'lesson_document') {
                $newLessonMeta['document_lists'][] = $newMedia->getPrivateFileMeta();
            }
        }

        $newLesson->meta = $newLessonMeta;
        $newLesson->save();
    }

    public static function getAccessMessage($course, $lesson, $config)
    {
        $isLocked = Arr::get($config, 'is_locked', false);
        $unlockDate = Arr::get($config, 'unlock_date', '');
        $courseLessonsUrl = Helper::baseUrl('/course/' . $course->slug . '/lessons');

        $headerMessage = __('This lesson is currently locked', 'fluent-community');
        $bodyMessage = __('Please enroll in this course to access this lesson', 'fluent-community');
        $backToCourseText = __('Back to Course', 'fluent-community');

        if ($isLocked && Arr::get($config, 'lock_type') === 'course_opens' && $unlockDate) {
            $headerMessage = __('This course opens for you soon', 'fluent-community');
            /* translators: %s is replaced by the date the course opens */
            $bodyMessage = sprintf(__('It opens on %s', 'fluent-community'),
                date_i18n(get_option('date_format'), strtotime($unlockDate))
            );
        } elseif ($isLocked && Arr::get($config, 'lock_type') === 'sequential') {
            $headerMessage = __('Complete the previous lesson first', 'fluent-community');
            $bodyMessage = __('You need to complete the previous lesson before unlocking this one.', 'fluent-community');
        } elseif ($isLocked && $unlockDate) {
            $headerMessage = __('This lesson is not published for you yet', 'fluent-community');
            /* translators: %s is replaced by the date */
            $bodyMessage = sprintf(__('It will be available to you on %s', 'fluent-community'),
                date_i18n(get_option('date_format'), strtotime($unlockDate))
            );
        }

        /* translators: %s is replaced by the header message, %s is replaced by the body message, %s is replaced by the course lessons url, %s is replaced by the back to course text */
        $accessMessage = sprintf('<div class="fcom_locker"><h1>%s</h1><p>%s</p><a href="%s" class="el-button el-button--info">%s</a></div>',
            $headerMessage,
            $bodyMessage,
            $courseLessonsUrl,
            $backToCourseText
        );

        return apply_filters('fluent_community/course/access_message_html', $accessMessage, $course, $lesson, $config);
    }

    public static function getParsedEmailSubject($text, $section, $user)
    {
        $parsedSubject = (new SmartCodeParser())->parse($text, $user, $section, false);

        return $parsedSubject;
    }

    public static function getParsedEmailBody($text, $section, $user)
    {
        $parsedBody = (new SmartCodeParser())->parse($text, $user, $section);

        $emailComposer = new EmailComposer();

        $emailComposer->addBlock('html_content', $parsedBody);
        $emailComposer->setDefaultLogo();
        $emailComposer->setDefaultFooter();

        return str_replace(
            '##email_notification_url##',
            ProfileHelper::getSignedNotificationPrefUrl($user->ID),
            $emailComposer->getHtml()
        );
    }

    /**
     * The single lesson access rule, shared by the lesson page, the course page and comments.
     *
     * @return array can_view, lock_type, unlock_date, has_section_access, is_admin
     */
    public static function getLessonAccess($lesson, $course, $user, $enrollment)
    {
        $isCourseAdmin = $course->isCourseAdmin($user);

        if (!$isCourseAdmin && ($course->status !== 'published' || $lesson->status !== 'published')) {
            return [
                'can_view'           => false,
                'lock_type'          => '',
                'unlock_date'        => '',
                'has_section_access' => false,
                'is_admin'           => false,
            ];
        }

        $section = $lesson->topic;
        $unlockDate = '';
        $hasSectionAccess = false;

        $courseOpensOn = null;

        if ($enrollment) {
            $unlockDate = $section ? self::getSectionAccessDate($section, $course->getCourseType(), $enrollment) : null;
            $hasSectionAccess = !$unlockDate || strtotime($unlockDate) <= strtotime(current_time('mysql'));
            if ($hasSectionAccess) {
                $unlockDate = null;
            }
        } elseif ($user && !$isCourseAdmin) {
            // Enrolled ahead of time (scheduleEnrollment): no access yet, but the lock says when it opens
            $scheduled = self::getScheduledEnrollment($course->id, $user->ID);
            if ($scheduled && $scheduled->starts_at) {
                $courseOpensOn = $scheduled->starts_at;
                $unlockDate = $courseOpensOn;
            }
        }

        $hasPublicAccess = $course->hasPublicLessonView();

        $access = self::resolveLessonAccess($hasSectionAccess || $isCourseAdmin || $hasPublicAccess, $lesson, $course, $user, [
            'enrollment'         => $enrollment,
            'section'            => $section,
            'is_admin'           => $isCourseAdmin,
            'has_section_access' => $hasSectionAccess,
            'has_public_access'  => $hasPublicAccess,
        ]);

        // The course-wide open date outranks any per-lesson lock a filter set
        if ($courseOpensOn && empty($access['can_view'])) {
            $access['lock_type'] = 'course_opens';
        }

        $access['unlock_date'] = $unlockDate;
        $access['has_section_access'] = $hasSectionAccess;
        $access['is_admin'] = $isCourseAdmin;

        return $access;
    }

    /**
     * Whether the course's lesson list (section and lesson titles) may be shown to a user
     * who is not necessarily allowed to open the lessons.
     *
     * Course admins, enrolled students and visitors of a published public course see it.
     * On a private course, a visitor who is not enrolled sees it only when the admin shows
     * the "Lessons" block on the course's custom lock screen. A secret course never shows
     * it to them. A lesson the user can open is listed regardless (see processCourse).
     */
    public static function canSeeLessonList($course, $enrollment, $isCourseAdmin)
    {
        if ($isCourseAdmin) {
            return true;
        }

        if ($course->status !== 'published') {
            return false;
        }

        if ($enrollment || $course->privacy === 'public') {
            return true;
        }

        if ($course->privacy !== 'private') {
            return false;
        }

        return self::lockscreenShowsLessons($course);
    }

    public static function lockscreenShowsLessons($course)
    {
        if (Arr::get($course->settings, 'custom_lock_screen') !== 'yes') {
            return false;
        }

        foreach ((array) LockscreenService::getLockscreenSettings($course) as $field) {
            if (Arr::get($field, 'type') === 'lesson' && !Arr::isTrue($field, 'hidden')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $user can open the lesson, by the same rule as the lesson page.
     */
    public static function canAccessLesson($lesson, $course, $user, $enrollment)
    {
        return !empty(self::getLessonAccess($lesson, $course, $user, $enrollment)['can_view']);
    }

    /**
     * Whether $feed is a lesson that $user cannot open. A lesson whose course no longer exists
     * counts as locked. Any other feed type is never locked.
     */
    public static function isLockedLesson($feed, $user)
    {
        if ($feed->type !== 'course_lesson') {
            return false;
        }

        $lesson = CourseLesson::find($feed->id);
        $course = $lesson ? $lesson->course : null;

        return !$course || !self::canAccessLesson($lesson, $course, $user, self::getCourseEnrollment($course->id, $user ? $user->ID : null));
    }
}
