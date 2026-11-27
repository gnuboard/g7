<?php

namespace App\Console\Commands\LanguagePack;

use App\Console\Commands\Concerns\HandlesPackageIntegrity;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ReportsLifecycleWarnings;
use App\Extension\Integrity\PackageIntegrityOptions;
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
 *  --yes                         확인 질문만 생략 — 이 명령은 진행 확인을 묻지 않아 효과가 없다. 다른 `{type}:update` 와
 *                                같은 형태(`-y`)로 부르는 일괄 갱신 스크립트가 언어팩에서만 「옵션 없음」으로 실패하지 않게 받는다.
 *                                미서명 패키지 확인은 대신하지 않는다(`--allow-unsigned`)
 */
class UpdateLanguagePackCommand extends Command
{
    use HandlesPackageIntegrity;
    use HasUnifiedConfirm;
    use LocalizesDescription;
    use ReportsLifecycleWarnings;

    /**
     * @var string
     */
    protected $signature = 'language-pack:update
        {identifier : 언어팩 식별자}
        {--force : 버전 변경이 없어도 강제 재적용 (_bundled 우선)}
        {--source=auto : 업데이트 소스 (auto|bundled|github)}
        {--y|yes : 확인 질문만 생략 — 이 명령은 진행 확인을 묻지 않음. module·plugin·template:update 와 같은 형태로 부르는 스크립트를 위해 받음}
        {--allow-unsigned : GitHub 에서 받은 새 판이 서명되지 않았어도(또는 이 서버에서 검증할 수 없어도) 확인 없이 계속 — 답할 수 없는 실행은 이 옵션이 없으면 실패. --force·--yes 는 이 확인을 대신하지 않음}';

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
            $this->error(__('language_packs.commands.update.invalid_source', ['source' => $source]));

            return self::FAILURE;
        }

        $pack = $this->service->findByIdentifier($identifier);
        if (! $pack) {
            $this->error(__('language_packs.commands.update.not_found', ['identifier' => $identifier]));

            return self::FAILURE;
        }

        try {
            $fromVersion = $pack->version;
            // source=bundled 는 force 와 동등 (bundled 1순위 강제). source 는 서비스로 넘겨 그 소스만 쓰게 한다 —
            // 종전에는 넘기지 않아 --source=github 이 실제 효과가 없었다(--force 와 함께면 번들을 썼다, 7.2.1 출시 전).
            $effectiveForce = $force || $source === 'bundled';
            // 패키지 서명 검증 — 미서명이면 대화형은 묻고, 답할 수 없는 실행은 --allow-unsigned 없이는 실패(7.2.0)
            $updated = $this->runWithIntegrityConsent(
                fn (PackageIntegrityOptions $integrity) => $this->service->performUpdate($pack, $effectiveForce, $integrity, $source),
                new PackageIntegrityOptions((bool) $this->option('allow-unsigned'))
            );

            $this->info(__('language_packs.commands.update.done', [
                'identifier' => $updated->identifier,
                'from' => $fromVersion,
                'to' => $updated->version,
            ]));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error(__('language_packs.commands.update.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }
    }
}
