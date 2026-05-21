<?php

namespace Tests\Feature\Modules\Inquiry;

use Modules\Sirsoft\Inquiry\Providers\InquiryServiceProvider;
use Tests\TestCase;

class ModuleBootstrapTest extends TestCase
{
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
}
