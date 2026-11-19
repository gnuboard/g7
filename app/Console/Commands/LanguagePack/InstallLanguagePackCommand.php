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
 * 번들/GitHub/URL 소스에서 언어팩을 설치합니다.
 *
 * 모듈/플러그인/템플릿의 install 커맨드와 동일한 운영 도구.
 * `--source=bundled` 시 `lang-packs/_bundled/{identifier}` 디렉토리에서 설치하며,
 * 외부 다운로드 없이 코어 언어팩의 (재)설치/복구가 가능합니다.
 */
class InstallLanguagePackCommand extends Command
{
    use HandlesPackageIntegrity;
    use HasUnifiedConfirm;
    use LocalizesDescription;
    use ReportsLifecycleWarnings;

    /**
     * @var string
     */
    protected $signature = 'language-pack:install
        {identifier : 번들 식별자 또는 소스에 따른 식별자/URL}
        {--source=bundled : 설치 소스 (bundled|github|url)}
        {--no-activate : 설치 후 자동 활성화하지 않음}
        {--allow-unsigned : 서명되지 않은 언어팩(또는 이 서버에서 서명을 검증할 수 없는 언어팩)을 확인 없이 계속 — 답할 수 없는 실행은 이 옵션이 없으면 실패. 환경설정 「미서명 패키지 차단」이 켜져 있으면 무시}
        {--signature= : --source=url 일 때 서명 파일(.sig.json) 주소(https) — 생략하면 서명 없이 받음(주소를 추측하지 않음)}';

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

        $signatureUrl = $this->option('signature');
        $signatureUrl = is_string($signatureUrl) && $signatureUrl !== '' ? $signatureUrl : null;
        $integrityOptions = new PackageIntegrityOptions((bool) $this->option('allow-unsigned'));

        try {
            // 패키지 서명 검증 — 미서명이면 대화형은 묻고, 답할 수 없는 실행은 --allow-unsigned 없이는 실패(7.2.0)
            $pack = $this->runWithIntegrityConsent(fn (PackageIntegrityOptions $integrity) => match ($source) {
                'bundled' => $this->service->installFromBundled($identifier, $autoActivate),
                'github' => $this->service->installFromGithub($identifier, $autoActivate, null, false, $integrity),
                'url' => $this->service->installFromUrl($identifier, null, $autoActivate, null, false, $signatureUrl, $integrity),
                default => throw new \InvalidArgumentException(
                    __('language_packs.errors.unsupported_source', ['source' => $source])
                ),
            }, $integrityOptions);

            $this->info(__('language_packs.commands.install.done', [
                'identifier' => $pack->identifier,
                'version' => $pack->version,
                'status' => $pack->status,
            ]));

            $this->renderPackageIntegrity(['status' => $pack->integrity_status, 'key_id' => $pack->integrity_key_id, 'signer_label' => $this->signerLabelOf($pack->integrity_key_id)]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error(__('language_packs.commands.install.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }
    }
}
