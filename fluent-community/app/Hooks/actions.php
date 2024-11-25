<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * @var $app FluentCommunity\Framework\Foundation\Application
 */

(new \FluentCommunity\App\Hooks\Handlers\PortalHandler())->register();
(new \FluentCommunity\App\Hooks\Handlers\CustomizerHander())->register();
(new \FluentCommunity\App\Hooks\Handlers\PortalSettingsHandler())->register();
(new \FluentCommunity\App\Hooks\Handlers\NotificationEventHandler())->register();
(new \FluentCommunity\App\Hooks\Handlers\EmailNotificationHandler())->register();
(new \FluentCommunity\App\Hooks\Handlers\ActivityMonitorHandler())->register();
(new \FluentCommunity\App\Hooks\Handlers\Scheduler())->register();
(new \FluentCommunity\App\Hooks\Handlers\CleanupHandler())->register();

// Rate limit handler
(new \FluentCommunity\App\Hooks\Handlers\RateLimitHandler())->register();