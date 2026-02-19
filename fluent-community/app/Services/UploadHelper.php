<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Services\Libs\FileSystem;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Framework\Validator\Validator;

class UploadHelper
{
    public function processUpload($requestFiles, $options = [])
    {
        $defaultOptions = [
            'max_size'        => 10,
            'size_unit'       => 'MB',
            'disable_convert' => 'no',
            'resize'          => true,
            'max_width'       => 0,
            'context'         => '',
            'skip_convert'    => ''
        ];

        $options = wp_parse_args($options, $defaultOptions);

        $allowedTypes = implode(
            ',',
            apply_filters('fluent_community/support_attachment_types', [
                'image/jpeg',
                'image/pjpeg',
                'image/jpeg',
                'image/pjpeg',
                'image/png',
                'image/gif',
                'image/webp'
            ])
        );

        $maxFileUnit = apply_filters('fluent_community/media_upload_max_file_unit', $options['size_unit']);
        $maxFileSize = apply_filters('fluent_community/media_upload_max_file_size', $options['max_size']);

        $allowedFileSize = $maxFileSize;
        if (strtoupper($maxFileUnit) == 'MB') {
            $allowedFileSize = $maxFileSize * 1024;
        } else if (strtoupper($maxFileUnit) == 'GB') {
            $allowedFileSize = $maxFileSize * 1024 * 1024;
        }

        $validator = new Validator([
            'file' => $requestFiles
        ], );

        $validator->validate($requestFiles, [
            'file' => 'mimetypes:' . $allowedTypes . '|max:' . $allowedFileSize,
        ], [
            'file.mimetypes' => __('The file must be an image type.', 'fluent-community'),
            /* translators: %$1s is replaced by the maximum allowed file size, %2$s is replaced by the file size unit (e.g. MB) */
            'file.max'       => sprintf(__('The file size must be less than %1$s%2$s.', 'fluent-community'), $maxFileSize, $maxFileUnit)
        ]);

        if ($validator->fails()) {
            return new \WP_Error('validation_error', __('Validation Error', 'fluent-community'), $validator->errors());
        }

        add_filter('wp_handle_upload', [$this, 'fixImageOrientation']);
        $uploadedFiles = FileSystem::put($requestFiles);
        remove_filter('wp_handle_upload', [$this, 'fixImageOrientation']);

        $file = $uploadedFiles[0];

        $upload_dir = wp_upload_dir();

        $originalUrl = $file['url'];
        $orginalPath = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];
        $originalFileType = $file['type'];
        $originalFileName = $file['file'];

        $willWebPConvert = Arr::get($options, 'disable_convert') != 'yes';

        $willWebPConvert = apply_filters('fluent_community/convert_image_to_webp', $willWebPConvert, $file);
        $willResize = Arr::get($options, 'resize') != 'yes';
        $maxWidth = Arr::get($options, 'max_width', 0);

        $willResize = apply_filters('fluent_community/media_upload_resize', $willResize, $file);

        if ($context = Arr::get($options, 'context')) {
            $maxWidth = apply_filters('fluent_community/media_upload_max_width_' . $context, $maxWidth, $file);
        }

        if ($willResize && $maxWidth) {
            $upload_dir = wp_upload_dir();
            $fileUrl = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file['url']);

            $editor = wp_get_image_editor($fileUrl);

            if (!is_wp_error($editor) && $editor->get_size()['width'] > $maxWidth) {
                // Current file extension
                $ext = pathinfo($file['url'], PATHINFO_EXTENSION);
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                $willConvert = in_array($ext, $imageExtensions) && $willWebPConvert;

                if ($willConvert) {
                    $imageExtensions = array_map(function ($ext) {
                        return '.' . $ext;
                    }, $imageExtensions);

                    $fileUrl = str_replace($imageExtensions, '.webp', $fileUrl);
                    $file['file'] = str_replace($imageExtensions, '.webp', $file['file']);
                    $file['url'] = str_replace($imageExtensions, '.webp', $file['url']);
                    $file['type'] = 'image/webp';
                }

                // resize the image
                $editor->resize($maxWidth, null, false);
                $editor->set_quality(90);
                if ($willConvert) {
                    $result = $editor->save($fileUrl, 'image/webp');
                    if ($result['mime-type'] == 'image/webp') {
                        // remove original file now
                        wp_delete_file(str_replace('.webp', '.' . $ext, $fileUrl));
                    }
                    $file['is_converted'] = true;
                } else {
                    $result = $editor->save($fileUrl);
                }

                if ($result['mime-type'] != 'image/webp') {
                    $file['file'] = $originalFileName;
                    $file['url'] = $originalUrl;
                    $file['type'] = $result['mime-type'];
                }

                $file['meta'] = [
                    'width'  => $editor->get_size()['width'],
                    'height' => $editor->get_size()['height']
                ];
            }
            $file['path'] = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];
        } else {
            $upload_dir = wp_upload_dir();
            $file['path'] = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];
        }

        if ($willWebPConvert && empty($file['is_converted']) && !Arr::get($options, 'skip_convert')) {
            $path = $file['path'];
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            $convertFromExtensions = ['png', 'jpg', 'jpeg', 'gif'];
            if ($extension != 'webp' && in_array($extension, $convertFromExtensions)) {
                // Let's convert to webp
                $editor = wp_get_image_editor($file['path']);
                if (!is_wp_error($editor)) {
                    $file['path'] = str_replace('.' . $extension, '.webp', $file['path']);
                    $file['url'] = str_replace('.' . $extension, '.webp', $file['url']);
                    $file['type'] = 'image/webp';
                    $result = $editor->save($file['path'], 'image/webp');

                    if ($result['mime-type'] != 'image/webp') {
                        $file['path'] = $orginalPath;
                        $file['url'] = $originalUrl;
                        $file['type'] = $result['mime-type'];
                    } else {
                        wp_delete_file($orginalPath);
                    }

                    $file['meta'] = [
                        'width'  => $editor->get_size()['width'],
                        'height' => $editor->get_size()['height']
                    ];
                }
            }
        }

        $mediaData = [
            'media_type' => $file['type'],
            'driver'     => 'local',
            'media_path' => $file['path'],
            'media_url'  => $file['url'],
            'settings'   => Arr::get($file, 'meta', [])
        ];

        $mediaData = apply_filters('fluent_community/media_upload_data', $mediaData, $file);

        if (is_wp_error($mediaData)) {
            return $mediaData;
        }

        if (!$mediaData) {
            return new \WP_Error('upload_error', __('Upload cancelled by a filter', 'fluent-community'));
        }

        // Let's create the media now
        $media = Media::create($mediaData);

        $mediaUrl = $media->public_url;

        $mediaUrl = add_query_arg([
            'media_key' => $media->media_key,
        ], $mediaUrl);

        return [
            'url'       => $mediaUrl,
            'media_key' => $media->media_key,
            'type'      => $media->media_type,
            'width'     => Arr::get($media->settings, 'width'),
            'height'    => Arr::get($media->settings, 'height')
        ];
    }

    public function fixImageOrientation($file)
    {
        // Only process JPEG images (since they typically have EXIF data)
        $image_types = array('image/jpeg', 'image/jpg');
        if (!in_array($file['type'], $image_types)) {
            return $file;
        }

        // Check if the EXIF extension is available
        if (!function_exists('exif_read_data')) {
            return $file;
        }

        // Read EXIF data from the uploaded image
        $exif = @exif_read_data($file['file']);

        if (!$exif || !isset($exif['Orientation'])) {
            return $file;
        }

        $orientation = $exif['Orientation'];

        // Load the image based on the available library (Imagick or GD)
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            // Use Imagick if available
            try {
                $image = new \Imagick($file['file']);
                switch ($orientation) {
                    case 3: // 180°
                        $image->rotateImage(new \ImagickPixel(), 180);
                        break;
                    case 6: // 90° clockwise
                        $image->rotateImage(new \ImagickPixel(), 90);
                        break;
                    case 8: // 90° counter-clockwise
                        $image->rotateImage(new \ImagickPixel(), -90);
                        break;
                }
                // Strip EXIF data to prevent further issues
                $image->stripImage();
                // Save the rotated image
                $image->writeImage($file['file']);
                $image->destroy();
            } catch (\Exception $e) {

            }
        } elseif (function_exists('imagecreatefromjpeg')) {
            // Use GD if Imagick is not available
            $image = @imagecreatefromjpeg($file['file']);
            if ($image === false) {
                return $file;
            }

            switch ($orientation) {
                case 3: // 180°
                    $image = imagerotate($image, 180, 0);
                    break;
                case 6: // 90° clockwise
                    $image = imagerotate($image, -90, 0);
                    break;
                case 8: // 90° counter-clockwise
                    $image = imagerotate($image, 90, 0);
                    break;
            }

            // Save the rotated image
            imagejpeg($image, $file['file'], 100);
            imagedestroy($image);
        }

        return $file;
    }
}
