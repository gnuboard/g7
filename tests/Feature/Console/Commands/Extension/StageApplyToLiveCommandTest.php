<?php

namespace Tests\Feature\Console\Commands\Extension;

use App\Console\Helpers\ArtisanCommandRunner;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `stage:apply-to-live` — 운영 서버에서, 검수 서버가 공유 DB 에 먼저 반영한 확장 판을 마무리한다.
 *
 * 검수 서버가 확장을 업데이트하면 공유 DB 판이 먼저 새 판이 되어 운영 서버의 `*:update` 는 「업데이트 없음」으로 끝난다. 운영 서버가
 * 따로 해야 하는 일(파일 맞추기 → 레이아웃 갱신 keep → 미뤄 둔 정리)을 한 명령으로 차례로 하고, 실패한 단계에서 멈춘다.
 * 안쪽 명령은 실제로 돌리지 않는다 — 실행기를 바꿔 끼워 호출 순서·인자·종료 코드만 본다(실제 활성 폴더를 건드리지 않게).
 *
 * @scenario-manifest tests/scenarios/stage-apply-to-live.yaml
 */
class StageApplyToLiveCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 가짜 실행기를 끼웁니다.
     *
     * @param  array<string, int>  $exitCodes  명령 이름 → 종료 코드 (없으면 0)
     * @return ArtisanCommandRunner 호출 기록(`calls`)을 가진 실행기
     */
    private function fakeRunner(array $exitCodes = []): ArtisanCommandRunner
    {
        $runner = new class($exitCodes) extends ArtisanCommandRunner
        {
            /** @var array<int, array{0: string, 1: array<string, mixed>, 2?: bool}> 호출 기록 (세 번째 칸 true = 새 프로세스) */
            public array $calls = [];

            /**
             * 명령별 종료 코드를 정해 둡니다.
             *
             * @param  array<string, int>  $exitCodes  명령 이름 → 종료 코드
             */
            public function __construct(private array $exitCodes) {}

            /**
             * 호출을 기록하고 정해 둔 종료 코드를 돌려줍니다.
             *
             * @param  string  $command  명령 이름
             * @param  array<string, mixed>  $parameters  인자·옵션
             * @return array{exit_code: int, output: string} 종료 코드와 출력
             */
            public function run(string $command, array $parameters = []): array
            {
                $this->calls[] = [$command, $parameters];

                return ['exit_code' => $this->exitCodes[$command] ?? 0, 'output' => '{"items":[]}'];
            }

            /**
             * 새 프로세스 실행을 기록합니다(세 번째 칸 true).
             *
             * @param  string  $command  명령 이름
             * @param  array<string, mixed>  $parameters  인자·옵션
             * @return array{exit_code: int, output: string} 종료 코드와 출력
             */
            public function runFresh(string $command, array $parameters = []): array
            {
                $this->calls[] = [$command, $parameters, true];

                return ['exit_code' => $this->exitCodes[$command] ?? 0, 'output' => '{"items":[]}'];
            }
        };
        $this->app->instance(ArtisanCommandRunner::class, $runner);

        return $runner;
    }

    /**
     * 명령을 실행합니다.
     *
     * @param  array<string, mixed>  $options  옵션
     * @return array{0: int, 1: string} [종료 코드, 출력]
     */
    private function runApply(array $options = []): array
    {
        $buffer = new BufferedOutput;
        $code = Artisan::call('stage:apply-to-live', $options, $buffer);

        return [$code, $buffer->fetch()];
    }

    /**
     * 켜진 사용자·관리자 템플릿을 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function activeTemplates(): void
    {
        Template::factory()->create(['identifier' => 'acme-applyprobe_admin', 'vendor' => 'acme', 'type' => 'admin', 'status' => 'active']);
        Template::factory()->create(['identifier' => 'acme-applyprobe_user', 'vendor' => 'acme', 'type' => 'user', 'status' => 'active']);
    }

    /**
     * 검수 서버에서는 아무 단계도 실행하지 않고 1 이다.
     *
     * @scenario case=stage_refused
     *
     * @effects stage_role_refuses_apply_to_live
     *
     * @return void 반환값 없음
     */
    public function test_stage_role_refuses(): void
    {
        config(['core.runtime_role' => 'stage']);
        $runner = $this->fakeRunner();

        [$code, $output] = $this->runApply(['--yes' => true]);

        $this->assertSame(1, $code);
        $this->assertSame([], $runner->calls);
        $this->assertStringContainsString(__('common.console.stage_apply.stage_refused'), $output);
    }

    /**
     * 운영 서버 실행은 파일 맞추기 → 레이아웃 갱신 keep(사용자 템플릿 → 관리자 템플릿 → 모듈·플러그인) → 정리 실행 순서이고,
     * 실행 전에 방향(번들 파일 → 이 서버)과 「검수 서버 데이터를 옮기지 않음」 을 알린다.
     *
     * @scenario case=live_run
     *
     * @effects steps_run_in_order_files_layouts_cleanup, direction_is_announced_before_running
     *
     * @return void 반환값 없음
     */
    public function test_live_runs_steps_in_order(): void
    {
        config(['core.runtime_role' => 'live']);
        $this->activeTemplates();
        $runner = $this->fakeRunner();

        [$code, $output] = $this->runApply(['--yes' => true]);

        $this->assertSame(0, $code);
        $names = array_column($runner->calls, 0);
        $this->assertSame('extension:reset-files-from-bundled', $names[0], '1단계는 파일 맞추기');
        $this->assertSame('extension:apply-deferred-cleanup', $names[count($names) - 1], '마지막은 정리 실행');
        $this->assertArrayNotHasKey('--type', $runner->calls[0][1], '파일 맞추기는 전 종류(언어팩 포함)');

        $refreshes = array_values(array_filter($runner->calls, fn ($call) => str_ends_with($call[0], ':refresh-layout')));
        $identifiers = array_map(fn ($call) => $call[1]['identifier'] ?? null, $refreshes);
        $user = array_search('acme-applyprobe_user', $identifiers, true);
        $admin = array_search('acme-applyprobe_admin', $identifiers, true);
        $this->assertNotFalse($user);
        $this->assertNotFalse($admin);
        $this->assertLessThan($admin, $user, '관리자 템플릿은 사용자 템플릿 뒤 — 설치된 템플릿 설정 화면 재등록이 마지막 판정');
        foreach ($refreshes as [, $parameters]) {
            $this->assertSame('keep', $parameters['--layout-strategy'] ?? null, '운영자 수정본을 지키는 keep');
            $this->assertArrayNotHasKey('--dry-run', $parameters);
        }

        $direction = strpos($output, __('common.console.stage_apply.direction'));
        $this->assertNotFalse($direction);
        $this->assertStringContainsString(__('common.console.stage_apply.not_copy'), $output);
        $this->assertStringContainsString(__('common.console.stage_apply.core_first'), $output);
        $this->assertLessThan(strpos($output, __('common.console.stage_apply.done')), $direction, '방향 안내는 결과보다 먼저');
    }

    /**
     * 미리보기는 모든 단계를 미리보기로 부르고, 확인을 묻지 않는다.
     *
     * @scenario case=dry_run
     *
     * @effects dry_run_passes_dry_run_to_every_step
     *
     * @return void 반환값 없음
     */
    public function test_dry_run_passes_dry_run_to_every_step(): void
    {
        config(['core.runtime_role' => 'live']);
        $this->activeTemplates();
        $runner = $this->fakeRunner();

        [$code] = $this->runApply(['--dry-run' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code);
        $this->assertGreaterThanOrEqual(4, count($runner->calls));
        foreach ($runner->calls as [$name, $parameters]) {
            $this->assertTrue($parameters['--dry-run'] ?? false, "{$name} 도 미리보기여야 한다");
        }
    }

    /**
     * 한 단계가 실패하면 그 단계에서 멈추고 1 이다 — 뒤 단계는 부르지 않고 결과에 「실행 안 함」으로 남긴다.
     *
     * @scenario case=step_failure
     *
     * @effects failed_step_stops_the_run
     *
     * @return void 반환값 없음
     */
    public function test_failed_step_stops_the_run(): void
    {
        config(['core.runtime_role' => 'live']);
        $runner = $this->fakeRunner(['extension:reset-files-from-bundled' => 1]);

        [$code, $output] = $this->runApply(['--yes' => true, '--json' => true]);

        $this->assertSame(1, $code);
        $this->assertSame(['extension:reset-files-from-bundled'], array_column($runner->calls, 0));
        $json = json_decode(trim($output), true);
        $this->assertIsArray($json, '--json 출력에는 안내 문장을 섞지 않는다');
        $this->assertSame('files', $json['failed_step']);
        $this->assertSame(['failed', 'skipped', 'skipped'], array_column($json['steps'], 'status'));
    }

    /**
     * 정리 실행이 1(실패·기다림)이면 1 로 끝나고, 파일 맞추기를 먼저 끝냈는지 확인하라고 안내한다.
     *
     * @scenario case=cleanup_failure
     *
     * @effects failed_step_stops_the_run
     *
     * @return void 반환값 없음
     */
    public function test_cleanup_failure_hints_file_reset(): void
    {
        config(['core.runtime_role' => 'live']);
        $this->fakeRunner(['extension:apply-deferred-cleanup' => 1]);

        [$code, $output] = $this->runApply(['--yes' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString(__('common.console.stage_apply.cleanup_failed_hint'), $output);
    }

    /**
     * 확인에 답할 수 없는 실행(`--yes` 없음, 비대화형)은 아무것도 하지 않고 1 이다.
     *
     * @scenario case=no_confirmation
     *
     * @effects unanswerable_confirmation_refuses
     *
     * @return void 반환값 없음
     */
    public function test_unanswerable_confirmation_refuses(): void
    {
        config(['core.runtime_role' => 'live']);
        $runner = $this->fakeRunner();

        [$code, $output] = $this->runApply(['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertSame([], $runner->calls);
        $this->assertStringContainsString(__('common.console.stage_apply.confirm_hint'), $output);
    }

    /**
     * 파일을 바꾼 뒤의 단계(레이아웃 갱신·정리)는 새 프로세스로 돈다 — 같은 프로세스는 이미 불러온 옛 확장 코드의 선언으로
     * 계산해, 새 판이 추가한 항목을 지울 수 있다.
     *
     * @scenario case=live_run
     *
     * @effects steps_after_file_reset_run_in_fresh_process
     *
     * @return void 반환값 없음
     */
    public function test_steps_after_file_reset_run_in_fresh_process(): void
    {
        config(['core.runtime_role' => 'live']);
        $this->activeTemplates();
        $runner = $this->fakeRunner();

        [$code] = $this->runApply(['--yes' => true]);

        $this->assertSame(0, $code);
        foreach ($runner->calls as $call) {
            $fresh = $call[2] ?? false;
            if ($call[0] === 'extension:reset-files-from-bundled') {
                $this->assertFalse($fresh, '파일 맞추기는 이 프로세스에서');
            } else {
                $this->assertTrue($fresh, "{$call[0]} 는 파일을 바꾼 뒤라 새 프로세스로 돈다");
            }
        }
    }
}
