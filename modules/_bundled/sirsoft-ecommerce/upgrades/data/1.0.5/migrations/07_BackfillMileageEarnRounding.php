<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 마일리지 통화별 규칙에 적립 절사 기준을 명시 기입합니다.
 *
 * 적립 포인트 산출은 종전에 `(int) floor(...)` 로 하드코딩되어 운영자가 조정할 수
 * 없었습니다. 이제 통화별 규칙에 `earn_rounding_unit` / `earn_rounding_method` 를 두어
 * 선택할 수 있으며, 값이 없으면 `1` / `floor` 로 폴백해 종전과 같은 금액을 산출합니다.
 *
 * 폴백이 있으므로 백필 없이도 동작은 같습니다. 그럼에도 기존 설치본의 저장 파일에 값을
 * 명시하는 이유는 두 가지입니다.
 *
 * 1. 설정 파일이 SSoT 인데 키가 없으면 "운영자가 버림을 선택했다" 와 "키가 없어서
 *    폴백됐다" 가 구분되지 않습니다.
 * 2. 이후 폴백 기본값을 바꾸면 값을 명시하지 않은 기존 사이트의 적립 절사가 아무도
 *    건드리지 않았는데 조용히 바뀝니다. 명시해 두면 그 경로가 닫힙니다.
 *
 * 이미 값이 있는 행은 건드리지 않습니다(idempotent). 파일이 없으면 신규 설치이므로
 * `config/settings/defaults.json` 이 값을 갖고 있어 스킵합니다.
 *
 * V-1 안전: Illuminate\Support\Facades\File + 로컬 헬퍼만 사용.
 */
class BackfillMileageEarnRounding implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /** 적립 절사 단위 키 (Support\MileageRounding::UNIT_KEY 와 동일 — 업그레이드 코드는 모듈 클래스에 의존하지 않는다) */
    private const UNIT_KEY = 'earn_rounding_unit';

    /** 적립 절사 방식 키 */
    private const METHOD_KEY = 'earn_rounding_method';

    /** 종전 하드코딩 동작과 같은 값 */
    private const LEGACY_UNIT = '1';

    private const LEGACY_METHOD = 'floor';

    public function name(): string
    {
        return 'BackfillMileageEarnRounding';
    }

    /**
     * 백필을 수행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.0.5] mileage.json 미존재 — 적립 절사 기준 백필 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);

        if (! is_array($settings) || ! isset($settings['currency_rules']) || ! is_array($settings['currency_rules'])) {
            $context->logger->info('[ecommerce:1.0.5] mileage.currency_rules 없음 — 적립 절사 기준 백필 스킵');

            return;
        }

        $rules = $settings['currency_rules'];
        $filled = 0;

        foreach ($rules as $idx => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $touched = false;

            if (! array_key_exists(self::UNIT_KEY, $rule)) {
                $rules[$idx][self::UNIT_KEY] = self::LEGACY_UNIT;
                $touched = true;
            }

            if (! array_key_exists(self::METHOD_KEY, $rule)) {
                $rules[$idx][self::METHOD_KEY] = self::LEGACY_METHOD;
                $touched = true;
            }

            if ($touched) {
                $filled++;
            }
        }

        if ($filled === 0) {
            $context->logger->info('[ecommerce:1.0.5] 적립 절사 기준 이미 기입됨 — 백필 스킵 (idempotent)');

            return;
        }

        $settings['currency_rules'] = $rules;

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info(sprintf(
            '[ecommerce:1.0.5] 적립 절사 기준 백필 완료 (%d개 통화 규칙 → %s / %s)',
            $filled,
            self::LEGACY_UNIT,
            self::LEGACY_METHOD
        ));
    }

    /**
     * mileage.json 의 저장 경로를 반환합니다.
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

        return storage_path($base.self::MODULE_IDENTIFIER.'/settings/mileage.json');
    }
}
