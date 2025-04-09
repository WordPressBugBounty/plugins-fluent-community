<?php

namespace FluentCommunity\App\Services;

class SmartCodeParser
{
    public function parse($templateString, $data)
    {
        $result = [];
        $isSingle = false;

        if (!is_array($templateString)) {
            $isSingle = true;
        }

        foreach ((array)$templateString as $key => $string) {
            $result[$key] = $this->parseShortcode($string, $data);
        }

        if ($isSingle) {
            return reset($result);
        }

        return $result;
    }


    public function parseShortcode($string, $data)
    {
        // check if the string contains any smartcode
        if (strpos($string, '{{') === false && strpos($string, '##') === false) {
            return $string;
        }

        return preg_replace_callback('/({{|##)+(.*?)(}}|##)/', function ($matches) use ($data) {
            return $this->replace($matches, $data);
        }, $string);
    }

    protected function replace($matches, $user)
    {
        if (empty($matches[2])) {
            return apply_filters('fluent_community/smartcode_fallback', $matches[0], $user);
        }

        $matches[2] = trim($matches[2]);

        $matched = explode('.', $matches[2]);

        if (count($matched) <= 1) {
            return apply_filters('fluent_community/smartcode_fallback', $matches[0], $user);
        }

        $dataKey = trim(array_shift($matched));

        $valueKey = trim(implode('.', $matched));

        if (!$valueKey) {
            return apply_filters('fluent_community/smartcode_fallback', $matches[0], $user);
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
                $value = $this->getWpValue($valueKey, $defaultValue, $user);
                break;
            case 'user':
                if (!$user) {
                    $value = $defaultValue;
                } else {
                    $value = $this->getUserValue($valueKey, $defaultValue, $user);
                }
                break;
            default:
                $value = apply_filters('fluent_community/smartcode_group_callback_' . $dataKey, $matches[0], $valueKey, $defaultValue, $user);
        }

        if ($transformer && is_string($transformer) && $value) {
            switch ($transformer) {
                case 'trim':
                    return trim($value);
                case 'ucfirst':
                    return ucfirst($value);
                case 'strtolower':
                    return strtolower($value);
                case 'strtoupper':
                    return strtoupper($value);
                case 'ucwords':
                    return ucwords($value);
                case 'concat_first': // usage: {{contact.first_name||concat_first|Hi
                    if (isset($valueKeys[3])) {
                        $value = trim($valueKeys[3] . ' ' . $value);
                    }
                    return $value;
                case 'concat_last': // usage: {{contact.first_name||concat_last|, => FIRST_NAME,
                    if (isset($valueKeys[3])) {
                        $value = trim($value . '' . $valueKeys[3]);
                    }
                    return $value;
                case 'show_if': // usage {{contact.first_name||show_if|First name exist
                    if (isset($valueKeys[3])) {
                        $value = $valueKeys[3];
                    }
                    return $value;
                default:
                    return $value;
            }
        }

        return $value;

    }

    protected function getWpValue($valueKey, $defaultValue, $wpUser = [])
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


    protected function getUserValue($valueKey, $defaultValue, $userModel = null)
    {
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
}
