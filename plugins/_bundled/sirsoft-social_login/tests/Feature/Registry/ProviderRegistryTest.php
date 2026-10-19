<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Registry;

use App\Extension\HookManager;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Drivers\FacebookDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\GoogleDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\KakaoDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\NaverDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 제공자 레지스트리 시험 — 시딩, 확장 등록, 내장 덮어쓰기 무시, 커스텀 한도, 모의 게이트.
 *
 * @since 1.0.0
 */
class ProviderRegistryTest extends PluginTestCase
{
    /**
     * 새 레지스트리를 반환합니다(요청당 캐시 비움).
     *
     * @return ProviderRegistry 레지스트리
     */
    private function registry(): ProviderRegistry
    {
        $registry = app(ProviderRegistry::class);
        $registry->flush();

        return $registry;
    }

    /**
     * 내장 제공자 4개를 꺼진 채로 만들고, 다시 시딩해도 운영자가 바꾼 값을 지키는지 확인한다.
     *
     * @scenario seed=builtin
     * @effects builtin_four_seeded_disabled
     * @return void 반환값 없음
     */
    public function test_seeder_creates_four_builtin_providers_disabled_and_keeps_operator_changes(): void
    {
        (new ProviderSeeder)->run();

        $rows = SocialLoginProvider::query()->orderBy('sort_order')->get();
        $this->assertSame(['kakao', 'naver', 'google', 'facebook'], $rows->pluck('key')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->enabled === false && $r->type === 'builtin'));

        SocialLoginProvider::query()->where('key', 'kakao')->update(['enabled' => true, 'sort_order' => 99, 'client_id' => 'operator-id']);
        (new ProviderSeeder)->run();

        $kakao = SocialLoginProvider::query()->where('key', 'kakao')->first();
        $this->assertTrue($kakao->enabled, '다시 시딩해도 운영자가 켠 값을 되돌리지 않는다');
        $this->assertSame(99, $kakao->sort_order);
        $this->assertSame('operator-id', $kakao->client_id);
        $this->assertSame(4, SocialLoginProvider::query()->count());
    }

    /**
     * 제공자 행이 driver 컬럼에 맞는 드라이버로 만들어지는지 확인한다.
     *
     * @scenario registry=builtin
     * @effects drivers_instantiated_by_driver_column
     * @return void 반환값 없음
     */
    public function test_rows_become_drivers_by_driver_column(): void
    {
        (new ProviderSeeder)->run();
        SocialLoginProvider::query()->create(['key' => 'acme_idp', 'driver' => 'oidc', 'type' => 'custom', 'label' => ['ko' => '사내', 'en' => 'Corp']]);

        $all = $this->registry()->all();

        $this->assertInstanceOf(KakaoDriver::class, $all['kakao']);
        $this->assertInstanceOf(NaverDriver::class, $all['naver']);
        $this->assertInstanceOf(GoogleDriver::class, $all['google']);
        $this->assertInstanceOf(FacebookDriver::class, $all['facebook']);
        $this->assertInstanceOf(OidcDriver::class, $all['acme_idp']);
        $this->assertSame('acme_idp', $all['acme_idp']->key());
    }

    /**
     * 확장은 새 제공자를 더할 수 있지만 내장 제공자는 바꿀 수 없는지 확인한다.
     *
     * @scenario registry=extension
     * @effects extension_adds_new_key_builtin_override_ignored
     * @return void 반환값 없음
     */
    public function test_extension_can_add_new_key_but_cannot_replace_builtin(): void
    {
        (new ProviderSeeder)->run();
        $foreign = app(OidcDriver::class)->withConfig(['key' => 'ext_idp', 'client_id' => 'x']);
        $impostor = app(OidcDriver::class)->withConfig(['key' => 'kakao']);

        HookManager::addFilter(ProviderRegistry::FILTER_REGISTERED, function (array $drivers) use ($foreign, $impostor) {
            $drivers['ext_idp'] = $foreign;
            $drivers['kakao'] = $impostor;
            $drivers['Bad Key'] = $foreign;

            return $drivers;
        }, 20);

        $all = $this->registry()->all();

        $this->assertSame($foreign, $all['ext_idp']);
        $this->assertInstanceOf(KakaoDriver::class, $all['kakao'], '내장 키 덮어쓰기는 무시');
        $this->assertArrayNotHasKey('Bad Key', $all, '키 형식이 틀리면 받지 않는다');
    }

    /**
     * 커스텀 제공자가 한도까지만 등록되는지 확인한다.
     *
     * @scenario registry=custom_limit
     * @effects custom_providers_capped
     * @return void 반환값 없음
     */
    public function test_custom_providers_are_capped_at_the_limit(): void
    {
        for ($i = 1; $i <= ProviderRegistry::DEFAULT_CUSTOM_LIMIT + 1; $i++) {
            SocialLoginProvider::query()->create(['key' => 'idp_'.$i, 'driver' => 'oidc', 'type' => 'custom', 'sort_order' => $i, 'label' => ['ko' => 'x']]);
        }

        $keys = array_keys($this->registry()->all());

        $this->assertCount(ProviderRegistry::DEFAULT_CUSTOM_LIMIT, $keys);
        $this->assertNotContains('idp_'.(ProviderRegistry::DEFAULT_CUSTOM_LIMIT + 1), $keys);
    }

    /**
     * 모의 제공자가 게이트가 켜졌을 때만 등록되는지 확인한다.
     *
     * @scenario registry=mock_gate
     * @effects mock_providers_only_when_gate_on
     * @return void 반환값 없음
     */
    public function test_mock_providers_appear_only_while_gate_is_on(): void
    {
        $gate = app(MockGate::class);
        $gate->disable();
        $this->assertSame([], array_values(array_filter(array_keys($this->registry()->all()), fn ($k) => str_starts_with($k, 'mock_'))));

        $gate->enable(10);
        (new ProviderSeeder)->run();
        $all = $this->registry()->all();
        $mockKeys = array_values(array_filter(array_keys($all), fn ($k) => str_starts_with($k, 'mock_')));
        $gate->disable();

        $this->assertSame(['mock_kakao', 'mock_naver', 'mock_google', 'mock_facebook', 'mock_oidc'], $mockKeys);
        // TLS 검증을 끄는 것은 모의 제공자(이 사이트 자신을 부름)뿐이다 — 실제 제공자는 언제나 검증한다.
        foreach ($mockKeys as $key) {
            $this->assertTrue($all[$key]->isMock(), $key);
        }
        foreach (['kakao', 'naver', 'google', 'facebook'] as $key) {
            $this->assertFalse($all[$key]->isMock(), $key);
        }
    }
}
