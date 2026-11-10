<?php

namespace Tests\Unit\Support;

use App\Support\ConfigCacheHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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
    /**
     * 강제한 환경을 testing 으로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // 환경 강제 복원 (다른 테스트 오염 방지)
        app()->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    /**
     * testing 환경의 rebuild() 는 config:clear 만 하고 config:cache 는 만들지 않는다.
     *
     * @scenario case=testing_env
     *
     * @effects rebuild_skips_cache_in_testing
     *
     * @return void 반환값 없음
     */
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

    /**
     * 설치된 운영 환경의 rebuild() 는 먼저 지우지 않고 config:cache 를 임시 경로로 돌려 굽는다 — 실제 파일은 굽는 내내
     * 남는다(#882: 지운 뒤 굽는 사이 들어온 요청이 비캐시 부팅·반쯤 쓰인 파일을 만났다). 굽기가 아무것도 만들지 못하면
     * (여기서는 명령을 대역으로 바꿔 파일이 생기지 않는다) 그때 config:clear 로 낡은 캐시를 지운다.
     *
     * @scenario case=installed
     *
     * @effects rebuild_recreates_cache_when_installed
     *
     * @return void 반환값 없음
     */
    public function test_rebuild_caches_into_temp_path_then_clears_stale_only_on_failure_when_installed(): void
    {
        // 비-testing 환경 + 설치 완료 상태 강제
        app()->detectEnvironment(fn () => 'production');
        config(['app.installer_completed' => true]);
        $realPath = app()->getCachedConfigPath();

        $calls = [];
        $pathDuringCache = null;
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls, &$pathDuringCache) {
            $calls[] = $command;
            if ($command === 'config:cache') {
                $pathDuringCache = app()->getCachedConfigPath();
            }

            return 0;
        });

        ConfigCacheHelper::rebuild();

        $this->assertSame(['config:cache', 'config:clear'], $calls, '먼저 굽고, 구운 파일이 없을 때만 낡은 캐시를 지워야 합니다.');
        $this->assertNotNull($pathDuringCache);
        $this->assertNotSame($realPath, $pathDuringCache, '굽는 동안 출력 경로가 임시 파일로 돌려져야 합니다.');
        $this->assertSame(dirname($realPath), dirname($pathDuringCache), '임시 파일은 실제 파일과 같은 폴더여야 바꿔 끼울 수 있습니다.');
        $this->assertSame($realPath, app()->getCachedConfigPath(), '굽기가 끝나면 출력 경로가 되돌아와야 합니다.');
        $this->assertFileDoesNotExist($pathDuringCache, '임시 파일이 남으면 안 됩니다.');
    }

    /**
     * 설치가 끝나지 않은 운영 환경의 rebuild() 는 clear 만 하고 불완전한 config 를 캐시에 박지 않는다.
     *
     * @scenario case=not_installed
     *
     * @effects rebuild_skips_cache_when_not_installed
     *
     * @return void 반환값 없음
     */
    public function test_rebuild_clears_only_when_not_installed(): void
    {
        // 비-testing + 설치 미완료 → clear 만, 재생성은 스킵(불완전 config 박제 방지)
        app()->detectEnvironment(fn () => 'production');
        config(['app.installer_completed' => false]);

        // 설치 플래그 파일도 없다고 답하게 한다 — 실제 storage/app/g7_installed 유무(설치된 개발 PC 에는 있다)에
        // 결과가 갈리지 않도록 파일 확인만 대역으로 바꾼다(종전에는 그 파일이 있으면 이 테스트를 건너뛰었다).
        File::partialMock()->shouldReceive('exists')->with(storage_path('app/g7_installed'))->andReturn(false);

        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls) {
            $calls[] = $command;

            return 0;
        });

        ConfigCacheHelper::rebuild();

        $this->assertContains('config:clear', $calls);
        $this->assertNotContains('config:cache', $calls, '설치 미완료 시 config:cache 를 생성하지 않아야 합니다.');
    }

    /**
     * clear() 는 설치된 운영 환경에서도 config:cache 를 만들지 않는다.
     *
     * @scenario case=clear_production
     *
     * @effects clear_never_recreates_cache
     *
     * @return void 반환값 없음
     */
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

    /**
     * clear() 는 testing 환경에서도 config:clear 를 실행한다.
     *
     * @scenario case=clear_testing
     *
     * @effects clear_never_recreates_cache
     *
     * @return void 반환값 없음
     */
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
