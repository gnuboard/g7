<?php

namespace App\Console\Commands\LanguagePack;

use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 번들/GitHub/URL 소스에서 언어팩을 설치합니다.
 *
 * 모듈/플러그인/템플릿의 install 커맨드와 동일한 운영 도구.
 * `--source=bundled` 시 `lang-packs/_bundled/{identifier}` 디렉토리에서 설치하며,
 * 외부 다운로드 없이 코어 언어팩의 (재)설치/복구가 가능합니다.
 */
class InstallLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:install
        {identifier : 번들 식별자 또는 소스에 따른 식별자/URL}
        {--source=bundled : 설치 소스 (bundled|github|url)}
        {--no-activate : 설치 후 자동 활성화하지 않음}';

    /**
     * @var string
     */
    protected $description = '번들/GitHub/URL 소스에서 언어팩을 설치합니다.';

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
        $source = (string) $this->option('source');
        $autoActivate = ! (bool) $this->option('no-activate');

        try {
            $pack = match ($source) {
                'bundled' => $this->service->installFromBundled($identifier, $autoActivate),
                'github' => $this->service->installFromGithub($identifier, $autoActivate),
                'url' => $this->service->installFromUrl($identifier, null, $autoActivate),
                default => throw new \InvalidArgumentException(
                    __('language_packs.errors.unsupported_source', ['source' => $source])
                ),
            };

            $this->info(sprintf(
                '언어팩 설치 완료: %s v%s (status=%s)',
                $pack->identifier,
                $pack->version,
                $pack->status,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('언어팩 설치 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
