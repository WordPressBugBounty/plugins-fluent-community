<?php

namespace FluentCommunity\Modules\Integrations\FluentPlayer\Http\Controllers;

use FluentCommunity\App\Http\Controllers\Controller;
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
		
        $allowedVideoTypes = apply_filters('fluent_community/support_video_types', [
            'video/mp4',
            'video/m3u8',
			'video/mpd',
            'video/webm',
            'video/mov',
            'video/quicktime'
        ]);
        $maxFileUnit = apply_filters('fluent_community/video_upload_max_file_unit', 'MB');
        $maxFileSize = apply_filters('fluent_community/video_upload_max_file_size', 300);
        $allowedFileSize = $maxFileSize;
        if (strtoupper($maxFileUnit) == 'MB') {
            $allowedFileSize = $maxFileSize * 1024;
        } else if (strtoupper($maxFileUnit) == 'GB') {
            $allowedFileSize = $maxFileSize * 1024 * 1024;
        }

        $allowedTypesString = implode(',', $allowedVideoTypes);

        $files = $this->validate($request->files(), [
            'file' => 'mimetypes:' . $allowedTypesString . '|max:' . $allowedFileSize,
        ], [
            'file.mimetypes' => __('The file must be a valid video type (MP4, M3U8, MPD, WebM, MOV).', 'fluent-community'),
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
                'src'  => $file['url'],
                'title' => $file['original_name']
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

    public function getFluentPlayerContent(Request $request)
    {
        if (!defined('FLUENT_PLAYER_VERSION')) {
            return [
                'html' => '',
            ];
        }
        $mediaId = intval($request->get('media_id'));
        $media = Media::find($mediaId);
        if (!$media) {
            $media = (object) $request->all();
        }
        return $this->generateFluentPlayerHtml($mediaId, $media);
    }

    private function generateFluentPlayerHtml($mediaId, $media)
    {
        $mediaVarName = 'fluentPlayerMedia_' . $mediaId . '_' . wp_rand(1000, 9999);
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
		
        $mediaSettings = wp_parse_args($mediaSettings, $this->getFluentplayerDefaultsSettings());
        $formattedMedia['settings'] = $mediaSettings;

        // Generate the HTML using fluent-player's view system
        ob_start();
        if (class_exists('\FluentPlayer\App\App')) {
            try {
                $fluentPlayerApp = \FluentPlayer\App\App::getInstance();
                if ($fluentPlayerApp) {
                    $fluentPlayerApp->view->render('player', [
                        'media_id' => $mediaId,
                        'media_var_name' => $mediaVarName,
                        'settings' => $mediaSettings
                    ]);
                }
            } catch (\Exception $e) {
            }
        }
        $html = ob_get_clean();
        return [
            'html' => $html ? $html . $this->generateFluentPlayerCustomStyle($mediaId, $mediaSettings) : '',
            'media' => $formattedMedia
        ];
    }

    private function generateFluentPlayerCustomStyle($mediaId, $settings)
    {
        $customCss = '';
        $playerWidth = Arr::get($settings, 'playerWidth');
        if ($playerWidth) {
            $customCss .= "
                #fluent_player_" . esc_attr($mediaId) . " .fluent-player-container {
                    width: " . esc_attr($playerWidth) . 'px' . ";
                }
            ";
        }
        $brandingColor = Arr::get($settings, 'brandColor', '');
        if ($brandingColor) {
            $customCss .= "
                #fluent_player_" . esc_attr($mediaId) . " {
                    --media-brand: " . esc_attr($brandingColor) . ";
                }
            ";
        }
        if (!empty(Arr::get($settings, 'posterSrc', ''))) {
            $customCss .= "
                #fluent_player_" . esc_attr($mediaId) . " .fluent-player-container {
                    background-image: url('" . esc_url(Arr::get($settings, 'posterSrc', '')) . "');
                    background-size: cover;
                    background-position: center;
                }
            ";
        }

        $aspectRatio = Arr::get($settings, 'aspectRatio');
        if ($aspectRatio && $aspectRatio != 'original') {
            $cssAspectRatio = preg_replace('/^(\d+):(\d+)$/', '$1/$2', $aspectRatio);
            $customCss .= "
                #fluent_player_" . esc_attr($mediaId) . " {
                    aspect-ratio: " . esc_attr($cssAspectRatio) . ";
                }
                #fluent_player_" . esc_attr($mediaId) . " media-player[data-view-type='video'] {
                    aspect-ratio: " . esc_attr($cssAspectRatio) . ";
                }
            ";
        } else {
            $customCss .= "
                #fluent_player_" . esc_attr($mediaId) . " {
                    min-height: 300px;
                }

                @media (max-width: 768px) {
                    #fluent_player_" . esc_attr($mediaId) . " {
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
            'loadStrategy' => 'idle',
            'brandColor' => '#4a90e2',
            'aspectRatio' => 'original',
            'playerWidth' => '',
        ];
		$mediaSettings = Bootstrap::getSettings();
	    if (Arr::isTrue($mediaSettings, 'behaviors.muted_autoplay')) {
		    $mediaSettings['autoplay'] = true;
		    $mediaSettings['muted'] = true;
	    }
        $settings = array_merge($settings, $mediaSettings);
        return apply_filters('fluent_community/fluentplayer_defaults_settings', $settings);
    }
}
