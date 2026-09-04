<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Helpers\SettingsMigrator;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * claim 설정 카테고리 기본값을 병합합니다.
 *
 * 즉시취소 가능 상태(`claim.instant_cancel_statuses`)는 기존
 * `order_settings.cancellable_statuses` 를 승계한다. 클레임 일반화 이후 취소·환불의
 * 분기 기준이 이 값인데, 기본값으로 새로 깔면 운영자가 좁혀 둔 취소 가능 상태가
 * 조용히 넓어져 그동안 취소를 막아 온 상태에서 취소가 열린다.
 *
 * 기존 설정이 이미 존재하면 덮어쓰지 않는다(SettingsMigrator addField/addCategory 보장).
 *
 * idempotent: 이미 병합된 사이트는 적용 대상이 없어 no-op.
 * V-1 안전: SettingsMigrator·File 은 1.3.0 이전 코어에도 존재하는 헬퍼다. 저장 경로는
 * `ExtensionStoragePath`(코어 7.0.10 — 이 버전의 최소 요구 코어)가 해석한다 — 직접 조립하면
 * 디스크 root 가 바뀐 환경·테스트 환경에서 운영 설정 파일을 가리킨다.
 */
class MergeClaimSettingsDefaults implements DataMigration
{
    private const IDENTIFIER = 'sirsoft-ecommerce';

    public function name(): string
    {
        return 'MergeClaimSettingsDefaults';
    }

    public function run(UpgradeContext $context): void
    {
        $instantCancelStatuses = $this->resolveInstantCancelStatuses();

        $result = SettingsMigrator::forModule(self::IDENTIFIER)
            ->addCategory('claim', [
                'exchange_requestable_statuses' => ['delivered', 'confirmed'],
                'return_requestable_statuses' => ['delivered', 'confirmed'],
                'instant_cancel_statuses' => $instantCancelStatuses,
                'refund_request_statuses' => ['preparing', 'shipping_ready', 'shipping'],
                'allowed_charge_methods' => ['pay_on_request', 'pay_on_approval', 'deduct_from_refund'],
                'charge_payment_methods' => ['card', 'point'],
                'auto_approve_zero_charge' => false,
                'auto_approve_after_payment' => false,
                'auto_approve_refund_request' => false,
                'max_images' => 5,
                'image_max_size_kb' => 2048,
                'request_period_days' => 7,
                'complete_defaults' => [
                    'create_refund_request' => true,
                    'restore_stock' => true,
                ],
            ])
            // 카테고리가 이미 있는 사이트(부분 적용 후 재실행 등)를 위해 개별 필드도 보강한다.
            ->addField('claim.instant_cancel_statuses', $instantCancelStatuses)
            ->apply();

        $context->logger->info(
            '[ecommerce:1.3.0] claim 설정 기본값 병합 완료 ('.($result['applied'] ?? 0).'건 적용) — '
            .'즉시취소 상태 승계: '.implode(', ', $instantCancelStatuses)
        );
    }

    /**
     * 즉시취소 가능 상태를 해석합니다.
     *
     * 저장된 order_settings.cancellable_statuses → 모듈 defaults.json → 최후 기본값 순.
     * 저장 파일에 그 키가 없으면 defaults 가 유효값이므로 defaults 도 함께 읽는다.
     *
     * @return array<int, string> 즉시취소 가능 상태 값 배열
     */
    private function resolveInstantCancelStatuses(): array
    {
        $saved = $this->readJson(
            ExtensionStoragePath::module(self::IDENTIFIER, 'settings').'/order_settings.json'
        );

        if (isset($saved['cancellable_statuses']) && is_array($saved['cancellable_statuses'])) {
            return array_values($saved['cancellable_statuses']);
        }

        $defaults = $this->readJson($this->resolveDefaultsPath());
        $fromDefaults = $defaults['defaults']['order_settings']['cancellable_statuses'] ?? null;

        if (is_array($fromDefaults)) {
            return array_values($fromDefaults);
        }

        return ['payment_complete'];
    }

    /**
     * 모듈 defaults.json 경로를 반환합니다.
     *
     * 활성 디렉토리를 먼저 보고, 없으면 번들 원본을 본다.
     *
     * @return string defaults.json 절대 경로
     */
    private function resolveDefaultsPath(): string
    {
        $active = base_path('modules/'.self::IDENTIFIER.'/config/settings/defaults.json');

        if (File::exists($active)) {
            return $active;
        }

        return base_path('modules/_bundled/'.self::IDENTIFIER.'/config/settings/defaults.json');
    }

    /**
     * JSON 파일을 배열로 읽습니다.
     *
     * @param  string  $path  파일 절대 경로
     * @return array 파싱된 배열 (없거나 파싱 실패면 빈 배열)
     */
    private function readJson(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
