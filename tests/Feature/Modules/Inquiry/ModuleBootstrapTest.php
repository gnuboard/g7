<?php

namespace Tests\Feature\Modules\Inquiry;

use Modules\Sirsoft\Inquiry\Providers\InquiryServiceProvider;
use Tests\TestCase;

class ModuleBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(InquiryServiceProvider::class);
    }

    public function test_service_provider_is_resolvable(): void
    {
        $provider = $this->app->make(InquiryServiceProvider::class, ['app' => $this->app]);
        $this->assertInstanceOf(InquiryServiceProvider::class, $provider);
    }

    public function test_module_identifier_matches_manifest(): void
    {
        $provider = $this->app->make(InquiryServiceProvider::class, ['app' => $this->app]);
        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('moduleIdentifier');
        $prop->setAccessible(true);
        $this->assertSame('sirsoft-inquiry', $prop->getValue($provider));
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame('KRW', config('inquiry.quote.currency'));
        $this->assertContains('image/jpeg', config('inquiry.attachment.allowed_mimes'));
    }
}
