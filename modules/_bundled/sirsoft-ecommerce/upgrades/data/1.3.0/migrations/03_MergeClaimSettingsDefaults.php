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
 * 실효 구조: 런타임은 `claim.instant_cancel_statuses` 를 늘 `order_settings.cancellable_statuses` 의
 * 별칭으로 읽는다(EcommerceSettingsService). 그래서 claim 쪽 사본만 적으면 보호가 되지 않는다 — 저장본
 * `order_settings.json` 에 그 키가 없는 사이트는 1.3.0 기본값(미결제 2종 포함)으로 넓어진다. 같은 1.2.x 값을
 * `order_settings.cancellable_statuses` 에도 없을 때만 적어 실제 판정값을 고정한다(7.1.0 출시 점검 EU-3).
 *
 * 기존 설정이 이미 존재하면 덮어쓰지 않는다(SettingsMigrator addField/addCategory 보장).
 *
 * ## 값은 이 버전에 동결한다
 *
 * 운영자가 취소 가능 상태를 저장한 적이 없으면 1.2.x 의 유효값은 그 버전의 기본값
 * (`['payment_complete']`)이었다. 예전에는 그 자리에서 **새 버전의** `defaults.json` 을 읽었는데,
 * 1.3.0 은 기본값을 미결제 2종까지 넓혔으므로 스텝을 밟는 시점의 파일에 따라 결과가 달라졌다
 * (스텝은 어느 버전의 파일이 깔려 있든 같은 일을 해야 한다). 그래서 1.2.x 기본값을 상수로 둔다.
 *
 * 선교환 2키(`advance_exchange_enabled` 기본 꺼짐 · `advance_exchange_collection_due_days` 기본 14일)도
 * 같은 방식으로 없는 키만 채운다.
 *
 * 1.3.0 이 새로 도입한 자동 승인 3키(`auto_approve_refund_statuses` ·
 * `auto_approve_refund_payment_methods` · `auto_approve_refund_payment_methods_decided`)도 claim 카테고리
 * 기본값 한 벌로 함께 넣는다. 동작상 필수는 아니다 — 런타임은 저장본에 없는 키를 defaults.json 으로 채운다.
 * 같은 이유로 claim 밖의 새 항목(`privacy_retention` 카테고리, `language_currency.rate_provider`)은 넣지 않는다
 * (기본값 병합으로 동작하고, 운영자가 저장하면 그때 파일에 적힌다).
 *
 * idempotent: 이미 병합된 사이트는 적용 대상이 없어 no-op.
 * V-1 안전: SettingsMigrator·File 은 1.3.0 이전 코어에도 존재하는 헬퍼다. 저장 경로는
 * `ExtensionStoragePath`(코어 7.0.10 부터 존재 — 이 버전의 최소 요구 코어는 7.1.0)가 해석한다 — 직접 조립하면
 * 디스크 root 가 바뀐 환경·테스트 환경에서 운영 설정 파일을 가리킨다.
 */
class MergeClaimSettingsDefaults implements DataMigration
{
    private const IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 1.2.x 의 `order_settings.cancellable_statuses` 기본값.
     *
     * 저장값이 없는 상점의 1.2.x 유효값이다. 새 버전의 defaults.json 을 읽지 않는다(클래스 docblock).
     *
     * @var array<int, string>
     */
    private const LEGACY_CANCELLABLE_STATUSES = ['payment_complete'];

    /**
     * 1.3.0 에서 도입한 자동 승인 3키의 기본값.
     *
     * @var array<string, array<int, string>>
     */
    private const AUTO_APPROVE_DEFAULTS = [
        'auto_approve_refund_statuses' => ['payment_complete', 'preparing'],
        'auto_approve_refund_payment_methods' => ['card', 'point', 'free'],
        'auto_approve_refund_payment_methods_decided' => [],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MergeClaimSettingsDefaults';
    }

    /**
     * claim 설정 카테고리 기본값을 병합합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        $instantCancelStatuses = $this->resolveInstantCancelStatuses();

        $migrator = SettingsMigrator::forModule(self::IDENTIFIER)
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
                ...self::AUTO_APPROVE_DEFAULTS,
                'max_images' => 5,
                'image_max_size_kb' => 2048,
                'request_period_days' => 7,
                'advance_exchange_enabled' => false,
                'advance_exchange_collection_due_days' => 14,
                'complete_defaults' => [
                    'create_refund_request' => true,
                    'restore_stock' => true,
                ],
            ])
            // 카테고리가 이미 있는 사이트(부분 적용 후 재실행 등)를 위해 개별 필드도 보강한다.
            ->addField('claim.instant_cancel_statuses', $instantCancelStatuses)
            // 런타임 판정값은 order_settings 쪽이다(별칭) — 저장본에 키가 없으면 같은 값으로 고정한다(EU-3).
            ->addField('order_settings.cancellable_statuses', $instantCancelStatuses)
            // 선교환 2키(D1·D2)도 없는 키만 채운다 — 카테고리가 이미 있는 사이트는 addCategory 가 건너뛴다.
            ->addField('claim.advance_exchange_enabled', false)
            ->addField('claim.advance_exchange_collection_due_days', 14);

        foreach (self::AUTO_APPROVE_DEFAULTS as $key => $default) {
            $migrator->addField('claim.'.$key, $default);
        }

        $result = $migrator->apply();

        $context->logger->info(
            '[ecommerce:1.3.0] claim 설정 기본값 병합 완료 ('.($result['applied'] ?? 0).'건 적용) — '
            .'즉시취소 상태 승계: '.implode(', ', $instantCancelStatuses)
        );
    }

    /**
     * 즉시취소 가능 상태를 해석합니다.
     *
     * 저장된 order_settings.cancellable_statuses → 1.2.x 기본값 순. 저장 파일에 그 키가 없으면
     * 1.2.x 에서의 유효값은 그 버전의 기본값이었으므로 그것을 쓴다(새 버전 defaults.json 을 읽지 않는다).
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

        return self::LEGACY_CANCELLABLE_STATUSES;
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
