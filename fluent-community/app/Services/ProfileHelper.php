<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Meta;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Models\XProfile;

class ProfileHelper
{

    public static function getProfile($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        return XProfile::where('user_id', $userId)->first();
    }

    public static function getXProfilePublicFields()
    {
        return ['user_id', 'total_points', 'is_verified', 'status', 'display_name', 'username', 'avatar', 'created_at', 'last_activity', 'short_description', 'meta'];
    }

    public static function socialLinkProviders()
    {
        return apply_filters('fluent_community/social_link_providers', [
            'instagram' => [
                'title'       => 'Instagram',
                'icon_svg'    => '<svg viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 0C7.6302 0 7.8336 0.00599997 8.4732 0.036C9.1122 0.066 9.5472 0.1662 9.93 0.315C10.326 0.4674 10.6596 0.6738 10.9932 1.0068C11.2983 1.30674 11.5344 1.66955 11.685 2.07C11.8332 2.4522 11.934 2.8878 11.964 3.5268C11.9922 4.1664 12 4.3698 12 6C12 7.6302 11.994 7.8336 11.964 8.4732C11.934 9.1122 11.8332 9.5472 11.685 9.93C11.5348 10.3307 11.2987 10.6936 10.9932 10.9932C10.6932 11.2982 10.3304 11.5343 9.93 11.685C9.5478 11.8332 9.1122 11.934 8.4732 11.964C7.8336 11.9922 7.6302 12 6 12C4.3698 12 4.1664 11.994 3.5268 11.964C2.8878 11.934 2.4528 11.8332 2.07 11.685C1.6694 11.5347 1.30652 11.2986 1.0068 10.9932C0.701644 10.6933 0.465559 10.3305 0.315 9.93C0.1662 9.5478 0.066 9.1122 0.036 8.4732C0.00779997 7.8336 0 7.6302 0 6C0 4.3698 0.00599997 4.1664 0.036 3.5268C0.066 2.8872 0.1662 2.4528 0.315 2.07C0.465142 1.66931 0.701282 1.30639 1.0068 1.0068C1.3066 0.701539 1.66946 0.465438 2.07 0.315C2.4528 0.1662 2.8872 0.066 3.5268 0.036C4.1664 0.00779997 4.3698 0 6 0ZM6 3C5.20435 3 4.44129 3.31607 3.87868 3.87868C3.31607 4.44129 3 5.20435 3 6C3 6.79565 3.31607 7.55871 3.87868 8.12132C4.44129 8.68393 5.20435 9 6 9C6.79565 9 7.55871 8.68393 8.12132 8.12132C8.68393 7.55871 9 6.79565 9 6C9 5.20435 8.68393 4.44129 8.12132 3.87868C7.55871 3.31607 6.79565 3 6 3ZM9.9 2.85C9.9 2.65109 9.82098 2.46032 9.68033 2.31967C9.53968 2.17902 9.34891 2.1 9.15 2.1C8.95109 2.1 8.76032 2.17902 8.61967 2.31967C8.47902 2.46032 8.4 2.65109 8.4 2.85C8.4 3.04891 8.47902 3.23968 8.61967 3.38033C8.76032 3.52098 8.95109 3.6 9.15 3.6C9.34891 3.6 9.53968 3.52098 9.68033 3.38033C9.82098 3.23968 9.9 3.04891 9.9 2.85ZM6 4.2C6.47739 4.2 6.93523 4.38964 7.27279 4.72721C7.61036 5.06477 7.8 5.52261 7.8 6C7.8 6.47739 7.61036 6.93523 7.27279 7.27279C6.93523 7.61036 6.47739 7.8 6 7.8C5.52261 7.8 5.06477 7.61036 4.72721 7.27279C4.38964 6.93523 4.2 6.47739 4.2 6C4.2 5.52261 4.38964 5.06477 4.72721 4.72721C5.06477 4.38964 5.52261 4.2 6 4.2Z" fill="currentColor"/></svg>',
                'placeholder' => 'instagram @username',
                'domain'      => 'https://instagram.com/',
            ],
            'twitter'   => [
                'title'       => 'Twitter/X',
                'icon_svg'    => '<svg viewBox="0 0 12 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.1024 0.125H10.7564L7.1429 4.255L11.3939 9.875H8.0654L5.4584 6.4665L2.47542 9.875H0.820422L4.68542 5.4575L0.607422 0.125H4.02042L6.3769 3.2405L9.1024 0.125ZM8.5219 8.885H9.4384L3.52242 1.063H2.53892L8.5219 8.885Z" fill="currentColor"/></svg>',
                'placeholder' => 'twitter/X @username',
                'domain'      => 'https://x.com/',
            ],
            'youtube'   => [
                'title'       => 'YouTube',
                'icon_svg'    => '<svg viewBox="0 0 12 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.7258 1.699C12 2.7682 12 5.0002 12 5.0002C12 5.0002 12 7.2322 11.7258 8.3014C11.5734 8.8924 11.1276 9.3574 10.563 9.5146C9.5376 9.8002 6 9.8002 6 9.8002C6 9.8002 2.4642 9.8002 1.437 9.5146C0.87 9.355 0.4248 8.8906 0.2742 8.3014C1.78814e-08 7.2322 0 5.0002 0 5.0002C0 5.0002 1.78814e-08 2.7682 0.2742 1.699C0.4266 1.108 0.8724 0.642995 1.437 0.485795C2.4642 0.200195 6 0.200195 6 0.200195C6 0.200195 9.5376 0.200195 10.563 0.485795C11.13 0.645395 11.5752 1.1098 11.7258 1.699ZM4.8 7.1002L8.4 5.0002L4.8 2.9002V7.1002Z" fill="currentColor"/></svg>',
                'placeholder' => 'youtube @username',
                'domain'      => 'https://youtube.com/',
            ],
            'linkedin'  => [
                'title'       => 'LinkedIn',
                'icon_svg'    => '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.80061 9.8035H8.20161V7.2973C8.20161 6.6997 8.18961 5.9305 7.36761 5.9305C6.53421 5.9305 6.40701 6.5809 6.40701 7.2535V9.8035H4.80741V4.6501H6.34341V5.3521H6.36441C6.57921 4.9477 7.10121 4.5199 7.88121 4.5199C9.50121 4.5199 9.80121 5.5867 9.80121 6.9745V9.8035H9.80061ZM3.00141 3.9451C2.87934 3.94526 2.75845 3.92132 2.64565 3.87466C2.53285 3.828 2.43037 3.75954 2.34408 3.6732C2.2578 3.58686 2.1894 3.48433 2.14282 3.37151C2.09623 3.25868 2.07237 3.13776 2.07261 3.0157C2.07273 2.832 2.12732 2.65246 2.22947 2.49979C2.33163 2.34711 2.47677 2.22816 2.64653 2.15797C2.81629 2.08778 3.00305 2.06951 3.1832 2.10546C3.36334 2.14142 3.52878 2.22998 3.65859 2.35996C3.78841 2.48994 3.87676 2.65549 3.91248 2.83569C3.9482 3.01588 3.92969 3.20262 3.85928 3.37229C3.78887 3.54196 3.66973 3.68694 3.51692 3.7889C3.36412 3.89086 3.18451 3.94522 3.00081 3.9451H3.00141ZM3.80301 9.8035H2.19921V4.6501H3.80361V9.8035H3.80301ZM10.6016 0.600098H1.39701C0.955409 0.600098 0.599609 0.948098 0.599609 1.3783V10.6219C0.599609 11.0521 0.956009 11.4001 1.39641 11.4001H10.5992C11.0396 11.4001 11.3996 11.0521 11.3996 10.6219V1.3783C11.3996 0.948098 11.0396 0.600098 10.5992 0.600098H10.601H10.6016Z" fill="currentColor"/></svg>',
                'placeholder' => 'linkedin username',
                'domain'      => 'https://linkedin.com/in/',
            ],
            'fb'        => [
                'title'       => 'Facebook',
                'icon_svg'    => '<svg width="7" height="12" viewBox="0 0 7 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.2 6.9H5.7L6.3 4.5H4.2V3.3C4.2 2.682 4.2 2.1 5.4 2.1H6.3V0.0840001C6.1044 0.0582001 5.3658 0 4.5858 0C2.9568 0 1.8 0.9942 1.8 2.82V4.5H0V6.9H1.8V12H4.2V6.9Z" fill="currentColor"/></svg>',
                'placeholder' => 'fb username',
                'domain'      => 'https://facebook.com/',
            ],
        ]);
    }

    public static function getReservedUserNames()
    {
        return apply_filters('fluent_community/reserved_usernames', [
            'admin', 'administrator', 'me', 'moderator', 'mod', 'superuser', 'root', 'system', 'official', 'staff', 'support', 'helpdesk', 'user', 'guest', 'anonymous', 'everyone', 'anybody', 'someone', 'webmaster', 'postmaster', 'hostmaster', 'abuse', 'security', 'ssl', 'firewall', 'no-reply', 'noreply', 'mail', 'email', 'mailer', 'smtp', 'pop', 'imap', 'ftp', 'sftp', 'ssh', 'ceo', 'cfo', 'cto', 'founder', 'cofounder', 'owner', 'president', 'vicepresident', 'director', 'manager', 'supervisor', 'executive', 'info', 'contact', 'sales', 'marketing', 'support', 'billing', 'accounting', 'finance', 'hr', 'humanresources', 'legal', 'compliance', 'it', 'itsupport', 'customerservice', 'customersupport', 'dev', 'developer', 'api', 'sdk', 'app', 'bot', 'chatbot', 'sysadmin', 'devops', 'infosec', 'security', 'test', 'testing', 'beta', 'alpha', 'staging', 'production', 'development', 'home', 'about', 'contact', 'faq', 'help', 'news', 'blog', 'forum', 'community', 'events', 'calendar', 'shop', 'store', 'cart', 'checkout', 'social', 'follow', 'like', 'share', 'tweet', 'post', 'status', 'privacy', 'terms', 'copyright', 'trademark', 'legal', 'policy', 'all', 'none', 'null', 'undefined', 'true', 'false', 'default', 'example', 'sample', 'demo', 'temporary', 'delete', 'remove', 'profanity', 'explicit', 'offensive', 'yourappname', 'yourbrandname', 'yourdomain',
        ]);
    }

    public static function isUsernameAvailable($userName, $targetUserId = null)
    {
        $userName = strtolower($userName);

        if (strlen($userName) < 3) {
            return false;
        }

        $reservedUserNames = self::getReservedUserNames();
        if (in_array($userName, $reservedUserNames)) {
            return false;
        }

        $user = get_user_by('login', $userName);

        if ($user) {
            if ($targetUserId && $user->ID != $targetUserId) {
                return false;
            }
        }

        $xProfile = XProfile::where('username', $userName)
            ->when($targetUserId, function ($query) use ($targetUserId) {
                return $query->where('user_id', '!=', $targetUserId);
            })
            ->exists();

        if ($xProfile) {
            return false;
        }

        return true;
    }

    public static function generateUserName($user, $useUserName = false)
    {
        if (!$user instanceof \WP_User) {
            $user = get_user_by('ID', $user);
        }

        return self::createUserNameFromStrings($user->user_login, array_filter([
            $user->user_nicename,
            $user->first_name,
            $user->last_name,
            $user->display_name
        ]), $user->ID);
    }

    public static function createUserNameFromStrings($maybeEmail, $fallbacks = [], $userId = null)
    {
        $emailParts = explode('@', $maybeEmail);
        $userName = $emailParts[0];

        $userName = CustomSanitizer::sanitizeUserName($userName);

        if (self::isUsernameAvailable($userName, $userId)) {
            return $userName;
        }

        foreach ($fallbacks as $fallback) {
            // only take alphanumeric characters and _ -
            $fallback = preg_replace('/[^a-z0-9_-]/', '', $fallback);
            $userName = CustomSanitizer::sanitizeUserName($fallback);
            if (self::isUsernameAvailable($userName, $userId)) {
                return $userName;
            }
        }

        $userName = strtolower($emailParts[0]);

        $finalUserName = $userName;
        // loop until we find a unique username
        $counter = 2;
        while (!self::isUsernameAvailable($userName, $userId)) {
            $userName = $finalUserName . $counter;
            $counter++;
            if ($counter % 100 === 0) {
                $finalUserName = $finalUserName . $userId . '-';
            }
        }

        return $userName;
    }

    public static function getUserAuthHash($userId = null)
    {
        if (!$userId) {
            return '';
        }

        if (Utility::getPrivacySetting('email_auto_login') === 'no') {
            return '';
        }

        static $cached = [];

        if (isset($cached[$userId])) {
            return $cached[$userId];
        }

        $exist = Meta::byType('user')
            ->byMetaKey('auth_hash')
            ->byObjectId($userId)
            ->first();

        $validTil = strtotime('+1 day');

        if ($exist) {
            $hashes = (array)$exist->value;

            $validHashes = array_filter($hashes, function ($hash) use ($validTil) {
                return $hash['valid_til'] > $validTil;
            });

            if ($validHashes) {
                $lastHash = end($validHashes);
                return $lastHash['hash'] . '__' . $exist->id;
            }
            $newHash = md5(wp_generate_uuid4() . '_' . $userId . '_' . '_' . time() . '__' . $userId);

            $hashes[] = [
                'hash'      => $newHash,
                'valid_til' => strtotime('+2 days')
            ];

            // remove expired hashes
            $hashes = array_filter($hashes, function ($hash) use ($validTil) {
                return $hash['valid_til'] > time();
            });

            $exist->value = array_values($hashes);
            $exist->save();

            $cached[$userId] = $newHash . '__' . $exist->id;

            return $cached[$userId];
        }

        $newHash = md5(wp_generate_uuid4() . '_' . $userId . '_' . '_' . time() . '__' . $userId);

        $meta = Meta::create([
            'object_type' => 'user',
            'object_id'   => $userId,
            'meta_key'    => 'auth_hash',
            'value'       => [
                [
                    'hash'      => $newHash,
                    'valid_til' => strtotime('+2 days')
                ]
            ]
        ]);

        $cached[$userId] = $newHash . '__' . $meta->id;

        return $cached[$userId];
    }

    public static function signUserUrlWithAuthHash($url, $userId = null)
    {
        $hash = self::getUserAuthHash($userId);
        if (!$hash) {
            return $url;
        }
        
        return add_query_arg([
            'fcom_action'   => 'signed_url',
            'fcom_url_hash' => $hash
        ], $url);
    }

    public static function getSignedNotificationPrefUrl($userId)
    {
        $notificationPref = Helper::baseUrl('fcom_route?route=user_notification_settings&auth=yes');
        return self::signUserUrlWithAuthHash($notificationPref, $userId);
    }

    /*
     * Get WP User by URL Hash
     * @param string $hash
     * @return \WP_User|null
     */
    public static function getUserByUrlHash($hash)
    {
        $hashParts = explode('__', $hash);
        if (count($hashParts) !== 2) {
            return null;
        }

        $metaId = $hashParts[1];
        $meta = Meta::where('object_type', 'user')
            ->where('meta_key', 'auth_hash')
            ->where('id', $metaId)
            ->first();

        if (!$meta) {
            return null;
        }

        $hashes = (array)$meta->value;
        $hash = $hashParts[0];

        $validHash = array_filter($hashes, function ($hashData) use ($hash) {
            return $hashData['hash'] == $hash;
        });

        if (!$validHash) {
            return null;
        }

        return get_user_by('ID', $meta->object_id);
    }

    public static function canViewUserSpaces($targetUserId, $currentUser = null)
    {
        $status = Utility::getPrivacySetting('user_space_visibility', 'everybody');

        if ($status == 'everybody') {
            return true;
        }

        if ($status == 'logged_in') {
            return !!$currentUser;
        }

        if (is_numeric($currentUser)) {
            $currentUser = User::find($currentUser);
        }

        if (!$currentUser) {
            return false;
        }

        if ($targetUserId == $currentUser->ID) {
            return true;
        }

        return Helper::isModerator($currentUser);
    }
}
