<?php

namespace FluentCommunity\Modules\Integrations\FluentPlayer;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\Framework\Support\Sanitizer;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\App\Models\Media;

class Bootstrap
{
    protected $app = null;
    
    /**
     * Cached plugin status to avoid repeated file system checks
     */
    private static $status = null;

    public function register($app)
    {
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/player_api.php';
        });

        $this->app = $app;
        $this->init();
        
    }
    
    public function init()
    {
        // Always register portal vars hook to provide plugin status
        $this->app->addFilter('fluent_community/portal_vars', [$this, 'getPortalVars']);
        
        // Only register feed functionality if plugin is active
        if (defined('FLUENT_PLAYER_VERSION')) {
            $this->registerFeedHooks();
        }
    }

    private function registerFeedHooks()
    {
        $this->app->addFilter('fluent_community/feed/new_feed_data', [$this, 'maybeAddFluentPlayerMedia'], 10, 2);
        $this->app->addFilter('fluent_community/feed/update_feed_data', [$this, 'maybeUpdateFluentPlayerMedia'], 10, 2);
        $this->app->addFilter('fluent_community/feed/uploaded_feed_medias', [$this, 'maybeUpdateUploadedMedia'], 10, 2);
    }
    
    /**
     * Get FluentPlayer plugin status
     *
     * @return string 'active', 'installed', or 'not_installed'
     */
    public static function getPluginStatus()
    {
        if (self::$status !== null) {
            return self::$status;
        }
        
        if (defined('FLUENT_PLAYER_VERSION')) {
            return self::$status = 'active';
        }
        
        if (file_exists(WP_PLUGIN_DIR . '/fluent-player/fluent-player.php')) {
            return self::$status = 'installed';
        }
        
        return self::$status = 'not_installed';
    }
	
    public static function getSettings()
    {
        static $settings = null;
        if ($settings) {
            return $settings;
        }

        $defaults = [
            'enable_fluent_player' => 'no',
            'skin'                 => 'modern',
            'brandColor'           => '#4a90e2',
            'controls'             => [
                'play'            => true,
                'volume'          => true,
                'progress_bar'    => true,
                'current_time'    => true,
                'captions_toggle' => true,
                'playback_speed'  => true,
                'settings'        => true,
                'pip'             => true,
                'fullscreen'      => true,
                'backward'        => true,
                'forward'         => true
            ],
            'behaviors'            => [
                'muted_autoplay'       => false,
                'save_play_position'   => false,
                'hide_top_controls'    => false,
                'hide_center_controls' => false,
                'hide_bottom_controls' => false
            ],
            'video_upload'         => 'no',
            'video_upload_role'    => 'admin',
            'play_embedded_videos' => 'yes'
        ];

        $settings = Utility::getOption('_fluent_player_settings', $defaults);
        $settings = wp_parse_args($settings, $defaults);

        return $settings;
    }

    public static function updateSettings($settings)
    {
        $sanitizerRules = [
            'enable_fluent_player' => 'sanitize_text_field',
            'skin'                 => 'sanitize_text_field',
            'brandColor'           => 'sanitize_text_field',
            'controls.*'           => 'rest_sanitize_boolean',
            'behaviors.*'          => 'rest_sanitize_boolean',
            'video_upload'         => 'sanitize_text_field',
            'video_upload_role'    => 'sanitize_text_field',
            'play_embedded_videos' => 'sanitize_text_field'
        ];

        $prevSettings = self::getSettings();
        $settings = Arr::only($settings, array_keys($prevSettings));
        $settings = wp_parse_args($settings, $prevSettings);
        $settings = Sanitizer::sanitize($settings, $sanitizerRules);

        Utility::updateOption('_fluent_player_settings', $settings);

        return $settings;
    }

    public function getPortalVars($data)
    {
        if (!isset($data['features'])) {
            $data['features'] = [];
        }

        $status = self::getPluginStatus();

        $data['features']['fluent_player_status'] = $status;
        $data['features']['has_fluent_player'] = ($status === 'active');

        if ($status === 'active') {
            $playerSettings = self::getSettings();
            $data['features']['enable_fluent_player'] = $playerSettings['enable_fluent_player'];
            $data['features']['fluent_player'] = [
                'enable'               => Arr::get($playerSettings, 'enable_fluent_player') === 'yes',
                'has_video_upload'     => Arr::get($playerSettings, 'video_upload') === 'yes',
                'video_upload_role'    => Arr::get($playerSettings, 'video_upload_role', 'admin'),
                'play_embedded_videos' => Arr::get($playerSettings, 'play_embedded_videos', 'no') === 'yes'
            ];
        }
        return $data;
    }

    public function maybeAddFluentPlayerMedia($data, $requestData)
    {
        $media = Arr::get($requestData, 'meta.media_preview', []);
        if ($newMedia = Arr::get($requestData, 'media')) {
            $media = $newMedia;
        }
        if ($media && is_array($media) && Arr::get($media, 'player') == 'fluent_player') {
            if (!isset($data['meta'])) {
                $data['meta'] = [];
            }
            $data['meta']['media_preview'] = array_filter($media);
        }
        return $data;
    }
	public function maybeUpdateFluentPlayerMedia($data, $requestData)
    {
	    $media = Arr::get($requestData, 'media');
        if ($media && is_array($media) && Arr::get($media, 'player') == 'fluent_player') {
            if (!isset($data['meta'])) {
                $data['meta'] = [];
            }
            $data['meta']['media_preview'] = array_filter($media);
        }
        return $data;
    }

    public function maybeUpdateUploadedMedia($uploadedMedias, $requestData)
    {
        $media = Arr::get($requestData, 'meta.media_preview', []);
        if ($newMedia = Arr::get($requestData, 'media')) {
            $media = $newMedia;
        }
        if ($media && is_array($media) && Arr::get($media, 'player') == 'fluent_player' && $mediaId = Arr::get($media, 'media_id')) {
            $mediaId = intval($mediaId);
            if ($mediaId) {
                $media = Media::find($mediaId);
                if ($media) {
                    $uploadedMedias[] = $media;
                }
            }
        }
        return $uploadedMedias;
    }
}
