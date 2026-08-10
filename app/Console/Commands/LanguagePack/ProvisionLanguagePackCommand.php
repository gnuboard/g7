<?php

namespace App\Console\Commands\LanguagePack;

use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackBaseLocales;
use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 정책(supported_locales) 기준으로 미설치 번들 언어팩을 멱등 설치(프로비저닝)합니다.
 *
 * fresh install / 시더 / 수동 복구가 공유하는 단일 프로비저닝 SSoT 입니다.
 * base locale(ko/en)은 가상 보호 행으로 항상 서빙되므로 대상에서 제외되고,
 * 나머지 로케일(예: ja)은 `lang-packs/_bundled/` 에 소스가 있어도 설치본 디렉토리로
 * 복사·등록되어야 서빙되므로 이 커맨드가 그 프로비저닝을 담당합니다.
 *
 * 대상은 두 갈래입니다 — 미설치 번들 팩(신규 프로비저닝)과, 설치 행은 있으나 설치본 파일이
 * 사라진 드리프트 팩(수동 복구). 후자는 슬롯이 점유돼 있어 `getUninstalledBundledPacks()` 가
 * 잡지 못하므로 `getDriftedInstalledPacks()` 로 따로 모읍니다. 이 커맨드가 복구 경로를 겸하지 않으면
 * 드리프트는 화면에서 보이기만 하고 CLI 로는 고칠 수 없습니다.
 *
 * 멱등성: 정상 설치된 팩은 두 집합 어디에도 속하지 않으므로 재실행 시 신규 설치 0 건으로 수렴합니다.
 */
class ProvisionLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:provision
        {--locale=* : 대상 로케일(미지정 시 supported_locales 에서 base locale 제외)}
        {--scope=* : core/module/plugin/template 로 대상 스코프 한정}
        {--no-activate : 설치 후 자동 활성화하지 않음}';

    /**
     * @var string
     */
    protected $description = '정책(supported_locales) 기준 미설치 번들 언어팩을 멱등 설치합니다.';

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
        $targetLocales = $this->resolveTargetLocales();
        if (empty($targetLocales)) {
            $this->info('프로비저닝 대상 로케일이 없습니다 (base locale 외 supported_locales 미선언).');

            return self::SUCCESS;
        }

        $scopeFilter = array_values(array_filter(array_map('strval', (array) $this->option('scope'))));
        $autoActivate = ! (bool) $this->option('no-activate');

        $inScope = fn (LanguagePack $pack) => in_array($pack->locale, $targetLocales, true)
            && (empty($scopeFilter) || in_array($pack->scope, $scopeFilter, true));

        // 미설치 번들(신규 프로비저닝) + 드리프트 설치 행(수동 복구) 이 모두 대상이다.
        // 드리프트 행은 슬롯이 점유돼 있어 getUninstalledBundledPacks() 가 잡지 못하므로 따로 합친다.
        $candidates = $this->service->getUninstalledBundledPacks()->filter($inScope)
            ->concat($this->service->getDriftedInstalledPacks()->filter($inScope))
            ->values();

        if ($candidates->isEmpty()) {
            $this->info(sprintf(
                '설치하거나 복구할 번들 언어팩이 없습니다 (로케일: %s). 이미 프로비저닝된 상태입니다.',
                implode(', ', $targetLocales),
            ));

            return self::SUCCESS;
        }

        $installed = 0;
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $pack) {
            $identifier = (string) ($pack->getAttribute('bundled_identifier') ?? $pack->identifier);
            $blockedReason = $pack->getAttribute('install_blocked_reason');

            // 대상 확장 미설치/미활성 등으로 설치가 불가한 팩은 best-effort 로 건너뛴다.
            if ($blockedReason !== null && $blockedReason !== '') {
                $this->warn(sprintf('건너뜀 (설치 불가: %s): %s', $blockedReason, $identifier));
                $skipped++;

                continue;
            }

            try {
                $isRepair = (bool) $pack->getAttribute('files_missing');
                $result = $this->service->installFromBundled($identifier, $autoActivate);
                $this->info(sprintf(
                    '%s: %s v%s (status=%s)',
                    $isRepair ? '복구' : '설치',
                    $result->identifier,
                    $result->version,
                    $result->status,
                ));
                $isRepair ? $repaired++ : $installed++;
            } catch (Throwable $e) {
                $this->warn(sprintf('실패 (건너뜀): %s — %s', $identifier, $e->getMessage()));
                $failed++;
            }
        }

        $this->info(sprintf(
            '프로비저닝 완료 — 설치 %d, 복구 %d, 건너뜀 %d, 실패 %d',
            $installed,
            $repaired,
            $skipped,
            $failed,
        ));

        // 성과가 하나도 없고 실패만 있었다면 실패로 보고 (멱등 재실행/전량 스킵은 성공).
        return $installed === 0 && $repaired === 0 && $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 프로비저닝 대상 로케일을 해석합니다.
     *
     * `--locale` 지정 시 그 값, 미지정 시 `config('app.supported_locales')` 에서 base locale(ko/en)을 제외한 집합.
     *
     * @return array<int, string> 대상 로케일 목록 (base locale 제외)
     */
    private function resolveTargetLocales(): array
    {
        $explicit = array_values(array_filter(array_map('strval', (array) $this->option('locale'))));
        $source = ! empty($explicit)
            ? $explicit
            : array_map('strval', (array) config('app.supported_locales', []));

        return array_values(array_unique(array_filter(
            $source,
            fn (string $locale) => $locale !== '' && ! LanguagePackBaseLocales::isBaseLocale($locale),
        )));
    }
}
