<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Behind a tunnel or load balancer (ngrok, a hosting proxy) the app only sees plain HTTP
     * unless it trusts the proxy's X-Forwarded-* headers; without that, asset and route URLs come
     * out as http:// on an https:// page and browsers block them. Set TRUSTED_PROXIES to '*' (or
     * a comma-separated list of IPs); unset, no proxy is trusted.
     */
    protected function proxies()
    {
        return config('app.trusted_proxies');
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
