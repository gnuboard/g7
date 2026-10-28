<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Concerns\RunsCascadeInstall;
use App\Console\Commands\Concerns\SelectsLayoutInstallSource;
use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\RendersLayoutSyncResult;
use App\Console\Commands\Traits\ReportsIntegrationNotices;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstallTemplateCommand extends Command
{
    use HasProgressBar;
    use LocalizesDescription;
    use RendersLayoutSyncResult;
    use ReportsIntegrationNotices;
    use ReportsLifecycleWarnings;
    use RunsCascadeInstall;
    use SelectsLayoutInstallSource;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:install
        {identifier : 템플릿 식별자 (디렉토리명)}
        {--force : 이미 설치된 경우에도 _bundled/_pending 원본으로 활성 디렉토리를 덮어쓰고 재설치 (불완전 설치 복구)}
        {--layout-source= : 레이아웃 설치 소스 (auto|json) — 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따름. json 은 짝 .json 이 있으면 .g7.html 대신 원본 JSON 으로 등록(진단·복구용)}
        {--with-dependencies : 필요한 의존 확장(의존의 의존 포함)을 같은 vendor 방식으로 함께 설치·활성화}
        {--with-language-packs=none : 함께 설치할 번들 언어팩 (none|all|식별자,…) — 관리자 설치 창의 후보와 같음}';

    /**
     * 템플릿 관리자 및 리포지토리
     *
     * @param  TemplateManager  $templateManager  템플릿 설치를 수행하는 매니저
     * @param  TemplateRepositoryInterface  $templateRepository  설치 결과 조회 리포지토리
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // `--layout-source` 검증 + 런타임 반영 (알 수 없는 값이면 즉시 실패).
        if (! $this->applyLayoutInstallSource()) {
            return self::FAILURE;
        }

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

            // 의존 확장 확인 — --with-dependencies 면 함께 설치·활성화 (템플릿은 vendor 없음 → auto)
            if (! $this->runCascadeDependencies('template', $identifier, VendorMode::Auto)) {
                return Command::FAILURE;
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
                $this->info($this->lifecycleDoneIcon().' '.__('templates.commands.install.success', ['template' => $identifier]));
                $this->reportIntegrationNotices('template', $identifier);
                $this->info('   - '.__('templates.commands.install.type', ['type' => __('templates.types.'.$template->type)]));
                $this->info('   - '.__('templates.commands.install.version', ['version' => $template->version]));
                $this->info('   - '.trans_choice('templates.commands.install.layouts_created', $template->layouts()->count()));
                $this->renderLayoutIncludeWarnings($this->templateManager->takeLastLayoutWarnings());

                Log::info(__('templates.commands.install.success', ['template' => $identifier]));

                // 동반 번들 언어팩 (--with-language-packs) — best-effort
                $this->runCascadeLanguagePacks();

                return Command::SUCCESS;
            }

            $this->reportKeptCascadeDependencies();

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            $this->reportKeptCascadeDependencies();
            Log::error('템플릿 설치 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
