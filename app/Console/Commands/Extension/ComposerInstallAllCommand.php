<?php

namespace App\Console\Commands\Extension;

use Illuminate\Console\Command;

class ComposerInstallAllCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'extension:composer-install
                            {--no-dev : dev 의존성 제외}';

    /**
     * The console command description.
     */
    protected $description = '모든 모듈과 플러그인의 Composer 의존성을 설치합니다';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $noDev = $this->option('no-dev');
        $noDevOption = $noDev ? ' --no-dev' : '';

        $this->info('📦 모든 확장의 Composer 의존성 설치 시작');
        $this->line('');

        // 모듈 Composer 설치
        $this->info('=== 모듈 ===');
        $moduleResult = $this->call('module:composer-install', [
            '--all' => true,
            '--no-dev' => $noDev,
        ]);
        $this->line('');

        // 플러그인 Composer 설치
        $this->info('=== 플러그인 ===');
        $pluginResult = $this->call('plugin:composer-install', [
            '--all' => true,
            '--no-dev' => $noDev,
        ]);
        $this->line('');

        if ($moduleResult === Command::SUCCESS && $pluginResult === Command::SUCCESS) {
            $this->info('✅ 모든 확장의 Composer 의존성 설치 완료');

            return Command::SUCCESS;
        }

        $this->warn('⚠️  일부 확장의 Composer 의존성 설치에 실패했습니다.');

        return Command::FAILURE;
    }
}
