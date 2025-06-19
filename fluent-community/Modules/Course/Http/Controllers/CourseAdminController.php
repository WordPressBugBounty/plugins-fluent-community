<?php

namespace FluentCommunity\Modules\Course\Http\Controllers;

use FluentCommunity\App\App;
use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Http\Controllers\Controller;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Reaction;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\CustomSanitizer;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\LockscreenService;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Model\CourseLesson;
use FluentCommunity\Modules\Course\Model\CourseTopic;
use FluentCommunity\Modules\Course\Services\CourseHelper;

class CourseAdminController extends Controller
{
    public function getCourses(Request $request)
    {
        $user = $this->getUser();

        $courses = Course::searchBy($request->getSafe('search'))
            ->byAdminAccess($user->ID)
            ->orderBy('id', 'DESC')
            ->with(['owner'])
            ->paginate();

        foreach ($courses as $course) {
            $course->students_count = $course->students()->count();
            if (!$course->cover_photo) {
                $course->cover_photo = FLUENT_COMMUNITY_PLUGIN_URL . 'assets/images/course-placeholder.jpg';
            }
            $course->sectionsCount = CourseTopic::where('space_id', $course->id)->count();
            $course->lessonsCount = CourseLesson::where('space_id', $course->id)->count();
        }

        return [
            'courses' => $courses
        ];
    }

    public function createCourse(Request $request)
    {
        $this->validate($request->all(), [
            'title'       => 'required',
            'description' => 'required',
            'privacy'     => 'required|in:public,private,secret',
            'course_type' => 'required|in:self_paced,structured,scheduled'
        ]);

        $parentId = $request->get('parent_id');
        if ($parentId) {
            $serial = BaseSpace::where('parent_id', $parentId)->max('serial') + 1;
        } else {
            $serial = BaseSpace::max('serial') + 1;
        }

        $courseData = [
            'parent_id'   => $request->get('parent_id') ?: NULL,
            'title'       => $request->getSafe('title', 'sanitize_text_field'),
            'privacy'     => $request->get('privacy'),
            'description' => wp_kses_post($request->get('description')),
            'status'      => $request->get('status', 'draft'),
            'settings'    => [
                'course_type'                    => $request->get('course_type'),
                'emoji'                          => CustomSanitizer::sanitizeEmoji($request->get('settings.emoji', '')),
                'shape_svg'                      => CustomSanitizer::sanitizeSvg($request->get('settings.shape_svg', '')),
                'disable_comments'               => $request->get('settings.disable_comments') === 'yes' ? 'yes' : 'no',
                'hide_members_count'             => $request->get('settings.hide_members_count') === 'yes' ? 'yes' : 'no',
                'course_layout'                  => $request->get('settings.course_layout') === 'modern' ? 'modern' : 'classic',
                'course_details'                 => CustomSanitizer::unslashMarkdown(sanitize_textarea_field(trim($request->get('settings.course_details')))),
                'hide_instructor_view'           => $request->get('settings.hide_instructor_view') === 'yes' ? 'yes' : 'no',
                'show_instructor_students_count' => $request->get('settings.show_instructor_students_count') === 'yes' ? 'yes' : 'no'
            ],
            'serial'      => $serial
        ];

        $lockScreenType = $request->get('settings.custom_lock_screen');
        if (!in_array($lockScreenType, ['yes', 'no', 'redirect']) || $request->get('privacy') != 'private') {
            $lockScreenType = 'no';
        }

        $courseData['settings']['custom_lock_screen'] = $lockScreenType;

        if ($lockScreenType === 'redirect') {
            $redirectUrl = $request->get('settings.onboard_redirect_url');
            if (!$redirectUrl || !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                return $this->sendError([
                    'message' => __('Course Redirect URL is not valid', 'fluent-community')
                ]);
            }
            $courseData['settings']['onboard_redirect_url'] = sanitize_url($redirectUrl);
        }

        if ($request->get('privacy') == 'public' && $request->get('course_type') == 'self_paced') {
            $courseData['settings']['public_lesson_view'] = $request->get('settings.public_lesson_view') == 'yes' ? 'yes' : 'no';
        }

        $slug = $request->get('slug');

        if (!$slug) {
            $slug = sanitize_title($courseData['title']);
        }

        if ($slug) {
            $slug = Utility::slugify($slug);
            $exist = Course::where('slug', $slug)
                ->exists();

            if ($exist) {
                $slug = $slug . '-' . time();
            }

            $courseData['slug'] = $slug;
        }

        do_action('fluent_community/course/before_create', $courseData);

        $course = Course::create($courseData);

        $imageTypes = ['cover_photo', 'logo'];

        $metaData = [];
        foreach ($imageTypes as $type) {
            if (!empty($request->get($type))) {
                $media = Helper::getMediaFromUrl($request->get($type));
                if (!$media || $media->is_active) {
                    continue;
                }
                $metaData[$type] = $media->public_url;
                $media->update([
                    'is_active'     => true,
                    'user_id'       => get_current_user_id(),
                    'sub_object_id' => $course->id,
                    'object_source' => 'space_' . $type
                ]);
            }
        }

        if ($metaData) {
            $course->fill($metaData);
            $course->save();
        }

        $course->syncCategories($request->get('category_ids', []));

        do_action('fluent_community/course/created', $course);

        return [
            'course' => $course
        ];
    }

    public function findCourse(Request $request, $courseId)
    {
        $course = Course::where('id', $courseId)
            ->with(['owner'])
            ->firstOrFail();

        $course->students_count = $course->students()->count();
        $course->course_type = $course->settings['course_type'];
        $course->lockscreen = $course->getLockscreen();

        $course->category_ids = $course->categories->pluck('id')->toArray();

        if ($course->students_count) {
            $course->completed_students = $course->getCompletedStrundesCount();
            $course->overAllProgress = CourseHelper::overallCourseProgressAverage($course);
        }

        unset($course->categories);

        $course = apply_filters('fluent_community/course_info', $course);

        return [
            'course' => $course
        ];
    }

    public function updateCourse(Request $request, $courseId)
    {
        $this->validate($request->all(), [
            'title'       => 'required',
            'description' => 'required',
            'privacy'     => 'required|in:public,private,secret',
            'status'      => 'required|in:draft,published,archived',
            'course_type' => 'required|in:self_paced,structured,scheduled',
            'created_by'  => 'exists:users,ID'
        ]);

        $course = Course::findOrFail($courseId);

        $courseData = [
            'title'       => $request->getSafe('title', 'sanitize_text_field'),
            'privacy'     => $request->get('privacy'),
            'description' => wp_kses_post($request->get('description')),
            'status'      => $request->get('status'),
            'cover_photo' => $request->getSafe('cover_photo', 'sanitize_url'),
            'parent_id'   => $request->get('parent_id') ?: NULL,
        ];

        $slug = $request->get('slug');
        if ($slug && $course->slug != $slug) {
            $slug = Utility::slugify($slug);

            $exist = App::getInstance('db')->table('fcom_spaces')->where('slug', $slug)
                ->where('id', '!=', $course->id)
                ->exists();

            if ($exist || !$slug) {
                return $this->sendError([
                    'message' => __('Slug is already taken. Please use a different slug', 'fluent-community')
                ]);
            }

            $courseData['slug'] = $slug;
        }

        if ($request->get('created_by') && Helper::isSiteAdmin()) {
            $courseData['created_by'] = (int)$request->get('created_by');
        }

        $imageTypes = ['cover_photo', 'logo'];

        foreach ($imageTypes as $type) {
            if (!empty($request->get($type))) {
                $media = Helper::getMediaFromUrl($request->get($type));
                if (!$media || $media->is_active) {
                    continue;
                }
                $courseData[$type] = $media->public_url;
                $media->update([
                    'is_active'     => true,
                    'user_id'       => get_current_user_id(),
                    'sub_object_id' => $course->id,
                    'object_source' => 'space_' . $type
                ]);
            } else {
                $courseData[$type] = null;
            }
        }

        $existingSettings = $course->settings;
        $existingSettings['course_type'] = $request->get('course_type');

        $lockScreenType = $request->get('settings.custom_lock_screen');
        if (!in_array($lockScreenType, ['yes', 'no', 'redirect']) || $request->get('privacy') != 'private') {
            $lockScreenType = 'no';
        }
        if ($lockScreenType == 'redirect') {
            $redirectUrl = $request->get('settings.onboard_redirect_url');
            if (!$redirectUrl || !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                return $this->sendError([
                    'message' => __('Course Redirect URL is not valid', 'fluent-community')
                ]);
            }
            $existingSettings['onboard_redirect_url'] = sanitize_url($redirectUrl);
        }

        $existingSettings['custom_lock_screen'] = $lockScreenType;
        $existingSettings['emoji'] = CustomSanitizer::sanitizeEmoji($request->get('settings.emoji', ''));
        $existingSettings['shape_svg'] = CustomSanitizer::sanitizeSvg($request->get('settings.shape_svg', ''));
        $existingSettings['disable_comments'] = $request->get('settings.disable_comments') === 'yes' ? 'yes' : 'no';
        $existingSettings['hide_members_count'] = $request->get('settings.hide_members_count') === 'yes' ? 'yes' : 'no';
        $existingSettings['hide_instructor_view'] = $request->get('settings.hide_instructor_view') === 'yes' ? 'yes' : 'no';
        $existingSettings['show_instructor_students_count'] = $request->get('settings.show_instructor_students_count') === 'yes' ? 'yes' : 'no';
        $existingSettings['course_layout'] = $request->get('settings.course_layout') === 'modern' ? 'modern' : 'classic';
        $existingSettings['course_details'] = CustomSanitizer::unslashMarkdown(sanitize_textarea_field(trim($request->get('settings.course_details'))));

        if ($request->get('privacy') == 'public' && $existingSettings['course_type'] == 'self_paced') {
            $existingSettings['public_lesson_view'] = $request->get('settings.public_lesson_view') == 'yes' ? 'yes' : 'no';
        } else {
            unset($existingSettings['public_lesson_view']);
        }

        $courseData['settings'] = $existingSettings;


        $previousStatus = $course->status;

        $course->fill($courseData);
        $dirtyFields = $course->getDirty();

        if ($dirtyFields) {
            $course->save();
            do_action('fluent_community/course/updated', $course, $dirtyFields);
            if ($previousStatus != 'published' && $course->status == 'published') {
                do_action('fluent_community/course/published', $course);
            }

        }

        $course->syncCategories($request->get('category_ids', []));

        $metaSettings = $request->get('meta_settings', []);
        if($metaSettings) {
            foreach ($metaSettings as $metaProvider => $metaData) {
                do_action('fluent_community/course/update_meta_settings_'.$metaProvider, $metaData, $course);
            }
        }

        return [
            'message' => __('Course has been updated successfully.', 'fluent-community'),
            'course'  => $course
        ];
    }

    public function deleteCourse(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        do_action('fluent_community/course/before_delete', $course);

        // Let's remove the reactions
        Reaction::query()->whereHas('feed', function ($q) use ($course) {
            $q->where('space_id', $course->id);
        })->delete();

        Comment::whereHas('post', function ($q) use ($course) {
            $q->where('space_id', $course->id);
        })->delete();

        $courseLessons = CourseLesson::where('space_id', $course->id)->get();

        foreach ($courseLessons as $courseLesson) {
            do_action('fluent_community/lesson/before_deleted', $courseLesson);
            $courseLesson->delete();
        }

        // Let's delete the student enrollments
        SpaceUserPivot::where('space_id', $course->id)
            ->delete();

        $courseId = $course->id;
        $course->delete();

        do_action('fluent_community/course/deleted', $courseId);

        return [
            'message' => __('Course has been deleted successfully along with all the associated data', 'fluent-community')
        ];
    }

    public function getCourseComments(Request $request, $courseId)
    {
        Course::findOrFail($courseId);

        $comments = Comment::whereHas('post', function ($q) use ($courseId) {
            return $q->where('space_id', $courseId);
        })
            ->orderBy('id', 'DESC')
            ->with([
                'post'     => function ($q) {
                    return $q->select(['id', 'title', 'slug']);
                },
                'xprofile' => function ($q) {
                    $q->select(ProfileHelper::getXProfilePublicFields());
                }
            ])
            ->paginate();

        foreach ($comments as $comment) {
            if ($comment->user) {
                $comment->user->makeHidden(['user_email']);
            }
            $likedIds = FeedsHelper::getLikedIdsByUserFeedId($comment->post_id, get_current_user_id());
            if ($likedIds && in_array($comment->id, $likedIds)) {
                $comment->liked = 1;
            }
        }

        return [
            'comments' => $comments
        ];
    }

    public function getCourseStudents(Request $request, $courseId)
    {
        Course::findOrFail($courseId);

        $search = $request->getSafe('search', 'sanitize_text_field');

        $students = XProfile::whereHas('space_pivot', function ($q) use ($courseId) {
            return $q->where('space_id', $courseId)
                ->where('role', 'student');
        })
            ->searchBy($search)
            ->whereHas('user')
            ->with([
                'space_pivot' => function ($q) use ($courseId) {
                    return $q->where('space_id', $courseId);
                },
            ])
            ->select(ProfileHelper::getXProfilePublicFields())
            ->paginate();

        foreach ($students as $student) {
            $student->progress = CourseHelper::getCourseProgress($courseId, $student->user_id);
        }

        return [
            'students' => $students
        ];
    }

    public function addStudent(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        $this->validate($request->all(), [
            'user_id' => 'required|exists:users,ID'
        ]);

        $userId = (int)$request->get('user_id');
        $targetUser = User::findOrFail($userId);
        $xprofile = $targetUser->syncXProfile();

        if ($xprofile && $xprofile->status != 'active') {
            return $this->sendError([
                'message' => __('Selected user is not active', 'fluent-community')
            ]);
        }

        $enrolled = CourseHelper::enrollCourse($course, $userId, 'by_admin');

        if (!$enrolled) {
            return $this->sendError([
                'message' => __('User is already added to this course.', 'fluent-community')
            ]);
        }

        return [
            'message' => __('User has been added to this course', 'fluent-community')
        ];
    }

    public function removeStudent(Request $request, $courseId, $studentId)
    {
        $course = Course::findOrFail($courseId);

        $student = SpaceUserPivot::bySpace($course->id)
            ->byUser($studentId)
            ->first();

        if (!$student) {
            return $this->sendError([
                'message' => __('Selected user is not a student of this course', 'fluent-community')
            ]);
        }

        Helper::removeFromSpace($course, $studentId, 'by_admin');

        return [
            'message' => __('Student has been removed from this course', 'fluent-community')
        ];
    }

    public function getSections(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        $sectionsQuery = CourseTopic::where('space_id', $courseId)
            ->orderBy('priority', 'ASC');

        if (in_array('only_published', $request->get('conditions', []))) {
            $sectionsQuery->where('status', 'published')
                ->with(['lessons' => function ($q) {
                    $q->where('status', 'published');
                }]);
        }

        if (empty($request->get('conditions', []))) {
            $sectionsQuery->with(['lessons']);
        }

        $sections = $sectionsQuery->get();

        $data = [
            'sections' => $sections
        ];

        if ($request->get('with_lock_screen')) {
            $data['lockscreen'] = LockscreenService::getLockscreenSettings($course);
        }

        return $data;
    }

    public function getSection(Request $request, $courseId, $topicId)
    {
        $topic = CourseTopic::where('space_id', $courseId)
            ->whereHas('course', function ($query) use ($courseId) {
                $query->where('id', $courseId);
            })
            ->where('id', $topicId)
            ->with(['lessons'])
            ->firstOrFail();

        return [
            'topic' => $topic
        ];
    }

    public function resetSectionIndexes(Request $request, $courseId)
    {
        $indexes = $request->get('indexes', []);

        $sections = CourseTopic::where('space_id', $courseId)
            ->whereIn('id', array_keys($indexes))
            ->get();

        foreach ($sections as $section) {
            if (!isset($indexes[$section->id])) continue;
            $section->priority = $indexes[$section->id];
            $section->save();
        }

        return [
            'sections' => $sections,
            'message'  => __('Section indexes have been updated successfully.', 'fluent-community')
        ];
    }

    public function resetLessonIndexes(Request $request, $courseId, $sectionId)
    {
        $indexes = $request->get('indexes', []);

        $lessons = CourseLesson::where('space_id', $courseId)
            ->where('parent_id', $sectionId)
            ->whereIn('id', array_keys($indexes))
            ->get();

        foreach ($lessons as $lesson) {
            if (!isset($indexes[$lesson->id])) continue;
            $lesson->priority = $indexes[$lesson->id];
            $lesson->save();
        }

        return [
            'lessons' => $lessons,
            'message' => __('Lesson indexes have been updated successfully.', 'fluent-community')
        ];
    }

    public function moveLesson(Request $request, $courseId)
    {
        $lessonId = $request->getSafe('lesson_id', 'intval');
        $sectionId = $request->getSafe('section_id', 'intval');

        Course::findOrFail($courseId);
        CourseTopic::findOrFail($sectionId);

        $lesson = CourseLesson::findOrFail($lessonId);

        $lesson->update([
            'parent_id' => $sectionId
        ]);

        return [
            'message' => __('Lesson has been moved successfully', 'fluent-community')
        ];
    }

    public function createSection(Request $request, $courseId)
    {
        $this->validate($request->all(), [
            'title' => 'required'
        ]);

        $sectionData = [
            'title'    => $request->getSafe('title'),
            'space_id' => $courseId,
            'status'   => 'published'
        ];

        Course::findOrFail($courseId);

        $section = CourseTopic::create($sectionData);

        $section->load('lessons');

        return [
            'message' => __('Topic has been created successfully.', 'fluent-community'),
            'section' => $section
        ];
    }

    public function updateSection(Request $request, $courseId, $tipicId)
    {
        $this->validate($request->all(), [
            'title'  => 'required',
            'status' => 'required|in:draft,published,archived'
        ]);

        $course = Course::findOrFail($courseId);

        $topic = CourseTopic::where('space_id', $courseId)
            ->where('id', $tipicId)
            ->firstOrFail();


        $topicData = [
            'title'  => $request->getSafe('title'),
            'status' => $request->get('status')
        ];

        $course->update($topicData);

        return [
            'message' => __('Topic has been updated successfully.', 'fluent-community'),
            'topic'   => $topic
        ];
    }

    public function patchSection(Request $request, $courseId, $tipicId)
    {
        $course = Course::findOrFail($courseId);

        $topic = CourseTopic::where('space_id', $courseId)
            ->where('id', $tipicId)
            ->firstOrFail();

        $acceptedFields = ['title', 'status'];

        if ($course->getCourseType() == 'scheduled') {
            $acceptedFields[] = 'scheduled_at';
        } else if ($course->getCourseType() == 'structured') {
            $acceptedFields[] = 'reactions_count';
        }


        $topicData = $request->only($acceptedFields);

        if (!empty($topicData['scheduled_at'])) {
            $topic->reactions_count = 0;
        } else if (isset($topicData['reactions_count'])) {
            $topic->scheduled_at = null;
            $topic->reactions_count = $topicData['reactions_count'];
        }

        $topicData = array_filter($topicData);
        $topic->fill($topicData);
        $topic->save();

        return [
            'message' => __('Topic has been updated successfully.', 'fluent-community'),
            'topic'   => $topic
        ];
    }

    public function deleteSection(Request $request, $courseId, $sectionId)
    {
        $topic = CourseTopic::where([
            'id'       => $sectionId,
            'space_id' => $courseId
        ])->firstOrFail();

        $topic->delete();

        $lessons = CourseLesson::where([
            'parent_id' => $sectionId,
            'space_id'  => $courseId
        ])->get();

        foreach ($lessons as $lesson) {
            do_action('fluent_community/lesson/before_deleted', $lesson);
            $lesson->delete();
        }

        return [
            'message' => __('Section has been deleted successfully.', 'fluent-community')
        ];
    }

    public function getLessons(Request $request, $courseId)
    {
        Course::findOrFail($courseId);

        $lessons = CourseLesson::where('space_id', $courseId)
            ->orderBy('priority', 'ASC');

        $topicId = (int)$request->get('topic_id');

        if ($topicId) {
            $lessons = $lessons->where('parent_id', $topicId);
        }

        $lessons = $lessons->get();

        return [
            'lessons' => $lessons
        ];
    }

    public function getLesson(Request $request, $courseId, $lessonId)
    {
        $lesson = CourseLesson::whereHas('course', function ($query) use ($courseId) {
            $query->where('id', $courseId);
        })
            ->where('id', $lessonId)
            ->with(['topic', 'course'])
            ->firstOrFail();

        return [
            'lesson' => $lesson
        ];
    }

    public function createLesson(Request $request, $courseId)
    {
        $this->validate($request->all(), [
            'title'      => 'required',
            'section_id' => 'required'
        ]);

        $sectionId = (int)$request->get('section_id');

        $topic = CourseTopic::whereHas('course', function ($query) use ($courseId) {
            $query->where('id', $courseId);
        })
            ->where('id', $sectionId)
            ->firstOrFail();

        $lessonData = [
            'title'     => $request->getSafe('title'),
            'parent_id' => $topic->id,
            'space_id'  => $courseId,
            'status'    => 'draft'
        ];

        $lessonData = apply_filters('fluent_community/lesson/create_data', $lessonData, $request);

        $lesson = CourseLesson::create($lessonData);

        $lesson = CourseLesson::findOrFail($lesson->id);

        return [
            'message' => __('Lesson has been created successfully.', 'fluent-community'),
            'lesson'  => $lesson
        ];
    }

    public function updateLesson(Request $request, $courseId, $lessionId)
    {
        Course::findOrFail($courseId);

        $lessonData = $request->get('lesson');

        $this->validate($lessonData, [
            'title'     => 'required',
            'parent_id' => 'required',
            'status'    => 'required|in:draft,published,archived'
        ]);

        CourseTopic::whereHas('course', function ($query) use ($courseId) {
            $query->where('id', $courseId);
        })
            ->where('id', $lessonData['parent_id'])
            ->firstOrFail();

        $lesson = CourseLesson::where('id', $lessionId)
            ->where('space_id', $courseId)
            ->firstOrFail();

        $previousStatus = $lesson->status;

        $updatedMeta = CourseHelper::sanitizeLessonMeta(Arr::get($lessonData, 'meta', []), $lesson);
        $updatedMeta['document_ids'] = Arr::get($lesson->meta, 'document_ids', []);

        if ($mediaId = Arr::get($updatedMeta, 'featured_image_id')) {
            $mediaUrl = wp_get_attachment_image_url($mediaId);
            if ($mediaUrl) {
                $lesson->featured_image = $mediaUrl;
            } else {
                $lesson->featured_image = NULL;
            }
        } else {
            $lesson->featured_image = NULL;
        }

        $updateData = array_filter([
            'title'   => sanitize_text_field(Arr::get($lessonData, 'title')),
            'message' => CourseHelper::santizeLessonBody(Arr::get($lessonData, 'message')),
            'status'  => Arr::get($lessonData, 'status'),
            'meta'    => wp_parse_args($updatedMeta, $lesson->meta)
        ]);

        $updateData = apply_filters('fluent_community/lesson/update_data', $updateData, $lesson);

        $lesson->fill($updateData);
        $dirtyFields = $lesson->getDirty();

        if ($dirtyFields) {
            $lesson->save();
            $isNewlyPublished = $lesson->status === 'published' && $previousStatus !== 'published';
            do_action('fluent_community/lesson/updated', $lesson, $dirtyFields, $isNewlyPublished);
        }

        return [
            'message' => __('Lesson has been updated successfully.', 'fluent-community'),
            'lesson'  => $lesson
        ];
    }

    public function patchLesson(Request $request, $courseId, $lessionId)
    {
        $lesson = CourseLesson::whereHas('course', function ($query) use ($courseId) {
            $query->where('id', $courseId);
        })
            ->where('id', $lessionId)
            ->firstOrFail();

        $acceptedFields = ['title', 'status'];

        $lessonData = array_filter($request->only($acceptedFields));

        if (Arr::get($lessonData, 'status') === 'published' && $lesson->status !== 'published') {
            if (empty($lesson->scheduled_at)) {
                $lessonData['scheduled_at'] = current_time('mysql');
            }
        }

        if (!empty($lessonData)) {
            $lesson->fill($lessonData);
            if ($lesson->isDirty()) {
                $lesson->save();
            }
        }

        return [
            'message' => __('Lesson has been updated successfully.', 'fluent-community'),
            'lesson'  => $lesson
        ];
    }

    public function deleteLesson(Request $request, $courseId, $lessionId)
    {
        $lesson = CourseLesson::whereHas('course', function ($query) use ($courseId) {
            $query->where('id', $courseId);
        })
            ->where('id', $lessionId)
            ->firstOrFail();

        do_action('fluent_community/lesson/before_deleted', $lesson);

        $lesson->delete();

        return [
            'message' => __('Lesson has been deleted successfully.', 'fluent-community')
        ];
    }

    public function getOtherUsers(Request $request, $courseId)
    {
        $search = $request->getSafe('search');

        $selects = [
            'ID',
            'display_name'
        ];

        if (current_user_can('list_users')) {
            $selects[] = 'user_email';
        }

        $userIds = User::select(['ID'])
            ->whereDoesntHave('space_pivot', function ($q) use ($courseId) {
                $q->where('space_id', $courseId);
            })
            ->limit(100)
            ->searchBy($search)
            ->get()
            ->pluck('ID')
            ->toArray();

        $users = User::whereIn('ID', $userIds)
            ->select($selects)
            ->paginate(100);

        return [
            'users' => $users
        ];
    }

    public function updateLinks(Request $request, $id)
    {
        $course = Course::findOrFail($id);
        $links = $request->get('links', []);

        $links = array_map(function ($link) {
            return CustomSanitizer::santizeLinkItem($link);
        }, $links);

        $settings = $course->settings;
        $settings['links'] = $links;
        $course->settings = $settings;
        $course->save();

        return [
            'message' => __('Links have been updated for the course', 'fluent-community'),
            'links'   => $links
        ];
    }

    public function getMetaSettings(Request $request, $id)
    {
        $course = Course::findOrFail($id);
        $metaSettings = apply_filters('fluent_community/course/meta_fields', [], $course);

        if (!$metaSettings) {
            return [
                'meta_settings' => null
            ];
        }

        return [
            'meta_settings' => $metaSettings
        ];
    }

    public function getOtherInstructors(Request $request, $courseId)
    {
        $search = $request->getSafe('search');

        Course::findOrFail($courseId);

        $instructors = User::select(['ID', 'display_name', 'user_email'])
            ->limit(100)
            ->searchBy($search)
            ->get();

        return [
            'instructors' => $instructors
        ];
    }
}
