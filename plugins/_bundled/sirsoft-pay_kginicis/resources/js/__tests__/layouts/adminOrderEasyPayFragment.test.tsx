/**
 * @file adminOrderEasyPayFragment.test.tsx
 * @description 관리자 주문 상세 › 결제수단 칸의 간편결제 이름 조각(admin_order_payment_query 의 payment_method_block 주입)
 *
 * 예전에는 주입 스크립트(adminOrderPaymentDisplayInjector)가 `/admin/ecommerce/orders/…` 주소 글자를 맞추고
 * 로그인 토큰을 저장소에서 직접 읽어 거래 상태를 다시 조회한 뒤, 이커머스가 그린 결제수단 칸 글자를 덮어썼다.
 * history.pushState 도 덮어써 화면 이동을 엿봤다(#639). 이제 같은 조각의 결제 상태 데이터 소스(kginicis_status)를
 * 그대로 쓰고, 이커머스가 약속한 결제수단 칸(payment_method_block_{결제 순번}) 안에 한 줄을 덧붙인다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/admin-order-easy-pay-fragment.yaml
 */

import { afterEach, describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import extension from '../../../extensions/admin_order_payment_query.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { renderFragment, type LayoutTest } from './fragmentTestKit';

/** 결제수단 칸에 붙는 주입 */
const INJECTION = (extension as any).injections.find((i: any) => String(i.target_id).startsWith('payment_method_block_'));

/**
 * 이커머스의 결제 건 반복 안 결제수단 칸을 흉내 내고 그 안에 조각을 붙인다.
 *
 * @returns 결제 건 반복 컴포넌트
 */
function hostWithInjection(): unknown[] {
    return [{
        id: 'payment_cards_{{payIdx}}',
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{order.data?.payments ?? []}}', item_var: 'payment', index_var: 'payIdx' },
        children: [{
            id: 'payment_method_block_{{payIdx}}',
            type: 'basic',
            name: 'Div',
            children: [
                { type: 'basic', name: 'Span', text: '{{payment.payment_method_label}}' },
                ...INJECTION.components,
            ],
        }],
    }];
}

let utils: LayoutTest | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
});

describe('관리자 주문 상세 결제수단 칸의 간편결제 이름', () => {
    // @scenario case=anchor
    // @effects attaches_to_ecommerce_block_id_only
    it('이커머스가 약속한 결제수단 칸 id 에 자식으로 붙고, 칸 값은 바꾸지 않는다', () => {
        expect(INJECTION.target_id).toBe('payment_method_block_{{payIdx}}');
        expect(INJECTION.position).toBe('append_child');
        expect((extension as any).data_sources.map((d: any) => d.id)).toContain('kginicis_status');
    });

    // @scenario case=easy_pay
    // @effects easy_pay_name_from_status_data_source
    it('이니시스 결제 칸에만 결제 상태 응답의 간편결제 이름을 한 줄 덧붙인다', async () => {
        utils = await renderFragment(hostWithInjection(), {
            order: {
                data: {
                    payments: [
                        { pg_provider: 'kginicis', payment_method_label: '신용카드' },
                        { pg_provider: null, payment_method_label: '무통장입금' },
                    ],
                },
            },
            kginicis_status: { data: { _embedded_pg_provider_label: '카카오페이', _pay_method_label: '카카오페이 (신용카드)' } },
        });

        expect(screen.getByText('신용카드')).toBeTruthy();
        expect(screen.getAllByText('간편결제: 카카오페이')).toHaveLength(1);
        expect(screen.getByText('무통장입금')).toBeTruthy();
    });

    // @scenario case=no_easy_pay
    // @effects easy_pay_name_from_status_data_source
    it('간편결제가 아니거나 결제 상태를 아직 받지 못했으면 아무것도 덧붙이지 않는다', async () => {
        utils = await renderFragment(hostWithInjection(), {
            order: { data: { payments: [{ pg_provider: 'kginicis', payment_method_label: '신용카드' }] } },
            kginicis_status: { data: { _embedded_pg_provider_label: null, _pay_method_label: '신용카드' } },
        });
        expect(screen.queryByText(/간편결제/)).toBeNull();
        utils.cleanup();

        utils = await renderFragment(hostWithInjection(), {
            order: { data: { payments: [{ pg_provider: 'kginicis', payment_method_label: '신용카드' }] } },
        });
        expect(screen.queryByText(/간편결제/)).toBeNull();
    });

    // @scenario case=i18n
    // @effects easy_pay_label_translated
    it('덧붙이는 문구는 ko·en 번역 키를 쓴다', () => {
        expect(JSON.stringify(INJECTION)).toContain('$t:sirsoft-pay_kginicis.admin.easy_pay_provider|provider=');
        expect((ko as any).admin.easy_pay_provider).toContain('{{provider}}');
        expect((en as any).admin.easy_pay_provider).toContain('{{provider}}');
    });
});
