<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Behind a tunnel such as ngrok the page is https but the app is reached over http; the
 * forwarded headers must be trusted (only when TRUSTED_PROXIES says so) or asset URLs come out
 * as http:// and browsers block them.
 */
class TrustedProxiesTest extends TestCase
{
    private function loginPageStylesheet(): string
    {
        $html = $this->get('/login', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'demo.ngrok-free.app',
            'X-Forwarded-Port' => '443',
        ])->getContent();

        preg_match('#href="([^"]*style\.css[^"]*)"#', $html, $match);

        return $match[1] ?? '';
    }

    public function test_forwarded_https_is_ignored_unless_proxies_are_trusted(): void
    {
        config(['app.trusted_proxies' => null]);

        $this->assertStringStartsNotWith('https://demo.ngrok-free.app', $this->loginPageStylesheet());
    }

    public function test_forwarded_https_is_honoured_when_proxies_are_trusted(): void
    {
        config(['app.trusted_proxies' => '*']);

        $this->assertStringStartsWith('https://demo.ngrok-free.app/', $this->loginPageStylesheet());
    }
}
