<?php

namespace FluentCommunity\Modules\Auth\Classes;

use FluentCommunity\App\App;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\Helper;

class InvitationHandler
{
    public function register()
    {
        add_filter('fluent_community/auth/invitation', function ($invitation, $token) {
            return Invitation::where('message_rendered', $token)
                ->where('status', 'pending')
                ->first();
        }, 10, 2);

        add_action('fluent_community/auth/show_invitation_for_user', [$this, 'showCommunityOnBoard'], 10, 2);
        add_action('wp_ajax_fcom_user_accept_invitation', [$this, 'acceptInvitationAjax']);
        add_filter('fluent_community/auth/after_login_with_invitation', [$this, 'handleInvitationLogin'], 10, 3);
        add_filter('fluent_community/auth/after_signup_redirect_url', function ($redirecctUrl, $user, $postedData) {

            if (empty($postedData['invitation_token'])) {
                return $redirecctUrl;
            }

            $invitation = Invitation::where('message_rendered', $postedData['invitation_token'])
                ->where('status', 'pending')
                ->first();

            if (!$invitation || !$user || $invitation->message != $user->user_email) {
                return $redirecctUrl;
            }

            if ($invitation->post_id) {
                $space = BaseSpace::find($invitation->post_id);
                if ($space) {
                    $role = 'member';
                    if ($space->type == 'course') {
                        $role = 'student';
                    }
                    Helper::addToSpace($space, $user->ID, $role);
                }

                $redirecctUrl = $space->getPermalink();
            }

            $invitation->status = 'accepted';
            $invitation->save();

            return $redirecctUrl;
        }, 10, 3);
    }

    public function addShortcode($atts)
    {
        return 'Hello world!';
    }

    public function showCommunityOnBoard($invitation, $frameData)
    {
        $user = Helper::getCurrentUser();
        $user->syncXProfile(false);
        $frameData['title'] = '';
        $frameData['description'] = \sprintf(__('Welcome back %1$s. %2$s has been invited you to join the community. Please click the button bellow to continue.', 'fluent-community'), $user->display_name, $invitation->xprofile->display_name);
        $frameData['invitation_token'] = $invitation->message_rendered;

        App::make('view')->render('auth.logged_in_accept', $frameData);
    }

    public function acceptInvitationAjax()
    {
        $token = sanitize_text_field($_POST['invitation_token']);
        $user = Helper::getCurrentUser();

        $redirectUrl = $this->handleInvitationLogin(null, $user, $token);

        if (is_wp_error($redirectUrl)) {
            wp_send_json([
                'message' => $redirectUrl->get_error_message()
            ], 422);
        }

        wp_send_json([
            'redirect_url' => $redirectUrl,
            'message'      => __('Invitation accepted successfully. Please wait...', 'fluent-community')
        ]);
    }

    public function handleInvitationLogin($url, $user, $token)
    {
        $userModel = User::find($user->ID);

        $invitation = Invitation::where('message_rendered', $token)
            ->where('status', 'pending')
            ->first();


        if (!$userModel || !$invitation || $invitation->message != $userModel->user_email) {
            return new \WP_Error('invalid_invitation', __('Invalid invitation token. Please try again', 'fluent-community'));
        }

        $userModel->syncXProfile(true);

        $space = null;
        if ($invitation->post_id) {
            $space = BaseSpace::find($invitation->post_id);
            if ($space) {
                $role = 'member';
                if ($space->type == 'course') {
                    $role = 'student';
                }
                Helper::addToSpace($space, $userModel->ID, $role);
            }
        }

        $invitation->status = 'accepted';
        $invitation->save();

        $redirectUrl = Helper::baseUrl();
        if ($space) {
            $redirectUrl = $space->getPermalink();
        }

        return $redirectUrl;
    }

}
