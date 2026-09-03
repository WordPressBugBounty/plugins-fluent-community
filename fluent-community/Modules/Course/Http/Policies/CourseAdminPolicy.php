<?php

namespace FluentCommunity\Modules\Course\Http\Policies;

use FluentCommunity\App\Http\Policies\BasePolicy;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\App\Services\Helper;

class CourseAdminPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCommunity\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        $user = Helper::getCurrentUser(true);

        if (!$user) {
            return false;
        }

        if (!$user->hasCourseCreatorAccess()) {
            return false;
        }

        if ($this->getRouteParam($request, 'course_id')) {
            return $this->canManageCourse($request);
        }

        return true;
    }

    protected function canManageCourse(Request $request)
    {
        if (Helper::isSuperAdmin()) {
            return true;
        }

        $user = Helper::getCurrentUser(true);

        if ($courseId = $this->getRouteParam($request, 'course_id')) {
            $course = Course::find($courseId);
            if (!$course) {
                return false;
            }

            return $course->isCourseAdmin($user);
        }

        return $user->hasSpaceManageAccess();
    }
}
