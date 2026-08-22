<?php

namespace Tests\Unit\Support;

use App\Support\ConfigCacheHelper;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ConfigCacheHelper 회귀 테스트.
 *
 * config 소스(config/*.php, .env, settings JSON, 활성 확장 목록)를 바꾸는 라이프사이클
 * 지점이 config:clear 만 하고 config:cache 를 재생성하지 않아, 한 번 캐시가 비워지면
 * config:cache 가 영구히 비활성 상태로 남던 결함을 단일 헬퍼로 막는다.
 *
 * rebuild() 는 testing 환경에서 early return 하므로(격리 보호), 실제 config:cache 생성
 * 경로는 환경을 임시로 'production' 으로 강제하고 Artisan 을 mock 하여 검증한다.
 * 실제 Artisan config:cache 를 호출하면 테스트 격리가 깨지므로 절대 실행하지 않는다.
 */
class ConfigCacheHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        // 환경 강제 복원 (다른 테스트 오염 방지)
        app()->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    public function test_rebuild_clears_but_never_caches_in_testing_environment(): void
    {
        // 기본 테스트 환경 = testing → config:clear 는 수행(값 반영 보장), config:cache 는 스킵(격리 보호).
        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls) {
            $calls[] = $command;

            return 0;
        });

        ConfigCacheHelper::rebuild();

        $this->assertContains('config:clear', $calls);
        $this->assertNotContains('config:cache', $calls, 'testing 환경에서는 config:cache 를 생성하지 않아야 합니다 (격리 보호).');
    }

    public function test_rebuild_clears_then_caches_when_installed(): void
    {
        // 비-testing 환경 + 설치 완료 상태 강제
        app()->detectEnvironment(fn () => 'production');
        config(['app.installer_completed' => true]);

        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls) {
            $calls[] = $command;

            return 0;
        });

        ConfigCacheHelper::rebuild();

        // config:clear 가 config:cache 보다 먼저 (stale 제거 후 재생성).
        $this->assertContains('config:clear', $calls);
        $this->assertContains('config:cache', $calls);
        $this->assertLessThan(
            array_search('config:cache', $calls, true),
            array_search('config:clear', $calls, true),
            'config:clear 는 config:cache 보다 먼저 실행되어야 합니다.'
        );
    }

    public function test_rebuild_clears_only_when_not_installed(): void
    {
        // 비-testing + 설치 미완료 → clear 만, 재생성은 스킵(불완전 config 박제 방지)
        app()->detectEnvironment(fn () => 'production');
        config(['app.installer_completed' => false]);

        // 설치 플래그 파일도 없어야 함 (isInstalled false 보장) — 존재 시 이 테스트는
        // 환경상 스킵될 수 있으나, 로컬 dev 에서 g7_installed 가 있으면 결과가 달라진다.
        if (file_exists(storage_path('app/g7_installed'))) {
            $this->markTestSkipped('storage/app/g7_installed 존재 — 미설치 경로 검증 불가');
        }

        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls) {
            $calls[] = $command;

            return 0;
        });

        ConfigCacheHelper::rebuild();

        $this->assertContains('config:clear', $calls);
        $this->assertNotContains('config:cache', $calls, '설치 미완료 시 config:cache 를 생성하지 않아야 합니다.');
    }

    public function test_clear_only_helper_never_caches(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['app.installer_completed' => true]);

        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls) {
            $calls[] = $command;

            return 0;
        });

        ConfigCacheHelper::clear();

        $this->assertContains('config:clear', $calls);
        $this->assertNotContains('config:cache', $calls, 'clear() 는 config:cache 를 재생성하지 않아야 합니다.');
    }

    public function test_clear_calls_config_clear_even_in_testing(): void
    {
        // clear() 는 config:clear 만 수행(재생성 없음). testing 에서도 clear 는 격리를 깨지 않으므로 실행한다.
        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls) {
            $calls[] = $command;

            return 0;
        });

        ConfigCacheHelper::clear();

        $this->assertSame(['config:clear'], $calls);
    }
}
