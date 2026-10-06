/**
 * @file mypageWithdrawRefundCreditNotice.test.ts
 * @description 탈퇴 확인 창의 「환불로 받은 마일리지 소멸」 안내 확장 구조 계약 (Q20)
 *
 * 현금 환불을 마일리지로 받은 회원이 탈퇴하면 그 돈도 소멸한다. 확장은 템플릿 탈퇴 확인 창 경고 목록 끝의
 * 확장점(`withdraw_warnings`)에 항목 하나를 넣고, 회원 응답의 `refund_credit_available` 이 0 보다 클 때만 선다.
 *
 * @vitest-environment node
 */

import { describe, expect, it } from 'vitest';

import notice from '../../../extensions/mypage-withdraw-refund-credit-notice.json';

type Node = Record<string, any>;

/**
 * if 식을 user 값으로 평가한다.
 *
 * @param expr `{{…}}` 식
 * @param user 사용자 데이터
 * @returns 평가 결과
 */
function evaluate(expr: string, user: Node): boolean {
  const body = expr.replace(/^\{\{/, '').replace(/\}\}$/, '');

  // eslint-disable-next-line no-new-func
  return Boolean(new Function('user', `return (${body});`)(user));
}

describe('탈퇴 확인 창 — 환불로 받은 마일리지 소멸 안내 (Q20)', () => {
  /**
   * @scenario refund_credit=present
   * @effects withdraw_modal_warns_refund_credit_forfeiture
   */
  it('탈퇴 확인 창의 확장점(withdraw_warnings)에 항목을 넣고 금액이 있을 때만 선다', () => {
    // 경고 목록은 모달 안에 있다 — 서버 오버레이 주입(target_id)은 레이아웃 components 트리만 찾아 모달 안 노드를 못 찾는다
    // (실측: 「Layout extension target not found」 로그, 경고 미노출). 모달까지 순회하는 확장점 주입을 쓴다(#747 C12 W1).
    const data = notice as Node;
    expect(data.extension_point).toBe('withdraw_warnings');
    expect(data.injections).toBeUndefined();

    const item = data.components[0];
    expect(item.id).toBe('ext_withdraw_refund_credit_warning');
    expect(evaluate(item.if, { data: { ecommerce_mileage: { refund_credit_available: 3000 } } })).toBe(true);
    expect(evaluate(item.if, { data: { ecommerce_mileage: { refund_credit_available: 0 } } })).toBe(false);
    expect(evaluate(item.if, { data: {} })).toBe(false);
    expect(JSON.stringify(item)).toContain('$t:sirsoft-ecommerce.user_mileage.withdraw_refund_credit_warning|amount=');
  });
});
