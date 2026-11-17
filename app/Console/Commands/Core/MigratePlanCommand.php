<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Services\Upgrade\MigrationPlanService;
use Illuminate\Console\Command;

/**
 * 실행 대기 마이그레이션의 SQL·파괴 여부와 업그레이드 스텝 목록을 실행 없이 보여 줍니다.
 *
 * 스테이지에서 새 판을 띄우기 전, 그 판이 공유 DB 에 무엇을 할지 미리 본다. 컬럼 삭제·이름 변경·타입 변경·NOT NULL 전환은
 * 옛 코드가 함께 도는 동안 깨질 수 있어 `defer_recommended` 로 표시한다. DB·파일은 바뀌지 않는다.
 *
 * @since 7.2.0
 */
class MigratePlanCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'g7:migrate-plan
        {--json : 결과를 JSON 으로 출력}
        {--scope=all : 범위 (all|core|extensions) — core 는 database/migrations 와 custom 등 마이그레이터에 등록된 비확장 경로}';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 다국어로 바꾼다)
     */
    protected $description = 'common.console.migrate_plan.description';

    /**
     * 계획을 출력합니다.
     *
     * @param  MigrationPlanService  $service  계획 서비스
     * @return int 종료 코드
     */
    public function handle(MigrationPlanService $service): int
    {
        $scope = (string) $this->option('scope');
        if (! in_array($scope, ['all', 'core', 'extensions'], true)) {
            $this->error(__('common.console.migrate_plan.invalid_scope', ['scope' => $scope]));

            return self::INVALID;
        }

        $plan = $service->plan($scope);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->warn('⚠ '.$plan['warning']);
        $this->newLine();

        if ($plan['migrations'] === []) {
            $this->info(__('common.console.migrate_plan.no_migrations'));
        } else {
            $this->table(
                [__('common.console.migrate_plan.col_source'), __('common.console.migrate_plan.col_migration'), __('common.console.migrate_plan.col_kind'), __('common.console.migrate_plan.col_defer')],
                array_map(fn (array $m) => [
                    $m['source'],
                    $m['name'],
                    $m['destructive'] ? implode(', ', $m['kinds']) : __('common.console.migrate_plan.additive'),
                    $m['defer_recommended'] ? __('common.console.migrate_plan.defer_yes') : '',
                ], $plan['migrations']),
            );
            foreach ($plan['migrations'] as $m) {
                $this->line("-- {$m['source']} {$m['name']}");
                foreach ($m['queries'] as $sql) {
                    $this->line($sql.';');
                }
            }
        }

        $this->newLine();
        if ($plan['upgrade_steps'] === []) {
            $this->info(__('common.console.migrate_plan.no_steps'));
        } else {
            $this->table(
                [__('common.console.migrate_plan.col_source'), __('common.console.migrate_plan.col_version'), __('common.console.migrate_plan.col_summary')],
                array_map(fn (array $s) => [
                    $s['source'],
                    $s['version'],
                    ($s['summary'] ?? null) !== null ? $s['summary'].($s['destructive'] ? ' ⚠' : '') : __('common.console.migrate_plan.no_description'),
                ], $plan['upgrade_steps']),
            );
        }

        return self::SUCCESS;
    }
}
