<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\PluginManager;
use Illuminate\Console\Command;

class ListPluginCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:list
        {--status= : 상태로 필터 (installed, uninstalled, active, inactive)}
        {--hidden : 숨김(hidden=true) 플러그인도 함께 출력}';

    /**
     * The console command description.
     */
    protected $description = '플러그인 목록을 조회합니다';

    /**
     * 플러그인 관리자 및 리포지토리
     */
    public function __construct(
        private PluginManager $pluginManager,
        private PluginRepositoryInterface $pluginRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 플러그인 디렉토리 스캔 및 로드
        $this->pluginManager->loadPlugins();

        $statusFilter = $this->option('status');
        $includeHidden = (bool) $this->option('hidden');

        // 상태 필터 검증
        if ($statusFilter && ! in_array($statusFilter, ['installed', 'uninstalled', 'active', 'inactive'])) {
            $this->error('❌ '.__('plugins.commands.list.invalid_status'));

            return Command::FAILURE;
        }

        // 설치된 플러그인 정보
        $installedPlugins = $this->pluginManager->getInstalledPluginsWithDetails();

        // 미설치 플러그인 정보
        $uninstalledPlugins = $this->pluginManager->getUninstalledPlugins();

        // 테이블 데이터 준비
        $tableData = [];

        // 설치된 플러그인 추가
        foreach ($installedPlugins as $identifier => $plugin) {
            // 숨김 필터: --hidden 미지정 시 hidden=true 플러그인 제외
            if (! $includeHidden && ! empty($plugin['hidden'])) {
                continue;
            }

            // 상태 필터
            if ($statusFilter) {
                if ($statusFilter === 'uninstalled') {
                    continue;
                }
                if ($statusFilter === 'active' && $plugin['status'] !== ExtensionStatus::Active->value) {
                    continue;
                }
                if ($statusFilter === 'inactive' && $plugin['status'] !== ExtensionStatus::Inactive->value) {
                    continue;
                }
                if ($statusFilter === 'installed' && ! in_array($plugin['status'], [ExtensionStatus::Active->value, ExtensionStatus::Inactive->value])) {
                    continue;
                }
            }

            $tableData[] = [
                'identifier' => $identifier,
                'name' => $plugin['name'],
                'vendor' => $plugin['vendor'],
                'version' => $plugin['version'],
                'status' => $this->formatStatus($plugin['status']),
            ];
        }

        // 미설치 플러그인 추가
        if (! $statusFilter || $statusFilter === 'uninstalled') {
            foreach ($uninstalledPlugins as $identifier => $plugin) {
                // 숨김 필터: --hidden 미지정 시 hidden=true 플러그인 제외
                if (! $includeHidden && ! empty($plugin['hidden'])) {
                    continue;
                }

                // 상태 필터 (uninstalled 또는 필터 없음)
                if ($statusFilter && $statusFilter !== 'uninstalled') {
                    continue;
                }

                $tableData[] = [
                    'identifier' => $identifier,
                    'name' => $plugin['name'],
                    'vendor' => $plugin['vendor'],
                    'version' => $plugin['version'],
                    'status' => $this->formatStatus('uninstalled'),
                ];
            }
        }

        // 플러그인이 없는 경우
        if (empty($tableData)) {
            $this->info(__('plugins.commands.list.no_plugins'));

            return Command::SUCCESS;
        }

        // 테이블 헤더
        $headers = [
            __('plugins.commands.list.headers.identifier'),
            __('plugins.commands.list.headers.name'),
            __('plugins.commands.list.headers.vendor'),
            __('plugins.commands.list.headers.version'),
            __('plugins.commands.list.headers.status'),
        ];

        // 테이블 출력
        $this->table($headers, $tableData);

        // 요약 정보
        $totalCount = count($tableData);
        $activeCount = count(array_filter($tableData, fn ($p) => str_contains($p['status'], __('plugins.commands.list.status.active'))));
        $installedCount = count(array_filter($tableData, fn ($p) => ! str_contains($p['status'], __('plugins.commands.list.status.uninstalled'))));

        $this->newLine();
        $this->info(__('plugins.commands.list.summary', [
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
            ExtensionStatus::Active->value => '✅ '.__('plugins.commands.list.status.active'),
            ExtensionStatus::Inactive->value => '⏸️  '.__('plugins.commands.list.status.inactive'),
            'uninstalled' => '📦 '.__('plugins.commands.list.status.uninstalled'),
            default => $status,
        };
    }
}
