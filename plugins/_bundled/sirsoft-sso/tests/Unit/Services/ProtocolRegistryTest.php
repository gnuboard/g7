<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Services;

use App\Extension\HookManager;
use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\FakeProtocolDriver;

/**
 * 프로토콜 레지스트리 — 내장 + 필터로 더한 드라이버, 내장 키 덮어쓰기와 계약 미구현은 무시.
 *
 * @since 1.0.0
 */
class ProtocolRegistryTest extends PluginTestCase
{
    /**
     * 내장 OIDC 드라이버가 등록되는지 확인한다.
     *
     * @scenario registry=builtin
     *
     * @effects oidc_registered
     *
     * @return void 반환값 없음
     */
    public function test_builtin_oidc_is_registered(): void
    {
        $this->assertInstanceOf(OidcDriver::class, app(ProtocolRegistry::class)->get('oidc'));
    }

    /**
     * 필터로 드라이버를 더할 수 있지만 내장 키는 덮어쓰지 못하는지 확인한다.
     *
     * @scenario registry=filter_add|filter_override|filter_invalid
     *
     * @effects third_party_added, builtin_kept, invalid_ignored
     *
     * @return void 반환값 없음
     */
    public function test_filter_adds_driver_but_cannot_override_builtin(): void
    {
        $custom = new FakeProtocolDriver('header');
        $override = new FakeProtocolDriver('oidc');

        HookManager::addFilter(ProtocolRegistry::FILTER_REGISTERED, function (array $drivers) use ($custom, $override) {
            $drivers['header'] = $custom;
            $drivers['oidc_override'] = $override;
            $drivers['broken'] = new \stdClass;

            return $drivers;
        });

        $registry = app(ProtocolRegistry::class);
        $registry->flush();

        $this->assertSame($custom, $registry->get('header'));
        $this->assertInstanceOf(OidcDriver::class, $registry->get('oidc'));
        $this->assertNull($registry->get('broken'));
    }
}
