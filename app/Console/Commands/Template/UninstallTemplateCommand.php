<?php

namespace App\Console\Commands\Template;

use App\Console\Commands\Traits\HasProgressBar;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsIntegrationNotices;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Helpers\TemplateLastActiveGuard;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UninstallTemplateCommand extends Command
{
    use HasProgressBar;
    use HasUnifiedConfirm;
    use LocalizesDescription;
    use ReportsIntegrationNotices;
    use ReportsLifecycleWarnings;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:uninstall
        {identifier : 템플릿 식별자}
        {--force : 확인 없이 삭제}
        {--y|yes : 확인 질문만 생략 (--force 의 다른 효과 없이)}
        {--delete-data : 「데이터도 삭제」를 선택한 것으로 제거 (활동 로그·제거 구독 확장에 전달)}';

    /**
     * 템플릿 관리자 및 리포지토리
     *
     * @param  TemplateManager  $templateManager  템플릿 관리자
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 저장소
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * 템플릿 제거를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        try {
            // 템플릿 디렉토리 스캔 및 로드
            $this->templateManager->loadTemplates();

            // 템플릿 존재 확인
            $template = $this->templateRepository->findByIdentifier($identifier);

            if (! $template) {
                $this->error('❌ '.__('templates.commands.activate.not_installed', ['template' => $identifier]));

                return Command::FAILURE;
            }

            // 유형의 마지막 활성 템플릿은 지우지 않는다 — 관리자 화면 경로와 같은 판정(TemplateLastActiveGuard). 지우면 그 화면
            // 전체가 그려지지 않는다. --force 는 확인 질문만 건너뛴다. 확인을 묻기 전에 거절해 답한 뒤에 막히지 않게 한다
            if (TemplateLastActiveGuard::isLastActiveOfType($template, $this->templateRepository)) {
                $this->error('❌ '.TemplateLastActiveGuard::message($template, 'templates.errors.last_active_uninstall'));

                return Command::FAILURE;
            }

            // 삭제 확인 프롬프트 (--force 면 묻지 않는다 — 모듈·플러그인 제거 명령과 같은 옵션, #138 실측 ⑤)
            if (! $this->option('force')) {
                $this->warn(__('templates.commands.uninstall.confirm_prompt', ['template' => $identifier]));
                $this->warn(trans_choice('templates.commands.uninstall.confirm_details.layouts', $template->layouts()->count()));
                $this->warn(__('templates.commands.uninstall.confirm_details.versions'));
                if ($this->option('delete-data')) {
                    $this->warn(__('templates.commands.uninstall.delete_data_notice'));
                }

                // 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 종전에 기본값 「아니오」로 취소 문구만 내고
                // 종료 코드 0 으로 끝나 자동화가 성공으로 읽었다. 그런 실행은 사유와 --force 안내를 남기고 실패로 끝낸다.
                // 대화형에서 운영자가 직접 「아니오」를 답한 취소는 의도이므로 종전대로 0 이다.
                if (! $this->canAnswerConfirmation()) {
                    return $this->refuseUnanswerableConfirmation(
                        '❌ '.__('templates.commands.uninstall.confirm_unavailable', ['template' => $identifier]),
                        __('templates.commands.uninstall.confirm_unavailable_hint', ['template' => $identifier]),
                    );
                }

                if (! $this->unifiedConfirm(__('templates.commands.uninstall.confirm_question'), false)) {
                    $this->info(__('templates.commands.uninstall.aborted'));

                    return $this->confirmAbortExitCode();
                }
            }

            // 레이아웃 및 버전 개수 조회
            $layoutsCount = $template->layouts()->count();

            // 템플릿 삭제
            $onProgress = $this->createProgressCallback(TemplateManager::UNINSTALL_STEPS);
            try {
                // 관리자 화면의 「데이터도 삭제」와 같은 값 — 템플릿 제거 동작은 바꾸지 않고 after_uninstall 훅 인자로 전달된다
                $result = $this->templateManager->uninstallTemplate(
                    $identifier,
                    $onProgress,
                    $preservedBackups,
                    (bool) $this->option('delete-data'),
                );
                $this->finishProgress();
            } catch (\Exception $e) {
                $this->finishProgress();
                throw $e;
            }

            if ($result) {
                // 성공 메시지
                $this->info($this->lifecycleDoneIcon().' '.__('templates.commands.uninstall.success', ['template' => $identifier]));
                $this->reportIntegratedBy('template', $identifier);
                $this->info('   - '.trans_choice('templates.commands.uninstall.layouts_deleted', $layoutsCount));

                // 운영자가 넣은 `custom/` 은 삭제 전에 사본을 남긴다 — 그 경로를 알리지 않으면
                // 로그를 뒤지지 않는 한 사본의 존재를 알 수 없다.
                foreach ($preservedBackups ?? [] as $backup) {
                    $this->info('   - '.__('templates.commands.uninstall.custom_preserved', [
                        'directory' => $backup['directory'],
                        'archive' => $backup['archive'],
                    ]));
                }

                Log::info(__('templates.commands.uninstall.success', ['template' => $identifier]));

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());
            Log::error('템플릿 삭제 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
