/**
 * @file mypageMockDeposit.test.tsx
 * @description 내 주문 상세 — NHN KCP 테스트 모드 모의입금 도구 조각
 *
 * 예전에는 주입 스크립트가 주문 상세 주소를 정규식으로 판정하고 DOM 을 폴링해 모의입금 폼을 손으로 만들어 붙였다
 * (로그인 토큰을 저장소에서 직접 읽고 history.pushState 를 덮어썼다). 이제 결제 정보 카드 슬롯 조각
 * (user_order_show)이 데이터 소스(nhnkcp_mock_deposit)로 정보 API 를 받아 그린다. 정보 API 는 공통 형식
 * ({ success, message, data })이고, 조건이 맞지 않으면 data.available=false 다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/vbank-mock-deposit.yaml
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import { flattenNodes } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutShapeUtils';
import extension from '../../../extensions/user_order_show.json';

/** 모의입금 도구 루트의 표식 */
const BLOCK_TESTID = 'nhnkcp-mock-deposit';

const block = flattenNodes(extension).find((n) => (n.props as any)?.['data-testid'] === BLOCK_TESTID) as any;
const dataSource = ((extension as any).data_sources ?? []).find((d: any) => d.id === 'nhnkcp_mock_deposit');

/** 가상계좌 입금 대기 NHN KCP 주문 */
const VBANK_ORDER = { order_number: 'ORD-1', payment: { pg_provider: 'nhnkcp', payment_method: 'vbank', vbank_number: '123-456' } };

/** 정보 API 가 쓸 수 있다고 답한 값 */
const AVAILABLE = {
  available: true,
  trade_no: 'TNO-1',
  account_no: '123-456',
  notify_url: 'https://shop.example/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify',
  mock_url: 'https://devadmin.kcp.co.kr/Modules/Noti/TEST_Vcnt_Noti_Proc.jsp',
  is_admin_view: false,
};

let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  const tags: Record<string, string> = { Div: 'div', Span: 'span', P: 'p', Form: 'form', Input: 'input', Button: 'button' };
  for (const [name, tag] of Object.entries(tags)) {
    registry.registry[name] = {
      component: ({ text, children, ...props }: any) => (tag === 'input'
        ? React.createElement(tag, { ...props, defaultValue: props.value, value: undefined })
        : React.createElement(tag, props, children || text)),
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
 * 주어진 주문·정보 API 값으로 모의입금 도구를 띄운다.
 *
 * @param order 주문 데이터 소스의 data
 * @param info 모의입금 정보 데이터 소스의 data (없으면 아직 안 받은 상태)
 * @returns 렌더된 컨테이너
 */
async function mount(order: Record<string, unknown>, info?: Record<string, unknown>): Promise<HTMLElement> {
  const initialData: Record<string, unknown> = { order: { data: order } };
  if (info) initialData.nhnkcp_mock_deposit = { data: info };
  utils = createLayoutTest({ version: '1.0.0', layout_name: 'kcp_mock', components: [block] } as any, {
    initialData,
    routeParams: { order_number: 'ORD-1' },
  });
  const { container } = await utils.render();

  return container;
}

describe('내 주문 상세 — 모의입금 도구', () => {
  // @scenario case=block_available
  // @effects mock_block_reads_envelope_data
  it('정보 API 가 쓸 수 있다고 답하면 data 의 값으로 KCP 테스트 입금통보 폼을 그린다', async () => {
    expect(block, '모의입금 도구 블록을 찾지 못함').toBeTruthy();

    const container = await mount(VBANK_ORDER, AVAILABLE);
    const form = container.querySelector('form') as HTMLFormElement;

    expect(form).not.toBeNull();
    expect(form.getAttribute('action')).toBe(AVAILABLE.mock_url);
    expect(form.getAttribute('method')?.toLowerCase()).toBe('post');
    expect(form.getAttribute('target')).toBe('_blank');
    const value = (name: string) => (container.querySelector(`input[name="${name}"]`) as HTMLInputElement | null)?.value;
    expect(value('e_trade_no')).toBe('TNO-1');
    expect(value('deposit_no')).toBe('123-456');
    expect(value('noti_url')).toBe(AVAILABLE.notify_url);
    expect(value('req_name')).toBe('NHN KCP');
    expect(container.querySelector('button[type="submit"]')).not.toBeNull();
  });

  // @scenario case=block_unavailable
  // @effects unavailable_as_data_flag
  it('정보 API 가 available=false 로 답하거나 아직 답하지 않았으면 그리지 않는다', async () => {
    expect((await mount(VBANK_ORDER, { available: false })).querySelector(`[data-testid="${BLOCK_TESTID}"]`)).toBeNull();
    utils?.cleanup();
    utils = null;
    expect((await mount(VBANK_ORDER)).querySelector(`[data-testid="${BLOCK_TESTID}"]`)).toBeNull();
  });

  // @scenario case=block_unavailable
  // @effects mock_block_only_for_own_pg
  it('다른 결제대행사 주문이면 정보가 와도 그리지 않는다', async () => {
    const container = await mount({ ...VBANK_ORDER, payment: { ...VBANK_ORDER.payment, pg_provider: 'tosspayments' } }, AVAILABLE);

    expect(container.querySelector(`[data-testid="${BLOCK_TESTID}"]`)).toBeNull();
  });

  // @scenario case=block_available
  // @effects mock_block_text_translated
  it('도구의 제목·입력 이름·버튼·관리자 안내는 번역 키로 그린다', () => {
    const texts = flattenNodes(block).map((n) => String(n.text ?? '')).filter((t) => t.trim() !== '');

    for (const key of ['mock_title', 'mock_trade_no', 'mock_deposit_no', 'mock_depositor', 'mock_notify_url', 'mock_submit', 'mock_admin_notice']) {
      expect(texts).toContain(`$t:sirsoft-pay_nhnkcp.user.${key}`);
    }
    expect(texts.every((t) => t.startsWith('$t:'))).toBe(true);
  });

  // @scenario case=owner_test_mode
  // @effects mock_info_requested_by_members_only
  it('정보는 로그인 회원만, 주소에 주문번호가 있는 주문 상세에서만 요청한다', () => {
    expect(dataSource, 'nhnkcp_mock_deposit 데이터 소스를 찾지 못함').toBeTruthy();
    expect(dataSource.auth_mode).toBe('required');
    expect(dataSource.if).toBe('{{!!route.order_number}}');
    expect(dataSource.endpoint).toBe('/api/plugins/sirsoft-pay_nhnkcp/user/orders/{{route.order_number}}/vbank-mock-deposit-info');
  });
});
