<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 통화별 사용 언어(locales)를 사이트 지원 언어로 정합화.
 *
 * 통화별 locales 는 가입 시 통화 추정 매핑(SignupCurrencyResolver)에 쓰이며, 후보는 사이트
 * 지원 언어(config app.supported_locales)로 제한된다. 그런데 시드 기본값(defaults.json)이
 * 지원 언어와 무관한 값(CNY=zh, EUR=de/fr/es/it)을 박아두어, 통화 설정 저장 시 검증
 * 규칙(in:supported_locales)에 걸려 저장이 영구 차단되던 결함을 정리한다.
 *
 * 정리 규칙:
 *   - 각 통화 locales 에서 지원 언어에 없는 값 제거 (운영자가 의도 설정한 유효값은 보존)
 *   - 제거 결과 빈 배열이 된 통화에만 기본값 부여: KRW → ko, 그 외 → en
 *     (ja 는 언어팩 설치 시에만 지원 언어이므로 코어 고정 기본값에서 제외)
 *
 * idempotent: 모든 locales 가 이미 지원 언어 부분집합이고 빈 통화가 없으면 no-op.
 *
 * V-1 안전: Illuminate\Support\Facades\File + config() 헬퍼 + 로컬 헬퍼만 사용.
 */
class NormalizeCurrencyLocales implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 빈 통화에 부여할 기본 사용 언어 (코드별).
     */
    private const DEFAULT_LOCALE = [
        'KRW' => 'ko',
    ];

    /**
     * 코드 미지정 / 매핑 외 통화의 기본 사용 언어.
     */
    private const FALLBACK_LOCALE = 'en';

    public function name(): string
    {
        return 'NormalizeCurrencyLocales';
    }

    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.0.0] language_currency.json 미존재 — locales 정합화 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings) || ! isset($settings['currencies']) || ! is_array($settings['currencies'])) {
            $context->logger->warning('[ecommerce:1.0.0] language_currency.json 통화 목록 없음 — locales 정합화 스킵');

            return;
        }

        $supported = $this->supportedLocales();
        $currencies = $settings['currencies'];
        $changed = false;

        foreach ($currencies as $idx => $currency) {
            $original = is_array($currency['locales'] ?? null) ? array_values($currency['locales']) : [];

            // 지원 언어 교집합만 유지 (순서·중복 정리 포함)
            $filtered = array_values(array_unique(array_filter(
                $original,
                static fn ($locale): bool => is_string($locale) && in_array($locale, $supported, true)
            )));

            // 제거 결과 비면 코드별 기본값 부여
            if ($filtered === []) {
                $filtered = [$this->defaultLocaleFor($currency['code'] ?? null)];
            }

            if ($filtered !== $original) {
                $currencies[$idx]['locales'] = $filtered;
                $changed = true;
            }
        }

        if (! $changed) {
            // 모든 통화가 이미 정합 (idempotent)
            return;
        }

        $settings['currencies'] = $currencies;

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info(sprintf(
            '[ecommerce:1.0.0] 통화 locales 정합화 완료 (지원 언어=%s)',
            implode(',', $supported)
        ));
    }

    /**
     * 사이트 지원 언어 목록을 반환합니다.
     *
     * @return array<int, string> 지원 언어 코드 배열
     */
    private function supportedLocales(): array
    {
        $supported = config('app.supported_locales', ['ko', 'en']);

        if (! is_array($supported) || $supported === []) {
            return ['ko', 'en'];
        }

        return array_values(array_filter($supported, 'is_string'));
    }

    /**
     * 빈 통화에 부여할 기본 사용 언어를 반환합니다.
     *
     * @param  string|null  $code  통화 코드
     * @return string 기본 사용 언어 코드
     */
    private function defaultLocaleFor(?string $code): string
    {
        return self::DEFAULT_LOCALE[$code] ?? self::FALLBACK_LOCALE;
    }

    /**
     * language_currency.json 의 저장 경로를 반환합니다.
     *
     * 테스트 환경에서는 운영 storage 오염을 막기 위해 framework/testing 경로를 사용합니다
     * (EcommerceSettingsService 의 저장 경로 분기와 동일).
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        $base = app()->runningUnitTests()
            ? 'framework/testing/modules/'
            : 'app/modules/';

        return storage_path($base.self::MODULE_IDENTIFIER.'/settings/language_currency.json');
    }
}
