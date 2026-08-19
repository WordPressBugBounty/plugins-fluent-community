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
            'controlBarColor'      => '',
            'playButtonColor'      => '',
            'playButtonBgColor'    => '',
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
                'hide_bottom_controls' => false,
                'load_strategy'        => 'visible'
            ],
            'video_upload'         => 'no',
            'video_upload_role'    => 'admin',
            'play_embedded_videos' => 'yes',
            'enable_audio'         => 'no'
        ];

        $settings = Utility::getOption('_fluent_player_settings', $defaults);
        $settings = wp_parse_args($settings, $defaults);
        $settings['behaviors'] = wp_parse_args($settings['behaviors'], $defaults['behaviors']);
        $settings['enable_audio'] = $settings['enable_audio'] === 'yes' ? 'yes' : 'no';

        return $settings;
    }

    public static function updateSettings($settings)
    {
        $sanitizerRules = [
            'enable_fluent_player' => 'sanitize_text_field',
            'skin'                 => 'sanitize_text_field',
            'brandColor'           => 'sanitize_text_field',
            'controlBarColor'      => 'sanitize_text_field',
            'playButtonColor'      => 'sanitize_text_field',
            'playButtonBgColor'    => 'sanitize_text_field',
            'controls.*'           => 'rest_sanitize_boolean',
            'behaviors.*'          => 'rest_sanitize_boolean',
            'video_upload'         => 'sanitize_text_field',
            'video_upload_role'    => 'sanitize_text_field',
            'play_embedded_videos' => 'sanitize_text_field',
            'enable_audio'         => 'sanitize_text_field'
        ];

        $prevSettings = self::getSettings();
        $loadStrategy = sanitize_text_field(Arr::get($settings, 'behaviors.load_strategy', 'visible'));
        $settings = Arr::only($settings, array_keys($prevSettings));
        $settings = wp_parse_args($settings, $prevSettings);
        $settings = Sanitizer::sanitize($settings, $sanitizerRules);

        $allowedStrategies = ['eager', 'visible', 'idle', 'play'];
        $settings['behaviors']['load_strategy'] = in_array($loadStrategy, $allowedStrategies) ? $loadStrategy : 'visible';
        $settings['enable_audio'] = Arr::get($settings, 'enable_audio') === 'yes' ? 'yes' : 'no';
        $settings['brandColor'] = self::sanitizeColor(Arr::get($settings, 'brandColor', ''));
        $settings['controlBarColor'] = self::sanitizeColor(Arr::get($settings, 'controlBarColor', ''));
        $settings['playButtonColor'] = self::sanitizeColor(Arr::get($settings, 'playButtonColor', ''));
        $settings['playButtonBgColor'] = self::sanitizeColor(Arr::get($settings, 'playButtonBgColor', ''));

        Utility::updateOption('_fluent_player_settings', $settings);

        return $settings;
    }

    public static function sanitizeColor($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $pattern = '/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]+|(rgb|rgba|hsl|hsla)\([0-9a-zA-Z.,%\s\/]+\))$/';
        return preg_match($pattern, $value) ? $value : '';
    }

    public static function getAllowedMediaTypes($settings = null, $kind = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $hasAudio = Arr::get($settings, 'enable_audio') === 'yes';

        $allowedVideoTypes = apply_filters('fluent_community/support_video_types', [
            'video/mp4',
            'video/webm',
            'video/quicktime'
        ]);

        if ($kind === 'video') {
            return array_values(array_unique($allowedVideoTypes));
        }

        $allowedAudioTypes = $hasAudio ? apply_filters('fluent_community/support_audio_types', [
            'audio/mpeg',
            'audio/wav',
            'audio/mp4',
            'audio/aac',
            'audio/ogg',
            'audio/flac'
        ]) : [];

        if ($kind === 'audio') {
            return array_values(array_unique($allowedAudioTypes));
        }

        return array_values(array_unique(array_merge($allowedVideoTypes, $allowedAudioTypes)));
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
                'play_embedded_videos' => Arr::get($playerSettings, 'play_embedded_videos', 'no') === 'yes',
                'has_audio_upload'     => Arr::get($playerSettings, 'enable_audio') === 'yes',
                'max_audios_per_post'  => self::maxAudiosPerPost(),
                'fallback'             => apply_filters('fluent_community/fluent_player/fallback_timings', [
                    'content_timeout_ms'  => 10000,
                    'script_timeout_ms'   => 12000,
                    'script_grace_ms'     => 2000,
                    'init_timeout_ms'     => 3000,
                    'stall_timeout_ms'    => 12000
                ])
            ];
        }
        return $data;
    }

    /**
     * Validate + cap the multi-audio array carried on a feed request.
     *
     * @return array list of fluent_player audio media entries (max N)
     */
    /**
     * From the submitted audio items, return the set of media ids the current user owns and may
     * attach: keyed by id for O(1) lookup. Eligible = own fluent_player media that is either an
     * unattached draft (is_active = 0) or already on the feed being edited.
     */
    private function eligibleAudioMediaIds($items, $requestData)
    {
        $candidateIds = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && Arr::get($item, 'player') === 'fluent_player') {
                $id = intval(Arr::get($item, 'media_id'));
                if ($id) {
                    $candidateIds[$id] = $id;
                }
            }
        }
        if (!$candidateIds) {
            return [];
        }

        $currentUserId = (int) get_current_user_id();
        $editingFeedId = intval(Arr::get($requestData, 'id', 0));

        $rows = $this->fetchOwnedAudioMediaRows(array_values($candidateIds), $currentUserId, $editingFeedId);

        $eligible = [];
        foreach ($rows as $row) {
            $eligible[(int) $row->id] = true;
        }
        return $eligible;
    }

    /**
     * Fetch the media rows the current user actually owns among the submitted ids: unattached
     * drafts (is_active = 0) or media already linked to the feed being edited. Isolated as a
     * protected seam so the sanitization logic can be unit-tested without a database.
     */
    protected function fetchOwnedAudioMediaRows(array $ids, $currentUserId, $editingFeedId)
    {
        return Media::whereIn('id', $ids)
            ->where('user_id', $currentUserId)
            ->where('media_type', 'fluent_player')
            ->where(function ($q) use ($editingFeedId) {
                $q->where('is_active', 0);
                if ($editingFeedId) {
                    $q->orWhere('feed_id', $editingFeedId);
                }
            })
            ->get();
    }

    /**
     * The per-post audio cap, clamped to a positive hard ceiling that holds independent of the
     * filter output. A filter returning 0, a negative, or an absurdly large value can never widen
     * the bound that protects the sanitization/DB path (WHERE ... IN size, per-item PHP work).
     */
    public static function maxAudiosPerPost()
    {
        $filtered = (int) apply_filters('fluent_community/fluent_player/max_audios_per_post', 10);
        return max(1, min($filtered, 50));
    }

    private function sanitizeAudioMedias($requestData)
    {
        // Accept both the top-level key (create) and the nested meta key (edit round-trip).
        $items = Arr::get($requestData, 'audio_medias', Arr::get($requestData, 'meta.audio_medias', []));
        if (!is_array($items) || empty($items)) {
            return [];
        }
        $max = self::maxAudiosPerPost();

        // Truncate the submitted list to the hard ceiling BEFORE collecting candidate ids or
        // touching the database. Only up to $max entries can ever be persisted, so an
        // authenticated client padding the array with thousands of ids must not translate into a
        // giant WHERE ... IN clause or repeated per-item PHP work on each sanitization pass.
        $items = array_slice(array_values($items), 0, $max);

        // Resolve which submitted media the current user actually owns and may attach (an
        // unattached draft, or already on the feed being edited). Only these ids are persisted
        // to meta.audio_medias — a submitted id belonging to another user is dropped, so it can
        // never be stored or later loaded/rendered by the player endpoint.
        $eligibleIds = $this->eligibleAudioMediaIds($items, $requestData);

        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || Arr::get($item, 'player') !== 'fluent_player') {
                continue;
            }
            $mediaId = intval(Arr::get($item, 'media_id'));
            if (!$mediaId || !isset($eligibleIds[$mediaId])) {
                continue;
            }
            // Build each stored entry from an explicit, per-field-sanitized allowlist rather
            // than persisting the raw client array.
            $settings = Arr::get($item, 'settings', []);
            $clean[] = array_filter([
                'media_id'     => $mediaId,
                'player'       => 'fluent_player',
                'provider'     => sanitize_text_field(Arr::get($item, 'provider', '')),
                'content_type' => 'audio',
                'url'          => esc_url_raw(Arr::get($item, 'url', '')),
                'title'        => sanitize_text_field(Arr::get($item, 'title', '')),
                'image'        => esc_url_raw(Arr::get($item, 'image', '')),
                'settings'     => is_array($settings) ? array_filter([
                    'src'              => esc_url_raw(Arr::get($settings, 'src', '')),
                    'title'            => sanitize_text_field(Arr::get($settings, 'title', '')),
                    'posterSrc'        => esc_url_raw(Arr::get($settings, 'posterSrc', '')),
                    'poster_is_custom' => filter_var(Arr::get($settings, 'poster_is_custom'), FILTER_VALIDATE_BOOLEAN),
                    'viewType'         => 'audio',
                ], function ($value) {
                    return $value !== '' && $value !== null;
                }) : [],
            ]);
            if (count($clean) >= $max) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Extract the ?media_key= query arg from an uploaded-media URL (used to relink posters).
     */
    private function extractMediaKey($url)
    {
        if (!$url) {
            return '';
        }
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return '';
        }
        parse_str($query, $args);
        return isset($args['media_key']) ? sanitize_text_field($args['media_key']) : '';
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
            $data['meta']['media_preview'] = array_filter(self::sanitizeMediaHtml($media));
        }
        $audioMedias = $this->sanitizeAudioMedias($requestData);
        if ($audioMedias) {
            if (!isset($data['meta'])) {
                $data['meta'] = [];
            }
            $data['meta']['audio_medias'] = $audioMedias;
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
            $data['meta']['media_preview'] = array_filter(self::sanitizeMediaHtml($media));
        }
        $audioMedias = $this->sanitizeAudioMedias($requestData);
        if ($audioMedias) {
            if (!isset($data['meta'])) {
                $data['meta'] = [];
            }
            $data['meta']['audio_medias'] = $audioMedias;
        }
        return $data;
    }

    /**
     * media_preview.html is rendered with v-html in _MediaPreview.vue whenever the
     * player itself cannot handle the media, so request-supplied markup has to go
     * through the oembed allowlist before it is stored.
     */
    private static function sanitizeMediaHtml($media)
    {
        if (!empty($media['html'])) {
            $media['html'] = \FluentCommunity\App\Services\RemoteUrlParser::sanitizeOembedHtml($media['html']);
        }

        return $media;
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
                $currentUserId = (int) get_current_user_id();
                $mediaModel = Media::find($mediaId);
                // Owner, or a moderator/admin who can edit the media's feed — never cross-user.
                $canAttach = $mediaModel && $currentUserId && (
                    (int) $mediaModel->user_id === $currentUserId
                    || ($mediaModel->feed_id && $mediaModel->feed && $mediaModel->feed->hasEditAccess($currentUserId))
                );
                if ($canAttach) {
                    $uploadedMedias[] = $mediaModel;
                }
            }
        }
        $audioMedias = $this->sanitizeAudioMedias($requestData);
        if ($audioMedias) {
            $currentUserId = (int) get_current_user_id();
            $editingFeedId = intval(Arr::get($requestData, 'id', 0));
            // Only the current user's own media may be attached, and only if it is an unattached
            // draft or already belongs to the feed being edited — never another user's row or a
            // row attached elsewhere (prevents cross-user media reassignment via sequential ids).
            $eligible = function ($query) use ($currentUserId, $editingFeedId) {
                $query->where('user_id', $currentUserId)
                    ->where(function ($q) use ($editingFeedId) {
                        $q->where('is_active', 0);
                        if ($editingFeedId) {
                            $q->orWhere('feed_id', $editingFeedId);
                        }
                    });
                return $query;
            };

            $audioIds = array_values(array_filter(array_map(function ($audio) {
                return intval(Arr::get($audio, 'media_id'));
            }, $audioMedias)));
            $posterKeys = array_values(array_filter(array_map(function ($audio) {
                return $this->extractMediaKey(Arr::get($audio, 'settings.posterSrc', ''));
            }, $audioMedias)));

            if ($audioIds) {
                $audioQuery = Media::whereIn('id', $audioIds)->where('media_type', 'fluent_player');
                foreach ($eligible($audioQuery)->get() as $audioModel) {
                    $uploadedMedias[] = $audioModel;
                }
            }
            // Activate + link the custom poster media rows so they survive the draft GC.
            if ($posterKeys) {
                $posterQuery = Media::whereIn('media_key', $posterKeys);
                foreach ($eligible($posterQuery)->get() as $posterModel) {
                    $uploadedMedias[] = $posterModel;
                }
            }
        }
        return $uploadedMedias;
    }
}
