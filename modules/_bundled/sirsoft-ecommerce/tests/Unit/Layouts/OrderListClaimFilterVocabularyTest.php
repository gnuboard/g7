<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Illuminate\Validation\Rules\In;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 목록 클레임 기준 필터(D22)의 화면 어휘 ↔ 서버 Enum 파리티
 *
 * 이 축의 허용 어휘는 Enum 이 단일 출처이고, `OrderListRequest` 는 `Rule::in(Enum::values())`
 * 로 그 출처를 그대로 쓴다. 그런데 화면은 레이아웃 JSON 이라 값을 리터럴로 적을 수밖에 없어,
 * 둘이 어긋나도 아무 곳에서도 예외가 나지 않는다.
 *
 * 어긋나는 방향마다 증상이 다르고 둘 다 조용하다:
 *  - 화면에만 있는 값: 그 칩을 누른 순간 목록 전체가 422 가 되는데 화면에는 직전 목록이
 *    그대로 남아 "필터가 걸렸다" 로 보인다.
 *  - Enum 에만 있는 값: 그 상태로 기록된 클레임이 달린 주문은 어떤 필터 조합으로도 도달
 *    불가가 되고, 운영자는 "그런 주문이 없다" 로 읽는다.
 *
 * @scenario claim_axis=type_status
 *
 * @effects order_list_claim_filter_vocabulary_matches_server_gate
 */
class OrderListClaimFilterVocabularyTest extends ModuleTestCase
{
    /** 클레임 필터 블록을 담은 레이아웃 파셜 */
    private const FILTER_PARTIAL = __DIR__.'/../../../resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json';

    /**
     * 유형별 칩 어휘가 서버 Enum 과 정확히 일치합니다. (부분집합도 초과집합도 아님)
     *
     * @return void
     */
    public function test_claim_filter_chip_vocabulary_matches_server_enums(): void
    {
        $chips = $this->collectClaimChipValues();

        $expected = [
            'claimExchangeStatuses' => ExchangeStatusEnum::values(),
            'claimReturnStatuses' => ReturnStatusEnum::values(),
            'claimRefundStatuses' => RefundStatusEnum::values(),
            'claimCancelTypes' => CancelTypeEnum::values(),
            'claimFaultTypes' => ClaimReasonFaultTypeEnum::values(),
        ];

        foreach ($expected as $stateKey => $values) {
            $this->assertArrayHasKey($stateKey, $chips, "화면에 {$stateKey} 축의 칩이 하나도 없습니다.");

            sort($values);
            $actual = $chips[$stateKey];
            sort($actual);

            $this->assertSame(
                $values,
                $actual,
                "{$stateKey} 축의 화면 어휘가 서버 Enum 과 다릅니다. 화면에만 있는 값은 목록 전체를 422 로 만들고, Enum 에만 있는 값은 그 상태의 주문을 도달 불가로 만듭니다."
            );
        }
    }

    /**
     * 클레임 유무 축의 칩 어휘가 서버 규칙과 정확히 일치합니다.
     *
     * 어휘를 여기에 리터럴로 적으면 형제 축(Enum 단일 출처)과 강도가 갈린다 — 서버 규칙이
     * 넓어져도 그 값의 칩이 없는 채로 초록이고, 이 파일 머리말이 경고한 "Enum 에만 있는 값 =
     * 도달 불가" 가 이 축에서 그대로 재현된다. 그래서 `OrderListRequest` 의 규칙을 파싱해
     * 그것을 단일 출처로 쓴다.
     *
     * @return void
     */
    public function test_claim_presence_chip_vocabulary_matches_request_rule(): void
    {
        $chips = $this->collectClaimChipValues();
        $expected = $this->claimPresenceRuleValues();

        $this->assertNotEmpty($expected, 'OrderListRequest 에서 claim_presence 의 허용 어휘를 읽지 못했습니다.');
        $this->assertArrayHasKey('claimPresence', $chips, '화면에 클레임 유무 축의 칩이 하나도 없습니다.');

        sort($expected);
        $actual = $chips['claimPresence'];
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            '클레임 유무 축의 화면 어휘가 서버 규칙과 다릅니다.'
        );
    }

    /**
     * `OrderListRequest` 의 `claim_presence` 규칙에서 허용 어휘를 읽습니다.
     *
     * @return array<int, string> 허용 어휘
     */
    private function claimPresenceRuleValues(): array
    {
        $rules = (new OrderListRequest)->rules()['claim_presence'] ?? [];

        foreach ((array) $rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return array_values(array_filter(explode(',', substr($rule, 3))));
            }

            if ($rule instanceof In) {
                // Rule::in(...) 로 바뀌어도 같은 출처를 읽는다 — `"in:..."` 형태로 직렬화된다.
                return array_values(array_filter(
                    array_map(
                        static fn (string $v): string => trim($v, '"'),
                        explode(',', substr((string) $rule, 3))
                    )
                ));
            }
        }

        return [];
    }

    /**
     * 필터 파셜에서 클레임 축 칩의 값을 상태 키별로 모읍니다.
     *
     * @return array<string, list<string>> 상태 키 => 칩 값 목록 (선언 순서 유지)
     */
    private function collectClaimChipValues(): array
    {
        $layout = json_decode(file_get_contents(self::FILTER_PARTIAL), true, 512, JSON_THROW_ON_ERROR);

        $chips = [];
        $walk = function ($node) use (&$walk, &$chips): void {
            if (! is_array($node)) {
                return;
            }

            if (($node['name'] ?? null) === 'ChipCheckbox') {
                $encoded = json_encode($node, JSON_UNESCAPED_UNICODE);

                foreach (['claimPresence', 'claimExchangeStatuses', 'claimReturnStatuses', 'claimRefundStatuses', 'claimCancelTypes', 'claimFaultTypes'] as $stateKey) {
                    if (str_contains((string) $encoded, 'filter.'.$stateKey)) {
                        $chips[$stateKey][] = (string) ($node['props']['value'] ?? '');
                        break;
                    }
                }
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($layout);

        return $chips;
    }
}
