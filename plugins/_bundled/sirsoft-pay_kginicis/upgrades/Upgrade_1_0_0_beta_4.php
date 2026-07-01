<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * v1.0.0-beta.4 업그레이드 스텝
 *
 * KG 이니시스 간편결제 4종 (kginicis_samsung_pay / kginicis_naverpay / kginicis_lpay / kginicis_kakaopay) 를
 * 이커머스 모듈의 saved order_settings.payment_methods 에 정확한 위치로 삽입.
 *
 * 배경:
 *   RegisterEasyPayMethodsListener 는 코어의 builtin 결제수단 배열에 4개 entry 를 inject
 *   하지만 mergePaymentMethodSettings 는 saved 의 sort_order 를 우선시 → 신규 entry 에는
 *   sort_order 를 동적으로 부여 → 기존 point/deposit/free 의 sort_order 와 충돌. PHP usort
 *   (unstable) 결과 화면 표시 순서가 어긋남.
 *
 * 본 step 은 saved order_settings.json 의 payment_methods 배열을 직접 수정:
 *   - 현재 sort_order 기준으로 정렬된 배열에서 'phone' 의 위치를 동적으로 찾기
 *   - 우리 4개 결제수단 중 saved 에 이미 있는 것 skip (멱등)
 *   - phone 직후에 누락된 결제수단을 순서대로 삽입 (samsung_pay → naverpay → lpay → kakaopay)
 *   - phone 이 없으면 배열 마지막에 append
 *   - 전체 sort_order 를 1부터 재할당
 *
 * 다른 운영자 환경에서 phone 의 sort_order 가 5 가 아니거나 (드래그 조정),
 * 다른 plugin 이 추가한 결제수단이 끼어있어도 동적 위치 계산이라 안전.
 *
 * 멱등성:
 *   - 이미 4개 결제수단이 모두 saved 에 있으면 변경 없이 통과
 *   - 일부만 있으면 누락된 것만 추가
 *   - settings 파일이 없으면 (이커머스 미설치 또는 첫 진입) skip
 */
class Upgrade_1_0_0_beta_4 implements UpgradeStepInterface
{
    private const ECOMMERCE_SETTINGS_PATH = 'app/modules/sirsoft-ecommerce/settings/order_settings.json';

    private const EASY_PAY_IDS = [
        'kginicis_samsung_pay',
        'kginicis_naverpay',
        'kginicis_lpay',
        'kginicis_kakaopay',
    ];

    public function run(UpgradeContext $context): void
    {
        $path = storage_path(self::ECOMMERCE_SETTINGS_PATH);

        if (! File::exists($path)) {
            $context->logger->info('[v1.0.0-beta.4] 이커머스 order_settings.json 없음 — 첫 진입 시 자동 생성되므로 skip');

            return;
        }

        $raw = File::get($path);
        $settings = json_decode($raw, true);

        if (! is_array($settings) || ! isset($settings['payment_methods']) || ! is_array($settings['payment_methods'])) {
            $context->logger->warning('[v1.0.0-beta.4] payment_methods 배열이 없거나 형식 비정상 — skip');

            return;
        }

        $methods = $settings['payment_methods'];

        // 1. sort_order 기준 정렬 (saved 의 정렬 상태 보존)
        usort($methods, fn ($a, $b) => ($a['sort_order'] ?? PHP_INT_MAX) <=> ($b['sort_order'] ?? PHP_INT_MAX));

        // 2. 이미 추가된 결제수단 식별 (멱등)
        $existingIds = array_column($methods, 'id');
        $missingIds = array_values(array_diff(self::EASY_PAY_IDS, $existingIds));

        if (empty($missingIds)) {
            $context->logger->info('[v1.0.0-beta.4] 4개 결제수단 모두 이미 saved 에 있음 — 변경 없음 (멱등)');

            return;
        }

        // 3. phone 위치 동적 검색 (운영자가 드래그로 위치 바꿨을 수 있음 — index 동적)
        $phoneIndex = null;
        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'phone') {
                $phoneIndex = $index;
                break;
            }
        }

        // 4. 누락된 결제수단의 entry 생성 — sort_order 는 일단 0 (재할당 단계에서 정정)
        $newEntries = array_map(fn (string $id) => [
            'id' => $id,
            'pg_provider' => null,
            'sort_order' => 0,
            'is_active' => false,
            'min_order_amount' => 0,
            'stock_deduction_timing' => 'payment_complete',
        ], $missingIds);

        // 5. phone 다음에 삽입 (phone 이 없으면 끝에 append)
        if ($phoneIndex === null) {
            $merged = array_merge($methods, $newEntries);
        } else {
            $merged = array_merge(
                array_slice($methods, 0, $phoneIndex + 1),
                $newEntries,
                array_slice($methods, $phoneIndex + 1),
            );
        }

        // 6. sort_order 를 1 부터 재할당 (전체 배열의 정합성 보장)
        foreach ($merged as $i => &$method) {
            $method['sort_order'] = $i + 1;
        }
        unset($method);

        // 7. 저장
        $settings['payment_methods'] = $merged;
        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $context->logger->info('[v1.0.0-beta.4] KG 이니시스 간편결제 결제수단을 phone 뒤에 삽입 완료', [
            'inserted' => $missingIds,
            'phone_index' => $phoneIndex,
            'total_methods' => count($merged),
        ]);
    }
}
