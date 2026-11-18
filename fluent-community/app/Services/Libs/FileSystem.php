<?php

namespace FluentCommunity\App\Services\Libs;

use FluentCommunity\App\Services\UploadHelper;

/**
 * @method string getDir()
 * @method mixed get(string $file)
 * @method string getAbsolutePathOfFile(string $file)
 * @method array uploadFromRequest()
 * @method array put(array $files)
 * @method array|\WP_Error sideload(array $file)
 * @method void delete(array|string $files)
 * @method void overrideUploadDir()
 * @method array setCustomUploadDir(array $param)
 * @method array renameFileName(array $file)
 */
class FileSystem
{
    /**
     * Read file content from custom upload dir of this application
     * @return string [path]
     */
    public function _get($file)
    {
        $arr = explode('/', $file);
        $fileName = end($arr);
        return file_get_contents($this->getDir() . '/' . $fileName); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /**
     * Get custom upload dir name of this application
     * @return string [directory path]
     */
    public function _getDir()
    {
        $uploadDir = wp_upload_dir();

        $folderName = apply_filters('fluent_community/upload_folder_name', FLUENT_COMMUNITY_UPLOAD_DIR);

        return $uploadDir['basedir'] . $folderName;
    }

    /**
     * Get absolute path of file using custom upload dir name of this application
     * @return string [file path]
     */
    public function _getAbsolutePathOfFile($file)
    {
        return $this->_getDir() . '/' . $file;
    }

    /**
     * Upload files into custom upload dir of this application
     * @return array
     */
    public function _uploadFromRequest()
    {
        return $this->_put(fluentCommunityApp('request')->files());
    }

    /**
     * Upload files into custom upload dir of this application
     * @param array $files
     * @return array
     */
    public function _put($files)
    {
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $this->overrideUploadDir();

        $uploadOverrides = [ 'test_form' => false ];

        $uploadedFiles = [];

        // Every upload path goes through here, so strip image metadata (when enabled) in one place.
        // Callers that add fixImageOrientation() first get it run before the strip.
        add_filter('wp_handle_upload', [ UploadHelper::class, 'stripImageMeta' ]);

        foreach ((array)$files as $file) {
            $filesArray = $file->toArray();
            $originalName = $filesArray['name'];
            $uploadedFile = \wp_handle_upload($filesArray, $uploadOverrides);

            if (isset($uploadedFile['error'])) {
                $uploadedFiles[] = new \WP_Error('upload_error', $uploadedFile['error']);
                continue;
            }

            $uploadedFile['original_name'] = $originalName;

            $uploadedFiles[] = $uploadedFile;
        }

        remove_filter('wp_handle_upload', [ UploadHelper::class, 'stripImageMeta' ]);

        return $uploadedFiles;
    }

    /**
     * Store a file the server wrote itself (a download or decoded data), the way put()
     * stores a browser upload: same folder, renamed, orientation fixed and metadata stripped.
     * wp_handle_upload() refuses such a file because PHP did not receive it as an upload.
     *
     * @param array $file name, type, tmp_name, error, size.
     * @return array|\WP_Error The stored file: url, file (name in the folder), type, original_name.
     */
    public function _sideload($file)
    {
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $prefilter = function ($upload) {
            add_filter('upload_dir', [ $this, '_setCustomUploadDir' ]);

            return $this->_renameFileName($upload);
        };

        $keepFileName = function ($fileinfo) {
            remove_filter('upload_dir', [ $this, '_setCustomUploadDir' ]);
            $fileinfo['file'] = basename($fileinfo['file']);

            return $fileinfo;
        };

        add_filter('wp_handle_sideload_prefilter', $prefilter);
        add_filter('wp_handle_upload', [ UploadHelper::class, 'fixImageOrientation' ]);
        add_filter('wp_handle_upload', [ UploadHelper::class, 'stripImageMeta' ]);
        add_filter('wp_handle_upload', $keepFileName);

        $originalName = $file['name'];
        $stored = \wp_handle_sideload($file, [ 'test_form' => false ]);

        remove_filter('wp_handle_sideload_prefilter', $prefilter);
        remove_filter('wp_handle_upload', [ UploadHelper::class, 'fixImageOrientation' ]);
        remove_filter('wp_handle_upload', [ UploadHelper::class, 'stripImageMeta' ]);
        remove_filter('wp_handle_upload', $keepFileName);
        remove_filter('upload_dir', [ $this, '_setCustomUploadDir' ]);

        if (isset($stored['error'])) {
            return new \WP_Error('upload_error', $stored['error']);
        }

        $stored['original_name'] = $originalName;

        return $stored;
    }

    /**
     * Delete a file from custom upload directory of this application
     * @param array $files
     * @return void
     */
    public function _delete($files)
    {
        $files = (array)$files;

        foreach ($files as $file) {
            $arr = explode('/', $file);
            $fileName = end($arr);
            wp_delete_file($this->getDir() . '/' . $fileName);
        }
    }

    /**
     * Register filters for custom upload dir
     */
    public function _overrideUploadDir()
    {
        add_filter('wp_handle_upload_prefilter', function ($file) {
            add_filter('upload_dir', [ $this, '_setCustomUploadDir' ]);

            add_filter('wp_handle_upload', function ($fileinfo) {
                remove_filter('upload_dir', [ $this, '_setCustomUploadDir' ]);
                $fileinfo['file'] = basename($fileinfo['file']);
                return $fileinfo;
            });

            return $this->_renameFileName($file);
        });
    }

    /**
     * Set plugin's custom upload dir
     * @param array $param
     * @return array $param
     */
    public function _setCustomUploadDir($param)
    {

        $folderName = apply_filters('fluent_community/upload_folder_name', FLUENT_COMMUNITY_UPLOAD_DIR);

        $param['url'] = $param['baseurl'] . '/' . $folderName;

        $param['path'] = $param['basedir'] . '/' . $folderName;

        // FileInfo directives only: Options or php_flag answer 500 for the whole folder where
        // AllowOverride does not include Options.
        $htaccessContent = "# BEGIN FluentCommunity\n# Disable parsing of PHP for some server configurations.\n<Files *>\nSetHandler none\nSetHandler default-handler\nRemoveHandler .cgi .php .php3 .php4 .php5 .phtml .pl .py .pyc .pyo\n</Files>\n# END FluentCommunity\n";
        $htaccessFile = $param['path'] . '/.htaccess';

        if (!is_dir($param['path'])) {
            // Recursive: the uploads base dir may not exist yet (fresh server, or an
            // upload_path outside wp-content). A plain mkdir() fails then, and the
            // .htaccess and index.php stubs are never written.
            if (!wp_mkdir_p($param['path'])) {
                return $param;
            }
            file_put_contents($htaccessFile, $htaccessContent); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        } elseif (is_file($htaccessFile) && strpos((string) file_get_contents($htaccessFile), '# END FluentCommunity\n') === 0) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            // Older versions wrote this file in single quotes, as one comment line that Apache ignores.
            file_put_contents($htaccessFile, $htaccessContent); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        if (!file_exists($param['basedir'] . '/' . $folderName . '/index.php')) {

            $indexStubContent = "<?php\n// Silence is golden.\n";

            file_put_contents($param['basedir'] . '/' . $folderName . '/index.php', $indexStubContent); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        return $param;
    }

    /**
     * Rename the uploaded file name before saving
     * @param array $file
     * @return array $file
     */
    public function _renameFileName($file)
    {
        $prefix = 'fluentcom-' . wp_generate_password(32, false) . '-fluentcom-';
        $originalName = $file['name'];
        $file['name'] = $prefix . $file['name'];
        $file['name'] = apply_filters('fluent_community/generated_upload_file_name', $file['name'], $originalName, $file);
        return $file;
    }

    public static function __callStatic($method, $params)
    {
        $instance = new static();

        return call_user_func_array([ $instance, $method ], $params);
    }

    public function __call($method, $params)
    {
        $hiddenMethod = "_" . $method;

        $method = method_exists($this, $hiddenMethod) ? $hiddenMethod : $method;

        return call_user_func_array([ $this, $method ], $params);
    }
}
