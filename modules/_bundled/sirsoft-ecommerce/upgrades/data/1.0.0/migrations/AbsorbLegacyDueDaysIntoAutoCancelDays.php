<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 구 입금기한 설정 키(vbank_due_days/dbank_due_days)를 단일 SSoT auto_cancel_days 로 흡수.
 *
 * 기존 환경의 storage settings JSON 에 남아있는 구 키 값을, auto_cancel_days 가 미설정(또는 < 1)
 * 인 경우에 한해 max(vbank_due_days, dbank_due_days) 로 흡수한다(고객이 더 긴 입금기한을 잃지
 * 않도록 안전 편향). 흡수 후 구 키는 settings JSON 에서 제거한다.
 *
 * idempotent: 구 키가 없으면 no-op. auto_cancel_days 가 이미 유효하면 흡수하지 않고 구 키만 정리.
 * V-1 안전: Illuminate\Support\Facades\File + 로컬 헬퍼만 사용.
 */
class AbsorbLegacyDueDaysIntoAutoCancelDays implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    public function name(): string
    {
        return 'AbsorbLegacyDueDaysIntoAutoCancelDays';
    }

    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.0.0] order_settings.json 미존재 — 흡수 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings)) {
            $context->logger->warning('[ecommerce:1.0.0] order_settings.json 파싱 불가 — 흡수 스킵');

            return;
        }

        $hasVbank = array_key_exists('vbank_due_days', $settings);
        $hasDbank = array_key_exists('dbank_due_days', $settings);

        if (! $hasVbank && ! $hasDbank) {
            // 구 키 없음 — 이미 정리됨 (idempotent)
            return;
        }

        $current = $settings['auto_cancel_days'] ?? null;
        $needsAbsorb = $current === null || (int) $current < 1;

        if ($needsAbsorb) {
            $vbank = (int) ($settings['vbank_due_days'] ?? 0);
            $dbank = (int) ($settings['dbank_due_days'] ?? 0);
            $merged = max($vbank, $dbank);

            if ($merged >= 1) {
                $settings['auto_cancel_days'] = $merged;
                $context->logger->info("[ecommerce:1.0.0] auto_cancel_days 흡수: max({$vbank}, {$dbank}) = {$merged}");
            }
        }

        // 구 키 제거 (런타임 무해하나 정리)
        unset($settings['vbank_due_days'], $settings['dbank_due_days']);

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info('[ecommerce:1.0.0] 구 입금기한 키 정리 완료');
    }

    /**
     * order_settings.json 의 운영 저장 경로를 반환합니다.
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        return storage_path('app/modules/'.self::MODULE_IDENTIFIER.'/settings/order_settings.json');
    }
}
