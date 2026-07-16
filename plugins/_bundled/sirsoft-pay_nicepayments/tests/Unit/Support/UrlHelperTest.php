<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Support;

use Plugins\Sirsoft\PayNicepayments\Support\UrlHelper;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class UrlHelperTest extends PluginTestCase
{
    public function test_relative_url_uses_config_app_url_instead_of_forwarded_host(): void
    {
        config(['app.url' => 'https://shop.example.test']);

        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'attacker.example.test';

        try {
            $this->assertSame(
                'https://shop.example.test/shop/checkout',
                UrlHelper::toAbsolute('/shop/checkout')
            );
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_HOST']);
        }
    }

    public function test_absolute_url_is_returned_unchanged(): void
    {
        config(['app.url' => 'https://shop.example.test']);

        $this->assertSame(
            'https://payments.example.test/return',
            UrlHelper::toAbsolute('https://payments.example.test/return')
        );
    }
}
