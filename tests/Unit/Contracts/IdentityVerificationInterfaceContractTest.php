<?php

namespace Tests\Unit\Contracts;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * IdentityVerificationInterface 계약 테스트.
 *
 * 모든 구현체가 계약의 공개 메서드를 빠짐없이 구현하고,
 * 반환 타입이 계약과 일치하는지 검증합니다.
 */
class IdentityVerificationInterfaceContractTest extends TestCase
{
    public function test_interface_defines_required_methods(): void
    {
        $reflection = new ReflectionClass(IdentityVerificationInterface::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        foreach ([
            'getId',
            'getLabel',
            'getChannels',
            'getRenderHint',
            'supportsPurpose',
            'isAvailable',
            'requestChallenge',
            'verify',
            'cancel',
            'getSettingsSchema',
            'withConfig',
        ] as $expected) {
            $this->assertContains($expected, $methods, "Interface must declare {$expected}().");
        }
    }

    public function test_mail_provider_fulfills_contract(): void
    {
        $provider = $this->app->make(MailIdentityProvider::class);

        $this->assertInstanceOf(IdentityVerificationInterface::class, $provider);
        $this->assertIsString($provider->getId());
        $this->assertIsString($provider->getLabel());
        $this->assertIsArray($provider->getChannels());
        $this->assertIsString($provider->getRenderHint());
        $this->assertIsBool($provider->supportsPurpose('signup'));
        $this->assertIsBool($provider->isAvailable());
        $this->assertIsArray($provider->getSettingsSchema());
    }

    public function test_with_config_returns_new_instance(): void
    {
        $provider = $this->app->make(MailIdentityProvider::class);
        $configured = $provider->withConfig(['code_length' => 8]);

        $this->assertInstanceOf(IdentityVerificationInterface::class, $configured);
        $this->assertNotSame($provider, $configured);
    }
}
