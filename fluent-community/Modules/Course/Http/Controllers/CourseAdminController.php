<?php

namespace FluentCommunity\Modules\Course\Http\Controllers;

use FluentCommunity\App\App;
use FluentCommunity\App\Http\Controllers\Controller;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
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
        $courses = Course::searchBy($request->getSafe('search'))
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

        $courseData = [
            'parent_id'   => $request->get('parent_id') ?: NULL,
            'title'       => $request->getSafe('title', 'sanitize_text_field'),
            'privacy'     => $request->get('privacy'),
            'description' => wp_kses_post($request->get('description')),
            'status'      => $request->get('status', 'draft'),
            'settings'    => [
                'course_type' => $request->get('course_type'),
                'emoji'       => CustomSanitizer::sanitizeEmoji($request->get('settings.emoji', '')),
                'shape_svg'   => CustomSanitizer::sanitizeSvg($request->get('settings.shape_svg', '')),
            ],
            'serial'      => BaseSpace::where('parent_id', $parentId)->max('serial') + 1
        ];

        $slug = $request->get('slug');

        if (!$slug) {
            $slug = sanitize_title($courseData['title']);
        }

        if ($slug) {
            $slug = sanitize_title($slug);
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
            'course_type' => 'required|in:self_paced,structured,scheduled'
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
            $slug = sanitize_title($slug);

            $exist = App::getInstance('db')->table('fcom_spaces')->where('slug', $slug)
                ->where('id', '!=', $course->id)
                ->exists();

            if ($exist) {
                return $this->sendError([
                    'message' => __('Slug is already taken. Please use a different slug', 'fluent-community')
                ]);
            }

            $courseData['slug'] = $slug;

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
            }
        }

        $existingSettings = $course->settings;
        $existingSettings['course_type'] = $request->get('course_type');
        $existingSettings['custom_lock_screen'] = $request->get('settings.custom_lock_screen') === 'yes' ? 'yes' : 'no';
        $existingSettings['emoji'] = CustomSanitizer::sanitizeEmoji($request->get('settings.emoji', ''));
        $existingSettings['shape_svg'] = CustomSanitizer::sanitizeSvg($request->get('settings.shape_svg', ''));

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

        Feed::withoutGlobalScopes()->where('space_id', $course->id)->delete();

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

        $admin = User::find(get_current_user_id());
        $admin->verifySpacePermission('can_add_member', $course);
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

        $admin = User::find(get_current_user_id());

        $admin->verifySpacePermission('can_remove_member', $course);

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

        CourseLesson::where([
            'parent_id' => $sectionId,
            'space_id'  => $courseId
        ])->delete();

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

        $lesson = CourseLesson::create($lessonData);

        $lesson = CourseLesson::findOrFail($lesson->id);

        return [
            'message' => __('Lesson has been created successfully.', 'fluent-community'),
            'lesson'  => $lesson
        ];
    }

    public function updateLesson(Request $request, $courseId, $lessionId)
    {
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

        $updateData = array_filter([
            'title'   => sanitize_text_field(Arr::get($lessonData, 'title')),
            'message' => wp_kses_post(Arr::get($lessonData, 'message')),
            'status'  => Arr::get($lessonData, 'status'),
            'meta'    => CourseHelper::sanitizeLessonMeta(Arr::get($lessonData, 'meta', []))
        ]);

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

        $lesson->fill($lessonData);
        $lesson->save();

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

        $lesson->delete();

        return [
            'message' => __('Lesson has been deleted successfully.', 'fluent-community')
        ];
    }

    public function getOtherUsers(Request $request)
    {
        $this->validate($request->all(), [
            'course_id' => 'required|exists:fcom_spaces,id'
        ]);

        $courseId = (int)$request->get('course_id');

        $search = $request->getSafe('search');

        $users = User::whereDoesntHave('courses', function ($q) use ($courseId) {
            $q->where('space_id', $courseId);
        })
            ->select(['ID', 'display_name'])
            ->searchBy($search)
            ->paginate();

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
}
