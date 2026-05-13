<?php

namespace App\Console\Commands\Extension;

use App\Extension\ExtensionManager;
use Illuminate\Console\Command;

class UpdateAutoloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'extension:update-autoload';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '모듈과 플러그인의 오토로드 캐시 파일을 생성합니다 (bootstrap/cache/autoload-extensions.php)';

    /**
     * Execute the console command.
     */
    public function handle(ExtensionManager $extensionManager): int
    {
        $this->info('확장 오토로드 파일을 생성합니다...');

        try {
            $extensionManager->generateAutoloadFile();

            $this->info('오토로드 파일이 성공적으로 생성되었습니다.');
            $this->line('  → bootstrap/cache/autoload-extensions.php');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('오토로드 파일 생성 중 오류가 발생했습니다: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
