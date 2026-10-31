<?php

namespace Tests\Feature\Extension;

use App\Exceptions\ExtensionUpdateFailedException;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionRollbackCacheRepair;
use App\Extension\HookCacheManager;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 확장 업데이트가 실패해 이전 판으로 되돌린 뒤 훅 캐시·확장 autoload 가 되돌린 판과 맞는다 (W3 최종 리허설 2회 재현).
 *
 * 결제 플러그인 업데이트가 업그레이드 가드(이커머스 1.3.0 필요)로 실패해 파일·DB 는 1.0.4 로 돌아갔는데, 업데이트 도중 새 판 기준으로
 * 다시 만든 `bootstrap/cache/hooks.php`·`autoload-extensions.php` 에 새 판에만 있는 리스너가 남아 첫 화면·게시판·주문서가 500 이었다.
 * 같은 프로세스에는 새 판 클래스가 이미 올라가 있어 그 자리에서 다시 구우면 새 판 리스너가 다시 들어갈 수 있으므로 새 프로세스로 굽고,
 * 띄울 수 없으면 autoload 만 다시 만들고 훅 캐시는 지워 다음 요청이 디스크(되돌린 판)를 기준으로 리스너를 찾게 한다.
 * 실패 안내도 실제 결과(되돌림·되돌리지 못함·새 판 유지·변경 없음)를 말한다.
 */
class ExtensionUpdateRollbackCacheRepairTest extends TestCase
{
    private string $cachePath;

    /**
     * 임시 훅 캐시 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = storage_path('framework/testing/rollback_hooks_'.uniqid().'.php');
        File::ensureDirectoryExists(dirname($this->cachePath));
        File::put($this->cachePath, "<?php return ['core'=>[],'modules'=>[],'plugins'=>['acme-pay'=>[['listener'=>'Plugins\\\\Acme\\\\NewOnlyListener','hooks'=>[]]]]];");
        (new HookCacheManager)->clear();
    }

    /**
     * 임시 파일과 목을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::delete($this->cachePath);
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 새 프로세스로 다시 구울 수 있으면 그 결과를 쓰고, 이 프로세스에서는 아무것도 굽지 않는다.
     *
     * @scenario fresh_process=available, failure_stage=after_cache_rebuild
     *
     * @effects rollback_rebuilds_caches_in_fresh_process
     *
     * @return void 반환값 없음
     */
    public function test_rebuilds_in_fresh_process_when_available(): void
    {
        $extensionManager = Mockery::mock(ExtensionManager::class);
        $extensionManager->shouldNotReceive('updateComposerAutoload');
        $calls = [];

        $mode = ExtensionRollbackCacheRepair::rebuild($extensionManager, function (array $args) use (&$calls) {
            $calls[] = $args;

            return ['ok' => true, 'exit_code' => 0, 'output' => ''];
        }, $this->hookCache());

        $this->assertSame('fresh_process', $mode);
        $this->assertSame([['extension:update-autoload']], $calls);
    }

    /**
     * 새 프로세스를 띄울 수 없으면 autoload 는 이 프로세스에서 다시 만들고, 훅 캐시는 지워 되돌린 판 기준 스캔으로 돌린다.
     *
     * @scenario fresh_process=unavailable, failure_stage=after_cache_rebuild
     *
     * @effects rollback_without_fresh_process_clears_hook_cache_so_no_stale_listener_remains
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_clearing_hook_cache(): void
    {
        $extensionManager = Mockery::mock(ExtensionManager::class);
        $extensionManager->shouldReceive('updateComposerAutoload')->once();
        $hookCache = $this->hookCache();
        $this->assertTrue($hookCache->isCached(), '전제 — 새 판 리스너가 박힌 캐시');

        $mode = ExtensionRollbackCacheRepair::rebuild($extensionManager, fn () => ['ok' => false, 'exit_code' => null, 'output' => 'proc_open 비활성'], $hookCache);

        $this->assertSame('in_process_fallback', $mode);
        $this->assertFalse($hookCache->isCached(), '되돌린 뒤에도 새 판 리스너가 박힌 훅 캐시가 남으면 사이트가 500 이다');
    }

    /**
     * 실패 결과 판정 — 되돌림·되돌리지 못함·새 판 유지(백업 삭제 뒤 실패)·변경 없음(파일 교체 전 실패).
     *
     * @scenario fresh_process=any, failure_stage=all
     *
     * @effects failure_notice_matches_actual_outcome
     *
     * @return void 반환값 없음
     */
    public function test_failure_outcome_matches_actual_state(): void
    {
        $this->assertSame('restored', ExtensionUpdateFailedException::outcomeFor(filesRestored: true, hadBackup: true, versionRecorded: true));
        $this->assertSame('restore_failed', ExtensionUpdateFailedException::outcomeFor(filesRestored: false, hadBackup: true, versionRecorded: true));
        $this->assertSame('kept_new', ExtensionUpdateFailedException::outcomeFor(filesRestored: false, hadBackup: false, versionRecorded: true));
        $this->assertSame('unchanged', ExtensionUpdateFailedException::outcomeFor(filesRestored: false, hadBackup: false, versionRecorded: false));
    }

    /**
     * 호출 계약 — 모듈·플러그인 실패 처리는 상태 복원 뒤 캐시를 다시 굽고, 세 매니저 모두 결과를 실은 예외를 던지며, 갱신 명령 3종은
     * 그 결과로 안내한다(무조건 「복원되었습니다」 금지).
     *
     * @scenario fresh_process=any, failure_stage=all
     *
     * @effects update_failure_paths_repair_caches_and_report_outcome
     *
     * @return void 반환값 없음
     */
    public function test_managers_and_commands_follow_contract(): void
    {
        foreach (['ModuleManager' => 'invalidateModuleStatusCache', 'PluginManager' => 'invalidatePluginStatusCache'] as $manager => $invalidate) {
            $source = (string) file_get_contents(base_path("app/Extension/{$manager}.php"));
            $catch = substr($source, (int) strrpos(substr($source, 0, (int) strpos($source, 'ExtensionUpdateFailedException::fromFailure(')), "self::{$invalidate}();"));
            $this->assertStringContainsString('ExtensionRollbackCacheRepair::rebuild(', $catch, "{$manager} 실패 처리가 상태 복원 뒤 캐시를 다시 굽지 않는다");
        }

        foreach (['ModuleManager', 'PluginManager', 'TemplateManager'] as $manager) {
            $source = (string) file_get_contents(base_path("app/Extension/{$manager}.php"));
            $this->assertStringContainsString('ExtensionUpdateFailedException::fromFailure(', $source, "{$manager} 가 실패 결과를 실은 예외를 던지지 않는다");
        }

        foreach (['Module/UpdateModuleCommand', 'Plugin/UpdatePluginCommand', 'Template/UpdateTemplateCommand'] as $command) {
            $source = (string) file_get_contents(base_path("app/Console/Commands/{$command}.php"));
            $this->assertStringContainsString('ExtensionUpdateFailedException', $source, "{$command} 가 실패 결과에 맞춰 안내하지 않는다");
        }
    }

    /**
     * 임시 경로를 쓰는 훅 캐시 관리자를 만듭니다.
     *
     * @return HookCacheManager 관리자
     */
    private function hookCache(): HookCacheManager
    {
        $path = $this->cachePath;

        return new class($path) extends HookCacheManager
        {
            /**
             * 임시 캐시 경로를 주입합니다.
             *
             * @param  string  $path  캐시 파일 경로
             */
            public function __construct(string $path)
            {
                $this->cacheFilePath = $path;
            }
        };
    }
}
