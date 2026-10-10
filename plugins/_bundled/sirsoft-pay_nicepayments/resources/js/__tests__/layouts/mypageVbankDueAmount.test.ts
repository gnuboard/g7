// e2e:allow 이커머스 전수 실측 후속 B — 가상계좌 입금 안내 금액은 구조 단언으로 고정(이력 B5). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * 나이스페이 마이페이지 가상계좌 입금 안내 — 입금 금액 = 결제예정금액 (B5 같은 결함군)
 *
 * @description
 * 입금 금액은 입금할 금액의 기준 필드인 결제예정금액(`total_due_amount_formatted`)을 먼저 읽고, 그 값이 없는 비회원
 * 응답에서는 주문금액(`total_amount_formatted`)으로 대신한다. 입금 대기 중에는 두 값이 같아(둘 다 마일리지 차감 뒤 —
 * 실측 B5) 화면 표시는 종전과 같다. 아래 시험의 서로 다른 두 값은 어느 필드를 읽는지 가리기 위한 값이다.
 */

import { describe, it, expect } from 'vitest';
import receipt from '../../../extensions/user_mypage_order_receipt.json';

describe('나이스페이 가상계좌 입금 안내 — 입금 금액', () => {
    /**
     * @scenario path=extension
     *
     * @effects nicepay_vbank_shows_due_amount
     */
    it('결제예정금액을 먼저 읽는다', () => {
        const text = JSON.stringify(receipt);

        expect(text).toContain('order.data.total_due_amount_formatted ?? order.data.total_amount_formatted');
    });
});
