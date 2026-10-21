<?php

namespace App\Console\Commands\LanguagePack;

use App\Console\Commands\Traits\HasUnifiedConfirm;
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

    /**
     * @var string
     */
    protected $signature = 'language-pack:uninstall
        {identifier : 언어팩 식별자}
        {--cascade : 코어 팩 제거 시 동일 locale 의 module/plugin/template 팩도 inactive 로 강등}
        {--force : 확인 없이 즉시 제거}';

    /**
     * @var string
     */
    protected $description = '언어팩을 제거합니다.';

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

        if (! $force && ! $this->unifiedConfirm(__('language_packs.commands.uninstall.confirm', ['identifier' => $identifier]), false)) {
            $this->info(__('language_packs.commands.uninstall.cancelled'));

            return self::SUCCESS;
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
