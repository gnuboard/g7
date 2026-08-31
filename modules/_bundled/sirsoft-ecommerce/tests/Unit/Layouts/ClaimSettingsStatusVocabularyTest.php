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
     * 네 축의 화면 선택지가 서버 게이트와 정확히 일치합니다. (부분집합도 초과집합도 아님)
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
     * 게이트 어휘가 종결·결제 전 상태를 포함하지 않습니다.
     *
     * 신청 가능 상태에 취소완료·반품완료·교환완료 같은 종결 상태나 주문대기·결제대기가 섞이면
     * 이미 끝난(또는 아직 결제되지 않은) 주문옵션이 클레임 분기로 들어간다. 저장은 200 이고
     * 로그도 남지 않아, 그 조합으로 실제 신청이 들어올 때에야 드러난다.
     *
     * @return void
     */
    public function test_gate_excludes_terminal_and_pre_payment_statuses(): void
    {
        // 백킹값을 손으로 적으면 Enum 이 개명될 때 assertNotContains 가 "없는 문자열을
        // 찾는" 항상-참 단언으로 조용히 바뀐다 — Enum 을 단일 출처로 읽는다.
        $forbidden = array_map(
            static fn (OrderStatusEnum $status): string => $status->value,
            [
                OrderStatusEnum::PENDING_ORDER,
                OrderStatusEnum::PENDING_PAYMENT,
                OrderStatusEnum::CANCELLED,
                OrderStatusEnum::EXCHANGE_IN_PROGRESS,
                OrderStatusEnum::RETURN_IN_PROGRESS,
                OrderStatusEnum::EXCHANGED,
                OrderStatusEnum::RETURNED,
            ],
        );

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
     * @return array<string, array<int, string>> `props.name` → 선택지 값 목록
     */
    private function collectStatusOptions(): array
    {
        $layout = json_decode((string) file_get_contents(self::POLICY_PARTIAL), true);
        $this->assertIsArray($layout, '클레임 정책 파셜을 읽지 못했습니다.');

        $collected = [];
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

            if (! str_starts_with($name, 'claim.') || ! str_contains($name, 'statuses')) {
                return;
            }

            $collected[$name] = array_values(array_filter(
                array_map(static fn ($o) => is_array($o) ? ($o['value'] ?? null) : null, $options),
                static fn ($v) => is_string($v) && $v !== ''
            ));
        });

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
