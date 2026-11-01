/**
 * @file mypageTaxBreakdown.test.tsx
 * @description 마이페이지 주문 상세 › 나이스페이먼츠 세금 상세 블록의 「영세금액」 줄 (#612)
 *
 * 영세(세율 0%)는 과세표준에 들지만 부가세가 없고, 결제사에는 면세로 넘긴다. 그래서 영세만 든 주문은
 * 부가세 0 · 면세 0 이라, 블록 조건이 부가세·면세만 보면 세금 상세 블록이 통째로 사라진다. 블록은 영세 금액도
 * 조건에 넣고, 영세 몫을 공급가액에 섞지 않고 「영세금액」 줄로 따로 보인다.
 *
 * 설치되는 조각 JSON 을 엔진(createLayoutTest)에 태워 렌더 결과를 본다(KG이니시스 userOrderShowFragment 와 같은 케이스).
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/mypage-tax-breakdown.yaml
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { createLayoutTest, screen } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import extensionLayout from '../../../extensions/user_mypage_order_receipt.json';
import {
    PLUGIN_TRANSLATIONS,
    clearTemplateConfig,
    resetFragmentRegistry,
    setupFragmentRegistry,
    stubTemplateConfig,
} from './fragmentRenderHelpers';

let utils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 카드 결제를 마친 나이스페이먼츠 주문으로 결제 정보 카드 슬롯 조각을 그립니다.
 *
 * @param tax 주문 응답의 세금 값
 */
async function renderSlot(tax: Record<string, unknown>): Promise<void> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'mypage_tax_breakdown_test', components: (extensionLayout as any).components } as any,
        {
            translations: PLUGIN_TRANSLATIONS,
            locale: 'ko',
            componentRegistry: setupFragmentRegistry(),
            initialData: {
                order: {
                    data: {
                        order_number: 'ORD-TAX',
                        total_amount_formatted: '13,000원',
                        total_vat_amount: 0,
                        total_tax_free_amount: 0,
                        total_zero_rated_amount: 0,
                        ...tax,
                        payment: { pg_provider: 'nicepayments', payment_method: 'card' },
                    },
                },
            },
        },
    );
    utils.mockApi('sirsoft_pay_nicepayments', { response: {} });
    await utils.render();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await utils.rerender();
}

/**
 * 줄 이름 옆에 보이는 값을 읽습니다.
 *
 * @param label 줄 이름
 * @return 같은 줄의 글자
 */
function rowText(label: string): string {
    return (screen.getByText(label).parentElement as HTMLElement).textContent ?? '';
}

describe('나이스페이먼츠 마이페이지 — 세금 상세의 영세금액 줄', () => {
    beforeEach(() => {
        stubTemplateConfig();
    });

    afterEach(() => {
        utils?.cleanup();
        utils = null;
        resetFragmentRegistry();
        clearTemplateConfig();
    });

    /**
     * @scenario order_tax_mix=zero_rated_only
     * @effects zero_rated_only_order_shows_tax_breakdown
     */
    it('영세만 든 주문도 세금 상세 블록을 그리고 「영세금액」 줄을 보이며 면세 줄은 숨긴다', async () => {
        await renderSlot({
            total_zero_rated_amount: 13000,
            total_zero_rated_amount_formatted: '13,000원',
            total_taxable_supply_amount_formatted: '0원',
            total_vat_amount_formatted: '0원',
        });

        expect(screen.getByText('세금 상세')).toBeTruthy();
        expect(rowText('영세금액')).toContain('13,000원');
        expect(rowText('공급가액')).toContain('0원');
        expect(screen.queryByText('면세금액')).toBeNull();
    });

    /**
     * @scenario order_tax_mix=taxable_with_zero_rated
     * @effects zero_rated_row_separate_from_supply
     */
    it('과세·영세 혼합 주문은 공급가액·부가세 줄과 별도로 「영세금액」 줄을 보인다', async () => {
        await renderSlot({
            total_vat_amount: 1143,
            total_vat_amount_formatted: '1,143원',
            total_taxable_supply_amount_formatted: '11,428원',
            total_zero_rated_amount: 11429,
            total_zero_rated_amount_formatted: '11,429원',
        });

        expect(rowText('공급가액')).toContain('11,428원');
        expect(rowText('부가세')).toContain('1,143원');
        expect(rowText('영세금액')).toContain('11,429원');
    });

    /**
     * @scenario order_tax_mix=taxable_only
     * @effects zero_rated_row_hidden_without_zero_rated
     */
    it('영세가 없는 과세 주문은 「영세금액」 줄을 그리지 않는다', async () => {
        await renderSlot({
            total_vat_amount: 1000,
            total_vat_amount_formatted: '1,000원',
            total_taxable_supply_amount_formatted: '10,000원',
        });

        expect(screen.getByText('세금 상세')).toBeTruthy();
        expect(screen.queryByText('영세금액')).toBeNull();
    });
});
