<?php

namespace FluentCommunity\Modules\Mcp\Http\Controllers;

use FluentCommunity\App\Http\Controllers\Controller;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Mcp\AbilitiesRegistrar;
use FluentCommunity\Modules\Mcp\McpModule;
use FluentCommunity\Modules\Mcp\Support\McpGate;

defined('ABSPATH') || exit;

/**
 * The "MCP for AI Agents" settings screen. Site admins only (AdminPolicy).
 */
class McpSettingsController extends Controller
{
    const ADAPTER_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    public function get(Request $request)
    {
        return $this->screenData();
    }

    public function save(Request $request)
    {
        $settings = (array) $request->get('settings', []);

        $this->validate($settings, [
            'active' => 'required|in:yes,no',
        ]);

        if (McpGate::setEnabled($settings['active'] === 'yes') === false && $settings['active'] === 'yes') {
            return $this->sendError([
                'message' => __('You are not allowed to change this setting.', 'fluent-community'),
            ], 403);
        }

        return array_merge($this->screenData(), [
            'message' => $settings['active'] === 'yes'
                ? __('MCP is enabled. AI agents can now connect.', 'fluent-community')
                : __('MCP is disabled. AI agents can no longer connect.', 'fluent-community'),
        ]);
    }

    /**
     * One-click FluentHub install. Works only when a plugin on the site offers
     * it through fluent_toolkit/can_auto_install; otherwise the screen links to
     * the download.
     */
    public function installAdapter(Request $request) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- route signature
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have permission to install plugins.', 'fluent-community'),
            ], 403);
        }

        if (!apply_filters('fluent_toolkit/can_auto_install', false)) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
            return $this->sendError([
                'message' => __('Please install FluentHub, then reload this page.', 'fluent-community'),
            ], 422);
        }

        do_action('fluent_toolkit/do_auto_install'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook

        wp_clean_plugins_cache();

        $data = $this->screenData();
        $adapter = $data['adapter'];

        if ($adapter['active']) {
            $message = __('FluentHub is installed and active. Reload the page to finish.', 'fluent-community');
        } elseif ($adapter['installed']) {
            $message = __('FluentHub is installed but could not be activated. Activate it from the Plugins page, then reload this page.', 'fluent-community');
        } else {
            $message = __('FluentHub could not be installed automatically. Install it manually, then reload this page.', 'fluent-community');
        }

        return array_merge($data, [ 'message' => $message ]);
    }

    /**
     * Turns on the FluentHub MCP server (target "hub") or the MCP of another
     * Fluent plugin on the site (target = its slug), through FluentHub's own
     * call. That call checks that the user may manage the site and runs the
     * plugin's own switch. Nothing can be turned off from this screen.
     */
    public function enableHub(Request $request)
    {
        $target = sanitize_key((string) $request->get('target', 'hub'));
        if ($target === '') {
            $target = 'hub';
        }

        $result = apply_filters('fluent_kit/mcp_hub_enable', null, McpModule::SERVER_ID, $target); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
        $status = is_array($result) ? Arr::get($result, 'status') : null;
        $reason = is_array($result) ? (string) Arr::get($result, 'reason') : '';

        if ($status === 'done') {
            return array_merge($this->screenData(), [
                'message' => $target === 'hub'
                    ? __('The FluentHub MCP server is on.', 'fluent-community')
                    : __('On. Your AI app sees it the next time it loads its tools.', 'fluent-community'),
            ]);
        }

        if ($status === 'forbidden') {
            $refusal = $reason === 'product_refused' ? sanitize_text_field((string) Arr::get($result, 'message', '')) : '';

            return $this->sendError([
                'message' => $refusal ? $refusal : __('Only a site administrator can turn this on.', 'fluent-community'),
            ], 403);
        }

        $messages = [
            'adapter_missing'  => __('FluentHub needs a newer MCP adapter before this can be turned on.', 'fluent-community'),
            'adapter_outdated' => __('FluentHub needs a newer MCP adapter before this can be turned on.', 'fluent-community'),
            'unknown_product'  => __('FluentHub does not know this plugin.', 'fluent-community'),
            'not_active'       => __('This plugin is not active on the site.', 'fluent-community'),
            'no_handler'       => __('This plugin cannot be turned on from here. Use its own MCP settings.', 'fluent-community'),
        ];

        return $this->sendError([
            'message' => isset($messages[$reason]) ? $messages[$reason] : __('FluentHub is not active on this site.', 'fluent-community'),
        ], 422);
    }

    private function screenData()
    {
        $definitions = AbilitiesRegistrar::getDefinitions();
        $listed = AbilitiesRegistrar::exposedNames(null, false);
        $proCount = 0;

        foreach ($definitions as $definition) {
            if (!empty($definition['pro'])) {
                ++$proCount;
            }
        }

        $user = wp_get_current_user();

        return [
            'settings'           => McpGate::settings(),
            'endpoint_url'       => McpModule::endpointUrl(),
            'server_name'        => McpModule::clientServerName(),
            'adapter'            => $this->adapterStatus(),
            'hub'                => $this->hubState(),
            'tools'              => [
                'total' => count($listed),
                'pro'   => $proCount,
            ],
            'pro_active'         => defined('FLUENT_COMMUNITY_PRO'),
            'app_passwords_url'  => admin_url('profile.php#application-passwords-section'),
            'plugins_url'        => admin_url('plugins.php'),
            'current_user_login' => $user ? $user->user_login : '',
            'is_local_dev'       => $this->isLocalDev(),
        ];
    }

    /**
     * FluentHub's one-URL server and its sign-in, as FluentHub reports them. It
     * also supplies the per-app instructions, so both screens use one wording.
     *
     * @return array|null Null when FluentHub is not active or is too old to report.
     */
    private function hubState()
    {
        $state = apply_filters('fluent_kit/mcp_hub_state', null, McpModule::SERVER_ID); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook

        // Version 1 of the contract in fluent-toolkit docs/mcp-product-connect.md. An unknown version is not guessed at.
        if (!is_array($state) || (int) Arr::get($state, 'version') !== 1 || empty($state['hub']) || empty($state['sign_in']) || empty($state['product'])) {
            return null;
        }

        $clients = [];
        foreach ((array) Arr::get($state, 'clients', []) as $client) {
            if (!is_array($client) || empty($client['key']) || empty($client['snippet'])) {
                continue;
            }

            $clients[] = [
                'key'     => sanitize_key($client['key']),
                'label'   => sanitize_text_field((string) Arr::get($client, 'label', $client['key'])),
                'note'    => sanitize_text_field((string) Arr::get($client, 'note', '')),
                'snippet' => (string) $client['snippet'],
            ];
        }

        $products = [];
        foreach ((array) Arr::get($state, 'products', []) as $product) {
            if (!is_array($product) || empty($product['slug'])) {
                continue;
            }

            $products[] = [
                'slug'        => sanitize_key($product['slug']),
                'name'        => sanitize_text_field((string) Arr::get($product, 'name', $product['slug'])),
                'served'      => !empty($product['served']),
                'can_enable'  => !empty($product['can_enable']),
                'tools_count' => (int) Arr::get($product, 'tools_count', 0),
            ];
        }

        return [
            'products'         => $products,
            'ready'            => !empty($state['ready']) && $clients,
            'endpoint_url'     => esc_url_raw((string) Arr::get($state, 'hub.endpoint_url', '')),
            'hub_reason'       => sanitize_key((string) Arr::get($state, 'hub.reason', '')),
            'sign_in_reason'   => sanitize_key((string) Arr::get($state, 'sign_in.reason', '')),
            'included'         => !empty($state['product']['included']),
            'user_can_connect' => !empty($state['user_can_connect']),
            'can_manage'       => !empty($state['can_manage']),
            'manage_url'       => esc_url_raw((string) Arr::get($state, 'manage_url', '')),
            'profile_url'      => esc_url_raw((string) Arr::get($state, 'profile_url', '')),
            'clients'          => $clients,
        ];
    }

    /**
     * @return array installed, active, provider (toolkit|plugin|''), version, abilities_api, can_auto_install, download_url
     */
    private function adapterStatus()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $toolkitLoaded = defined('FLUENT_TOOLKIT_VERSION');
        $toolkitInstalled = $toolkitLoaded || isset($plugins[self::TOOLKIT_PLUGIN_FILE]);
        $standaloneInstalled = isset($plugins[self::ADAPTER_PLUGIN_FILE]);
        $active = McpModule::hasAdapter();

        $provider = '';
        $version = null;

        if ($active && is_plugin_active(self::ADAPTER_PLUGIN_FILE)) {
            $provider = 'plugin';
            $version = isset($plugins[self::ADAPTER_PLUGIN_FILE]['Version']) ? $plugins[self::ADAPTER_PLUGIN_FILE]['Version'] : null;
        } elseif ($active && $toolkitLoaded) {
            $provider = 'toolkit';
            $version = (string) constant('FLUENT_TOOLKIT_VERSION');
        }

        return [
            'installed'        => $toolkitInstalled || $standaloneInstalled,
            'active'           => $active,
            'provider'         => $provider,
            'version'          => $version,
            'abilities_api'    => function_exists('wp_register_ability'),
            'can_auto_install' => (bool) apply_filters('fluent_toolkit/can_auto_install', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
            'download_url'     => 'https://github.com/WPManageNinja/fluent-toolkit',
        ];
    }

    /**
     * A local site usually has a self-signed certificate, which the Claude
     * Desktop proxy refuses unless the snippet says otherwise.
     */
    private function isLocalDev()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        foreach ([ '.test', '.lab', '.local', '.localhost', '.docker' ] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return (bool) filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
