<?php

namespace App\Console\Commands\Plugin;

use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPluginUpdatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plugin:check-updates {identifier? : 특정 플러그인만 확인 (선택)}';

    /**
     * The console command description.
     */
    protected $description = '플러그인 업데이트를 확인합니다';

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
        $identifier = $this->argument('identifier');

        try {
            $this->pluginManager->loadPlugins();

            if ($identifier) {
                return $this->checkSingle($identifier);
            }

            return $this->checkAll();
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('플러그인 업데이트 확인 실패', [
                'plugin' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 단일 플러그인 업데이트를 확인합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     */
    private function checkSingle(string $identifier): int
    {
        $plugin = $this->pluginRepository->findByIdentifier($identifier);

        if (! $plugin) {
            $this->error('❌ '.__('plugins.commands.check_updates.not_installed', ['plugin' => $identifier]));

            return Command::FAILURE;
        }

        $result = $this->pluginManager->checkPluginUpdate($identifier);

        // 단일 체크는 DB 갱신이 안 되므로 커맨드에서 직접 갱신
        $this->pluginRepository->updateByIdentifier($identifier, [
            'update_available' => $result['update_available'],
            'latest_version' => $result['latest_version'],
            'update_source' => $result['update_source'],
        ]);

        if ($result['update_available']) {
            $this->info('🔄 '.__('plugins.commands.check_updates.single_update_available', [
                'plugin' => $identifier,
                'current' => $result['current_version'],
                'latest' => $result['latest_version'],
                'source' => $result['update_source'],
            ]));
        } else {
            $this->info('✅ '.__('plugins.commands.check_updates.single_up_to_date', [
                'plugin' => $identifier,
                'version' => $result['current_version'],
            ]));
        }

        return Command::SUCCESS;
    }

    /**
     * 모든 설치된 플러그인의 업데이트를 확인합니다.
     */
    private function checkAll(): int
    {
        $installedPlugins = $this->pluginManager->getInstalledPluginsWithDetails();

        if (empty($installedPlugins)) {
            $this->info(__('plugins.commands.check_updates.no_installed'));

            return Command::SUCCESS;
        }

        // checkAllPluginsForUpdates는 내부에서 DB 갱신 포함
        $result = $this->pluginManager->checkAllPluginsForUpdates();

        $tableData = [];
        $updateCount = 0;

        foreach ($result['details'] as $detail) {
            $isUpdate = $detail['update_available'] ?? false;
            if ($isUpdate) {
                $updateCount++;
            }

            $tableData[] = [
                $detail['identifier'],
                $detail['current_version'] ?? '-',
                $detail['latest_version'] ?? '-',
                $detail['update_source'] ?? '-',
                $isUpdate
                    ? '🔄 '.__('plugins.commands.check_updates.update_available')
                    : '✅ '.__('plugins.commands.check_updates.up_to_date'),
            ];
        }

        $headers = [
            __('plugins.commands.check_updates.headers.identifier'),
            __('plugins.commands.check_updates.headers.current_version'),
            __('plugins.commands.check_updates.headers.latest_version'),
            __('plugins.commands.check_updates.headers.source'),
            __('plugins.commands.check_updates.headers.status'),
        ];

        $this->table($headers, $tableData);
        $this->newLine();
        $this->info(__('plugins.commands.check_updates.summary', [
            'total' => count($result['details']),
            'updates' => $updateCount,
        ]));

        return Command::SUCCESS;
    }
}
