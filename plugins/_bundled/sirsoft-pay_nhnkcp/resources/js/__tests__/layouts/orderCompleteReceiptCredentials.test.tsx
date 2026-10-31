/**
 * @file orderCompleteReceiptCredentials.test.tsx
 * @description 주문 완료 — NHN KCP 매출전표 불러오기 요청의 자격 증명
 *
 * 예전에는 주입 스크립트가 주문 완료 주소를 정규식으로 판정하고, 버튼 색 클래스(bg-blue-600)로 자리를 찾아 버튼을
 * 손으로 만들어 붙였으며, 로그인 토큰을 저장소에서 직접 읽어 Bearer 를 붙였다. 이제 주문 완료 조각
 * (user_order_complete_receipt)이 그 일을 맡는다. 주문 완료 화면은 회원·비회원이 함께 쓰므로 조각의 요청은
 * 선택 인증(auth_mode=optional)으로 회원 토큰을 싣고, 비회원은 공개 상태 `_global.guestOrderToken` 을
 * X-Guest-Order-Token 헤더로 싣는다. 배포 JSON 의 버튼을 그대로 엔진에 태워 실제로 나가는 요청을 본다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/order-screen-fragments.yaml
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import { actionsOf, flattenNodes } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutShapeUtils';
import extension from '../../../extensions/user_order_complete_receipt.json';

/** 매출전표 요청 주소의 고유 조각 */
const TARGET = '/user/orders/';

const receiptButton = flattenNodes(extension).find(
  (n) => n.name === 'Button' && JSON.stringify(actionsOf(n)).includes(TARGET),
) as any;

let utils: ReturnType<typeof createLayoutTest> | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  for (const [name, tag] of Object.entries({ Button: 'button', Span: 'span', Icon: 'i' })) {
    registry.registry[name] = {
      component: ({ text, children, ...props }: any) => React.createElement(tag, props, children || text),
      metadata: { name, type: 'basic' },
    };
  }
  registry.registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
});

afterEach(() => {
  utils?.cleanup();
  utils = null;
  (ComponentRegistry.getInstance() as any).registry = {};
});

/**
 * 주어진 전역 상태로 주문 완료 매출전표 버튼을 띄운다.
 *
 * @param global 전역 상태
 * @returns 테스트 도구
 */
async function mount(global: Record<string, unknown>) {
  utils = createLayoutTest({ version: '1.0.0', layout_name: 'order_complete_receipt', components: [receiptButton] } as any, {
    initialState: { _global: global },
    initialData: { route: { id: 'ORD-1' } },
    routeParams: { id: 'ORD-1' },
  });
  await utils.render();

  // 렌더 도구가 자기 fetch 를 끼우므로, 버튼 요청을 볼 fetch 는 렌더 뒤에 바꿔 끼운다.
  fetchMock = vi.fn(async () => new Response(JSON.stringify({
    success: true,
    data: { receipt_url: 'https://admin8.kcp.co.kr/r/1', cash_receipt_url: null },
  }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  globalThis.fetch = fetchMock as any;

  return utils;
}

/**
 * 마지막으로 나간 매출전표 요청의 헤더를 돌려준다.
 *
 * @returns 요청 헤더(소문자 키)
 */
function lastRequestHeaders(): Record<string, string> {
  const receiptCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/receipt'));
  expect(receiptCalls.length, '매출전표 요청이 나가지 않음').toBeGreaterThan(0);
  const init = (receiptCalls[receiptCalls.length - 1][1] ?? {}) as RequestInit;
  const out: Record<string, string> = {};
  new Headers(init.headers as HeadersInit).forEach((value, key) => { out[key] = value; });

  return out;
}

describe('주문 완료 NHN KCP 매출전표 불러오기', () => {
  // @scenario case=order_complete_guest
  // @effects guest_token_header_sent
  it('비회원: 공개 상태의 조회 토큰을 X-Guest-Order-Token 헤더로 싣는다', async () => {
    expect(receiptButton, '매출전표 버튼을 찾지 못함').toBeTruthy();
    const t = await mount({ guestOrderToken: 'guest-token-1' });

    await t.triggerAction(actionsOf(receiptButton)[0]);

    expect(lastRequestHeaders()['x-guest-order-token']).toBe('guest-token-1');
  });

  // @scenario case=order_complete_member
  // @effects member_token_sent_optionally
  it('회원: 선택 인증이라 토큰이 없어도 요청을 막지 않고, 매출전표 주소는 응답 data 에서 읽는다', async () => {
    expect(JSON.stringify(actionsOf(receiptButton))).toContain('"auth_mode":"optional"');

    const t = await mount({});
    await t.triggerAction(actionsOf(receiptButton)[0]);

    // 회원 토큰이 없어도(선택 인증) 요청은 나간다 — 주소의 주문번호는 화면 주소(route.id)에서 온다.
    const urls = fetchMock.mock.calls.map(([url]) => String(url));
    expect(urls.some((url) => /^\/api\/plugins\/sirsoft-pay_nhnkcp\/user\/orders\/[^/]*\/receipt$/.test(url)), JSON.stringify(urls)).toBe(true);
    expect(t.getState()._local._kcpOcReceiptUrl).toBe('https://admin8.kcp.co.kr/r/1');
  });
});
