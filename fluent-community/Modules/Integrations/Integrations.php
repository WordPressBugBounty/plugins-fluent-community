<?php

namespace FluentCommunity\Modules\Integrations;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;

class Integrations
{
    public function register($app)
    {
        $this->init($app);
    }

    public static function getFluentEventSettings()
    {
        $settings = Utility::getOption('_fluent_event_settings', []);

        return wp_parse_args(is_array($settings) ? $settings : [], [
            'enable_fluent_event' => 'no',
            'route_slug'          => 'events',
        ]);
    }

    public static function isFluentEventEnabled()
    {
        return self::getFluentEventSettings()['enable_fluent_event'] === 'yes';
    }

    public function init($app)
    {
        if (defined('FLUENTCRM')) {
            new \FluentCommunity\Modules\Integrations\FluentCRM\SpaceJoinTrigger();
            (new \FluentCommunity\Modules\Integrations\FluentCRM\ProfileSection())->register();

            // Older FluentCRM sends every is_pro block to its own FluentCRM Pro upgrade page.
            if (!defined('FLUENT_COMMUNITY_PRO') && version_compare(FLUENTCRM_PLUGIN_VERSION, '3.2.6', '>')) {
                (new \FluentCommunity\Modules\Integrations\FluentCRM\ProActionPlaceholders())->register();
            }
            // Course Specifics
            if (Helper::isFeatureEnabled('course_module')) {
                new \FluentCommunity\Modules\Integrations\FluentCRM\CourseEnrollmentTrigger();
                new \FluentCommunity\Modules\Integrations\FluentCRM\CourseCompletedTrigger();
                new \FluentCommunity\Modules\Integrations\FluentCRM\CourseTopicCompletedTrigger();
                new \FluentCommunity\Modules\Integrations\FluentCRM\LessonCompletedTrigger();
            }
        }

        if (defined('FLUENTFORM')) {
            add_action('init', function () {
                new \FluentCommunity\Modules\Integrations\FluentForms\Bootstrap();
            });
        }

        if (defined('FLUENTCART_VERSION')) {
            (new \FluentCommunity\Modules\Integrations\FluentCart\Paywalls())->register($app);
            (new \FluentCommunity\Modules\Integrations\FluentCart\CartCheckout())->register();
        }

        (new \FluentCommunity\Modules\Integrations\FluentPlayer\Bootstrap())->register($app);

        // FluentEvent hooks into the portal only after an admin enables it under Features,
        // and builds its portal routes from the route_slug passed here.
        $fluentEventSettings = self::getFluentEventSettings();

        if ($fluentEventSettings['enable_fluent_event'] === 'yes') {
            do_action('fluent_community/fluent_event/init', $app, $fluentEventSettings);
        }
    }
}
