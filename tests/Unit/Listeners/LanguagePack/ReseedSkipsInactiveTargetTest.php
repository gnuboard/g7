<?php

namespace Tests\Unit\Listeners\LanguagePack;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Extension\Support\LifecycleWarnings;
use App\Listeners\LanguagePack\RunSeedersOnLanguagePackLifecycle;
use App\Models\LanguagePack;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

/**
 * 언어팩 활성·비활성 뒤 시더 재실행은 대상 확장이 활성일 때만 돈다 (7.1.3).
 *
 * 모듈·플러그인을 비활성화하면 그 확장의 언어팩도 함께 꺼지고, 이 리스너가 그 확장의 시더를 다시 돌리려 한다.
 * 확장이 이미 비활성이라 시더 명령은 늘 「활성화되어 있지 않습니다」로 거절한다 — 7.1.2 까지는 그 반환값을 버려
 * 조용했지만, 7.1.3 이 반환값을 경고로 올리면서 언어팩이 있는 확장을 끌 때마다 거짓 경고(관리자 노란 결과 창,
 * CLI 종료코드 1)가 났다. 비활성 대상은 건너뛴다(시더가 돌지 않는 결과는 7.1.2 와 같다).
 *
 * @scenario-manifest tests/scenarios/language-pack-reseed-inactive-target.yaml
 */
class ReseedSkipsInactiveTargetTest extends TestCase
{
    /**
     * 테스트 정리 — Mockery 기대를 검증하고 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 활성 확장 목록을 정한 리스너를 만듭니다.
     *
     * @param  array<int, string>  $activeModules  활성 모듈 식별자
     * @param  array<int, string>  $activePlugins  활성 플러그인 식별자
     * @param  bool  $filesPresent  활성 디렉터리에 확장 파일이 있다고 볼지 (false 면 실제 디렉터리로 판정 — 시험 식별자는 없다)
     * @return RunSeedersOnLanguagePackLifecycle 리스너
     */
    private function listener(array $activeModules, array $activePlugins, bool $filesPresent = true): RunSeedersOnLanguagePackLifecycle
    {
        $modules = Mockery::mock(ModuleRepositoryInterface::class);
        $modules->shouldReceive('getActiveModuleIdentifiers')->andReturn($activeModules);
        $plugins = Mockery::mock(PluginRepositoryInterface::class);
        $plugins->shouldReceive('getActivePluginIdentifiers')->andReturn($activePlugins);
        $this->app->instance(ModuleRepositoryInterface::class, $modules);
        $this->app->instance(PluginRepositoryInterface::class, $plugins);

        if (! $filesPresent) {
            return $this->app->make(RunSeedersOnLanguagePackLifecycle::class);
        }

        return new class($this->app, $modules, $plugins) extends RunSeedersOnLanguagePackLifecycle
        {
            /**
             * 시험 식별자는 활성 디렉터리가 없으므로 파일이 있다고 봅니다.
             *
             * @param  string  $type  modules 또는 plugins
             * @param  string  $identifier  확장 식별자
             * @return bool 항상 true
             */
            protected function hasActiveFiles(string $type, string $identifier): bool
            {
                return true;
            }
        };
    }

    /**
     * 확장 범위 언어팩(저장하지 않은 모델)을 만듭니다.
     *
     * @param  LanguagePackScope  $scope  모듈·플러그인 범위
     * @param  string  $target  대상 확장 식별자
     * @return LanguagePack 언어팩 모델
     */
    private function pack(LanguagePackScope $scope, string $target): LanguagePack
    {
        $pack = new LanguagePack;
        $pack->identifier = 'acme-'.$scope->value.'-'.$target.'-ja';
        $pack->scope = $scope->value;
        $pack->target_identifier = $target;
        $pack->locale = 'ja';

        return $pack;
    }

    /**
     * 대상 확장이 비활성이면 시더 명령을 부르지 않고 경고도 남기지 않는다.
     *
     * @scenario target=inactive
     *
     * @effects inactive_target_reseed_skipped_without_warning
     *
     * @return void 반환값 없음
     */
    public function test_inactive_target_is_skipped_without_warning(): void
    {
        Artisan::shouldReceive('call')->with('module:seed', Mockery::any())->never();
        Artisan::shouldReceive('call')->with('plugin:seed', Mockery::any())->never();
        $listener = $this->listener(activeModules: ['acme-other'], activePlugins: []);
        $mark = LifecycleWarnings::current()->mark();

        $listener->handleDeactivated($this->pack(LanguagePackScope::Module, 'acme-board'));
        $listener->handleDeactivated($this->pack(LanguagePackScope::Plugin, 'acme-pay'));

        $this->assertSame([], LifecycleWarnings::current()->since($mark), '비활성 확장 시더 재실행은 거짓 경고를 내면 안 된다');
    }

    /**
     * DB 에는 활성으로 남았지만 활성 디렉터리에 파일이 없는 확장(파일만 지워진 사이트)은 시더를 부르지 않고 경고도 남기지 않는다.
     * 종전에는 시더 명령이 「모듈을 찾을 수 없습니다」로 실패해 언어팩 설치·업데이트·켜기가 「일부 항목 실패」와 종료 코드 1 로
     * 끝났다 — 그 확장은 코드가 없어 돌지 않으므로 다시 시딩할 행도 없다. 시더가 도는 경로(핵심 언어팩의 전체 재시딩과 확장
     * 범위 언어팩)는 같은 메서드를 지난다.
     *
     * @scenario target=active_without_files
     *
     * @effects active_target_without_files_skipped_without_warning
     *
     * @return void 반환값 없음
     */
    public function test_active_target_without_files_is_skipped_without_warning(): void
    {
        Artisan::shouldReceive('call')->with('module:seed', Mockery::any())->never();
        Artisan::shouldReceive('call')->with('plugin:seed', Mockery::any())->never();
        $listener = $this->listener(activeModules: ['acme-ghost-module'], activePlugins: ['acme-ghost-plugin'], filesPresent: false);
        $mark = LifecycleWarnings::current()->mark();

        $listener->handleActivated($this->pack(LanguagePackScope::Module, 'acme-ghost-module'));
        $listener->handleActivated($this->pack(LanguagePackScope::Plugin, 'acme-ghost-plugin'));

        $this->assertSame([], LifecycleWarnings::current()->since($mark), '파일 없는 확장의 시더 재실행은 경고를 내면 안 된다');
    }

    /**
     * 대상 확장이 활성이면 종전대로 그 확장의 시더를 다시 돌린다.
     *
     * @scenario target=active
     *
     * @effects active_target_reseeded
     *
     * @return void 반환값 없음
     */
    public function test_active_target_is_reseeded(): void
    {
        Artisan::shouldReceive('call')->once()->with('module:seed', ['identifier' => 'acme-board', '--force' => true])->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('plugin:seed', ['identifier' => 'acme-pay', '--force' => true])->andReturn(0);
        $listener = $this->listener(activeModules: ['acme-board'], activePlugins: ['acme-pay']);

        $listener->handleActivated($this->pack(LanguagePackScope::Module, 'acme-board'));
        $listener->handleActivated($this->pack(LanguagePackScope::Plugin, 'acme-pay'));

        $this->assertTrue(true); // 기대는 Mockery 가 tearDown 에서 검증한다
    }
}
