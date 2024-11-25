<?php

namespace FluentCommunity\Modules\Integrations;

use FluentCommunity\App\Services\Helper;

class Integrations
{

    public function register()
    {
        $this->init();
    }

    public function init()
    {
        if (defined('FLUENTCRM')) {
            new \FluentCommunity\Modules\Integrations\FluentCRM\SpaceJoinTrigger();

            // Course Specifics
            if (Helper::isFeatureEnabled('course_module')) {
                new \FluentCommunity\Modules\Integrations\FluentCRM\CourseEnrollmentTrigger();
            }
        }
    
        if (defined('FLUENTFORM')) {
            new \FluentCommunity\Modules\Integrations\FluentForms\Bootstrap();
        }
    }
}
