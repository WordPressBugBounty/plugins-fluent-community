<?php

namespace FluentCommunity\App\Http\Policies;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Http\Request\Request;

/**
 * Insights are for site admins (and community managers with admin access).
 * Every controller action has its own method so none of them falls back to a
 * weaker default; space- and course-scoped access comes in a later phase.
 */
class InsightsPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        return $this->canViewInsights();
    }

    public function pages(Request $request)
    {
        return $this->canViewInsights();
    }

    public function page(Request $request)
    {
        return $this->canViewInsights();
    }

    public function card(Request $request)
    {
        return $this->canViewInsights();
    }

    public function export(Request $request)
    {
        return $this->canViewInsights();
    }

    private function canViewInsights()
    {
        return is_user_logged_in() && Helper::isSiteAdmin();
    }
}
