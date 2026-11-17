<?php

namespace App\Console\Commands\LanguagePack;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 언어팩을 제거합니다.
 *
 * 모듈/플러그인/템플릿의 uninstall 커맨드와 동일한 운영 도구.
 * 보호된 팩(`is_protected=true`)은 차단되며, `--force` 없이는 yes/no 확인을 요구합니다.
 */
class UninstallLanguagePackCommand extends Command
{
    use HasUnifiedConfirm;
    use LocalizesDescription;
    use ReportsLifecycleWarnings;

    /**
     * @var string
     */
    protected $signature = 'language-pack:uninstall
        {identifier : 언어팩 식별자}
        {--cascade : 코어 팩 제거 시 동일 locale 의 module/plugin/template 팩도 inactive 로 강등}
        {--force : 확인 없이 즉시 제거}
        {--yes : 확인 질문만 생략 (--force 의 다른 효과 없이)}';

    /**
     * @param  LanguagePackService  $service  언어팩 Service
     */
    public function __construct(
        private readonly LanguagePackService $service,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $identifier = (string) $this->argument('identifier');
        $cascade = (bool) $this->option('cascade');
        $force = (bool) $this->option('force');

        $pack = $this->service->findByIdentifier($identifier);
        if (! $pack) {
            $this->error(__('language_packs.commands.uninstall.not_found', ['identifier' => $identifier]));

            return self::FAILURE;
        }

        // 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 종전에 기본값 「아니오」로 취소 문구만 내고
        // 종료 코드 0 으로 끝나 자동화가 성공으로 읽었다. 그런 실행은 사유와 --force 안내를 남기고 실패로 끝낸다.
        // 대화형에서 운영자가 직접 「아니오」를 답한 취소는 의도이므로 종전대로 0 이다.
        if (! $force) {
            if (! $this->canAnswerConfirmation()) {
                return $this->refuseUnanswerableConfirmation(
                    __('language_packs.commands.uninstall.confirm_unavailable', ['identifier' => $identifier]),
                    __('language_packs.commands.uninstall.confirm_unavailable_hint', ['identifier' => $identifier]),
                );
            }

            if (! $this->unifiedConfirm(__('language_packs.commands.uninstall.confirm', ['identifier' => $identifier]), false)) {
                $this->info(__('language_packs.commands.uninstall.cancelled'));

                return self::SUCCESS;
            }
        }

        try {
            $this->service->uninstall($pack, $cascade);
            $this->info(__('language_packs.commands.uninstall.done', ['identifier' => $identifier]));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error(__('language_packs.commands.uninstall.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }
    }
}
