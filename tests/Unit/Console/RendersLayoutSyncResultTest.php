<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Traits\RendersLayoutSyncResult;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 확장 업데이트 커맨드의 레이아웃 처리 결과 출력(#727 D2 — CLI 는 표) 단위 테스트.
 */
class RendersLayoutSyncResultTest extends TestCase
{
    /**
     * 트레이트를 쓰는 커맨드와 출력 버퍼를 만듭니다.
     *
     * @return array{0: Command, 1: BufferedOutput} [커맨드, 버퍼]
     */
    private function command(): array
    {
        $command = new class extends Command
        {
            use RendersLayoutSyncResult;

            protected $signature = 'probe:render';

            /**
             * 보고를 출력합니다.
             *
             * @param  array<string, mixed>|null  $sync  보고
             * @return void 반환값 없음
             */
            public function render(?array $sync): void
            {
                $this->renderLayoutSyncResult($sync);
            }

            /**
             * 한 줄 요약을 출력합니다.
             *
             * @param  mixed  $result  업데이트 결과
             * @return void 반환값 없음
             */
            public function line1(mixed $result): void
            {
                $this->lineLayoutSyncSummary($result);
            }

            /**
             * 덮어쓰기 경고를 출력합니다.
             *
             * @param  array<string, mixed>  $modified  수정 목록
             * @return void 반환값 없음
             */
            public function warnOverwrite(array $modified): void
            {
                $this->warnModifiedLayoutsBeforeOverwrite($modified, 'modules');
            }
        };

        $buffer = new BufferedOutput;
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return [$command, $buffer];
    }

    /**
     * 보고 한 건.
     *
     * @return array<string, mixed> 보고
     */
    private function report(): array
    {
        return [
            'strategy' => 'keep', 'applied_count' => 1, 'conflict_count' => 1, 'kept_no_base_count' => 0,
            'kept_removed_count' => 0, 'kept_deleted_by_operator_count' => 0, 'kept_name_collision_count' => 0,
            'kept_invalid_count' => 0, 'modified_count' => 1,
            'entries' => [[
                'kind' => 'layout', 'template' => 'sirsoft-admin_basic', 'name' => 'admin_module_list', 'verdict' => 'merged',
                'applied' => [['unit' => 'u', 'kind' => 'partial']], 'conflicts' => [['unit' => 'v', 'reason' => 'both_changed']],
            ]],
        ];
    }

    /**
     * 운영자 관련 행이 있으면 표 머리·행·요약 한 줄을 출력한다.
     *
     * @effects layout_sync_result_is_shown_in_cli
     *
     * @return void 반환값 없음
     */
    public function test_renders_table_and_summary(): void
    {
        [$command, $buffer] = $this->command();
        $command->render($this->report());
        $out = $buffer->fetch();

        $this->assertStringContainsString(__('extensions.layout_sync.cli.header'), $out);
        $this->assertStringContainsString('sirsoft-admin_basic · admin_module_list', $out);
        $this->assertStringContainsString(__('extensions.layout_sync.verdict.merged'), $out);
        $this->assertStringContainsString(__('extensions.layout_sync.summary', ['applied' => 1, 'conflicts' => 1]), $out);
    }

    /**
     * 운영자 관련 행이 없으면 아무것도 출력하지 않는다.
     *
     * @effects layout_sync_result_is_shown_in_cli
     *
     * @return void 반환값 없음
     */
    public function test_renders_nothing_without_operator_rows(): void
    {
        [$command, $buffer] = $this->command();
        $command->render(['modified_count' => 0, 'entries' => []]);
        $command->line1(['layout_sync' => null]);

        $this->assertSame('', trim($buffer->fetch()));
    }

    /**
     * core:update 는 확장마다 요약 한 줄만, overwrite 경고는 레이아웃·조각 목록을 낸다.
     *
     * @effects layout_sync_result_is_shown_in_cli, overwrite_warns_about_modified_layouts
     *
     * @return void 반환값 없음
     */
    public function test_summary_line_and_overwrite_warning(): void
    {
        [$command, $buffer] = $this->command();
        $command->line1(['layout_sync' => $this->report()]);
        $command->warnOverwrite(['modified_count' => 1, 'modified_layouts' => [['name' => '확장 조각 admin_user_detail (probe_a)', 'updated_at' => '2026-09-30 10:00:00']]]);
        $out = $buffer->fetch();

        $this->assertStringNotContainsString(__('extensions.layout_sync.cli.header'), $out);
        $this->assertStringContainsString(__('extensions.layout_sync.summary', ['applied' => 1, 'conflicts' => 1]), $out);
        $this->assertStringContainsString(__('modules.commands.update.modified_layouts_warning', ['count' => 1]), $out);
        $this->assertStringContainsString('확장 조각 admin_user_detail (probe_a)', $out);
    }
}
