<?php

namespace Tests\Unit\Support;

use App\Contracts\Extension\CacheInterface;
use App\Support\SettingsVersion;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 설정 버전 — 설정을 저장할 때마다 공유 캐시에 저장 시각을 남긴다
 *
 * 큐 워커는 부팅 때 읽은 설정을 프로세스가 끝날 때까지 들고 있다. 저장한 프로세스만 다시 읽으므로, 워커가
 * 설정이 바뀐 것을 알 수 있도록 저장 시각을 공유 캐시(큐 재시작 신호와 같은 저장소)에 남긴다.
 */
class SettingsVersionTest extends TestCase
{
    /**
     * 올리면 마이크로초까지 담은 저장 시각이 남는다.
     *
     * @scenario case=version
     *
     * @effects version_bumped_on_save
     *
     * @return void 반환값 없음
     */
    public function test_bump_stores_microsecond_timestamp(): void
    {
        SettingsVersion::forget();
        $this->assertNull(SettingsVersion::current());

        SettingsVersion::bump();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) SettingsVersion::current());
    }

    /**
     * 연달아 올려도 값이 바뀐다 (같은 초 안의 저장도 구분된다).
     *
     * @scenario case=version
     *
     * @effects version_bumped_on_save
     *
     * @return void 반환값 없음
     */
    public function test_consecutive_bumps_change_value(): void
    {
        SettingsVersion::bump();
        $first = SettingsVersion::current();
        usleep(10);
        SettingsVersion::bump();

        $this->assertNotSame($first, SettingsVersion::current());
    }

    /**
     * 캐시 저장소가 실패해도 예외가 밖으로 새지 않고 경고만 남긴다 — 설정 저장을 막지 않는다.
     *
     * @scenario case=version
     *
     * @effects version_failure_does_not_block_save
     *
     * @return void 반환값 없음
     */
    public function test_cache_failure_is_swallowed(): void
    {
        $failing = \Mockery::mock(CacheInterface::class);
        $failing->shouldReceive('put')->andThrow(new \RuntimeException('캐시 장애 대역'));
        $failing->shouldReceive('get')->andThrow(new \RuntimeException('캐시 장애 대역'));
        $this->app->instance(CacheInterface::class, $failing);
        Log::shouldReceive('warning')->atLeast()->once();

        SettingsVersion::bump();

        $this->assertNull(SettingsVersion::current());
    }
}
