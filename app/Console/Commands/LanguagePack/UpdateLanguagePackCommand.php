<?php

namespace App\Console\Commands\LanguagePack;

use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 언어팩을 업데이트합니다.
 *
 * 모듈/플러그인/템플릿의 update 커맨드와 동일한 운영 도구.
 * 동시성 가드 + 백업 + 롤백은 Service 의 performUpdate 가 담당합니다.
 *
 * 옵션:
 *  --force                       버전이 동일해도 강제 재적용 (확장 update --force 와 동일 의미)
 *  --source=auto|bundled|github  업데이트 소스 우선순위. auto(기본): force 시 bundled, 외 GitHub
 */
class UpdateLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:update
        {identifier : 언어팩 식별자}
        {--force : 버전 변경이 없어도 강제 재적용 (_bundled 우선)}
        {--source=auto : 업데이트 소스 (auto|bundled|github)}';

    /**
     * @var string
     */
    protected $description = '언어팩을 GitHub 또는 _bundled 신버전으로 업데이트합니다.';

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
        $source = (string) $this->option('source');

        if (! in_array($source, ['auto', 'bundled', 'github'], true)) {
            $this->error("알 수 없는 --source 값: {$source} (auto|bundled|github 중 선택)");

            return self::FAILURE;
        }

        $pack = $this->service->findByIdentifier($identifier);
        if (! $pack) {
            $this->error("언어팩을 찾을 수 없습니다: {$identifier}");

            return self::FAILURE;
        }

        try {
            $fromVersion = $pack->version;
            // source=bundled 는 force 와 동등 (bundled 1순위 강제). source=github 는 force 무시.
            $effectiveForce = $force || $source === 'bundled';
            $updated = $this->service->performUpdate($pack, $effectiveForce);

            $this->info(sprintf(
                '언어팩 업데이트 완료: %s (%s → %s)',
                $updated->identifier,
                $fromVersion,
                $updated->version,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('언어팩 업데이트 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
