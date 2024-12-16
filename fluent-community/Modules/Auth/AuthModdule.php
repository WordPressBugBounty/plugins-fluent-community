<?php


namespace FluentCommunity\Modules\Auth;

use FluentAuth\App\Hooks\Handlers\CustomAuthHandler;
use FluentCommunity\App\App;
use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\AuthenticationService;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Vite;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Auth\Classes\Invitation;
use FluentCommunity\Modules\Auth\Classes\InvitationHandler;
use FluentCommunity\Modules\Auth\Classes\InvitationService;

class AuthModdule
{
    public function register($app)
    {
        add_action('fluent_community/portal_action_signed_url', [$this, 'maybeAutoLogin'], 10, 1);
        add_action('fluent_community/portal_action_auth', [$this, 'viewAuthPage']);
        add_action('wp_ajax_nopriv_fcom_user_registration', [$this, 'handleUserSignup']);
        add_action('wp_ajax_fcom_user_registration', [$this, 'handleUserSignup']);
        add_action('wp_ajax_nopriv_fcom_user_login_form', [$this, 'handleUserLogin']);
        add_action('wp_ajax_fcom_user_login_form', [$this, 'handleUserLogin']);

        add_filter('fluent_auth/login_redirect_url', function ($redirectUrl, $user) {
            if (empty($_REQUEST['is_fcom_auth']) || empty($_REQUEST['fcom_redirect'])) {
                return $redirectUrl;
            }

            // validate the url
            $redirectUrl = $_REQUEST['fcom_redirect'];
            if (!filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                $redirectUrl = Helper::baseUrl();
            }

            $redirectUrl = apply_filters('fluent_community/auth/after_login_redirect_url', $redirectUrl, $user);
            return $redirectUrl;
        }, 10, 2);
    }

    public function maybeAutoLogin($requestData)
    {
        $urlHash = Arr::get($requestData, 'fcom_url_hash');
        if ($urlHash) {
            $tagetUser = ProfileHelper::getUserByUrlHash($urlHash);
            if ($tagetUser) {
                $willAtoLogin = apply_filters('fluent_community/allow_auto_login_by_url', !user_can($tagetUser, 'delete_pages'), $tagetUser);
                // $willAtoLogin = true;
                if ($willAtoLogin) {
                    InvitationService::makeLogin($tagetUser);
                }
            }
        }

        // Remove fcom_action and fcom_url_hash from the current url
        $currentUrl = home_url(add_query_arg($_GET, $GLOBALS['wp']->request));
        $url = remove_query_arg(['fcom_action', 'fcom_url_hash'], $currentUrl);
        wp_redirect($url);
        exit();
    }

    public function viewAuthPage()
    {

        add_filter('login_form_defaults', function ($defaults) {
            $defaults['label_username'] = __('Email Address', 'fluent-community');
            return $defaults;
        });

        $currentUserId = get_current_user_id();
        // check if there has any invitation token
        $inivtationToken = Arr::get($_GET, 'invitation_token');

        $inviation = null;
        if ($inivtationToken) {
            $inviation = apply_filters('fluent_community/auth/invitation', null, $inivtationToken);
            if ($inviation && !$inviation->isValid()) {
                $inviation = null;
            }
        }

        if ($currentUserId && !$inviation) {
            $redirectUrl = null;
            if (!empty($_REQUEST['redirect_to'])) {
                $redirectUrl = sanitize_url($_REQUEST['redirect_to']);
            }
            if (!$redirectUrl) {
                $redirectUrl = Helper::baseUrl();
            }

            wp_safe_redirect($redirectUrl);
            exit();
        }

        if ($currentUserId && $inviation) {
            $space = BaseSpace::withoutGlobalScopes()->find($inviation->post_id);
            if ($space) {
                if (Helper::isUserInSpace($currentUserId, $inviation->post_id)) {
                    // let's redirect the user to the space
                    $redirectUrl = $space->getPermalink();
                    wp_redirect($redirectUrl);
                    exit();
                }

                if (!empty($_REQUEST['auto_accept'])) {
                    $redirectUrl = (new InvitationHandler())->handleInvitationLogin(Helper::baseUrl(), get_user_by('ID', $currentUserId), $inviation->message_rendered);
                    if (is_wp_error($redirectUrl) || !$redirectUrl) {
                        $redirectUrl = Helper::baseUrl();
                    }
                    wp_redirect($redirectUrl);
                    exit();
                }
            }
        }

        do_action('fluent_community/auth/before_auth_page_process', $currentUserId, $inviation);

        $acceptedForms = ['login', 'register', 'reset_password'];
        $targetForm = Arr::get($_GET, 'form');
        if (!in_array($targetForm, $acceptedForms)) {
            $targetForm = 'login';
        }

        if ($inviation && $targetForm != 'reset_password') {
            if ($inviation->message) {
                $isUserAvailable = get_user_by('email', $inviation->message);
                $targetForm = $isUserAvailable ? 'login' : 'register';
            }
        }

        if ($inviation && $currentUserId && $inviation->isValid()) {
            if ($inviation->message) {
                $invitedUser = get_user_by('email', $inviation->message);
                if ($invitedUser && $invitedUser->ID == $currentUserId) {
                    $targetForm = 'accept_invitation';
                }
            } else {
                $targetForm = 'accept_invitation';
            }
        }

        $isFluentAuth = AuthHelper::isFluentAuthAvailable();
        if (!$isFluentAuth && $targetForm == 'reset_password') {
            wp_redirect(wp_lostpassword_url(Helper::baseUrl()));
            exit();
        }

        $portalSettings = Helper::generalSettings();
        $titleVar = Arr::get($portalSettings, 'site_title');

        $frameData = [
            'logo'          => Arr::get($portalSettings, 'logo', ''),
            'title'         => sprintf(__('Join %s', 'fluent-community'), $titleVar),
            'description'   => __('Login or Signup to join the community', 'fluent-community'),
            'loginBtnText'  => __('Login', 'fluent-community'),
            'signupBtnText' => __('Signup', 'fluent-community'),
        ];

        $currentUrl = home_url(add_query_arg($_GET, $GLOBALS['wp']->request));

        $pageVars = [
            'title'          => $frameData['title'],
            'description'    => $frameData['description'],
            'url'            => $currentUrl,
            'featured_image' => '',
            'css_files'      => [
                Vite::getDynamicSrcUrl('theme-default.scss'),
                Vite::getStaticSrcUrl('user_registration.css')
            ],
            'js_files'       => [
                Vite::getStaticSrcUrl('user_registration.js')
            ],
            'js_vars'        => [
                'fluentComRegistration' => [
                    'ajax_url'     => admin_url('admin-ajax.php'),
                    'is_logged_in' => is_user_logged_in(),
                ]
            ],
            'scope'          => 'user_registration',
            'layout'         => 'signup',
            'portal'         => [
                'logo'        => Arr::get($portalSettings, 'logo', ''),
                'title'       => \sprintf(__('Welcome to %s', 'fluent-community'), Arr::get($portalSettings, 'site_title')),
                'description' => get_bloginfo('description')
            ]
        ];

        if(Utility::isDev()) {
            $pageVars['js_files'] = [
                Vite::getStaticSrcUrl('public/js/user_registration.js')
            ];
        }

        $formType = ($targetForm == 'register') ? 'signup' : 'login';

        if ($isFluentAuth) {
            if ($formType == 'login') {
                $pageVars['js_files'][] = FLUENT_AUTH_PLUGIN_URL . 'dist/public/login_helper.js';
                $pageVars['js_files'][] = FLUENT_AUTH_PLUGIN_URL . 'dist/public/fls_login.js';
                $pageVars['js_vars']['fluentAuthPublic'] = [
                    'hide'              => false,
                    'redirect_fallback' => site_url(),
                    'fls_login_nonce'   => wp_create_nonce('fsecurity_login_nonce'),
                    'ajax_url'          => admin_url('admin-ajax.php'),
                    'i18n'              => [
                        'Username_or_Email' => __('Email Address', 'fluent-community'),
                        'Password'          => __('Password', 'fluent-community')
                    ]
                ];
                $pageVars['js_vars']['fls_magic_login_vars'] = [
                    'ajaxurl'      => admin_url('admin-ajax.php'),
                    'success_icon' => FLUENT_AUTH_PLUGIN_URL . 'dist/images/success.png',
                    'empty_text'   => __('Please provide your email address to get magic login link', 'fluent-community'),
                    'wait_text'    => __('Please Wait...', 'fluent-community'),
                ];
            }
        }

        $formSettings = AuthenticationService::getFormattedAuthSettings($formType);

        if ($formSettings) {
            $pageVars['portal'] = Arr::get($formSettings, 'banner');
            $pageVars['portal']['form'] = Arr::get($formSettings, 'form');
        }

        add_action('fluent_community/headless/content', function ($context) use ($targetForm, $currentUrl, $frameData, $inviation, $formSettings) {
            if ($targetForm == 'login') {
                $this->showLoginForm($frameData, $inviation);
            } else if ($targetForm == 'reset_password') {
                $frameData['title'] = __('Reset your password', 'fluent-community');
                ?>
                <div id="fcom_user_onboard_wrap" class="fcom_user_onboard">
                    <div class="fcom_onboard_header">
                        <div class="fcom_onboard_header_title">
                            <h2><?php echo esc_html($frameData['title']); ?></h2>
                        </div>
                        <div class="fcom_onboard_sub">
                            <p><?php _e('Please enter your email address. You will receive an email message with instructions on how to reset your password.', 'fluent-community'); ?></p>
                        </div>
                    </div>
                    <div class="fcom_onboard_body">
                        <div class="fcom_onboard_form">
                            <?php echo do_shortcode('[fluent_auth_reset_password redirect_to="' . esc_url($currentUrl) . '"]'); ?>
                            <div class="fcom_spaced_divider">
                                <div class="fcom_alt_auth_text">
                                    <a href="<?php echo esc_url(add_query_arg('form', 'login', $currentUrl)); ?>">
                                        <?php _e('Back to Login', 'fluent-community'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            } else if ($targetForm == 'accept_invitation') {
                do_action('fluent_community/auth/show_invitation_for_user', $inviation, $frameData);
            } else {
                //check if the registration is disabled
                if (!AuthHelper::isRegistrationEnabled()) {
                    echo '<div class="fcom_completed"><div class="fcom_complted_header"><h4>' . __('Registration is disabled for this community', 'fluent-community') . '</h4>';
                    return;
                }

                $frameData['hiddenFields'] = [
                    'register'          => 'yes',
                    'action'            => 'fcom_user_signup',
                    '_fls_signup_nonce' => wp_create_nonce('fluent_auth_signup_nonce')
                ];

                $frameData['loginUrl'] = add_query_arg('form', 'login', $currentUrl);
                $frameData = wp_parse_args(Arr::get($formSettings, 'form'), $frameData);

                $this->renderRegistrationForm($frameData, $inviation);
            }
        }, 10, 1);

        add_action('fluent_community/headless/head_early', function ($scope) use ($formSettings) {
            $bannerColors = array_filter(Arr::only($formSettings['banner'], ['title_color', 'text_color', 'background_color']));
            $css = Utility::getColorCssVariables(); ?>
            <style>
                .fcom_layout_side {
                <?php foreach ($bannerColors as $colorKey => $colorValue): ?> --fcom_ <?php echo $colorKey; ?>: <?php echo $colorValue; ?>;
                <?php endforeach; ?>
                }

                <?php echo $css; ?>
            </style>
            <?php
        });

        status_header(200);
        App::make('view')->render('headless_page', $pageVars);
        exit(200);
    }

    public function handleUserSignup()
    {
        if (is_user_logged_in()) {
            return $this->handleSignupCompleted(get_current_user_id());
        }

        if (!AuthHelper::isRegistrationEnabled()) {
            wp_send_json([
                'message' => __('Registration is disabled for this community', 'fluent-community')
            ], 422);
        }

        $app = App::make('app');
        $request = $app->make('request');
        $fields = AuthHelper::getFormFields();

        $requiredFields = array_filter($fields, function ($field) {
            return $field['required'] ?? false;
        });

        $keys = array_keys($fields);
        $data = Arr::only($request->all(), $keys);

        // remove space and special characters from username
        $data['username'] = sanitize_user(strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $data['username'])));

        if (empty($data['username'])) {
            wp_send_json([
                'message' => __('Username is not valid', 'fluent-community'),
                'errors'  => [
                    'username' => __('Please provide a valid username', 'fluent-community')
                ]
            ], 422);
        }

        if (!ProfileHelper::isUsernameAvailable($data['username'])) {
            wp_send_json([
                'message' => __('Username is already taken', 'fluent-community'),
                'errors'  => [
                    'username' => __('Username is already taken. Please use a different username', 'fluent-community')
                ]
            ], 422);
        }

        $invitationToken = $request->get('invitation_token');
        $invitation = null;
        if ($invitationToken) {
            $invitation = Invitation::where('message_rendered', $invitationToken)->first();
            if (!$invitation) {
                wp_send_json([
                    'message' => __('Invalid invitation token', 'fluent-community')
                ], 422);
            }

            if ($invitation->message && $invitation->message != $data['email']) {
                wp_send_json([
                    'message' => __('Email does not match with the invitation', 'fluent-community')
                ], 422);
            }

            if ($invitation->message) {
                add_filter('fluent_community/auth/two_factor_enabled', '__return_false');
                add_filter('fluent_auth/verify_signup_email', '__return_false');
            }
        }

        $data['email'] = sanitize_email($data['email']);

        $validations = [
            'full_name'     => 'required|max:100|string',
            'username'      => 'required|unique:users,user_login|unique:fcom_xprofile,username|min:4|max:30',
            'email'         => 'required|email|unique:users,user_email',
            'password'      => 'required|same:conf_password|max:50|string',
            'conf_password' => 'required|same:password'
        ];

        if (!AuthHelper::isPasswordConfRequired()) {
            unset($validations['conf_password']);
            $validations['password'] = 'required|max:50|string';
        }

        foreach ($requiredFields as $key => $field) {
            if (!isset($data[$key])) {
                $validations[$key] = 'required';
            }
        }

        $validator = $app->make('validator')->make($data, $validations, [
            'username.required'      => __('Username is required', 'fluent-community'),
            'username.unique'        => __('Username is already taken', 'fluent-community'),
            'email.required'         => __('Email is required', 'fluent-community'),
            'email.email'            => __('Email is not valid', 'fluent-community'),
            'email.unique'           => __('Email is already taken', 'fluent-community'),
            'password.required'      => __('Password is required', 'fluent-community'),
            'password.same'          => __('Password and confirmation password do not match', 'fluent-community'),
            'conf_password.required' => __('Password confirmation is required', 'fluent-community'),
            'conf_password.same'     => __('Password and confirmation password do not match', 'fluent-community'),
            'terms.required'         => __('You must agree to the terms and conditions', 'fluent-community'),
            'full_name.required'     => __('Full name is required', 'fluent-community'),
        ]);

        if ($validator->fails()) {
            wp_send_json([
                'message' => __('Please fill in all required fields correctly.', 'fluent-community'),
                'errors'  => $validator->errors()
            ], 422);
        }

        foreach ($data as $key => $value) {
            // let's sanitize the data
            $callBack = $fields[$key]['sanitize_callback'] ?? null;
            if ($callBack) {
                $data[$key] = call_user_func($callBack, $value);
            }
        }

        // let's extract the full_name and set the first_name and last_name
        if (!empty($data['full_name'])) {
            $nameParts = explode(' ', $data['full_name']);
            $data['first_name'] = $nameParts[0];
            $data['last_name'] = implode(' ', array_slice($nameParts, 1));
            unset($data['full_name']);
            $data = array_filter($data);
        }

        if (AuthHelper::isFluentAuthAvailable()) {
            $data = wp_parse_args($data, $request->all());
            $this->handleSignupViaFlentAuth($data);
            wp_send_json([
                'message' => __('Something is not working! Please try again', 'fluent-community')
            ], 422);
        }

        $rateLimit = AuthHelper::isAuthRateLimit();

        if (is_wp_error($rateLimit)) {
            wp_send_json([
                'message' => $rateLimit->get_error_message()
            ], 422);
        }

        // We need two-factor authentication here
        if (AuthHelper::isTwoFactorEnabled()) {
            // Check if Two Factor code is given
            $verificationToken = $request->get('__two_fa_signed_token');
            if ($verificationToken) {
                $code = $request->get('_email_verification_code');
                if (!$code) {
                    wp_send_json([
                        'message' => __('Verification code is required', 'fluent-community')
                    ], 422);
                }

                $validated = AuthHelper::validateVerificationCode($code, $verificationToken, $data);
                if (is_wp_error($validated)) {
                    wp_send_json([
                        'message' => $validated->get_error_message()
                    ], 422);
                }
            } else {
                // Let's send the verification code
                $htmlForm = AuthHelper::get2FaRegistrationCodeForm($data);
                wp_send_json([
                    'verifcation_html' => $htmlForm
                ]);
            }
        }

        // let's create the user now
        $userId = AuthHelper::registerNewUser($data['username'], $data['email'], $data['password'], [
            'first_name' => Arr::get($data, 'first_name'),
            'last_name'  => Arr::get($data, 'last_name'),
            'role'       => get_option('default_role', 'subscriber')
        ]);

        if (is_wp_error($userId)) {
            wp_send_json([
                'message' => $userId->get_error_message()
            ], 422);
        }

        $this->handleSignupCompleted($userId);
    }

    private function handleSignupViaFlentAuth($data)
    {
        add_filter('fluent_auth/signup_form_data', function ($requestData) use ($data) {
            return $data;
        });

        add_action('fluent_auth/after_creating_user', function ($userId) {
            $this->handleSignupCompleted($userId);
        }, 1, 1);

        add_filter('fluent_auth/signup_enabled', '__return_true');

        (new CustomAuthHandler())->handleSignupAjax();
    }

    private function handleSignupCompleted($userId)
    {
        // We have the user now let's set the community membership
        $user = User::find($userId);
        $user->syncXProfile(true, true);

        $redirectUrl = Helper::baseUrl();

        $redirectUrl = apply_filters('fluent_community/auth/after_signup_redirect_url', $redirectUrl, $user, $_REQUEST);
        $btnText = __('Continue to the community', 'fluent-community');

        $html = '<div class="fcom_completed"><div class="fcom_complted_header"><h2>' . __('Congratulations!', 'fluent-community') . '</h2>';
        $html .= '<p>' . __('You have successfully registered to the community', 'fluent-community') . '</p></div>';
        $html .= '<a href="' . $redirectUrl . '" class="fcom_btn fcom_btn_success">' . $btnText . '</a>';
        $html .= '</div>';

        if (!get_current_user_id()) {
            $wpUser = get_user_by('ID', $userId);
            AuthHelper::makeLogin($wpUser);
        }

        wp_send_json([
            'success_html' => $html,
            'redirect_url' => $redirectUrl
        ]);
    }

    public function handleUserLogin()
    {
        if (is_user_logged_in()) {
            $user = get_user_by('ID', get_current_user_id());
            return $this->handleUserLoginSuccess($user);
        }

        if (AuthHelper::isFluentAuthAvailable()) {
            wp_send_json([
                'message' => __('This form cannot be used to log in. Please reload the page and try again.', 'fluent-community')
            ], 422);
        }

        $app = App::make('app');
        $request = $app->make('request');

        $data = $request->all();

        $validator = $app->make('validator')->make($data, [
            'log' => 'required',
            'pwd' => 'required'
        ], [
            'log.required' => __('Email is required', 'fluent-community'),
            'pwd.required' => __('Password is required', 'fluent-community')
        ]);

        if ($validator->fails()) {
            wp_send_json([
                'message' => __('Please fill all the required fields correctly', 'fluent-community'),
                'errors'  => $validator->errors()
            ], 422);
        }

        $rateLimit = AuthHelper::isAuthRateLimit();
        if (is_wp_error($rateLimit)) {
            wp_send_json([
                'message' => $rateLimit->get_error_message()
            ], 422);
        }

        $user = wp_authenticate($data['log'], $data['pwd']);

        if (is_wp_error($user)) {
            wp_send_json([
                'message' => $user->get_error_message()
            ], 422);
        }

        InvitationService::makeLogin($user);

        $redirectUrl = null;
        if (!empty($_REQUEST['redirect_to'])) {
            $redirectUrl = sanitize_url($_REQUEST['redirect_to']);
        }

        if (!$redirectUrl) {
            $redirectUrl = Helper::baseUrl();
        }

        if ($invitationToken = $request->get('invitation_token')) {
            $maybeRedirectUrl = apply_filters('fluent_community/auth/after_login_with_invitation', null, $user, $invitationToken);
            if ($maybeRedirectUrl && !is_wp_error($maybeRedirectUrl)) {
                $redirectUrl = $maybeRedirectUrl;
            }
        }

        $this->handleUserLoginSuccess($user, $redirectUrl);
    }

    private function handleUserLoginSuccess($user, $redirectUrl = null)
    {
        if (!$redirectUrl) {
            $redirectUrl = Helper::baseUrl();
        }

        $redirectUrl = apply_filters('fluent_community/auth/after_login_redirect_url', $redirectUrl, $user);
        $btnText = __('Continue to the community', 'fluent-community');

        $html = '<div class="fcom_completed"><div class="fcom_complted_header"><h2>' . __('Welcome back!', 'fluent-community') . '</h2>';
        $html .= '<p>' . __('You have successfully logged in to the community', 'fluent-community') . '</p></div>';
        $html .= '<a href="' . $redirectUrl . '" class="fcom_btn fcom_btn_success">' . $btnText . '</a>';
        $html .= '</div>';

        wp_send_json([
            'success_html' => $html,
            'redirect_url' => $redirectUrl
        ]);
    }

    public function showLoginForm($frameData, $invitation = null)
    {
        $portalSettings = Helper::generalSettings();
        $isFluentAuth = AuthHelper::isFluentAuthAvailable();
        $loginSettings = AuthenticationService::getFormattedAuthSettings('login');
        $formSettings = Arr::get($loginSettings, 'form');
        $currentUrl = home_url(add_query_arg($_GET, $GLOBALS['wp']->request));
        $title = sprintf(__('Login to %s', 'fluent-community'), Arr::get($portalSettings, 'site_title'));

        $description = '';
        if ($invitation) {
            $invitationBy = $invitation->xprofile ? $invitation->xprofile->display_name : __('Someone', 'fluent-community');
            if ($invitation->post_id) {
                $space = BaseSpace::find($invitation->post_id);
                if ($space) {
                    $title = $space->title . ' - ' . Arr::get($portalSettings, 'site_title');
                }
            }
            $inviteDescription = sprintf(__('%s has invited you to join this community. Please login to accept your invitation.', 'fluent-community'), $invitationBy);
            add_action('fluent_community/before_auth_form_header', function ($formType) use ($inviteDescription) {
                ?>
                <div class="fcom_highlight_message">
                    <?php echo wp_kses_post($inviteDescription); ?>
                </div>
                <?php
            });
        }

        if ($isFluentAuth) {
            add_filter('login_form_top', function () {
                $reditectUrl = Arr::get($_GET, 'redirect_to');
                if (!$reditectUrl) {
                    $reditectUrl = apply_filters('fluent_community/default_redirect_url', $reditectUrl);
                }
                ob_start();
                ?>
                <input name="is_fcom_auth" type="hidden" value="yes"/>
                <input type="hidden" name="fcom_redirect" value="<?php echo esc_url($reditectUrl); ?>"/>
                <?php
                return ob_get_clean();
            });
            ?>
            <div id="fcom_user_onboard_wrap" class="fcom_user_onboard">
                <div class="fcom_onboard_header">
                    <?php do_action('fluent_community/before_auth_form_header', 'login'); ?>
                    <div class="fcom_onboard_header_title">
                        <?php if (!empty($formSettings['title'])): ?>
                            <h2>
                                <?php echo esc_html($formSettings['title']); ?>
                            </h2>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($formSettings['description'])): ?>
                        <div class="fcom_onboard_sub">
                            <?php echo wp_kses_post(trim($formSettings['description'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="fcom_onboard_body">
                    <div class="fcom_onboard_form">
                        <?php echo do_shortcode('[fluent_auth_login redirect_to="' . esc_url($currentUrl) . '"]'); ?>
                        <div class="fcom_spaced_divider">
                            <?php if (AuthHelper::isRegistrationEnabled()): ?>
                                <div class="fcom_alt_auth_text">
                                    <?php _e('Don\'t have an account?', 'fluent-community'); ?>
                                    <a href="<?php echo esc_url(add_query_arg('form', 'register', $currentUrl)); ?>">
                                        <?php _e('Signup', 'fluent-community'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <p class="fcom_reset_pass_text">
                                <a href="<?php echo AuthHelper::getLostPasswordUrl($currentUrl); ?>">
                                    <?php _e('Lost your password?', 'fluent-community'); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $frameData['redirect'] = $currentUrl;

        $frameData['hiddenFields'] = [];
        if ($invitation) {
            $frameData['loginBtnText'] = __('Log In & Accept Invitation', 'fluent-community');
            $frameData['hiddenFields']['invitation_token'] = $invitation->message_rendered;
        }

        $frameData['title'] = $title;
        $frameData['description'] = $description;

        $frameData['defaults'] = [
            'email' => $invitation ? $invitation->message : ''
        ];

        if (AuthHelper::isRegistrationEnabled()) {
            $frameData['signupUrl'] = add_query_arg('form', 'register', $currentUrl);
        }

        $frameData['settings'] = $formSettings;

        if (isset($_GET['redirect_to'])) {
            $frameData['redirect_to'] = sanitize_url($_GET['redirect_to']);
        }

        App::make('view')->render('auth.login_form', $frameData);
    }

    public function renderRegistrationForm($frameData, $invitation = null)
    {
        $formFields = AuthHelper::getFormFields($invitation);
        $frameData['formFields'] = $formFields;

        if ($invitation) {
            $frameData['hiddenFields'] = [
                'invitation_token'  => $invitation->message_rendered,
                'action'            => 'fcom_user_registration',
                '_fls_signup_nonce' => wp_create_nonce('fluent_auth_signup_nonce')
            ];

            $invitationBy = $invitation->xprofile ? $invitation->xprofile->display_name : __('Someone', 'fluent-community');
            $inviteDescription = sprintf(__('%s has invited you to join this community. Please create an account to accept your invitation.', 'fluent-community'), $invitationBy);

            add_action('fluent_community/before_auth_form_header', function ($formType) use ($inviteDescription) {
                ?>
                <div class="fcom_highlight_message">
                    <?php echo wp_kses_post($inviteDescription); ?>
                </div>
                <?php
            });

            $frameData['button_label'] = __('Register & Accept invitation', 'fluent-community');
        } else {
            $frameData['hiddenFields'] = [
                'register'          => 'yes',
                'action'            => 'fcom_user_registration',
                '_fls_signup_nonce' => wp_create_nonce('fluent_auth_signup_nonce'),
            ];
            $frameData['button_label'] = Arr::get($frameData, 'signupBtnText', __('Sign up', 'fluent-community'));
        }

        add_action('fluent_community/before_registration_form', function ($frameData) {
            if (AuthHelper::isFluentAuthAvailable()) {
                $currentUrl = home_url(add_query_arg($_GET, $GLOBALS['wp']->request));
                ob_start();
                $titlePrefix = __('Signup with', 'fluent-community');
                do_shortcode('[fs_auth_buttons redirect="' . $currentUrl . '" title_prefix="' . $titlePrefix . ' " title=""]');
                $html = ob_get_clean();
                if ($html) {
                    echo '<div class="fcom_social_auth_wrap">';
                    echo $html;
                    echo '</div>';
                }
            }
        });

        App::make('view')->render('auth.user_invitation', $frameData);
    }
}
