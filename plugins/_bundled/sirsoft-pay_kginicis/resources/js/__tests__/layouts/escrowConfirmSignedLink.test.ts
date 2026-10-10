// e2e:allow 에스크로 [구매결정] — 실제 이니시스 에스크로 결제(구매결정 창 payEscrowMain)가 있어야 해 브라우저 E2E 로 재현할 수 없다. 대체 검증: 이 Vitest + PHPUnit EscrowConfirmSignedLinkTest + 전용 Chrome 실측(이커머스 전수 실측 S4-D19)
/**
 * @file escrowConfirmSignedLink.test.ts
 * @description 주문 상세 KG이니시스 [구매결정] 링크가 영수증 조회 응답의 한시 서명 주소를 쓰는지 (이커머스 전수 실측 S4 — S4-D19)
 *
 * 결함: 링크가 `/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/{주문번호}` 를 그대로 새 창으로 열어, 토큰 로그인 회원도
 *   구매결정 창에서 401 「로그인한 뒤 주문 상세에서 구매결정을 다시 진행해 주세요.」 를 봤다. 조각이 마운트 때 부르는 영수증
 *   조회(토큰 인증) 응답의 `escrow_confirm_url`(본인 에스크로 결제에만, 10분 서명)을 로컬 상태에 담아 그 주소로 연다.
 */

import { describe, it, expect } from 'vitest';
import extensionLayout from '../../../extensions/user_order_show.json';

type Node = Record<string, unknown>;

/** 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, match: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') {
    return out;
  }
  const value = node as Node;
  if (match(value)) {
    out.push(value);
  }
  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** `{{ … }}` 표현식을 화면 컨텍스트로 평가한다 */
function evaluate(text: string, ctx: Record<string, unknown>): unknown {
  const expr = text.replace(/^\{\{([\s\S]+)\}\}$/, '$1');
  const names = Object.keys(ctx);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${expr});`)(...names.map((name) => ctx[name]));
}

const confirmLinks = collect(extensionLayout, (n) => n.name === 'A' && JSON.stringify(n.children ?? []).includes('escrow_confirm_button'));
const receiptCall = collect(extensionLayout, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('/receipt'))[0];

describe('KG이니시스 [구매결정] 링크 — 한시 서명 주소', () => {
  /**
   * @scenario case=receipt_success_stores_link
   * @effects link_uses_signed_url_from_receipt_api
   */
  it('영수증 조회 성공 때 escrow_confirm_url 을 로컬 상태에 담는다', () => {
    expect(receiptCall).toBeDefined();
    expect(JSON.stringify(receiptCall.onSuccess)).toContain('response.data.escrow_confirm_url');
  });

  /**
   * @scenario case=link_href_signed
   * @effects link_uses_signed_url_from_receipt_api
   */
  it('[구매결정] 링크는 그 서명 주소로 열고, 주소가 없으면 보이지 않는다', () => {
    expect(confirmLinks.length).toBe(1);
    const link = confirmLinks[0];
    const props = link.props as Node;
    expect(String(props.href)).not.toContain('/payment/escrow-confirm/{{');
    const signed = '/plugins/sirsoft-pay_kginicis/payment/escrow-confirm/20261007-1?expires=1&uid=7&signature=x';
    const order = { data: { order_number: '20261007-1', payment: { is_escrow: true } } };
    expect(evaluate(String(props.href), { _local: { _kgEscrowConfirmUrl: signed }, order })).toBe(signed);
    const visible = (local: Record<string, unknown>) => collect(extensionLayout, (n) => Array.isArray(n.children) && (n.children as Node[]).includes(link))
      .every((parent) => !parent.if || Boolean(evaluate(String(parent.if), { _local: local, order })));
    expect(visible({ _kgEscrowConfirmUrl: signed })).toBe(true);
    expect(visible({ _kgEscrowConfirmUrl: null })).toBe(false);
  });
});
