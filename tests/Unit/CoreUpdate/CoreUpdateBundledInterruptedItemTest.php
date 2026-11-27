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
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 관리자 화면 코어 업데이트의 번들 항목이 요청 시간 제한으로 끊겼을 때의 결과 판정.
 *
 * 끊긴 것은 항목 자식 프로세스(`core:execute-bundled-updates`)를 기다리던 요청이지 자식이 아니다 — 30초 호스팅 실측에서
 * 자식은 끝까지 돌아 템플릿을 새 판으로 반영했는데, 결과 화면은 「1개가 업데이트되지 않았습니다 … 다시 시도」라고 했고
 * 안내대로 다시 업데이트하면 「사용 가능한 업데이트가 없습니다」였다. 끊긴 항목은 실패로 세지 않고, 번들 단계 끝에서 실제
 * 판(번들에 아직 새 판이 남았는가)을 확인해 반영됐으면 성공, 아니면 「완료 여부 확인 불가」로 알린다.
 *
 * 커버 축: core_update_bundled_interrupted_item
 */
class CoreUpdateBundledInterruptedItemTest extends TestCase
{
    /** 격리 루트 */
    private string $root;

    /**
     * 격리 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_bundled_interrupted_'.uniqid());
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
     * 실측 재현: 끊긴 항목의 자식이 끝까지 돌아 새 판이 반영됐으면 성공으로 세고 「업데이트되지 않았습니다」를 알리지 않는다.
     *
     * @scenario case=interrupted_item_applied
     *
     * @effects interrupted_item_counted_as_success_when_applied, no_not_updated_warning_for_applied_item
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_interrupted_item_that_was_applied_counts_as_success(): void
    {
        $this->interruptOnlyItem();

        $this->finishBundledStep(stillPending: false);

        $after = $this->state();
        $this->assertSame(['success' => 1, 'failed' => 0, 'unconfirmed' => 0, 'planned' => 1], array_intersect_key($after->context('bundled_result'), array_flip(['success', 'failed', 'unconfirmed', 'planned'])), '끝까지 돌아 반영된 항목을 실패로 셌다');
        $this->assertNotContains(trans_count('settings.core_update.web.bundled_partial_failure', ['failed' => '1', 'count' => 1]), $after->all()['warnings'] ?? [], '반영된 항목을 「업데이트되지 않았습니다」로 알렸다');
        // 진행 기록 줄은 `[시:분:초] ` 머리를 단다.
        $applied = (string) __('settings.core_update.web.log_bundled_item_interrupted_applied', ['identifier' => 'sirsoft-basic']);
        $this->assertNotEmpty(array_filter($after->logs(), static fn (string $line): bool => str_ends_with($line, $applied)), '끊겼던 항목이 반영된 것을 확인했다는 기록이 없다');
    }

    /**
     * 끊긴 항목에 아직 새 판이 남아 있으면(자식이 실패했거나 아직 도는 중) 실패로 단정하지 않고 「완료 여부 확인 불가」로 알린다.
     *
     * @scenario case=interrupted_item_still_pending
     *
     * @effects interrupted_item_reported_as_unconfirmed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_interrupted_item_still_pending_is_reported_as_unconfirmed(): void
    {
        $this->interruptOnlyItem();

        $this->finishBundledStep(stillPending: true);

        $after = $this->state();
        $this->assertSame(['success' => 0, 'failed' => 0, 'unconfirmed' => 1, 'planned' => 1], array_intersect_key($after->context('bundled_result'), array_flip(['success', 'failed', 'unconfirmed', 'planned'])));
        $warnings = $after->all()['warnings'] ?? [];
        $this->assertContains(trans_count('settings.core_update.web.bundled_interrupted_unconfirmed', ['count' => 1, 'identifiers' => 'sirsoft-basic']), $warnings, '완료 여부를 확인하라는 안내가 없다');
        $this->assertNotContains(trans_count('settings.core_update.web.bundled_partial_failure', ['failed' => '1', 'count' => 1]), $warnings, '끝까지 돌았을 수 있는 항목을 「업데이트되지 않았습니다」로 단정했다');
    }

    /**
     * 번들 항목 하나(템플릿)를 실행하던 요청이 시간 초과로 죽은 상태를 만든다(종료 처리가 기록한다).
     *
     * @return void 반환값 없음
     */
    private function interruptOnlyItem(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep']],
            'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => 1,
        ]]);
        $state->claim('token-bundled', CoreUpdateStep::Bundled->value, 60);

        $budget = new CoreUpdateTimeBudget(null, 30);
        $budget->begin();
        $service = app(CoreUpdateService::class);

        (new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), $budget))
            ->handleFatalShutdown('token-bundled', ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded']);
    }

    /**
     * 다음 요청이 번들 단계를 마무리하게 한다 — 번들에 그 템플릿의 새 판이 남아 있는지를 정해 준다.
     *
     * @param  bool  $stillPending  true 면 아직 새 판이 남아 있다(반영 안 됨·진행 중)
     * @return void 반환값 없음
     */
    private function finishBundledStep(bool $stillPending): void
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn([
            'modules' => [],
            'plugins' => [],
            'templates' => $stillPending ? [['identifier' => 'sirsoft-basic', 'current_version' => '1.4.0', 'latest_version' => '1.4.99']] : [],
        ]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300)))->advance();
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
