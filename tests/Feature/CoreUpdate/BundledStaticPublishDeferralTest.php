<?php

namespace Tests\Feature\CoreUpdate;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Services\CoreUpdateService;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 코어 업데이트 번들 단계의 정적 게시 미루기 검증.
 *
 * 회귀 가드: 7.2.0 → 7.2.1 웹 코어 업데이트 실측에서 번들 31개가 8분 걸렸다. 항목마다 새 프로세스가 끝날 때 정적 게시
 * (전 로케일 문구 병합·전 템플릿 산출물 복사, 로컬 실측 약 4초)를 되풀이했다. 항목 프로세스는 게시를 건너뛰고, 일괄을
 * 시작한 쪽(웹 마무리·명령줄 일괄 자식)이 끝에서 한 번 예약한다.
 */
class BundledStaticPublishDeferralTest extends TestCase
{
    /**
     * 각 테스트 뒤 게시 예약·root 흉내를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        ExtensionStaticCacheService::fakeRootProcessForTesting(null);

        parent::tearDown();
    }

    /**
     * 래퍼 안에서는 게시를 예약하지 않고, 래퍼가 끝나면 다시 예약할 수 있다.
     *
     * @scenario caller=item_process
     *
     * @effects item_process_skips_static_publish
     *
     * @return void 반환값 없음
     */
    public function test_wrapper_suppresses_publish_scheduling_only_while_running(): void
    {
        $this->app['env'] = 'production';
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        ExtensionStaticCacheService::fakeRootProcessForTesting(false);

        ExtensionStaticCacheService::withoutScheduledPublish(static function (): void {
            ExtensionStaticCacheService::schedulePublishOnTerminate();
        });

        $this->assertFalse(ExtensionStaticCacheService::isPublishScheduledForTesting(), '항목 처리 중에 게시가 예약됐다');

        ExtensionStaticCacheService::schedulePublishOnTerminate();

        $this->assertTrue(ExtensionStaticCacheService::isPublishScheduledForTesting(), '래퍼가 끝난 뒤에도 게시를 예약하지 못한다');
    }

    /**
     * 매니페스트 표시가 참일 때만 미룬다 — 표시가 없는 옛 부모·단독 실행은 종전대로 항목마다 게시한다.
     *
     * @scenario caller=legacy_parent
     *
     * @effects legacy_manifest_keeps_per_item_publish
     *
     * @return void 반환값 없음
     */
    public function test_only_explicit_manifest_flag_defers_publish(): void
    {
        $this->assertTrue(ExecuteBundledUpdatesCommand::defersStaticPublish(['defer_static_publish' => true]));
        $this->assertFalse(ExecuteBundledUpdatesCommand::defersStaticPublish([]));
        $this->assertFalse(ExecuteBundledUpdatesCommand::defersStaticPublish(['defer_static_publish' => 1]));
    }

    /**
     * 웹 코어 업데이트가 항목마다 쓰는 매니페스트에 미루기 표시가 실린다.
     *
     * @scenario caller=web_update
     *
     * @effects web_item_manifest_defers_static_publish
     *
     * @return void 반환값 없음
     */
    public function test_web_update_item_manifest_carries_defer_flag(): void
    {
        $service = app(CoreUpdateService::class);
        $orchestrator = new CoreUpdateOrchestrator(
            $service,
            app(CoreUpdateRunState::class),
            new CoreUpdatePreflight($service),
            app(CommandRunnerResolver::class),
            new CoreUpdateTimeBudget(null, 300),
        );

        $method = new \ReflectionMethod($orchestrator, 'writeBundledItemManifest');
        $path = $method->invoke($orchestrator, ['type' => 'plugin', 'identifier' => 'sirsoft-popup', 'strategy' => 'overwrite'], false, null);

        try {
            $manifest = json_decode(File::get($path), true);
            $this->assertTrue(ExecuteBundledUpdatesCommand::defersStaticPublish($manifest));
        } finally {
            File::delete($path);
        }
    }
}
