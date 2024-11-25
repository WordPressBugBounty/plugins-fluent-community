<?php

add_action('fluent_community/portal_loaded', function ($app) {
    (new \FluentCommunity\Modules\FeaturesHandler())->register($app);

    // Load the Integrations
    (new \FluentCommunity\Modules\Integrations\Integrations())->register();
});