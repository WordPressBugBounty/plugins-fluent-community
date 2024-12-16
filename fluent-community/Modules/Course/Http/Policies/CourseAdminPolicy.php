<?php

namespace FluentCommunity\Modules\Course\Http\Policies;

use FluentCommunity\App\Http\Policies\BasePolicy;
use FluentCommunity\App\Models\User;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Modules\Course\Model\Course;

class CourseAdminPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCommunity\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return false;
        }

        $user = User::find($userId);

        return $user->hasCourseCreatorAccess();
    }

    public function createCourse(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function updateCourse(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function deleteCourse(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function getOtherUsers(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function addStudent(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function removeStudent(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function updateLockscreenSettings(Request $request)
    {
        return $this->canManageCourse($request);
    }

    public function updateLinks(Request $request)
    {
        return $this->canManageCourse($request);
    }

    protected function canManageCourse(Request $request)
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $user = User::find($userId);

        if ($courseId = $request->get('cousre_id')) {
            $course = Course::find($courseId);
            if (!$course) {
                return false;
            }

            return $course->isCourseAdmin($user);
        }

        return $user->hasCourseCreatorAccess();
    }
}
