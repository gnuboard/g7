/**
 * 나이스페이먼츠 화면 글자·결제 오류 알림은 번역 키로 낸다 (#599)
 *
 * 종전에는 주문 완료 화면 영수증 버튼(「영수증 조회」「로딩 중...」), 결제 실패 알림(「나이스페이먼츠 SDK를 불러올 수
 * 없습니다」「결제 중 오류가 발생했습니다」), 영수증 조각의 간편결제 이름표(「네이버페이」 등)를 한국어로 박아 두어 영어·일본어
 * 화면에서도 한국어로 나왔다. 이제 플러그인 번역 키(ko·en, 일본어는 언어팩)를 쓴다.
 * 주문 완료 화면 영수증 버튼은 #639 에서 주입 스크립트 대신 레이아웃 조각(user_order_complete_receipt)의 링크가 됐다.
 */

import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest, screen } from '../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';

import { requestPaymentHandler } from '../handlers/requestPayment';
import orderCompleteFragment from '../../extensions/user_order_complete_receipt.json';
import {
    clearTemplateConfig,
    findNodeById,
    resetFragmentRegistry,
    setupFragmentRegistry,
    stubTemplateConfig,
} from './layouts/fragmentRenderHelpers';

const EXT = path.resolve(__dirname, '../../extensions');
const JA: Record<string, string> = {
    'sirsoft-pay_nicepayments.user.receipt_view': '領収書を確認',
    'sirsoft-pay_nicepayments.payment.error.request_failed': '決済中にエラーが発生しました。',
};
/** 조각 렌더용 일본어 사전 (플러그인 이름공간 아래) */
const JA_DICTIONARY = {
    'sirsoft-pay_nicepayments': {
        user: {
            receipt_label: '領収書',
            view_receipt: '領収書を見る',
            view_sales_slip: '売上伝票を見る',
            view_cash_receipt: '現金領収書を確認',
            easy_pay_label: '簡単決済',
            easy_pay_names: { KAKAOPAY: 'カカオペイ' },
        },
    },
};

function fakeT(key: string): string {
    return JA[key] ?? key;
}

describe('나이스페이먼츠 — 화면 글자·오류 알림 번역 키', () => {
    let utils: ReturnType<typeof createLayoutTest> | null = null;

    beforeEach(() => {
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        utils?.cleanup();
        utils = null;
        resetFragmentRegistry();
        clearTemplateConfig();
        vi.restoreAllMocks();
        delete (window as any).G7Core;
        document.body.innerHTML = '';
    });

    /**
     * @scenario surface=order_complete_button
     * @effects text_from_translation_key
     */
    it('주문 완료 화면 영수증 링크 글자를 번역 키로 낸다', async () => {
        stubTemplateConfig();
        const block = findNodeById(orderCompleteFragment, 'nicepay_oc_receipt_block');
        utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'order_complete_receipt_text_test', components: [block] } as any,
            {
                translations: JA_DICTIONARY,
                locale: 'ko',
                componentRegistry: setupFragmentRegistry(),
                initialData: { orderData: { data: { order_number: 'ORD-OC-9', payment: { pg_provider: 'nicepayments', transaction_id: 'T-9', paid_at: '2026-09-28 10:00:00' } } } },
            },
        );
        utils.mockApi('sirsoft_pay_nicepayments', { response: { receipt_url: 'https://r.example/1', payment_receipt_url: 'https://r.example/2', embedded_pg_provider: 'kakaopay' } });
        await utils.render();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await utils.rerender();

        expect(screen.getByText('売上伝票を見る')).toBeTruthy();
        expect(screen.getByText('領収書を見る')).toBeTruthy();
        expect(screen.getByText('カカオペイ')).toBeTruthy();
        expect(document.body.textContent).not.toContain('영수증');
    });

    /**
     * @scenario surface=payment_error_toast
     * @effects text_from_translation_key
     */
    it('결제 요청 실패 알림을 번역 키로 낸다', async () => {
        const toastError = vi.fn();
        (window as any).G7Core = {
            t: fakeT,
            api: { get: vi.fn().mockRejectedValue(new Error('network')) },
            state: { setLocal: vi.fn() },
            toast: { error: toastError },
        };

        await requestPaymentHandler({ params: { pgPaymentData: { order_number: 'O-1', order_name: 'T', amount: 1 }, paymentMethod: 'card' } });

        expect(toastError).toHaveBeenCalledWith('決済中にエラーが発生しました。');
    });

    /**
     * @scenario surface=easy_pay_name
     * @effects text_from_translation_key
     */
    it('영수증 조각의 간편결제 이름은 번역 키로 풀고 한국어 이름표를 두지 않는다', () => {
        for (const name of ['user_mypage_order_receipt', 'user_order_complete_receipt', 'admin_order_payment_query']) {
            for (const ext of ['.g7.html', '.json']) {
                const text = readFileSync(path.join(EXT, name + ext), 'utf8');
                expect(text, name + ext).not.toContain('네이버페이');
                expect(text, name + ext).toContain("sirsoft-pay_nicepayments.user.easy_pay_names.");
            }
        }
    });
});
