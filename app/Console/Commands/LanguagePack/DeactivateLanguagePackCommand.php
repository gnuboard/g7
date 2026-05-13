<?php

namespace App\Console\Commands\LanguagePack;

use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 언어팩을 비활성화합니다.
 *
 * 모듈/플러그인/템플릿의 deactivate 커맨드와 동일한 운영 도구.
 * 보호된 팩(is_protected=true)은 거부되며, 호스트 cascade 컨텍스트에서만 우회됩니다.
 */
class DeactivateLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:deactivate
        {identifier : 언어팩 식별자}';

    /**
     * @var string
     */
    protected $description = '언어팩을 비활성화합니다.';

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

        $pack = $this->service->findByIdentifier($identifier);
        if (! $pack) {
            $this->error("언어팩을 찾을 수 없습니다: {$identifier}");

            return self::FAILURE;
        }

        try {
            $this->service->deactivate($pack);
            $this->info("언어팩 비활성화 완료: {$identifier}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('언어팩 비활성화 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
