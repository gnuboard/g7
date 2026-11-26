<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Contracts\CommandRunner;
use App\Execution\ExecutionMode;
use App\Extension\Support\ExtensionBatchUpdateContext;
use App\Listeners\ExtensionUpdateQueueListener;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 관리자 화면 코어 업데이트의 번들 단계가 항목마다 「몇 개짜리 묶음의 한 항목인지」를 싣고, 묶음 끝에서 큐 워커 재시작을 한 번 보내는지 검증.
 *
 * 항목 자식은 그 표시로 일괄(2개 이상)일 때만 항목마다의 정적 게시·시작 시 캐시 재굽기·큐 워커 재시작을 건너뛴다. 확장 하나만
 * 올리는 번들 단계(1개짜리)는 종전과 같다 — 항목 자식이 그 자리에서 재시작하므로 끝 재시작도 보내지 않는다.
 *
 * 커버 축: core_update_bundled_batch_context
 */
class CoreUpdateBundledBatchContextTest extends TestCase
{
    /** 격리 루트 */
    private string $root;

    /** @var array<int, array<string, mixed>> 항목 자식에 넘긴 매니페스트 */
    private array $manifests = [];

    /**
     * 격리 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_bundled_batch_'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 번들 2개 이상이면 항목마다 같은 묶음(크기 2)을 싣고, 끝에서 큐 워커 재시작을 한 번 보낸다.
     *
     * @scenario batch=batch, caller=web_update
     *
     * @effects web_item_manifest_carries_batch_size, batch_update_restarts_queue_once_at_end
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_batch_items_carry_the_batch_and_restart_once_at_end(): void
    {
        $this->runBundledStep(['probe-plugin-a', 'probe-plugin-b'], expectedRestarts: 1);

        $this->assertCount(2, $this->manifests);
        $first = ExtensionBatchUpdateContext::fromManifest($this->manifests[0]);
        $second = ExtensionBatchUpdateContext::fromManifest($this->manifests[1]);
        $this->assertNotNull($first, '항목 매니페스트에 묶음 표시가 없다');
        $this->assertSame(2, $first->size);
        $this->assertTrue($first->isBatch());
        $this->assertSame($first->id, $second?->id, '같은 번들 단계의 항목이 다른 묶음으로 실렸다');
        $this->assertTrue($this->manifests[0]['defer_static_publish'] ?? false);
    }

    /**
     * 번들이 하나뿐이면 1개짜리 묶음이라 항목 자식은 종전대로 동작하고, 끝 재시작도 보내지 않는다.
     *
     * @scenario batch=single, caller=web_update
     *
     * @effects single_update_keeps_per_extension_queue_restart
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_single_item_bundled_step_is_unchanged(): void
    {
        $this->runBundledStep(['probe-plugin-a'], expectedRestarts: 0);

        $this->assertCount(1, $this->manifests);
        $batch = ExtensionBatchUpdateContext::fromManifest($this->manifests[0]);
        $this->assertFalse($batch?->isBatch() ?? false, '1개짜리 번들 단계가 일괄로 실렸다');
    }

    /**
     * 번들 단계를 끝까지 돌린다 — 항목 자식 실행은 대역이 매니페스트를 받아 두고 성공 결과를 돌려준다.
     *
     * @param  array<int, string>  $plugins  올릴 플러그인 식별자
     * @param  int  $expectedRestarts  묶음 끝 재시작 기대 횟수
     * @return void 반환값 없음
     */
    private function runBundledStep(array $plugins, int $expectedRestarts): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);

        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn([
            'modules' => [],
            'plugins' => array_map(static fn (string $id): array => ['identifier' => $id, 'current_version' => '1.0.0', 'latest_version' => '1.0.1'], $plugins),
            'templates' => [],
        ]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('mode')->andReturn(ExecutionMode::Subprocess);
        $runner->shouldReceive('run')->andReturnUsing(function (string $command, array $parameters): CommandRunResult {
            $this->manifests[] = (array) json_decode((string) File::get((string) $parameters['--manifest']), true);

            return new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Subprocess);
        });
        $resolver = Mockery::mock(CommandRunnerResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($runner);

        $this->app->instance(ExtensionUpdateQueueListener::class, Mockery::mock(ExtensionUpdateQueueListener::class, fn (MockInterface $m) => $m->shouldReceive('restartAfterBatch')->times($expectedRestarts)));

        for ($i = 0; $i < count($plugins) + 2 && $this->state()->context('bundled_result') === null; $i++) {
            (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $resolver, new CoreUpdateTimeBudget(null, 300)))->advance();
        }

        $this->assertSame(count($plugins), (int) ($this->state()->context('bundled_result')['success'] ?? -1), '번들 단계가 끝나지 않았다');
    }

    /**
     * 격리 경로의 실행 상태.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
    }
}
