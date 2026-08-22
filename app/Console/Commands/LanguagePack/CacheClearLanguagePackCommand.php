<?php

namespace App\Console\Commands\LanguagePack;

use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 언어팩 관련 캐시(레지스트리/Translator/템플릿/버전 스탬프)를 정리합니다.
 *
 * 모듈/플러그인/템플릿의 cache-clear 커맨드와 동일한 운영 도구.
 * _bundled 의 다국어 파일을 손으로 수정한 뒤 즉시 반영하려는 운영 시나리오에 사용합니다.
 */
class CacheClearLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:cache-clear';

    /**
     * @var string
     */
    protected $description = '언어팩 캐시(registry/translator/template/version)를 정리합니다.';

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
        try {
            $result = $this->service->refreshCache();
            $this->info('언어팩 캐시 정리 완료:');
            foreach ($result as $key => $ok) {
                $this->line('  - '.$key.': '.($ok ? 'ok' : 'failed'));
            }

            return collect($result)->every(fn ($v) => $v === true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('언어팩 캐시 정리 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
