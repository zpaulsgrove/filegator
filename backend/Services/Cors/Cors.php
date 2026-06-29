<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Cors;

use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Service;

/**
 * @codeCoverageIgnore
 */
class Cors implements Service
{
    protected $request;

    protected $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    public function init(array $config = [])
    {
        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        $origin = (string) $this->request->headers->get('Origin');

        // When an explicit allowlist is configured, only echo the Origin back
        // (together with Access-Control-Allow-Credentials: true) if it is on the
        // list. Reflecting an arbitrary Origin with credentials enabled would let
        // any website make credentialed cross-origin requests on behalf of a
        // logged-in user (CWE-942). An empty/unset list preserves the legacy
        // reflect-any behaviour for same-machine development setups.
        $allowed = isset($config['allowed_origins']) && is_array($config['allowed_origins'])
            ? $config['allowed_origins']
            : [];

        if (! empty($allowed)) {
            if ($origin === '' || ! in_array($origin, $allowed, true)) {
                // Origin not permitted: emit no CORS headers so the browser
                // blocks the cross-origin response. Still answer a preflight so
                // it fails cleanly rather than hanging.
                if ($this->request->server->get('REQUEST_METHOD') == 'OPTIONS') {
                    $this->response->send();
                    die;
                }

                return;
            }

            // Response varies by Origin, so it must not be cached across origins.
            $this->response->headers->set('Vary', 'Origin');
        }

        $this->response->headers->set('Access-Control-Allow-Origin', $origin);
        $this->response->headers->set('Access-Control-Allow-Credentials', 'true');
        $this->response->headers->set('Access-Control-Expose-Headers', 'x-csrf-token');

        if ($this->request->server->get('REQUEST_METHOD') == 'OPTIONS') {
            $this->response->headers->set('Access-Control-Allow-Headers', 'content-type, x-csrf-token');
            $this->response->send();
            die;
        }
    }
}
