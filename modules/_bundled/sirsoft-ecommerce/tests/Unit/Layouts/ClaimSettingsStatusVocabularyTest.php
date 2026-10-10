<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 설정 클레임 탭의 신청 가능 상태 어휘 ↔ 서버 게이트 파리티
 *
 * 이 네 축은 Enum 전체가 아니라 **축마다 다른 부분집합**이다 — 교환·반품은 결제완료부터
 * 구매확정까지, 즉시취소는 배송 시작 전까지, 환불신청은 배송중까지다. Enum 이 단일 출처가
 * 될 수 없으므로 화면(레이아웃 JSON)과 게이트(FormRequest)가 각자 목록을 갖고, 그래서
 * 어긋나도 어디에서도 예외가 나지 않는다.
 *
 * 어긋나는 방향마다 증상이 다르고 둘 다 조용하다:
 *  - 화면에만 있는 값: 그 상태를 고른 채 저장하면 422 인데, 화면은 그 값을 이미 칩으로
 *    보여 주고 있어 운영자는 무엇이 거부됐는지 알 수 없다.
 *  - 게이트에만 있는 값: 화면으로는 만들 수 없는 조합이 API·업그레이드 경로로 저장되고,
 *    `OrderRefundRequestService::branchForStatus()` 가 그 목록을 옵션 상태와 2차 축소 없이
 *    직접 비교하므로 이미 종결된 옵션이 취소 분기로 들어간다.
 *
 * @scenario reason_type=exchange
 *
 * @effects settings_claim_status_vocabulary_matches_server_gate
 */
class ClaimSettingsStatusVocabularyTest extends ModuleTestCase
{
    /** 클레임 정책 카드를 담은 레이아웃 파셜 */
    private const POLICY_PARTIAL = __DIR__.'/../../../resources/layouts/admin/partials/admin_ecommerce_settings/_claim_policy_section.json';

    /**
     * 주문 설정 탭 파셜 — `order_settings.cancellable_statuses` 카드가 여기 있다.
     *
     * 이 키는 `claim.instant_cancel_statuses` 의 **별칭**이다(어느 탭에서 저장해도 두 키가 함께
     * 갱신된다). 같은 값을 두 화면이 보여 주므로 선택지도 같아야 한다 — 한쪽만 늘리면 다른 탭은
     * 저장값을 원문 키(`pending_order`)로 그대로 노출한다(#599 후속 D10 회귀, 2026-09-13).
     */
    private const ORDER_PARTIAL = __DIR__.'/../../../resources/layouts/admin/partials/admin_ecommerce_settings/_tab_order_settings.json';

    /**
     * 자동 전이 축의 안내가 **지금 일어나는 일**을 말합니다. (#599 후속 D2)
     *
     * 이 축의 이름은 「즉시취소」였고 문구도 "바로 취소된다" 고 말했다. 새 모델에서는 그 상태의
     * 상품도 **환불신청서를 먼저 만든다** — 다만 승인·환불완료까지 자동으로 밀릴 뿐이고, 계좌로
     * 송금해야 하는 수단은 승인에서 멈춘다. 옛 문구를 그대로 두면 운영자는 이 축에 담긴 상태를
     * "서류 없이 끝나는 것" 으로 읽고, 승인 대기에 쌓인 신청서를 오류로 본다.
     *
     * 문구는 코드가 아니므로 어긋나도 예외가 없다. 그래서 **세 로케일 전부**를 대조한다 —
     * ja 는 번들 언어팩이 소유하므로 한쪽만 고쳐진 채 남기 쉽다.
     *
     * @scenario reason_type=refund
     *
     * @effects instant_cancel_hint_describes_auto_advance
     *
     * @return void
     */
    public function test_auto_advance_hint_describes_a_refund_request_not_an_instant_cancel(): void
    {
        $required = [
            'ko' => ['환불신청서', '자동'],
            'en' => ['refund request', 'automatic'],
            'ja' => ['返金申請書', '自動'],
        ];
        // 옛 어휘 — 이 축이 서류 없이 끝난다고 읽히게 만드는 단어다.
        $forbidden = [
            'ko' => ['즉시 취소', '즉시취소'],
            'en' => ['cancelled immediately', 'immediate cancel'],
            'ja' => ['即時キャンセル'],
        ];

        foreach ($this->settingsDictionaries() as $locale => $dictionary) {
            $hint = $dictionary['claim']['policy']['requestable']['instant_cancel_hint'] ?? null;

            $this->assertIsString($hint, "{$locale} 에 자동 전이 안내 문구가 없습니다 — 모집단이 비면 이 검사가 공전합니다");

            foreach ($required[$locale] as $needle) {
                $this->assertStringContainsStringIgnoringCase(
                    $needle,
                    $hint,
                    "{$locale} 자동 전이 안내가 '{$needle}' 를 말하지 않습니다 — 이 축도 환불신청서를 만든다는 사실이 드러나야 합니다",
                );
            }

            foreach ($forbidden[$locale] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $hint,
                    "{$locale} 자동 전이 안내가 옛 어휘 '{$needle}' 를 그대로 씁니다 — 서류 없이 끝나는 것으로 읽힙니다",
                );
            }
        }
    }

    /**
     * 설정 화면 사전을 로케일별로 읽습니다.
     *
     * ja 는 번들 언어팩이 소유하므로 경로가 다르다 — ko/en 만 보면 팩 쪽 문구가 사실과 달라져도
     * 드러나지 않는다.
     *
     * @return array<string, array<string, mixed>> 로케일 => 디코드된 사전
     */
    private function settingsDictionaries(): array
    {
        $moduleRoot = dirname(__DIR__, 3);
        $paths = [
            'ko' => $moduleRoot.'/resources/lang/partial/ko/admin/settings.json',
            'en' => $moduleRoot.'/resources/lang/partial/en/admin/settings.json',
            'ja' => dirname($moduleRoot, 3).'/lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/settings.json',
        ];

        $out = [];
        foreach ($paths as $locale => $path) {
            $this->assertFileExists($path, "{$locale} 설정 사전을 찾지 못했습니다");
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($decoded, "{$locale} 설정 사전을 파싱하지 못했습니다");
            $out[$locale] = $decoded;
        }

        return $out;
    }

    /**
     * 네 축의 화면 선택지가 서버 게이트와 정확히 일치합니다. (부분집합도 초과집합도 아님)
     *
     * 자동 전이 축은 **두 탭**에 선다 — 클레임 정책 탭의 `claim.instant_cancel_statuses` 와
     * 주문 설정 탭의 `order_settings.cancellable_statuses` 는 같은 저장값의 별칭이므로 둘 다
     * 같은 게이트와 대조한다. 클레임 탭만 D10 을 반영하고 주문 탭을 빠뜨리면, 기본값에 든
     * 미결제 2종이 주문 탭에서 라벨 없는 원문 키로 노출된다.
     *
     * @scenario reason_type=exchange
     *
     * @effects settings_claim_status_vocabulary_matches_server_gate
     *
     * @return void
     */
    public function test_claim_status_options_match_server_gate(): void
    {
        $options = $this->collectStatusOptions();

        $expected = [
            'claim.exchange_requestable_statuses' => StoreEcommerceSettingsRequest::claimRequestableStatuses(),
            'claim.return_requestable_statuses' => StoreEcommerceSettingsRequest::claimRequestableStatuses(),
            'claim.instant_cancel_statuses' => StoreEcommerceSettingsRequest::claimInstantCancelStatuses(),
            'order_settings.cancellable_statuses' => StoreEcommerceSettingsRequest::claimInstantCancelStatuses(),
            'claim.refund_request_statuses' => StoreEcommerceSettingsRequest::claimRefundRequestStatuses(),
        ];

        foreach ($expected as $name => $gateValues) {
            $this->assertArrayHasKey($name, $options, "레이아웃에 {$name} 선택지가 없습니다.");

            $screen = $options[$name];
            sort($screen);
            sort($gateValues);

            $this->assertSame(
                $gateValues,
                $screen,
                "{$name} 의 화면 선택지가 서버 게이트와 다릅니다. 화면에만 있는 값은 저장 시 422 가 되고, ".
                '게이트에만 있는 값은 화면으로 만들 수 없는 조합을 API 경로로 통과시킵니다.'
            );
        }
    }

    /**
     * 게이트 어휘가 **종결 상태**를 포함하지 않습니다.
     *
     * 신청 가능 상태에 취소완료·반품완료·교환완료 같은 종결 상태가 섞이면 이미 끝난 주문옵션이
     * 클레임 분기로 들어간다. 저장은 200 이고 로그도 남지 않아, 그 조합으로 실제 신청이 들어올
     * 때에야 드러난다.
     *
     * **미결제 2종은 자동 전이 축에만 허용된다**(#599 후속 D10). 미결제 주문도 취소·환불 신청
     * 입구로 들어와야 하고 — 그것이 D10 이 여는 축이다 — 돌려줄 돈이 없으므로 환불신청서 없이
     * 취소신청서만 생긴다. 교환·반품·환불신청 축에는 여전히 허용되지 않는다: 받은 돈이 없는
     * 주문에 환불 심사를 걸 이유가 없고, 배송되지 않은 상품을 교환·반품할 수도 없다.
     *
     * @scenario reason_type=return
     *
     * @effects settings_claim_status_vocabulary_matches_server_gate
     *
     * @return void
     */
    public function test_gate_excludes_terminal_and_pre_payment_statuses(): void
    {
        // 백킹값을 손으로 적으면 Enum 이 개명될 때 assertNotContains 가 "없는 문자열을
        // 찾는" 항상-참 단언으로 조용히 바뀐다 — Enum 을 단일 출처로 읽는다.
        $terminal = array_map(
            static fn (OrderStatusEnum $status): string => $status->value,
            [
                OrderStatusEnum::CANCELLED,
                OrderStatusEnum::EXCHANGE_IN_PROGRESS,
                OrderStatusEnum::RETURN_IN_PROGRESS,
                OrderStatusEnum::EXCHANGED,
                OrderStatusEnum::RETURNED,
            ],
        );

        $prePayment = array_map(
            static fn (OrderStatusEnum $status): string => $status->value,
            [OrderStatusEnum::PENDING_ORDER, OrderStatusEnum::PENDING_PAYMENT],
        );

        // 자동 전이 축(구 즉시취소)만 미결제를 받는다.
        foreach ($prePayment as $status) {
            $this->assertContains(
                $status,
                StoreEcommerceSettingsRequest::claimInstantCancelStatuses(),
                "자동 전이 축이 미결제 상태 '{$status}' 를 허용해야 한다 (D10)",
            );
            $this->assertNotContains(
                $status,
                StoreEcommerceSettingsRequest::claimRefundRequestStatuses(),
                "환불신청 축은 미결제 상태 '{$status}' 를 허용하면 안 된다 — 받은 돈이 없다",
            );
            $this->assertNotContains(
                $status,
                StoreEcommerceSettingsRequest::claimRequestableStatuses(),
                "교환·반품 축은 미결제 상태 '{$status}' 를 허용하면 안 된다 — 배송되지 않았다",
            );
        }

        $forbidden = $terminal;

        $axes = [
            'claimRequestableStatuses' => StoreEcommerceSettingsRequest::claimRequestableStatuses(),
            'claimInstantCancelStatuses' => StoreEcommerceSettingsRequest::claimInstantCancelStatuses(),
            'claimRefundRequestStatuses' => StoreEcommerceSettingsRequest::claimRefundRequestStatuses(),
        ];

        foreach ($axes as $axis => $values) {
            foreach ($forbidden as $status) {
                $this->assertNotContains(
                    $status,
                    $values,
                    "{$axis} 가 종결·결제 전 상태 '{$status}' 를 허용합니다."
                );
            }
        }
    }

    /**
     * 레이아웃에서 축별 상태 선택지를 모읍니다.
     *
     * 클레임 정책 파셜의 `claim.*_statuses` 와 주문 설정 파셜의 별칭
     * `order_settings.cancellable_statuses` 를 함께 모은다.
     *
     * @return array<string, array<int, string>> `props.name` → 선택지 값 목록
     */
    private function collectStatusOptions(): array
    {
        $partials = [
            self::POLICY_PARTIAL => '클레임 정책 파셜을 읽지 못했습니다.',
            self::ORDER_PARTIAL => '주문 설정 파셜을 읽지 못했습니다.',
        ];

        $collected = [];
        foreach ($partials as $path => $failure) {
            $layout = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($layout, $failure);

            $this->walk($layout, function (array $node) use (&$collected): void {
                $props = $node['props'] ?? null;
                if (! is_array($props)) {
                    return;
                }

                $name = $props['name'] ?? null;
                $options = $props['options'] ?? null;
                if (! is_string($name) || ! is_array($options)) {
                    return;
                }

                $isClaimAxis = str_starts_with($name, 'claim.') && str_contains($name, 'statuses');
                if (! $isClaimAxis && $name !== 'order_settings.cancellable_statuses') {
                    return;
                }

                $collected[$name] = array_values(array_filter(
                    array_map(static fn ($o) => is_array($o) ? ($o['value'] ?? null) : null, $options),
                    static fn ($v) => is_string($v) && $v !== ''
                ));
            });
        }

        return $collected;
    }

    /**
     * 레이아웃 트리를 순회하며 각 노드에 콜백을 적용합니다.
     *
     * @param  mixed  $node  현재 노드
     * @param  callable  $visit  노드 방문 콜백
     * @return void
     */
    private function walk(mixed $node, callable $visit): void
    {
        if (! is_array($node)) {
            return;
        }

        if (! array_is_list($node)) {
            $visit($node);
        }

        foreach ($node as $child) {
            $this->walk($child, $visit);
        }
    }
}
