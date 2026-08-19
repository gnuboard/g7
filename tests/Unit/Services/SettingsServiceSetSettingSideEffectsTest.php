<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 코어 단건 설정 저장(setSetting)의 부수효과 정합 테스트 (공개 #114 동종, B-3)
 *
 * 단건 저장은 `PUT /api/admin/settings/{key}` 로 실제 도달 가능한 경로인데, 벌크 저장이
 * 수행하는 부수효과 중 일부를 건너뛰었다:
 *
 * - `general.asset_url_mode` 변경 시 SEO 프리렌더 캐시 삭제
 * - `drivers` 카테고리 저장 시 큐 워커 재시작 신호
 *
 * 벌크 위임은 채택하지 않는다 — 입력 shape 이 다르고(벌크는 화면 키 + reverseFrontendKeys
 * 전제, 단건은 원본 dot-key), 벌크의 shallow `array_merge` 로는 `identity.purpose_providers.X.Y`
 * 같은 깊은 키를 저장할 때 형제 매핑이 통째로 소실된다. 부수효과만 이식한다.
 */
class SettingsServiceSetSettingSideEffectsTest extends TestCase
{
    /**
     * ConfigRepository/AttachmentRepository 를 mock 한 SettingsService 를 만듭니다.
     *
     * @param  mixed  $existingValue  `get()` 이 돌려줄 기존 값
     * @param  bool  $setResult  `set()` 의 반환값
     * @return SettingsService 대상 서비스
     */
    private function makeService(mixed $existingValue = null, bool $setResult = true): SettingsService
    {
        $configRepository = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepository->shouldReceive('get')->andReturn($existingValue);
        $configRepository->shouldReceive('set')->andReturn($setResult);

        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();
        $this->mock(CacheInterface::class)->shouldIgnoreMissing();

        return app(SettingsService::class);
    }

    /**
     * 자산 URL 방식을 단건으로 바꾸면 SEO 프리렌더 캐시가 삭제된다. (실패-먼저)
     *
     * @effects seo_prerender_cache_cleared_on_asset_url_mode_change
     */
    public function test_asset_url_mode_change_clears_seo_cache(): void
    {
        $this->mock(SeoCacheManagerInterface::class)
            ->shouldReceive('clearAll')
            ->once();

        $service = $this->makeService(existingValue: 'relative');

        $this->assertTrue($service->setSetting('general.asset_url_mode', 'absolute'));
    }

    /**
     * 값이 그대로면 SEO 캐시를 건드리지 않는다.
     *
     * @effects seo_prerender_cache_untouched_when_value_unchanged
     */
    public function test_same_asset_url_mode_does_not_clear_seo_cache(): void
    {
        $this->mock(SeoCacheManagerInterface::class)
            ->shouldReceive('clearAll')
            ->never();

        $service = $this->makeService(existingValue: 'absolute');

        $this->assertTrue($service->setSetting('general.asset_url_mode', 'absolute'));
    }

    /**
     * 다른 키 저장은 SEO 프리렌더 캐시를 건드리지 않는다.
     *
     * @effects seo_prerender_cache_untouched_when_value_unchanged
     */
    public function test_unrelated_key_does_not_clear_seo_cache(): void
    {
        $this->mock(SeoCacheManagerInterface::class)
            ->shouldReceive('clearAll')
            ->never();

        $service = $this->makeService(existingValue: 'old');

        $this->assertTrue($service->setSetting('general.site_name', 'new'));
    }

    /**
     * drivers 카테고리 단건 저장은 큐 워커 재시작 신호를 보낸다. (실패-먼저)
     *
     * @effects queue_workers_restarted_on_driver_change
     */
    public function test_driver_setting_restarts_queue_workers(): void
    {
        Artisan::shouldReceive('call')->with('config:clear')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('config:cache')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('queue:restart')->once();

        $service = $this->makeService(existingValue: 'sync');

        $this->assertTrue($service->setSetting('drivers.queue_driver', 'redis'));
    }

    /**
     * drivers 외 카테고리는 큐 워커를 재시작하지 않는다.
     *
     * @effects queue_workers_not_restarted_for_other_categories
     */
    public function test_non_driver_setting_does_not_restart_queue_workers(): void
    {
        Artisan::shouldReceive('call')->with('config:clear')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('config:cache')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('queue:restart')->never();

        $service = $this->makeService(existingValue: 'old');

        $this->assertTrue($service->setSetting('general.site_name', 'new'));
    }

    /**
     * 저장 실패 시에는 부수효과가 실행되지 않는다.
     *
     * @effects side_effects_skipped_when_save_fails
     */
    public function test_failed_save_skips_side_effects(): void
    {
        Artisan::shouldReceive('call')->with('config:clear')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('config:cache')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('queue:restart')->never();

        $this->mock(SeoCacheManagerInterface::class)
            ->shouldReceive('clearAll')
            ->never();

        $service = $this->makeService(existingValue: 'sync', setResult: false);

        $this->assertFalse($service->setSetting('drivers.queue_driver', 'redis'));
    }

    /**
     * 단건 저장은 받은 키를 변형 없이 저장소에 넘긴다. (벌크 위임 금지 — 계약 고정)
     *
     * 벌크 저장은 입력을 탭/필드로 쪼개 `reverseFrontendKeys()`(화면 키 → 저장소 키)를 태운 뒤
     * shallow `array_merge` 로 병합한다. 단건 경로를 벌크에 위임하면 두 가지가 깨진다.
     *
     *  - 저장소 키를 직접 넘기는 프로그램 호출자(본인인증 플러그인 설치/삭제의
     *    `identity.purpose_providers.*`)의 키가 역변환 대상에 걸릴 수 있다.
     *  - shallow merge 는 깊은 키를 평평한 문자열 키로 취급해 형제 provider 매핑을 통째로
     *    지운다 — 다른 목적(purpose)의 인증수단 설정이 조용히 사라진다.
     *
     * 그래서 단건 경로는 받은 키를 그대로 `ConfigRepository::set()` 에 넘긴다.
     * 이 테스트는 위임·키 분해·역변환 중 어느 것이 끼어도 실패한다.
     *
     * @effects single_key_save_uses_storage_key_verbatim
     */
    public function test_single_key_save_passes_the_storage_key_verbatim(): void
    {
        Artisan::shouldReceive('call')->zeroOrMoreTimes();

        $written = [];
        $configRepository = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepository->shouldReceive('get')->andReturn(null);
        $configRepository->shouldReceive('set')->andReturnUsing(function (string $key, mixed $value) use (&$written): bool {
            $written[] = $key;

            return true;
        });

        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();
        $this->mock(CacheInterface::class)->shouldIgnoreMissing();

        $service = app(SettingsService::class);

        // ① 화면 키가 따로 있는 저장소 키 — 역변환이 끼면 `cache.cache_enabled` 가 된다
        $service->setSetting('cache.enabled', true);
        // ② 플러그인이 실제로 넘기는 깊은 키 — 형제 provider 매핑이 보존되어야 하는 자리
        $service->setSetting('identity.purpose_providers.signup.sms', 'nhnkcp');

        $this->assertSame(
            ['cache.enabled', 'identity.purpose_providers.signup.sms'],
            $written,
            '단건 저장이 받은 키를 변형했습니다 — 프로그램 호출자가 엉뚱한 키에 저장됩니다.'
        );
    }
}
