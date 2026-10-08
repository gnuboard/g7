// e2e:allow 이커머스 전수 실측 후속 B — 입금 통지 표시는 실 PG 통지가 있어야 그려져 단위 구조 단언으로 고정한다(이력 B4). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * 관리자 주문 상세 「결제 조회」 창의 가상계좌 입금 통지 금액도 보는 언어의 원화 표기를 따른다 (B4 — S3-D23 같은 결함군)
 *
 * @description
 * 통지 금액(`noti.amt`)을 `toLocaleString() + '원'` 고정으로 적어, 영어 관리자에게 「5,000원」이 보였다. 서버·이커머스
 * 공용 포맷과 같은 번역 키(`sirsoft-ecommerce.currency.krw_prefix`·`krw_suffix`)로 적는다 — 나이스페이 가상계좌는 원화만 받는다.
 */

import { describe, it, expect } from 'vitest';
import paymentQuery from '../../../extensions/admin_order_payment_query.json';

describe('결제 조회 창 — 입금 통지 금액 표기', () => {
    /**
     * @scenario surface=vbank_notice
     *
     * @effects payment_query_notice_amount_follows_locale
     */
    it('고정 「원」 대신 원화 접두·접미 번역 키를 쓴다', () => {
        const text = JSON.stringify(paymentQuery);

        expect(text).not.toContain("toLocaleString() + '원'");
        expect(text).toContain('sirsoft-ecommerce.currency.krw_prefix');
        expect(text).toContain('sirsoft-ecommerce.currency.krw_suffix');
    });
});
