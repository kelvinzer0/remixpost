<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic APP_URL & APP_FORCE_HTTPS Middleware
 *
 * Detects the request host and dynamically configures:
 * - APP_URL: based on the actual host being accessed
 * - APP_FORCE_HTTPS: true for domain names, false for IP addresses
 * - Session cookies: domain, secure flag, same_site
 *
 * This allows the app to be accessed from multiple domains/IPs
 * without manual .env configuration.
 */
class DynamicAppUrl
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // Determine scheme: HTTPS for domains, HTTP for IPs
        $scheme = $isIpAddress ? 'http' : 'https';

        // Build dynamic APP_URL from request
        $port = $request->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portSuffix = ($port && $port !== $defaultPort) ? ":{$port}" : '';
        $dynamicUrl = "{$scheme}://{$host}{$portSuffix}";

        // Override APP_URL in config at runtime
        config(['app.url' => $dynamicUrl]);

        // Configure session dynamically based on request context
        config(['session.domain' => $host]);
        config(['session.secure' => !$isIpAddress]);
        config(['session.same_site' => $isIpAddress ? 'lax' : 'none']);

        // Configure CSRF cookie to match session settings
        config(['session.secure_cookie' => !$isIpAddress]);

        // Force HTTPS only for domain names (not IP addresses)
        if (!$isIpAddress) {
            URL::forceScheme('https');
        }

        // Trust proxies when behind a domain (likely reverse proxy / tunnel)
        if (!$isIpAddress) {
            $this->trustProxies($request);
        }

        return $next($request);
    }

    /**
     * Configure trusted proxies for the current request.
     */
    protected function trustProxies(Request $request): void
    {
        // Trust all proxies when accessed via domain
        $request->setTrustedProxies(
            $request->server->all(),
            Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_AWS_ELB
        );
    }
}
