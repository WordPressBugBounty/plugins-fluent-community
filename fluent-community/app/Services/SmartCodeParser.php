<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\Modules\Course\Model\CourseTopic;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;

class SmartCodeParser
{
    protected static $isHtml = true;

    protected static $store = [
        'user'      => null,
        'feed'      => null,
        'course'    => null,
        'community' => null
    ];

    public function parse($templateString, $user, $feed = null, $isHtml = true)
    {
        static::$isHtml = $isHtml;
        static::setData($user, $feed);

        $result = [];
        $isSingle = false;

        if (!is_array($templateString)) {
            $isSingle = true;
        }

        foreach ((array)$templateString as $key => $string) {
            $result[$key] = $this->parseShortcode($string);
        }

        if ($isSingle) {
            return reset($result);
        }

        return $result;
    }

    protected static function setData($user, $feed = null)
    {
        static::$store['user'] = $user;
        static::$store['feed'] = $feed;
        static::$store['course'] = ($feed && $feed->course) ? $feed->course : null;
        static::$store['community'] = Helper::generalSettings();
    }

    public function parseShortcode($string)
    {
        if (strpos($string, '{{') === false && strpos($string, '##') === false) {
            return $string;
        }

        if (static::$isHtml) {
            $string = $this->resolveHrefPlaceholders($string);
        }

        return preg_replace_callback('/({{|##)+(.*?)(}}|##)/', function ($matches) {
            return $this->replace($matches);
        }, $string);
    }

    /**
     * Resolve placeholders inside href="..." attributes to raw URLs.
     * Prevents nested anchors when URL smartcodes auto-wrap in HTML mode.
     */
    protected function resolveHrefPlaceholders($string)
    {
        return preg_replace_callback(
            '/(?<![a-zA-Z-])href\s*=\s*(["\'])([^"\']*(?:\{\{|##)[^"\']*)\1/i',
            function ($m) {
                static::$isHtml = false;
                $resolved = $this->parseShortcode($m[2]);
                static::$isHtml = true;
                return 'href=' . $m[1] . esc_url($resolved) . $m[1];
            },
            $string
        );
    }

    protected function replace($matches)
    {
        if (empty($matches[2])) {
            return apply_filters('fluent_community/smartcode_fallback', $matches[0], $this->store['user']);
        }

        $matches[2] = trim($matches[2]);

        $matched = explode('.', $matches[2]);

        if (count($matched) <= 1) {
            return apply_filters('fluent_community/smartcode_fallback', $matches[0], $this->store['user']);
        }

        $dataKey = trim(array_shift($matched));

        $valueKey = trim(implode('.', $matched));

        if (!$valueKey) {
            return apply_filters('fluent_community/smartcode_fallback', $matches[0], $this->store['user']);
        }

        $valueKeys = explode('|', $valueKey);

        $valueKey = $valueKeys[0];
        $defaultValue = '';
        $transformer = '';

        $valueCounts = count($valueKeys);

        if ($valueCounts >= 3) {
            $defaultValue = trim($valueKeys[1]);
            $transformer = trim($valueKeys[2]);
        } else if ($valueCounts === 2) {
            $defaultValue = trim($valueKeys[1]);
        }

        $value = '';
        switch ($dataKey) {
            case 'site':
                $value = $this->getWpValue($valueKey, $defaultValue);
                break;
            case 'user':
                $value = static::$store['user'] ? $this->getUserValue($valueKey, $defaultValue) : $defaultValue;
                break;
            case 'community':
                $value = $this->getCommunityValue($valueKey, $defaultValue);
                break;
            case 'section':
                $value = $this->getSectionValue($valueKey, $defaultValue);
                break;
            case 'course':
                $value = $this->getCourseValue($valueKey, $defaultValue);
                break;
            default:
                $value = apply_filters('fluent_community/smartcode_group_callback_' . $dataKey, $matches[0], $valueKey, $defaultValue, static::$store['user']);
        }

        if ($transformer && is_string($transformer) && $value) {
            switch ($transformer) {
                case 'trim':
                    $value = trim($value);
                    break;
                case 'ucfirst':
                    $value = ucfirst($value);
                    break;
                case 'strtolower':
                    $value = strtolower($value);
                    break;
                case 'strtoupper':
                    $value = strtoupper($value);
                    break;
                case 'ucwords':
                    $value = ucwords($value);
                    break;
                case 'concat_first': // usage: {{contact.first_name||concat_first|Hi
                    if (isset($valueKeys[3])) {
                        $value = trim($valueKeys[3] . ' ' . $value);
                    }
                    break;
                case 'concat_last': // usage: {{contact.first_name||concat_last|, => FIRST_NAME,
                    if (isset($valueKeys[3])) {
                        $value = trim($value . '' . $valueKeys[3]);
                    }
                    break;
                case 'show_if': // usage {{contact.first_name||show_if|First name exist
                    if (isset($valueKeys[3])) {
                        $value = $valueKeys[3];
                    }
                    break;
            }
        }

        return $this->escapeValueForContext($value, $dataKey, $valueKey);
    }

    /**
     * Smartcode values are substituted into lockscreen/lesson/email HTML *after*
     * that content has passed through wp_kses / do_blocks, so a resolved scalar
     * carrying markup would otherwise bypass sanitisation. Escape ordinary
     * values for the HTML context. The few branches that intentionally build a
     * trusted fragment (photo_html, name_with_url, section url) already escape
     * their own interpolated parts, so they are left untouched, and values from
     * third-party group callbacks are the extension's responsibility.
     */
    protected function escapeValueForContext($value, $dataKey, $valueKey)
    {
        if (!static::$isHtml || !is_string($value) || $value === '') {
            return $value;
        }

        $knownGroups = ['site', 'user', 'community', 'section', 'course'];
        if (!in_array($dataKey, $knownGroups, true)) {
            return $value;
        }

        $trustedHtml = [
            'user'      => ['photo_html'],
            'community' => ['name_with_url'],
            'section'   => ['url'],
        ];

        $baseKey = strtok($valueKey, '.'); // "photo_html.50px" -> "photo_html"
        if (in_array($baseKey, Arr::get($trustedHtml, $dataKey, []), true)) {
            return $value;
        }

        return esc_html($value);
    }

    protected function getWpValue($valueKey, $defaultValue)
    {
        if ($valueKey == 'login_url') {
            return network_site_url('wp-login.php', 'login');
        }

        if ($valueKey == 'name') {
            return wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        }

        $value = get_bloginfo($valueKey);
        if (!$value) {
            return $defaultValue;
        }
        return $value;
    }


    protected function getUserValue($valueKey, $defaultValue)
    {
        $userModel = static::$store['user'];
        if (!$userModel || !$userModel instanceof \FluentCommunity\App\Models\User) {
            return $defaultValue;
        }

        $xProfile = $userModel->xprofile;

        if ($valueKey == 'profile_link') {
            if ($xProfile) {
                return $xProfile->getPermalink();
            }
            return $defaultValue;
        }

        if ($xProfile) {
            if ($valueKey == 'display_name') {
                return $xProfile->display_name;
            }
        }

        if ($valueKey == 'photo_html') {
            if ($xProfile) {
                return '<img src="' . esc_url($xProfile->avatar) . '" alt="' . esc_attr($xProfile->display_name) . '" class="fcom_user_dynamic_photo" />';
            }
            return '<img src="' . esc_url($userModel->photo) . '" alt="' . esc_attr($userModel->display_name) . '" class="fcom_user_dynamic_photo" />';
        }

        $wpUser = $userModel->getWpUser();
        $valueKeys = explode('.', $valueKey);
        if (count($valueKeys) == 1) {
            // Smartcodes are resolved against the *viewer* and can be authored by
            // space/course/page admins, so only a fixed set of non-sensitive
            // profile fields may be read. Never expose user_pass,
            // user_activation_key, session tokens or capability meta.
            $allowedFields = apply_filters('fluent_community/smartcode/user_fields', [
                'ID', 'first_name', 'last_name', 'nickname', 'display_name',
                'user_email', 'user_login', 'user_nicename', 'user_url',
                'description', 'user_registered'
            ]);

            if (!in_array($valueKey, $allowedFields, true)) {
                return $defaultValue;
            }

            $value = $wpUser->get($valueKey);
            if (!$value) {
                return $defaultValue;
            }

            if (!is_array($value) || !is_object($value)) {
                return $value;
            }

            return $defaultValue;
        }

        $customKey = $valueKeys[0];
        $customProperty = $valueKeys[1];

        if ($customKey === 'photo_html') {
            $width = (string)esc_attr($customProperty);
            $style = 'style="width: ' . $width . '; height: ' . $width . ';"';
            if ($xProfile) {
                return '<img ' . $style . ' src="' . esc_url($xProfile->avatar) . '" alt="' . esc_attr($xProfile->display_name) . '" class="fcom_user_dynamic_photo" />';
            }
            return '<img ' . $style . ' src="' . esc_url($userModel->photo) . '" alt="' . esc_attr($userModel->display_name) . '" class="fcom_user_dynamic_photo" />';
        }

        if ($customKey == 'meta') {
            // No first-party template reads user meta through smartcodes, and the
            // viewer's own meta (session_tokens, capability keys, reset keys,
            // any _-prefixed value) must never leak into author-controlled
            // content. Resolve only meta keys a site has explicitly allowed.
            $allowedMetaKeys = apply_filters('fluent_community/smartcode/user_meta_keys', []);

            if (strpos($customProperty, '_') === 0 || !in_array($customProperty, $allowedMetaKeys, true)) {
                return $defaultValue;
            }

            $metaValue = get_user_meta($wpUser->ID, $customProperty, true);
            if (!$metaValue) {
                return $defaultValue;
            }

            if (!is_array($metaValue) || !is_object($metaValue)) {
                return $metaValue;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }

    protected function getCommunityValue($valueKey, $defaultValue)
    {
        $communitySettings = static::$store['community'];

        if ($valueKey == 'name') {
            return Arr::get($communitySettings, 'site_title');
        }

        if ($valueKey == 'name_with_url') {
            $siteTitle = Arr::get($communitySettings, 'site_title');
            return static::$isHtml ? '<a target="_blank" href="' . Helper::baseUrl('/') . '">' . $siteTitle . '</a>' : $siteTitle;
        }

        if (isset($communitySettings[$valueKey])) {
            return $communitySettings[$valueKey];
        }

        return $defaultValue;
    }

    protected function getSectionValue($valueKey, $defaultValue)
    {
        $sectionModel = static::$store['feed'];
        if (!$sectionModel || !$sectionModel instanceof CourseTopic) {
            return $defaultValue;
        }

        if ($valueKey === 'url') {
            $user = static::$store['user'] ?? null;
            $course = static::$store['course'] ?? null;

            if (!$course instanceof Course) {
                return $defaultValue;
            }

            $courseUrl = $course->getPermalink();

            $userId = $user->ID ?? null;
            $signedUrl = $userId ? ProfileHelper::signUserUrlWithAuthHash($courseUrl, $userId) : $courseUrl;

            if (static::$isHtml) {
                return sprintf('<a href="%s">%s</a>', esc_url($signedUrl), esc_html($courseUrl));
            }

            return $signedUrl;
        }

        $fillables = array_merge(
            (new CourseTopic())->getFillable(),
            ['id', 'created_at', 'updated_at']
        );

        if (in_array($valueKey, $fillables)) {
            return $sectionModel->{$valueKey};
        }

        return $defaultValue;
    }

    protected function getCourseValue($valueKey, $defaultValue)
    {
        $courseModel = static::$store['course'];
        if (!$courseModel || !$courseModel instanceof Course) {
            return $defaultValue;
        }

        $fillables = array_merge(
            (new Course())->getFillable(),
            ['id', 'created_at', 'updated_at']
        );

        if (in_array($valueKey, $fillables)) {
            return $courseModel->{$valueKey};
        }

        return $defaultValue;
    }
}
