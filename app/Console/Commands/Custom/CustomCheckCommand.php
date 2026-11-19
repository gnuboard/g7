<?php

namespace App\Console\Commands\Custom;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Custom\CustomPackageConflictChecker;
use App\Support\Custom\CustomStatus;
use Illuminate\Console\Command;

/**
 * custom 예약 자리 점검 — 등록된 개발자 라우트·거부된 라우트·엔진 전체 이동 패턴·개발자 패키지·코어 충돌.
 *
 * @since 7.2.0
 */
class CustomCheckCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string
     */
    protected $signature = 'custom:check
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 명령을 실행합니다.
     *
     * @return int 종료 코드 (충돌·거부 라우트가 있으면 1)
     */
    public function handle(): int
    {
        $status = CustomStatus::collect();
        $problem = $status['routes']['rejected'] !== [] || $status['conflicts'] !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $problem ? self::FAILURE : self::SUCCESS;
        }

        $this->info(__('settings.custom_reserved.check.reserved_title'));
        $this->table(
            [__('settings.custom_reserved.check.col_path'), __('settings.custom_reserved.check.col_state')],
            array_map(fn (array $r) => [$r['path'], $r['exists'] ? __('settings.custom_reserved.check.exists') : '-'], $status['reserved']),
        );

        $this->info(__('settings.custom_reserved.check.routes_title'));
        if (! $status['routes']['exists']) {
            $this->line('  '.__('settings.custom_reserved.check.no_routes_file'));
        } else {
            $rows = [];
            foreach ($status['routes']['registered'] as $route) {
                $rows[] = [$route['methods'], $route['uri'], $route['action'], __('settings.custom_reserved.check.registered')];
            }
            foreach ($status['routes']['rejected'] as $route) {
                $rows[] = [$route['methods'], $route['uri'], '-', __('settings.custom_reserved.check.rejected', ['reason' => $route['reason']])];
            }
            $this->table([__('settings.custom_reserved.check.col_methods'), __('settings.custom_reserved.check.col_uri'), __('settings.custom_reserved.check.col_action'), __('settings.custom_reserved.check.col_state')], $rows);
        }

        $this->info(__('settings.custom_reserved.check.patterns_title'));
        $this->line('  user : '.(implode(', ', $status['patterns']['user']) ?: '-'));
        $this->line('  admin: '.(implode(', ', $status['patterns']['admin']) ?: '-'));

        $this->info(__('settings.custom_reserved.check.packages_title'));
        if ($status['packages'] === []) {
            $this->line('  '.__('settings.custom_reserved.check.no_packages'));
        } else {
            $this->table(
                [__('settings.custom_reserved.check.col_package'), __('settings.custom_reserved.check.col_version')],
                array_map(fn ($name, $version) => [$name, $version], array_keys($status['packages']), $status['packages']),
            );
        }

        if ($status['conflicts'] === []) {
            $this->info(__('settings.custom_reserved.check.no_conflicts'));
        } else {
            $this->error(trans_choice('settings.custom_reserved.check.conflicts_title', count($status['conflicts'])));
            $this->table(
                [__('settings.core_update.custom_conflict_package'), __('settings.core_update.custom_conflict_reason'), __('settings.core_update.custom_conflict_core')],
                array_map(fn (array $c) => [
                    $c['package'].' ('.$c['installed'].')',
                    $c['type'] === CustomPackageConflictChecker::TYPE_DUPLICATE
                        ? __('settings.core_update.custom_conflict_duplicate')
                        : __('settings.core_update.custom_conflict_constraint', ['requires' => $c['requires'], 'constraint' => $c['constraint']]),
                    $c['core_package'].' '.$c['core_version'],
                ], $status['conflicts']),
            );
        }

        $this->line(__('settings.custom_reserved.check.developer_provider', ['state' => $status['developer_provider'] ? 'App\\Custom\\CustomServiceProvider' : '-']));

        return $problem ? self::FAILURE : self::SUCCESS;
    }
}
