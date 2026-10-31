/**
 * @file adminOrderEasyPayLabel.test.tsx
 * @description 관리자 주문 상세 — NHN KCP 패널이 간편결제 원 수단을 보여 준다
 *
 * 예전에는 주입 스크립트가 이커머스 결제 정보 카드의 결제수단 칸 글자를 직접 고쳤다(경로 정규식·DOM 폴링·
 * history.pushState 덮어쓰기). 이제 그 정보는 이 플러그인 조각(admin_order_payment_query)의 KCP 패널이 자기
 * 데이터 소스(kcp_status)로 보여 준다. 배포 JSON 의 패널을 그대로 엔진에 태워 확인한다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/order-screen-fragments.yaml
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import { flattenNodes } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutShapeUtils';
import extension from '../../../extensions/admin_order_payment_query.json';

/** 간편결제 이름이 그려지는 칸의 표식 */
const LABEL_TESTID = 'nhnkcp-admin-easy-pay-label';

const panel = flattenNodes(extension).find((n) => n.id === 'kcp_info_panel') as any;

let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  for (const [name, tag] of Object.entries({ Div: 'div', Span: 'span', H3: 'h3', Icon: 'i' })) {
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
 * 주어진 거래 상태로 KCP 패널을 띄운다.
 *
 * @param status kcp_status 데이터 소스의 data
 * @returns 렌더된 컨테이너
 */
async function mount(status: Record<string, unknown>): Promise<HTMLElement> {
  utils = createLayoutTest({ version: '1.0.0', layout_name: 'kcp_panel', components: [panel] } as any, {
    initialData: { kcp_status: { data: status } },
    routeParams: { orderNumber: 'ORD-1' },
  });
  const { container } = await utils.render();

  return container;
}

describe('관리자 주문 상세 KCP 패널 — 간편결제 원 수단', () => {
  // @scenario case=admin_easy_pay
  // @effects easy_pay_label_shown_in_own_panel
  it('간편결제 거래면 패널에 원 수단 이름이 보인다', async () => {
    expect(panel, 'kcp_info_panel 을 찾지 못함').toBeTruthy();

    const container = await mount({
      tno: 'T-1',
      payment_status: 'paid',
      res_cd: '0000',
      _embedded_pg_provider_label: '카카오페이',
    });

    expect(container.querySelector(`[data-testid="${LABEL_TESTID}"]`)?.textContent).toBe('카카오페이');
  });

  // @scenario case=admin_general
  // @effects easy_pay_row_hidden_for_general_payment
  it('일반 결제면 간편결제 칸을 그리지 않는다', async () => {
    const container = await mount({ tno: 'T-2', payment_status: 'paid', res_cd: '0000', _embedded_pg_provider_label: null });

    expect(container.querySelector(`[data-testid="${LABEL_TESTID}"]`)).toBeNull();
  });

  // @scenario case=admin_easy_pay
  // @effects host_screen_text_untouched
  it('이커머스 결제수단 칸(payment_method_block_*)을 고치지 않고 자기 패널 안에만 그린다', () => {
    const serialized = JSON.stringify(extension);

    expect(serialized).not.toContain('payment_method_block_');
    expect(JSON.stringify(panel)).toContain('_embedded_pg_provider_label');
  });
});
