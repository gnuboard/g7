<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\HasProgressBar;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstallTemplateCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:install
        {identifier : 템플릿 식별자 (디렉토리명)}
        {--force : 이미 설치된 경우에도 _bundled/_pending 원본으로 활성 디렉토리를 덮어쓰고 재설치 (불완전 설치 복구)}';

    /**
     * The console command description.
     */
    protected $description = '템플릿을 설치합니다';

    /**
     * 템플릿 관리자 및 리포지토리
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        // 식별자 형식 검증
        $validator = Validator::make(
            ['identifier' => $identifier],
            ['identifier' => [new ValidExtensionIdentifier]]
        );

        if ($validator->fails()) {
            $this->error('❌ '.$validator->errors()->first('identifier'));

            return Command::FAILURE;
        }

        $force = (bool) $this->option('force');

        try {
            // 템플릿 디렉토리 스캔 및 로드
            $this->templateManager->loadTemplates();

            // 이미 설치된 템플릿인지 확인 (force 시 경고 후 재설치/복구 허용)
            $existingTemplate = $this->templateRepository->findByIdentifier($identifier);
            if ($existingTemplate && $force) {
                $this->warn('⚠️  '.__('templates.commands.install.force_reinstall', ['template' => $identifier]));
            }

            // 템플릿 설치
            $onProgress = $this->createProgressCallback(TemplateManager::INSTALL_STEPS);
            try {
                $result = $this->templateManager->installTemplate($identifier, $onProgress, $force);
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                // 설치된 템플릿 정보 조회
                $template = $this->templateRepository->findByIdentifier($identifier);

                // 성공 메시지
                $this->info('✅ '.__('templates.commands.install.success', ['template' => $identifier]));
                $this->info('   - '.__('templates.commands.install.type', ['type' => __('templates.types.'.$template->type)]));
                $this->info('   - '.__('templates.commands.install.version', ['version' => $template->version]));
                $this->info('   - '.__('templates.commands.install.layouts_created', ['count' => $template->layouts()->count()]));

                Log::info(__('templates.commands.install.success', ['template' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 설치 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
