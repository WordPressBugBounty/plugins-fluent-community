<?php

namespace FluentCommunity\Modules\Integrations\FluentPlayer\Http\Controllers;

use FluentCommunity\App\Http\Controllers\Controller;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Libs\FileSystem;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Integrations\FluentPlayer\Bootstrap;

class MediaController extends Controller
{
    public function uploadVideo(Request $request)
    {
        $getFluentPlayerStatus = Bootstrap::getPluginStatus();
        if ($getFluentPlayerStatus !== 'active') {
            return $this->sendError([
                'message' => __('Uploading videos is not allowed. Activate FluentPlayer to upload videos.', 'fluent-community')
            ]);
        }
        
	    $fluentPlayerSettings = Bootstrap::getSettings();
	    if (!Arr::isTrue($fluentPlayerSettings, 'video_upload')) {
		    return $this->sendError([
			    'message' => __('Uploading videos is not allowed', 'fluent-community')
		    ]);
	    }
	    $uploadRole = Arr::get($fluentPlayerSettings, 'video_upload_role');
        $uploadRole = $uploadRole ?: 'admin';
        $isAllowed = false;
        if ('admin' === $uploadRole && Helper::isSiteAdmin()) {
            $isAllowed = true;
        } else if ('admin_moderator' === $uploadRole && (Helper::isModerator() || Helper::isSiteAdmin())) {
            $isAllowed = true;
        } else if ('everyone' === $uploadRole) {
            $isAllowed = true;
        }
	    if (!$isAllowed) {
		    return $this->sendError([
			    'message' => __('You do not have permission to upload videos', 'fluent-community')
		    ]);
	    }
		
        $hasAudioUpload = Arr::get($fluentPlayerSettings, 'enable_audio') === 'yes';
        $mediaKind = $request->getSafe('media_kind', 'sanitize_text_field', '');
        if (!in_array($mediaKind, ['video', 'audio'], true)) {
            $mediaKind = null;
        }

        if ($mediaKind === 'audio' && !$hasAudioUpload) {
            return $this->sendError([
                'message' => __('Uploading audio files is not allowed', 'fluent-community')
            ]);
        }

        $allowedMediaTypes = Bootstrap::getAllowedMediaTypes($fluentPlayerSettings, $mediaKind);
        $maxFileUnit = apply_filters('fluent_community/video_upload_max_file_unit', 'MB');
        $maxFileSize = apply_filters('fluent_community/video_upload_max_file_size', 300);
        $allowedFileSize = $maxFileSize;
        if (strtoupper($maxFileUnit) == 'MB') {
            $allowedFileSize = $maxFileSize * 1024;
        } else if (strtoupper($maxFileUnit) == 'GB') {
            $allowedFileSize = $maxFileSize * 1024 * 1024;
        }

        $allowedTypesString = implode(',', $allowedMediaTypes);

        $files = $this->validate($request->files(), [
            'file' => 'mimetypes:' . $allowedTypesString . '|max:' . $allowedFileSize,
        ], [
            'file.mimetypes' => $this->getMimeTypeErrorMessage($mediaKind, $hasAudioUpload),
            /* translators: %1$s is the maximum file size value, %2$s is the size unit (KB, MB, etc.) */
            'file.max'       => sprintf(__('The file size must be less than %1$s%2$s.', 'fluent-community'), $maxFileSize, $maxFileUnit)
        ]);
        // File size check
        if ($error = Helper::checkUploadSizeError()) {
            return $this->sendError($error, 413);
        }

        $uploadedFiles = FileSystem::put($files);
        $file = $uploadedFiles[0];

        $upload_dir = wp_upload_dir();
        $file['path'] = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];

        $mediaData = [
            'media_type' => 'fluent_player',
            'driver'     => 'local',
            'media_path' => $file['path'],
            'media_url'  => $file['url'],
            'settings'   => [
                'src'      => $file['url'],
                'title'    => $file['original_name'],
                'viewType' => $this->resolveViewType($file, $mediaKind)
            ]
        ];

        $mediaData = apply_filters('fluent_community/media_upload_data', $mediaData, $file);

        if (is_wp_error($mediaData)) {
            return $this->sendError([
                'message' => $mediaData->get_error_message(),
                'errors'  => $mediaData->get_error_data()
            ]);
        }

        if (!$mediaData) {
            return $this->sendError([
                'message' => __('Error while uploading the video', 'fluent-community')
            ]);
        }
        $mediaData['settings']['src'] = Arr::get($mediaData, 'media_url', $file['url']);

        // Disable crossorigin for cloud storage drivers that may lack CORS headers
        $driver = Arr::get($mediaData, 'driver', 'local');
        if ($driver === 's3') {
            $mediaData['settings']['crossorigin'] = false;
        }

        $media = Media::create($mediaData);
        return [
            'media' => [
                'media_id'  => $media->id,
                'url'       => $media->public_url,
                'media_key' => $media->media_key,
                'type'      => $media->media_type,
                'settings'  => $media->settings,
                'html'      => ''
            ]
        ];
    }

    /**
     * Whether the current user may edit an audio media's metadata.
     * Owner (upload sets user_id) or a moderator/site admin.
     */
    public function canEditAudioMedia($media)
    {
        if (!$media) {
            return false;
        }
        // Read user_id via the model accessor. A (array) cast of a WPFluent model exposes
        // the protected $attributes under a mangled key, not a flat user_id.
        if ((int) $media->user_id === (int) get_current_user_id()) {
            return true;
        }
        return Helper::isModerator() || Helper::isSiteAdmin();
    }

    /**
     * Update an audio media's display title and poster thumbnail from the composer.
     */
    public function updateAudioMeta(Request $request)
    {
        $media = Media::find(intval($request->get('media_id')));
        if (!$media || !$this->canEditAudioMedia($media)) {
            return $this->sendError([
                'message' => __('You are not allowed to edit this audio', 'fluent-community'),
            ], 403);
        }

        // Only fluent-player audio media may be edited through this endpoint.
        if ($media->media_type !== 'fluent_player' || !$this->isAudioMedia((array) $media->settings)) {
            return $this->sendError([
                'message' => __('This media is not an editable audio', 'fluent-community'),
            ], 422);
        }

        $settings = (array) $media->settings;
        $title = $request->getSafe('title', 'sanitize_text_field', '');
        $posterSrc = $request->getSafe('posterSrc', 'sanitize_url', '');

        if ($title !== '') {
            $settings['title'] = $title;
        }
        if ($posterSrc !== '') {
            $settings['posterSrc'] = $posterSrc;
            // poster_is_custom gates whether the render path shows the custom thumbnail
            // (see show_poster in generateFluentPlayerHtml).
            $settings['poster_is_custom'] = true;
        } else {
            $settings['posterSrc'] = '';
            $settings['poster_is_custom'] = false;
        }

        $media->settings = $settings;
        $media->save();

        return [
            'media' => [
                'media_id' => $media->id,
                'settings' => $media->settings,
            ],
        ];
    }

    private function resolveViewType($file, $mediaKind)
    {
        $audioExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'flac'];
        $name = Arr::get($file, 'original_name', Arr::get($file, 'url', ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, $audioExtensions, true)) {
            return 'audio';
        }

        if ($extension) {
            return 'video';
        }

        return $mediaKind === 'audio' ? 'audio' : 'video';
    }

    private function getMimeTypeErrorMessage($mediaKind, $hasAudioUpload)
    {
        if ($mediaKind === 'audio') {
            return __('The file must be a valid audio (MP3, WAV, M4A, AAC, OGG, FLAC) type.', 'fluent-community');
        }

        if ($mediaKind === 'video' || !$hasAudioUpload) {
            return __('The file must be a valid video (MP4, WebM, MOV) type.', 'fluent-community');
        }

        return __('The file must be a valid video (MP4, WebM, MOV) or audio (MP3, WAV, M4A, AAC, OGG, FLAC) type.', 'fluent-community');
    }

    public function getFluentPlayerContent(Request $request)
    {
        if (!defined('FLUENT_PLAYER_VERSION')) {
            return [
                'html' => '',
            ];
        }
        $mediaId = intval($request->get('media_id'));
        $instanceKey = sanitize_key($request->get('player_instance_key'));
        $shareUrl = esc_url_raw($request->get('share_url'));
        $media = Media::find($mediaId);
        if (!$media) {
            // Non-DB media (external embed / share URL) from a synthetic hash id: build from
            // allowlisted scalars only. Never trust request `settings`/`layers` — accepting
            // them lets an anon caller inject shortcode layers into FluentPlayer's renderer.
            $media = (object) [
                'url'       => esc_url_raw($request->get('url')),
                'title'     => sanitize_text_field($request->get('title')),
                'image'     => esc_url_raw($request->get('image')),
                'share_url' => esc_url_raw($request->get('share_url')),
                'provider'  => sanitize_text_field($request->get('provider')),
                'type'      => sanitize_text_field($request->get('type')),
            ];
        } else {
            $currentUserId = get_current_user_id();
            $canView = ($currentUserId && (int) $media->user_id === $currentUserId)
                || ($media->feed_id && Feed::byUserAccess($currentUserId)
                        ->byContentModerationAccessStatus($this->getUser())
                        ->find($media->feed_id));
            if (!$canView) {
                return ['html' => ''];
            }
            if ($shareUrl) {
                $media->share_url = $shareUrl;
            }
        }
        return $this->generateFluentPlayerHtml($mediaId, $media, $instanceKey);
    }

    private function generateFluentPlayerHtml($mediaId, $media, $instanceKey = '')
    {
        $mediaVarName = 'fluentPlayerMedia_' . $mediaId . '_' . wp_rand(1000, 9999);
        $playerKey = 'fcom_' . $mediaId;
        $instanceId = $playerKey . ($instanceKey ? '_' . $instanceKey : '');
        $mediaSettings = [];
        $formattedMedia = [
            'ID' => $mediaId
        ];
        if (!empty($media->settings)) {
            $mediaSettings = $media->settings;
        }
        if (empty($mediaSettings['src']) && !empty($media->url)) {
            $mediaSettings['src'] = $media->url;
        }
        if (empty($mediaSettings['title']) && !empty($media->title)) {
            $mediaSettings['title'] = $media->title;
        }
        if (empty($mediaSettings['posterSrc']) && !empty($media->image)) {
            $mediaSettings['posterSrc'] = $media->image;
        }
        if (empty($mediaSettings['share_url']) && !empty($media->share_url)) {
            $mediaSettings['share_url'] = esc_url_raw($media->share_url);
        }
		$src = Arr::get($mediaSettings, 'src', '');
		// Normalize YouTube /live/ URLs to /watch?v= so Vidstack can resolve them
		if ($src && strpos($src, 'youtube.com/live/') !== false) {
		    $src = preg_replace('/youtube\.com\/live\/([^?&#]+)\??/', 'youtube.com/watch?v=$1&', $src);
		    $src = rtrim($src, '&');
		    $mediaSettings['src'] = $src;
		}
		if ($src && preg_match('#youtube\.com/shorts/#i', $src)) {
            $mediaSettings['is_short'] = true;
        }

        $mediaSettings = wp_parse_args($mediaSettings, $this->getFluentplayerDefaultsSettings());

        if ($this->isAudioMedia($mediaSettings)) {
            // Only show a thumbnail block when the author uploaded one; otherwise render a
            // clean player with no poster (per design). poster_is_custom is set by the
            // audio edit modal / updateAudioMeta.
            $hasCustomPoster = Arr::get($mediaSettings, 'posterSrc', '') !== ''
                && filter_var(Arr::get($mediaSettings, 'poster_is_custom'), FILTER_VALIDATE_BOOLEAN);
            $mediaSettings['show_poster'] = $hasCustomPoster;
            // Show the inline "1x" speed control instead of the settings gear menu.
            if (!isset($mediaSettings['controls']) || !is_array($mediaSettings['controls'])) {
                $mediaSettings['controls'] = [];
            }
            $mediaSettings['controls']['settings'] = false;
        }

        $formattedMedia['settings'] = $mediaSettings;
        $formattedMedia['deep_link_ref'] = $playerKey;

        // Generate the HTML using fluent-player's view system
        ob_start();
        if (class_exists('\FluentPlayer\App\App')) {
            try {
                $fluentPlayerApp = \FluentPlayer\App\App::getInstance();
                if ($fluentPlayerApp) {
                    $fluentPlayerApp->view->render('player', [
                        'media_id' => $mediaId,
                        'instance_id' => $instanceId,
                        'media_var_name' => $mediaVarName,
                        'player_ref' => $playerKey,
                        'settings' => $mediaSettings
                    ]);
                }
            } catch (\Exception $e) {
            }
        }
        $html = ob_get_clean();
        return [
            'html' => $html ? $html . $this->generateFluentPlayerCustomStyle($instanceId, $mediaSettings) : '',
            'media' => $formattedMedia
        ];
    }

    private function isAudioMedia($settings)
    {
        if (class_exists('\FluentPlayer\App\Helpers\Helper')
            && method_exists('\FluentPlayer\App\Helpers\Helper', 'isAudioSource')) {
            return \FluentPlayer\App\Helpers\Helper::isAudioSource($settings);
        }
        return Arr::get($settings, 'viewType') === 'audio';
    }

    private function generateFluentPlayerCustomStyle($instanceId, $settings)
    {
        $customCss = '';
        $isAudio = $this->isAudioMedia($settings);
        $playerWidth = intval(Arr::get($settings, 'playerWidth'));
        if ($playerWidth > 0) {
            $customCss .= "
                #fluent_player_" . esc_attr($instanceId) . " .fluent-player-container {
                    width: " . $playerWidth . 'px' . ";
                }
            ";
        }
        $colorVars = array_filter([
            '--media-brand'       => Bootstrap::sanitizeColor(Arr::get($settings, 'brandColor', '')),
            '--fp-control-bar-bg' => Bootstrap::sanitizeColor(Arr::get($settings, 'controlBarColor', '')),
            '--media-play-color'  => Bootstrap::sanitizeColor(Arr::get($settings, 'playButtonColor', '')),
            '--media-play-bg'     => Bootstrap::sanitizeColor(Arr::get($settings, 'playButtonBgColor', ''))
        ]);
        if ($colorVars) {
            $customCss .= "
                #fluent_player_" . esc_attr($instanceId) . " {";
            foreach ($colorVars as $varName => $varValue) {
                $customCss .= "
                    " . $varName . ": " . esc_attr($varValue) . ";";
            }
            $customCss .= "
                }
            ";
        }
        if (!empty(Arr::get($settings, 'posterSrc', ''))) {
            $customCss .= "
                #fluent_player_" . esc_attr($instanceId) . " .fluent-player-container {
                    background-image: url('" . esc_url(Arr::get($settings, 'posterSrc', '')) . "');
                    background-size: cover;
                    background-position: center;
                    min-height: 100%;
                }
            ";
        }

        if ($isAudio) {
            // Audio follows the FluentCommunity color schema (Utility::getColorSchemas()).
            // The player renders inside the portal DOM and inherits the --fcom-* CSS vars that
            // generateCss() emits on body (and html.dark body), so theme/skin/dark changes recolor
            // audio live with no re-save. The stock "modern" audio skin is built for a dark
            // background (white title, translucent-white controls, brand-colored bar); we override
            // those to a flat, theme-surface card. Play circle + progress fill use the auto-
            // inverting primary_text/primary_bg pair so the same rules match a light card
            // (dark on light) and a dark card (light on dark) without per-theme branching.
            $playerId = '#fluent_player_' . esc_attr($instanceId);
            $customCss .= "
                {$playerId} {
                    --media-brand: var(--fcom-primary-text, #19283a);
                    --fp-control-bar-bg: transparent;
                }
                {$playerId} .fp-audio-layout {
                    background-color: var(--fcom-secondary-content-bg, var(--fcom-active-bg, #f0f2f5));
                    border-radius: 12px;
                }
                {$playerId} .fp-audio-main-controls {
                    background: transparent;
                }
                {$playerId} .fp-audio-title {
                    color: var(--fcom-primary-text, #19283a);
                    font-size: 14px;
                    font-weight: 600;
                }
                {$playerId} .fp-audio-subtitle {
                    color: var(--fcom-primary-text, #19283a);
                }
                {$playerId} media-play-button {
                    background: var(--fcom-primary-text, #19283a);
                }
                {$playerId} media-play-button media-icon {
                    color: var(--fcom-primary-bg, #ffffff);
                }
                {$playerId} media-seek-button {
                    background: transparent;
                }
                {$playerId} media-time-slider .fp-media-slider-track {
                    background: var(--fcom-secondary-border, #9CA3AF);
                    background: color-mix(in srgb, var(--fcom-primary-text, #19283a) 20%, transparent);
                }
                {$playerId} media-time-slider .fp-media-slider-track-fill {
                    background: var(--fcom-primary-text, #19283a);
                }
                {$playerId} .fp-seek-backward-10,
                {$playerId} .fp-seek-forward-10,
                {$playerId} .fp-media-mute-icon,
                {$playerId} .fp-media-volume-low-icon,
                {$playerId} .fp-media-volume-high-icon,
                {$playerId} media-seek-button media-icon,
                {$playerId} media-icon[type=\"odometer\"],
                {$playerId} media-icon[type=\"settings\"] {
                    color: var(--fcom-secondary-text, #525866);
                }
                {$playerId} .fp-media-time-group,
                {$playerId} .fp-media-time-group media-time,
                {$playerId} .fp-media-time-divider {
                    color: var(--fcom-primary-text, #19283a);
                }
            ";

            // Exact layout: large play circle on the left (spanning title + controls),
            // a single control row (seek / seek / volume / speed / time) and a full-width
            // progress bar at the bottom. The volume slider is hidden until hover.
            $customCss .= "
                {$playerId} .fp-audio-layout {
                    position: relative;
                    padding: 22px 24px 14px;
                }
                {$playerId} .fp-audio-layout .fp-audio-content {
                    padding: 0 0 0 82px;
                    gap: 6px;
                    width: 100%;
                }
                {$playerId} .fp-audio-header {
                    padding-left: 8px;
                }
                {$playerId} media-play-button {
                    position: absolute;
                    left: 22px;
                    top: 50%;
                    transform: translateY(-50%);
                    width: 64px;
                    height: 64px;
                    min-width: 64px;
                }
                {$playerId} .fp-audio-main-controls {
                    display: grid;
                    grid-template-columns: max-content max-content max-content max-content 1fr max-content;
                    grid-template-areas: \"sb sf vol spd play time\" \"pr pr pr pr pr pr\";
                    align-items: center;
                    column-gap: 0;
                    row-gap: 6px;
                    padding: 0;
                    width: 100%;
                }
                {$playerId} .fp-audio-main-controls media-tooltip:nth-of-type(1) { grid-area: sb; margin: 0; }
                {$playerId} .fp-audio-main-controls media-tooltip:nth-of-type(2) { grid-area: play; }
                {$playerId} .fp-audio-main-controls media-tooltip:nth-of-type(3) { grid-area: sf; margin: 0; }
                {$playerId} media-seek-button,
                {$playerId} .fp-audio-volume-group media-mute-button {
                    width: 32px;
                    height: 32px;
                    min-width: 32px;
                }
                {$playerId} .fp-audio-secondary-controls media-menu-button { min-width: 32px; padding: 0 6px; }
                {$playerId} .fp-audio-volume-group { grid-area: vol; margin: 0; overflow: visible; }
                {$playerId} .fp-audio-secondary-controls { grid-area: spd; margin: 0; }
                {$playerId} .fp-media-time-group { grid-area: time; margin: 0; justify-self: end; }
                {$playerId} media-player[data-view-type=\"audio\"] media-time-slider,
                {$playerId} media-time-slider { grid-area: pr; margin: 8px; }
                {$playerId} media-seek-button:hover,
                {$playerId} .fp-audio-volume-group media-mute-button:hover,
                {$playerId} .fp-audio-secondary-controls media-menu-button:hover {
                    background: transparent;
                }
                {$playerId} .fp-audio-volume-group media-volume-slider {
                    width: 0;
                    max-width: 0;
                    min-width: 0;
                    opacity: 0;
                    overflow: hidden;
                    margin: 0;
                    transition: width .2s ease, opacity .2s ease, margin .2s ease;
                }
                {$playerId} .fp-audio-volume-group:hover media-volume-slider,
                {$playerId} .fp-audio-volume-group:focus-within media-volume-slider {
                    width: 80px;
                    max-width: 80px;
                    opacity: 1;
                    margin-left: 6px;
                }
                {$playerId} .fp-speed-menu .fcom_speed_label {
                    font-weight: 600;
                    font-size: 15px;
                    line-height: 1;
                    color: var(--fcom-primary-text, #19283a);
                }
            ";

            // When a custom thumbnail is set, lay the card out as poster -> play -> content
            // (matching the reference). The poster sits on the left, the play circle after it.
            $customCss .= "
                {$playerId} .fp-audio-layout:not(.fp-audio-no-poster) {
                    gap: 16px;
                    padding-left: 16px;
                }
                {$playerId} .fp-audio-layout:not(.fp-audio-no-poster) .fp-audio-poster {
                    width: 96px;
                    height: 96px;
                    min-width: 96px;
                    border-radius: 12px;
                }
                {$playerId} .fp-audio-layout:not(.fp-audio-no-poster) media-play-button {
                    left: 128px;
                }
                {$playerId} .fp-audio-layout:not(.fp-audio-no-poster) .fp-audio-content {
                    padding-left: 82px;
                }
            ";
        }

        $aspectRatio = Arr::get($settings, 'aspectRatio');
        if ($aspectRatio && $aspectRatio != 'original' && preg_match('/^\d+:\d+$/', $aspectRatio)) {
            $cssAspectRatio = preg_replace('/^(\d+):(\d+)$/', '$1/$2', $aspectRatio);
            $customCss .= "
                #fluent_player_" . esc_attr($instanceId) . " {
                    aspect-ratio: " . esc_attr($cssAspectRatio) . ";
                }
                #fluent_player_" . esc_attr($instanceId) . " media-player[data-view-type='video'] {
                    aspect-ratio: " . esc_attr($cssAspectRatio) . ";
                }
            ";
        } else if (!$isAudio) {
            $customCss .= "
                #fluent_player_" . esc_attr($instanceId) . " {
                    max-width: 100%;
                    min-height: 300px;
                }

                @media (max-width: 768px) {
                    #fluent_player_" . esc_attr($instanceId) . " {
                        min-height: 200px;
                    }
                }
            ";
        }
        if (!empty($customCss)) {
            return '<style>' . $customCss . '</style>';
        }
        return '';
    }

    private function getFluentplayerDefaultsSettings()
    {
        $settings = [
            'src' => '',
            'title' => '',
            'posterSrc' => '',
            'viewType' => 'video',
            'brandColor' => '#4a90e2',
            'aspectRatio' => 'original',
            'playerWidth' => '',
            'playsInline' => true
        ];
		$mediaSettings = Bootstrap::getSettings();
	    if (Arr::isTrue($mediaSettings, 'behaviors.muted_autoplay')) {
		    $mediaSettings['autoplay'] = true;
		    $mediaSettings['muted'] = true;
	    }
        $settings['loadStrategy'] = 'idle'; // for SPA context this needs to be set to 'idle' 
        $settings = array_merge($settings, $mediaSettings);

        // Apply iOS Safari compatibility (playsinline, preload; muted only when autoplay is on)
        if (class_exists('\FluentPlayer\App\Services\MediaService')) {
            [$isIosSafari, $iosSafariSettings] = \FluentPlayer\App\Services\MediaService::getIOSSafariSettings($settings);
            if ($isIosSafari) {
                $settings = array_merge($settings, $iosSafariSettings);
            }
        }

        return apply_filters('fluent_community/fluentplayer_defaults_settings', $settings);
    }
}
