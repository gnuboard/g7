<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

// @scenario disk_setting=none, consumer=product, row_state=new_remote_row, override=follow_core, hook=unregistered, e2e=save_roundtrip

/**
 * SettingsServiceProvider 공개 자산 디스크 주입 사슬 테스트 (공개#100)
 *
 * drivers.public_asset_disk 저장값 → core.storage.public_asset_disk 주입과
 * 'none'(스트리밍 유지)/빈값 → '' 정규화를 검증합니다. 주입부
 * (injectPublicAssetDiskConfig)는 테스트 격리 가드와 분리되어 있어 직접 단언이
 * 가능하며, 가드(applyPublicAssetDiskConfig)가 testing 환경에서 주입을 실제로
 * 차단하는지도 함께 고정합니다.
 *
 * @effects global_setting_injects_core_config_with_none_normalized
 */
class SettingsServiceProviderPublicAssetDiskTest extends TestCase
{
    /**
     * 지정 메서드를 리플렉션으로 호출합니다.
     *
     * @param  string  $method  호출할 private 메서드명
     * @param  array  $driverSettings  drivers 카테고리 설정 데이터
     */
    private function callWithDriverSettings(string $method, array $driverSettings): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->with('drivers')
            ->willReturn($driverSettings);

        $provider = new SettingsServiceProvider($this->app);
        $reflection = new ReflectionMethod($provider, $method);
        $reflection->invoke($provider, $configRepository);
    }

    /**
     * 저장값이 core.storage.public_asset_disk 로 주입되는지 테스트합니다.
     */
    public function test_stored_disk_injected_into_core_config(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $this->callWithDriverSettings('injectPublicAssetDiskConfig', [
            'public_asset_disk' => 'fake_cdn',
        ]);

        $this->assertSame('fake_cdn', config('core.storage.public_asset_disk'));
    }

    /**
     * 'none'(스트리밍 유지 선택)이 미설정('')으로 정규화되는지 테스트합니다.
     */
    public function test_none_sentinel_normalized_to_empty(): void
    {
        Config::set('core.storage.public_asset_disk', 'stale_value');

        $this->callWithDriverSettings('injectPublicAssetDiskConfig', [
            'public_asset_disk' => 'none',
        ]);

        $this->assertSame('', config('core.storage.public_asset_disk'));
    }

    /**
     * 저장 키 부재 시 미설정('')으로 주입되는지 테스트합니다.
     */
    public function test_missing_key_injected_as_empty(): void
    {
        Config::set('core.storage.public_asset_disk', 'stale_value');

        $this->callWithDriverSettings('injectPublicAssetDiskConfig', []);

        $this->assertSame('', config('core.storage.public_asset_disk'));
    }

    /**
     * 빈 문자열 저장값이 그대로 미설정('')인지 테스트합니다.
     */
    public function test_blank_value_injected_as_empty(): void
    {
        Config::set('core.storage.public_asset_disk', 'stale_value');

        $this->callWithDriverSettings('injectPublicAssetDiskConfig', [
            'public_asset_disk' => '',
        ]);

        $this->assertSame('', config('core.storage.public_asset_disk'));
    }

    /**
     * testing 환경에서 가드가 주입을 차단하는지 테스트합니다 (테스트 격리).
     *
     * 이 테스트 자체가 testing 환경에서 실행되므로, 가드 메서드
     * (applyPublicAssetDiskConfig)를 호출해도 dev 공유 drivers.json 값이
     * 흘러들지 않아야 합니다.
     */
    public function test_guard_skips_injection_in_testing_env(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $this->callWithDriverSettings('applyPublicAssetDiskConfig', [
            'public_asset_disk' => 'fake_cdn',
        ]);

        $this->assertSame('', config('core.storage.public_asset_disk'));
    }
}
