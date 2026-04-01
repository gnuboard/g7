<?php

namespace App\Console\Commands\Module;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use Illuminate\Console\Command;

class ListModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'module:list
        {--status= : 상태로 필터 (installed, uninstalled, active, inactive)}';

    /**
     * The console command description.
     */
    protected $description = '모듈 목록을 조회합니다';

    /**
     * 모듈 관리자 및 리포지토리
     */
    public function __construct(
        private ModuleManager $moduleManager,
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 모듈 디렉토리 스캔 및 로드
        $this->moduleManager->loadModules();

        $statusFilter = $this->option('status');

        // 상태 필터 검증
        if ($statusFilter && ! in_array($statusFilter, ['installed', 'uninstalled', 'active', 'inactive'])) {
            $this->error('❌ '.__('modules.commands.list.invalid_status'));

            return Command::FAILURE;
        }

        // 설치된 모듈 정보
        $installedModules = $this->moduleManager->getInstalledModulesWithDetails();

        // 미설치 모듈 정보
        $uninstalledModules = $this->moduleManager->getUninstalledModules();

        // 테이블 데이터 준비
        $tableData = [];

        // 설치된 모듈 추가
        foreach ($installedModules as $identifier => $module) {
            // 상태 필터
            if ($statusFilter) {
                if ($statusFilter === 'uninstalled') {
                    continue;
                }
                if ($statusFilter === 'active' && $module['status'] !== ExtensionStatus::Active->value) {
                    continue;
                }
                if ($statusFilter === 'inactive' && $module['status'] !== ExtensionStatus::Inactive->value) {
                    continue;
                }
                if ($statusFilter === 'installed' && ! in_array($module['status'], [ExtensionStatus::Active->value, ExtensionStatus::Inactive->value])) {
                    continue;
                }
            }

            $tableData[] = [
                'identifier' => $identifier,
                'name' => $module['name'],
                'vendor' => $module['vendor'],
                'version' => $module['version'],
                'status' => $this->formatStatus($module['status']),
            ];
        }

        // 미설치 모듈 추가
        if (! $statusFilter || $statusFilter === 'uninstalled') {
            foreach ($uninstalledModules as $identifier => $module) {
                // 상태 필터 (uninstalled 또는 필터 없음)
                if ($statusFilter && $statusFilter !== 'uninstalled') {
                    continue;
                }

                $tableData[] = [
                    'identifier' => $identifier,
                    'name' => $module['name'],
                    'vendor' => $module['vendor'],
                    'version' => $module['version'],
                    'status' => $this->formatStatus('uninstalled'),
                ];
            }
        }

        // 모듈이 없는 경우
        if (empty($tableData)) {
            $this->info(__('modules.commands.list.no_modules'));

            return Command::SUCCESS;
        }

        // 테이블 헤더
        $headers = [
            __('modules.commands.list.headers.identifier'),
            __('modules.commands.list.headers.name'),
            __('modules.commands.list.headers.vendor'),
            __('modules.commands.list.headers.version'),
            __('modules.commands.list.headers.status'),
        ];

        // 테이블 출력
        $this->table($headers, $tableData);

        // 요약 정보
        $totalCount = count($tableData);
        $activeCount = count(array_filter($tableData, fn ($m) => str_contains($m['status'], __('modules.commands.list.status.active'))));
        $installedCount = count(array_filter($tableData, fn ($m) => ! str_contains($m['status'], __('modules.commands.list.status.uninstalled'))));

        $this->newLine();
        $this->info(__('modules.commands.list.summary', [
            'total' => $totalCount,
            'installed' => $installedCount,
            'active' => $activeCount,
        ]));

        return Command::SUCCESS;
    }

    /**
     * 상태 포맷팅
     */
    private function formatStatus(string $status): string
    {
        return match ($status) {
            ExtensionStatus::Active->value => '✅ '.__('modules.commands.list.status.active'),
            ExtensionStatus::Inactive->value => '⏸️  '.__('modules.commands.list.status.inactive'),
            'uninstalled' => '📦 '.__('modules.commands.list.status.uninstalled'),
            default => $status,
        };
    }
}
