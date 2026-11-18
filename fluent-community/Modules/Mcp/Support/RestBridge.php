<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\App\App;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Runs a tool through the same fluent-community/v2 route the portal calls, so the
 * policies, rate limits, duplicate checks and hooks of that route apply unchanged.
 */
class RestBridge
{
    const NAMESPACE_PATH = '/fluent-community/v2';

    /**
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param string $route  Path after the namespace, e.g. '/feeds/12/by-id'.
     * @param array  $params
     * @return array|\WP_Error Response data as plain arrays, or an MCP error.
     */
    public static function call($method, $route, $params = [])
    {
        // Controller::validate() only throws on a REST request. Elsewhere the
        // controller would carry on with invalid input.
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return McpHelper::error('internal_error', 'This tool only works over the REST transport.');
        }

        self::resetFrameworkRequest();

        $request = new \WP_REST_Request($method, self::NAMESPACE_PATH . $route);

        if ($method === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($params));
        }

        // Model::getPerPage() reads the page size from $_REQUEST, not from the route's request.
        $hadPerPage = isset($_REQUEST['per_page']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $previousPerPage = $hadPerPage ? $_REQUEST['per_page'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
        if (isset($params['per_page'])) {
            $_REQUEST['per_page'] = (int) $params['per_page'];
        }

        try {
            $response = rest_do_request($request);
        } finally {
            if ($hadPerPage) {
                $_REQUEST['per_page'] = $previousPerPage;
            } else {
                unset($_REQUEST['per_page']);
            }
        }

        $data = json_decode(wp_json_encode($response->get_data()), true);

        if ($response->is_error()) {
            return self::toError($response->get_status(), is_array($data) ? $data : []);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * The framework Request is a singleton and each dispatch merges its input
     * into it, so a second call in one process would see the first call's
     * parameters. A fresh instance per call keeps them apart.
     */
    private static function resetFrameworkRequest()
    {
        $app = App::getInstance();

        // Without a JSON body of its own, the framework Request reads php://input,
        // which on an MCP call is the JSON-RPC envelope (id, method, params). An
        // empty body keeps those keys out of the route's input.
        $fresh = new class($app, [], []) extends Request {
            public function getContent()
            {
                return '';
            }
        };

        $app->singleton(Request::class, function () use ($fresh) {
            return $fresh;
        });
        $app->alias(Request::class, 'request');
    }

    private static function toError($status, array $data)
    {
        $message = (string) Arr::get($data, 'message', '');
        $fields = self::fieldErrors($data);

        if (!$message && $fields) {
            $message = implode(' ', array_values($fields));
        }

        if (!$message) {
            $message = 'The request could not be completed.';
        }

        $details = $fields ? [ 'fields' => array_keys($fields) ] : [];

        if ($status === 401 || $status === 403) {
            return McpHelper::error('forbidden', $message, $details);
        }

        if ($status === 404) {
            // A failed model lookup names the PHP class; the agent only needs to know it is missing.
            if (strpos($message, 'No query results') === 0) {
                $message = 'Not found, or you cannot access it.';
            }

            return McpHelper::error('not_found', $message, $details);
        }

        if ($status === 429) {
            return McpHelper::error('rate_limited', $message, [ 'retryable' => true ]);
        }

        if ($status >= 500) {
            return McpHelper::error('internal_error', 'The site could not complete the request. Try again.', [ 'retryable' => true ]);
        }

        return McpHelper::error('validation_failed', $message, $details);
    }

    /**
     * A 422 from the validator is {field: {rule: message}}; sendError() is {message, errors}.
     */
    private static function fieldErrors(array $data)
    {
        $errors = Arr::get($data, 'errors');

        if (!is_array($errors)) {
            $errors = $data;
            unset($errors['message'], $errors['code'], $errors['data'], $errors['permission_failed'], $errors['shakes']);
        }

        $flat = [];

        foreach ($errors as $field => $messages) {
            if (is_string($messages)) {
                $flat[$field] = $messages;
            } elseif (is_array($messages) && is_string(reset($messages))) {
                $flat[$field] = reset($messages);
            }
        }

        return $flat;
    }
}
