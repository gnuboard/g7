<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Console\Commands\Core\ExecuteUpgradeStepsCommand;
use App\Extension\ExtensionManager;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 번들 일괄 업데이트 자식이 정상 종료(0)했는데 결과 줄(`[BUNDLED-RESULT]`)이 없으면 실패 1건으로 센다.
 *
 * 종전에는 「성공 0 / 실패 0」으로 성공 처리돼 `core:update` 가 0 으로 끝났다. 자식(`core:execute-bundled-updates`)은
 * 대상이 0건이어도 결과 줄을 늘 내므로, 이 판정이 정상 0건을 실패로 잡지 않는다는 것도 함께 확인한다.
 */
class BundledSpawnNoResultTest extends TestCase
{
    use DatabaseTransactions;

    /** 버퍼 출력 */
    private BufferedOutput $buffer;

    /** 테스트가 만든 임시 매니페스트 경로 */
    private ?string $manifestPath = null;

    /**
     * 임시 매니페스트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->manifestPath !== null && is_file($this->manifestPath)) {
            @unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    /**
     * 정상 종료 + 결과 없음 → 실패 1건 + 「결과를 남기지 않았다」 경고.
     *
     * @scenario case=child_exit_zero_without_result
     *
     * @effects bundled_child_without_result_counts_as_failure
     *
     * @return void 반환값 없음
     */
    public function test_child_exit_zero_without_result_counts_as_failure(): void
    {
        [$command, $method] = $this->outcomeMethod();

        $this->assertSame(['success' => 0, 'failed' => 1], $method->invoke($command, null, 0));
        $this->assertStringContainsString((string) __('extensions.commands.bundled_update.child_no_result'), $this->buffer->fetch());
    }

    /**
     * 회귀 — 비정상 종료 + 결과 없음은 종전대로 실패 1건(종료 코드 안내), 결과 줄이 있으면 그 수를 쓴다.
     *
     * @scenario case=child_result_regression
     *
     * @effects bundled_child_without_result_counts_as_failure
     *
     * @return void 반환값 없음
     */
    public function test_abnormal_exit_and_result_payload_are_unchanged(): void
    {
        [$command, $method] = $this->outcomeMethod();

        $this->assertSame(['success' => 0, 'failed' => 1], $method->invoke($command, null, 255));
        $this->assertStringContainsString((string) __('extensions.commands.bundled_update.child_aborted', ['code' => 255]), $this->buffer->fetch());

        $this->assertSame(['success' => 2, 'failed' => 1, 'skipped' => 0], $method->invoke($command, ['success' => 2, 'failed' => 1], 1));
        $this->assertSame(['success' => 0, 'failed' => 0, 'skipped' => 0], $method->invoke($command, ['success' => 0, 'failed' => 0], 0));
    }

    /**
     * 대상이 0건인 자식도 결과 줄(`success 0 / failed 0`)을 출력하고 0 으로 끝난다 — 정상 0건이 실패로 잡히지 않는다.
     *
     * 오토로드 재생성(`bootstrap/cache` 쓰기)과 빈 격리 디렉토리 청소는 대역으로 바꿔 실제 파일을 건드리지 않는다.
     *
     * @scenario case=zero_target_child_prints_result
     *
     * @effects bundled_child_always_prints_result_line
     *
     * @return void 반환값 없음
     */
    public function test_zero_target_child_prints_result_line(): void
    {
        $this->mock(ExtensionManager::class, fn (MockInterface $mock) => $mock->shouldReceive('updateComposerAutoload')->once());
        $this->mock(CoreUpdateService::class, fn (MockInterface $mock) => $mock->shouldReceive('sweepEmptyStagingDirectories')->once());

        $this->manifestPath = (string) tempnam(sys_get_temp_dir(), 'g7-bundled-empty-');
        file_put_contents($this->manifestPath, (string) json_encode(['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => []]));

        $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $this->manifestPath]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString(ExecuteBundledUpdatesCommand::RESULT_PREFIX.'{"success":0,"failed":0}', $output);
    }

    /**
     * 결과 판정 메서드와 출력을 버퍼로 받는 명령을 준비합니다 (판정은 두 명령이 함께 쓰는 트레이트에 있다).
     *
     * @return array{0: Command, 1: ReflectionMethod} [명령, 판정 메서드]
     */
    private function outcomeMethod(): array
    {
        $command = app(ExecuteUpgradeStepsCommand::class);
        $input = new ArrayInput([]);
        $this->buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle($input, $this->buffer));
        $command->setInput($input);

        return [$command, new ReflectionMethod($command, 'bundledSpawnOutcome')];
    }
}
