<?php

namespace App\Console\Commands\LanguagePack;

use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 언어팩을 활성화합니다.
 *
 * 모듈/플러그인/템플릿의 activate 커맨드와 동일한 운영 도구.
 * 동일 슬롯의 다른 활성 팩은 자동으로 inactive 로 전환됩니다.
 */
class ActivateLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:activate
        {identifier : 언어팩 식별자}
        {--force : 의존성/검증 경고를 무시하고 강제 활성화}';

    /**
     * @var string
     */
    protected $description = '언어팩을 활성화합니다.';

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
        $force = (bool) $this->option('force');

        $pack = $this->service->findByIdentifier($identifier);
        if (! $pack) {
            $this->error("언어팩을 찾을 수 없습니다: {$identifier}");

            return self::FAILURE;
        }

        try {
            $this->service->activate($pack, $force);
            $this->info("언어팩 활성화 완료: {$identifier}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('언어팩 활성화 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
