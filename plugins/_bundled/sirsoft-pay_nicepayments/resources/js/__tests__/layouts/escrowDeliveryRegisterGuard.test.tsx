/**
 * @file escrowDeliveryRegisterGuard.test.tsx
 * @description 관리자 주문 상세 — 에스크로 배송 등록의 필수값 잠금과 실패 문구
 *
 * 등록 실패 처리가 실패 문맥에 없는 `response` 를 읽어 어떤 실패든 일반 문구만 보였고,
 * 필수값이 비어도 버튼이 눌려 서버 422 로만 막혔다. 실패 문맥의 변수는 `error` 다.
 * 배포 JSON 의 버튼·액션을 그대로 엔진에 태워 확인한다.
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import { actionsOf, flattenNodes } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutShapeUtils';
import extension from '../../../extensions/admin_order_payment_query.json';

/** 등록 API 대상 주소의 고유 조각 */
const TARGET = '/escrow/register-delivery';
/** 필수값이 모두 찬 화면 상태 */
const FILLED: Record<string, string> = { _escrow_delivery_name: 'CJ대한통운', _escrow_tracking_number: '123456789', _escrow_register_name: '홍길동', _escrow_buyer_address: '서울시' };
/** 실패 문구가 담기는 화면 상태 키 */
const ERROR_KEY = '_escrowError';

const registerButton = flattenNodes(extension).find(
  (n) => n.name === 'Button' && JSON.stringify(actionsOf(n)).includes(TARGET),
) as any;

let utils: ReturnType<typeof createLayoutTest> | null = null;

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
 * 주어진 화면 상태로 등록 버튼을 띄운다.
 *
 * @param local 화면 상태
 */
async function mount(local: Record<string, unknown>) {
  utils = createLayoutTest({ version: '1.0.0', layout_name: 'escrow_register', components: [registerButton] } as any, {
    initialState: { _local: local },
    // 등록 버튼은 호스트 주문 응답의 주문 수정 권한(abilities.can_update)으로도 잠긴다(7.1.0 G04) — 권한이 있는 관리자 맥락으로 띄운다
    initialData: { payment: { transaction_id: 'TID-1' }, route: { orderNumber: 'ORD-1' }, order: { data: { abilities: { can_update: true } } } },
    routeParams: { orderNumber: 'ORD-1' },
  });
  const { container } = await utils.render();
  return { utils, button: container.querySelector('button') as HTMLButtonElement };
}

/**
 * 등록 API 가 주어진 상태·본문으로 실패하도록 fetch 를 바꿔 끼운다.
 *
 * @param status 응답 상태 코드
 * @param body 응답 본문
 */
function stubFailure(status: number, body: Record<string, unknown>): void {
  globalThis.fetch = vi.fn(async () =>
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  ) as any;
}

describe('에스크로 배송 등록', () => {
  /**
   * @scenario flow=escrow_delivery_register
   * @effects escrow_register_requires_fields_and_shows_server_reason
   */
  it('필수값이 하나라도 비면 등록 버튼이 잠기고, 모두 채우면 열린다', async () => {
    expect(registerButton).toBeTruthy();
    for (const key of Object.keys(FILLED)) {
      const { button } = await mount({ ...FILLED, [key]: '  ' });
      expect(button.disabled).toBe(true);
      utils?.cleanup();
      utils = null;
    }
    const { button } = await mount(FILLED);
    expect(button.disabled).toBe(false);
  });

  /**
   * @scenario flow=escrow_delivery_register
   * @effects escrow_register_requires_fields_and_shows_server_reason
   */
  it('PG 가 거절하면 서버가 준 사유를 그대로 보인다', async () => {
    const { utils: t } = await mount(FILLED);
    stubFailure(502, { success: false, message: '실패했습니다.', errors: { message: ['[PG] 이미 등록된 송장입니다.'] } });

    await t.triggerAction(actionsOf(registerButton)[0]);

    expect(t.getState()._local[ERROR_KEY]).toBe('[PG] 이미 등록된 송장입니다.');
  });

  /**
   * @scenario flow=escrow_delivery_register
   * @effects escrow_register_requires_fields_and_shows_server_reason
   */
  it('검증 오류면 그 항목의 문구를 보인다', async () => {
    const { utils: t } = await mount(FILLED);
    stubFailure(422, { success: false, message: '입력값 오류', errors: { tracking_number: ['송장번호는 필수입니다.'] } });

    await t.triggerAction(actionsOf(registerButton)[0]);

    expect(t.getState()._local[ERROR_KEY]).toBe('송장번호는 필수입니다.');
  });
});
