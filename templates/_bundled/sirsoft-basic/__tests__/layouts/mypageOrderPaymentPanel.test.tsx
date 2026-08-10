/**
 * @file mypageOrderPaymentPanel.test.tsx
 * @description 유저 주문상세 결제 패널의 확장 앵커 검증 (#454 S3).
 *
 * 두 가지를 고정한다:
 *  1. 루트 Div 의 id = "order_payment_info_panel"
 *     KG 플러그인(resources/extensions/user_order_show.json)이 이 id 를 target_id 로 지정하고
 *     mypageOrderShowInjector.ts 가 getElementById 로 찾는다. 그동안 이 노드에 id 가 없어
 *     주입이 조용히 no-op 이었다 — 본 이슈가 부수 복구한다(R1). id 를 지우면 KG 세금내역·
 *     영수증 링크·에스크로 확인 버튼이 한꺼번에 사라진다.
 *  2. 말단 extension_point "mypage_order_cash_receipt_slot"
 *     이커머스 모듈이 현금영수증 카드를 주입하는 자리.
 */

import { describe, it, expect } from 'vitest';
import paymentPartialJson from '../../layouts/partials/mypage/orders/_payment.json';
import kgUserOrderShowJson from '../../../../../plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.json';

function flatten(node: any, out: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return out;
  out.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((v) => flatten(v, out));
    else if (value && typeof value === 'object') flatten(value, out);
  }
  return out;
}

describe('유저 주문상세 결제 패널 — 확장 앵커', () => {
  it('루트 Div 에 KG 가 기대하는 id 가 부여되어야 한다 (R1 no-op 주입 복구)', () => {
    expect(paymentPartialJson.id).toBe('order_payment_info_panel');
  });

  it('KG overlay 의 target_id 와 실제 앵커 id 가 일치해야 한다', () => {
    const injection = (kgUserOrderShowJson as any).injections[0];
    expect(injection.target_id).toBe(paymentPartialJson.id);
    // append_child = 패널 내부 말단에 붙는다 → 앵커가 컨테이너여야 한다
    expect(injection.position).toBe('append_child');
    expect(Array.isArray(paymentPartialJson.children)).toBe(true);
  });

  it('현금영수증 확장 슬롯이 패널 말단에 존재해야 한다', () => {
    const slots = flatten(paymentPartialJson).filter((n) => n.type === 'extension_point');
    expect(slots.map((s) => s.name)).toContain('mypage_order_cash_receipt_slot');

    const slot = slots.find((s) => s.name === 'mypage_order_cash_receipt_slot');
    expect(slot.default).toEqual([]);
  });

  it('슬롯은 결제 정보 목록의 마지막 자식이어야 한다 (결제수단 행 아래)', () => {
    const infoList = (paymentPartialJson.children as any[]).find(
      (c) => (c.props?.className ?? '').includes('space-y-3')
    );
    const last = infoList.children[infoList.children.length - 1];
    expect(last.type).toBe('extension_point');
    expect(last.name).toBe('mypage_order_cash_receipt_slot');
  });

  it('템플릿은 현금영수증 도메인 필드를 직접 그리지 않는다 (모듈 소유)', () => {
    // 슬롯 이름/주석에는 'cash_receipt' 가 들어간다(정상). 금지 대상은 도메인 값 바인딩이다.
    const bindings = flatten(paymentPartialJson)
      .filter((n) => n.type !== 'extension_point')
      .flatMap((n) => [n.text, ...Object.values(n.props ?? {})])
      .filter((v): v is string => typeof v === 'string');

    for (const b of bindings) {
      expect(b, `템플릿이 현금영수증 필드를 직접 바인딩 → ${b}`).not.toContain('cash_receipt');
    }
  });

  it('id 부여가 기존 responsive.portable 규약을 깨지 않는다', () => {
    expect(Object.keys(paymentPartialJson.responsive)).toEqual(['portable']);
  });
});
