<?php

namespace FluentCommunity\Framework\Http\Request;

use FluentCommunity\Framework\Support\Arr;

trait InteractsWithHeadersTrait
{
	/**
     * Retrieve an item from the PHP headers
     * @param  string $key
     * @param  string $default
     * @return mixed
     */
    public function header($key = null, $default = null)
    {
        !$this->headers && $this->setHeaders();

        return $key ? Arr::get(
        	$this->headers, strtoupper($key), $default
        ) : $this->headers;
    }

    /**
     * Sets the headers for the request.
     * 
     * Note: Taken from Symfony and modified.
     */
    public function setHeaders()
    {
        $headers = [];
        
        $parameters = $this->server;
        
        $contentHeaders = [
            'CONTENT_LENGTH' => true,
            'CONTENT_MD5' => true,
            'CONTENT_TYPE' => true
        ];

        foreach ($parameters as $key => $value) {
            if (0 === strpos($key, 'HTTP_')) {
                $headers[substr($key, 5)] = $value;
            } elseif (isset($contentHeaders[$key])) {
                $headers[$key] = $value;
            }
        }

        if (isset($parameters['PHP_AUTH_USER'])) {
            $headers['PHP_AUTH_USER'] = $parameters['PHP_AUTH_USER'];
            $headers['PHP_AUTH_PW'] = isset($parameters['PHP_AUTH_PW']) ? $parameters['PHP_AUTH_PW'] : '';
        } else {
            /*
             * php-cgi under Apache does not pass HTTP Basic user/pass to PHP by default
             * For this workaround to work, add these lines to your .htaccess file:
             * RewriteCond %{HTTP:Authorization} ^(.+)$
             * RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
             *
             * A sample .htaccess file:
             * RewriteEngine On
             * RewriteCond %{HTTP:Authorization} ^(.+)$
             * RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
             * RewriteCond %{REQUEST_FILENAME} !-f
             * RewriteRule ^(.*)$ app.php [QSA,L]
             */

            $authorizationHeader = null;
            if (isset($parameters['HTTP_AUTHORIZATION'])) {
                $authorizationHeader = $parameters['HTTP_AUTHORIZATION'];
            } elseif (isset($parameters['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authorizationHeader = $parameters['REDIRECT_HTTP_AUTHORIZATION'];
            }

            if (null !== $authorizationHeader) {
                if (0 === stripos($authorizationHeader, 'basic ')) {
                    // Decode AUTHORIZATION header into PHP_AUTH_USER and PHP_AUTH_PW when authorization header is basic
                    $exploded = explode(
                        ':', base64_decode(
                            substr($authorizationHeader, 6)
                        ), 2
                    );

                    if (count($exploded) == 2) {
                        list(
                            $headers['PHP_AUTH_USER'],
                            $headers['PHP_AUTH_PW']
                        ) = $exploded;
                    }
                } elseif (
                    empty($parameters['PHP_AUTH_DIGEST']) &&
                    (0 === stripos($authorizationHeader, 'digest '))
                ) {
                    // In some circumstances PHP_AUTH_DIGEST needs to be set
                    $headers['PHP_AUTH_DIGEST'] = $authorizationHeader;
                    $parameters['PHP_AUTH_DIGEST'] = $authorizationHeader;
                } elseif (0 === stripos($authorizationHeader, 'bearer ')) {
                    /*
                     * XXX: Since there is no PHP_AUTH_BEARER in PHP predefined variables,
                     *      I'll just set $headers['AUTHORIZATION'] here.
                     *      http://php.net/manual/en/reserved.variables.server.php
                     */
                    $headers['AUTHORIZATION'] = $authorizationHeader;
                }
            }
        }

        if (isset($headers['AUTHORIZATION'])) {
            return $headers;
        }

        // PHP_AUTH_USER/PHP_AUTH_PW
        if (isset($headers['PHP_AUTH_USER'])) {
            $headers['AUTHORIZATION'] = 'Basic '.base64_encode(
                $headers['PHP_AUTH_USER'].':'.$headers['PHP_AUTH_PW']
            );
        } elseif (isset($headers['PHP_AUTH_DIGEST'])) {
            $headers['AUTHORIZATION'] = $headers['PHP_AUTH_DIGEST'];
        }

        return $this->headers = $headers;
    }

    /**
     * Set a header on the request.
     * 
     * @param string $key  
     * @param string $value
     */
    public function setHeader($key, $value)
    {
        if (empty($this->headers)) {
            $this->setHeaders();
        }

        $this->headers[$key] = $value;
    }
}
