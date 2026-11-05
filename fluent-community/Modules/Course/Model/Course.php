<?php

namespace FluentCommunity\Modules\Course\Model;

use FluentCommunity\App\Models\Activity;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\App\Models\Term;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\App\Models\SpaceGroup;
use FluentCommunity\App\Models\XProfile;

/**
 *  Course Model - DB Model for Individual Courses
 *
 *  Database Model
 *
 * @package FluentCrm\App\Models
 *
 * @version 1.1.0
 *
 * @property bool|null   $is_course_admin
 * @property bool|null   $can_self_enroll
 * @property int|null    $students_count
 * @property string|null $course_type
 * @property mixed       $lockscreen
 * @property mixed       $category_ids
 * @property int|null    $completed_students
 * @property float|null  $overAllProgress
 * @property-read mixed  $categories
 * @property-read mixed  $creator
 */
class Course extends BaseSpace
{
    protected static $type = 'course';

    public function scopeByAdminAccess($query, $userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        $userModel = User::find($userId);

        if ($userModel->hasSpaceManageAccess()) {
            return $query;
        }

        return $query->where('created_by', $userId);
    }

    /**
     * Matching on the posts inside the course space (BaseSpace::posts(), text posts only,
     * not lessons) is for the admin course list: on the portal it would tell a visitor
     * which words the discussion of a course they cannot enter contains.
     */
    public function scopeSearchBy($query, $search, $includePosts = false)
    {
        if ($search) {
            $fields = $this->searchable;
            $query->where(function ($query) use ($fields, $search, $includePosts) {
                $query->where(function ($q) use ($fields, $search) {
                    $q->where(array_shift($fields), 'LIKE', "%$search%");
                    foreach ($fields as $field) {
                        $q->orWhere($field, 'LIKE', "$search%");
                    }
                })
                    ->orWhereHas('categories', function ($q) use ($search) {
                        $q->where('title', 'LIKE', "%$search%")
                            ->orWhere('description', 'LIKE', "%$search%");
                    });

                if ($includePosts) {
                    $query->orWhereHas('posts', function ($q) use ($search) {
                        $q->where('status', 'published')
                            ->where(function ($q) use ($search) {
                                $q->where('title', 'LIKE', "%$search%")
                                    ->orWhere('message', 'LIKE', "%$search%");
                            });
                    });
                }
            });
        }

        return $query;
    }

    public function scopeByPostTopic($query, $topicSlug = null)
    {
        if (!$topicSlug) {
            return $query;
        }

        $postTpic = Term::where('taxonomy_name', 'post_topic')->where('slug', $topicSlug)->first();

        if (!$postTpic) {
            return $query;
        }

        return $query->whereHas('categories', function ($q) use ($postTpic) {
            $q->where('object_id', $postTpic->id);
        });
    }

    public function creator()
    {
        return $this->hasOneThrough(
            XProfile::class,
            User::class,
            'ID',         // Foreign key on the users table
            'user_id',    // Foreign key on the xprofiles table
            'created_by', // Local key on the courses table
            'ID'          // Local key on the users table
        )->withoutGlobalScopes();
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'fcom_space_user', 'space_id', 'user_id')
            ->wherePivot('role', 'student')
            ->withPivot([ 'role', 'created_at', 'status' ]);
    }

    public function course_topics()
    {
        return $this->hasMany(CourseTopic::class, 'space_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(SpaceUserPivot::class, 'id', 'space_id');
    }

    public function student_enrollments()
    {
        return $this->hasMany(SpaceUserPivot::class, 'space_id', 'id')
            ->where('role', 'student')
            ->where('status', 'active');
    }

    public function getCompletedStrundesCount()
    {
        return Activity::where('feed_id', $this->id)
            ->where('action_name', 'course_completed')
            ->count();
    }

    public function getCourseType()
    {
        $courseType = Arr::get($this->settings, 'course_type', 'self_paced'); // possible values: self_paced | scheduled | structured

        $validTypes = [ 'self_paced', 'scheduled', 'structured' ];

        if (!in_array($courseType, $validTypes, true)) {
            return 'self_paced';
        }
        
        return $courseType;
    }

    public function hasPublicLessonView()
    {
        return $this->privacy === 'public' && Arr::get($this->settings, 'public_lesson_view') === 'yes' && $this->getCourseType() === 'self_paced';
    }

    public function group()
    {
        return $this->belongsTo(SpaceGroup::class, 'parent_id', 'id');
    }

    public function categories()
    {
        return $this->belongsToMany(Term::class, 'fcom_meta', 'meta_key', 'object_id')
            ->wherePivot('object_type', 'term_space_relation')
            ->where('taxonomy_name', 'post_topic');
    }

    public function syncCategories($categoryIds = [])
    {
        $this->syncTopics($categoryIds);
        return $this;
    }

    public function isCourseAdmin($user = null)
    {
        if (!$user) {
            $user = Helper::getCurrentUser();
        }

        if (!$user) {
            return false;
        }

        $permissions = $user->getPermissions();

        if (!empty($permissions['course_admin'])) {
            return true;
        }

        // Check if course creator
        if (!empty($permissions['course_creator'])) {
            return (int) $this->created_by === (int) $user->ID;
        }

        return false;
    }

}
