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
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $noDev = $this->option('no-dev');
        $noDevOption = $noDev ? ' --no-dev' : '';

        $this->info('📦 '.__('extensions.commands.composer_install.start'));
        $this->line('');

        // 모듈 Composer 설치
        $this->info('=== '.__('extensions.commands.composer_install.section_modules').' ===');
        $moduleResult = $this->call('module:composer-install', [
            '--all' => true,
            '--no-dev' => $noDev,
        ]);
        $this->line('');

        // 플러그인 Composer 설치
        $this->info('=== '.__('extensions.commands.composer_install.section_plugins').' ===');
        $pluginResult = $this->call('plugin:composer-install', [
            '--all' => true,
            '--no-dev' => $noDev,
        ]);
        $this->line('');

        if ($moduleResult === Command::SUCCESS && $pluginResult === Command::SUCCESS) {
            $this->info('✅ '.__('extensions.commands.composer_install.done'));

            return Command::SUCCESS;
        }

        $this->warn('⚠️  '.__('extensions.commands.composer_install.partial_failure'));

        return Command::FAILURE;
    }
}
