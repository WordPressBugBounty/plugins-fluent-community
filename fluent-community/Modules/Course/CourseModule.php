<?php

namespace FluentCommunity\Modules\Course;


use FluentCommunity\App\Services\Helper;
use FluentCommunity\Modules\Course\Model\Course;

class CourseModule
{
    public function register($app)
    {
        /*
         * register the routes
         */
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/course_api.php';
        });

        // Add to Menu
        add_filter('fluent_community/main_menu_items', [$this, 'maybeRemoveCourseMenu']);

        add_filter('fluent_community/insights/cards', [$this, 'registerInsightsCards']);
    }

    public function registerInsightsCards($cards)
    {
        $cards[] = new \FluentCommunity\Modules\Course\Services\Insights\CourseSummaryCard();
        $cards[] = new \FluentCommunity\Modules\Course\Services\Insights\EnrollmentsCard();

        return $cards;
    }

    public function maybeRemoveCourseMenu($items)
    {

        if(!isset($items['all_courses'])) {
            return $items;
        }

        $userModel = Helper::getCurrentUser();

        $isModerator = $userModel && $userModel->isCommunityModerator();

        if (!$isModerator && !Course::where('status', 'published')->exists()) {
            unset($items['all_courses']);
        }

        return $items;
    }
}
