// e2e:allow 이커머스 전수 실측 후속 B — 전용 Chrome(CDP)으로 저장 배송지 주문·새 주소 주문의 주문완료 화면을 재실측(이력 B6 실측 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file order-complete-save-address.test.tsx
 * @description 주문완료 [이 배송지를 저장하기] — 이미 주소록에 있는 주소면 숨기고, 409 는 질문 대신 안내 (B6)
 *
 * 저장 배송지로 주문해도 주문완료에 버튼이 늘 보였고, 누르면 같은 주소가 「배송지」 이름으로 또 쌓이거나, 같은 이름이
 * 이미 있으면 서버 409 「동일한 이름의 배송지가 이미 존재합니다. 덮어쓰시겠습니까?」 가 답할 수 없는 토스트로 떴다
 * (전수 실측 S4, 제품 결정 — 세션 권장 ①).
 *  1. 회원의 주소록을 함께 받아 주문 배송지와 같은 항목(수령인·연락처 숫자·국가·우편번호·주소·상세주소 / 해외는 주소줄·도시·주·우편번호)이 있으면 버튼을 숨긴다.
 *  2. 저장에 성공하면 주소록을 다시 받아 버튼이 사라진다(같은 주소를 두 번 쌓지 않는다).
 *  3. 409 는 질문형 서버 문구 대신 안내 문구를 띄운다.
 *  4. 비회원은 주소록을 못 받아도(401) 화면을 막지 않는다 — 실패는 빈 주소록으로 떨어진다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import layout from '../../../layouts/shop/order_complete.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 첫 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @returns 찾은 노드
 */
function find(node: unknown, match: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = find(child, match);
      if (found) return found;
    }

    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  if (match(node as Node)) return node as Node;
  for (const value of Object.values(node as Node)) {
    const found = find(value, match);
    if (found) return found;
  }

  return undefined;
}

const saveButton = find(layout, (n) => n.name === 'Button' && JSON.stringify(n.children ?? n.text ?? '').includes('shop.order_complete.save_shipping_address')
  || (n.name === 'Button' && String(n.text ?? '').includes('shop.order_complete.save_shipping_address'))) as Node;

const KR_ORDER_ADDRESS = {
  recipient_name: '홍길동', recipient_phone: '010-1234-5678', recipient_country_code: 'KR',
  zipcode: '06236', address: '서울특별시 강남구 테헤란로 123', address_detail: '4층',
};

/**
 * 버튼 노드만 프로브로 렌더한다 — 데이터 소스·computed 정의는 레이아웃에서 그대로 가져온다.
 *
 * @param savedAddresses 주소록 항목 (null 이면 401)
 * @param registry 부품 레지스트리
 * @returns 테스트 유틸
 */
async function renderButton(savedAddresses: Node[] | null, registry: MockComponentRegistry) {
  const sources = ((layout as Node).data_sources ?? []).filter((d: Node) => ['orderData', 'userAddresses'].includes(d.id));
  const utils = createLayoutTest({
    version: '1.0.0', layout_name: 'test/order-complete-save-address', data_sources: sources, components: [{ ...saveButton, props: { ...(saveButton?.props ?? {}), 'data-testid': 'save-address' } }],
  } as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    initialData: { _computedDefinitions: (layout as Node).computed },
    initialState: { _global: { currentUser: { uuid: 'u-1' } } },
  });
  utils.mockApi('orderData', { response: { success: true, data: { order_number: 'O-1', shipping_address: KR_ORDER_ADDRESS } } });
  if (savedAddresses === null) {
    utils.mockApiError('userAddresses', 401, 'Unauthenticated');
  } else {
    utils.mockApi('userAddresses', { response: { success: true, data: { addresses: { data: savedAddresses } } } });
  }
  await utils.render();

  return utils;
}

/**
 * 엔진 토스트가 쌓이는 전역 상태(`_global.toasts`)를 읽는다 — 내장 toast 는 테스트 유틸의 getToasts 를 거치지 않는다.
 *
 * @param utils 테스트 유틸
 * @returns 쌓인 토스트 목록
 */
function engineToasts(utils: { getState: () => Node }): Array<{ type: string; message: string }> {
  return utils.getState()._global?.toasts ?? [];
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});

describe('주문완료 [이 배송지를 저장하기] (B6)', () => {
  /**
   * @scenario case=same_address_saved
   * @effects save_address_button_hidden_when_already_saved
   */
  it('주문 배송지가 주소록에 이미 있으면(연락처 표기만 달라도) 버튼을 숨긴다', async () => {
    const utils = await renderButton([{ ...KR_ORDER_ADDRESS, name: '집', country_code: 'KR', recipient_phone: '01012345678' }], registry);

    expect(document.querySelector('[data-testid="save-address"]')).toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario case=different_address
   * @effects save_address_button_shown_for_new_address
   */
  it('주소록에 없는 주소면 버튼을 보인다', async () => {
    const utils = await renderButton([{ ...KR_ORDER_ADDRESS, name: '회사', country_code: 'KR', address_detail: '7층' }], registry);

    expect(document.querySelector('[data-testid="save-address"]')).not.toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario case=address_book_unavailable
   * @effects save_address_button_shown_for_new_address
   */
  it('주소록을 못 받아도(401) 화면은 그대로이고 버튼을 보인다', async () => {
    const utils = await renderButton(null, registry);

    expect(document.querySelector('[data-testid="save-address"]')).not.toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario case=same_address_saved
   * @effects save_address_success_refetches_address_book
   */
  it('저장에 성공하면 주소록을 다시 받는다(버튼이 사라진다)', () => {
    const call = find(saveButton, (n) => n.handler === 'apiCall') as Node;
    const success = JSON.stringify(call?.onSuccess ?? []);

    expect(success).toContain('refetchDataSource');
    expect(success).toContain('userAddresses');
  });

  /**
   * @scenario case=duplicate_name_409
   * @effects save_address_409_shows_notice_not_question
   */
  it('409 는 상태 코드별 error-handling 으로 안내하고, onError 에 conditions 를 두지 않는다', () => {
    const call = find(saveButton, (n) => n.handler === 'apiCall') as Node;

    // onError 안의 conditions 는 엔진이 오류 처리기로 옮길 때 조건 목록을 버려 아무 토스트도 뜨지 않는다(실측 B6)
    expect(JSON.stringify(call?.onError ?? [])).not.toContain('"conditions"');
    expect(call?.errorHandling?.['409']?.handler).toBe('toast');
    expect(call?.errorHandling?.['409']?.params).toEqual({ type: 'info', message: '$t:shop.order_complete.address_name_exists' });
  });

  /**
   * @scenario case=duplicate_name_409
   * @effects save_address_409_shows_notice_not_question
   */
  it('누른 뒤 409 가 오면 실제로 안내(info) 토스트 한 건만 뜬다 — 오류 토스트·서버 질문 문구 없음', async () => {
    const utils = await renderButton([], registry);
    utils.mockApiError('addresses', 409, '동일한 이름의 배송지가 이미 존재합니다. 덮어쓰시겠습니까?');

    await utils.triggerAction(find(saveButton, (n) => n.handler === 'apiCall') as any);

    const toasts = engineToasts(utils);
    expect(toasts).toHaveLength(1);
    expect(toasts[0].type).toBe('info');
    expect(toasts[0].message).toContain('address_name_exists');
    expect(JSON.stringify(toasts)).not.toContain('덮어쓰시겠습니까');
    utils.cleanup();
  });

  /**
   * @scenario case=other_error
   * @effects save_address_other_error_shows_failure_toast
   */
  it('409 가 아닌 실패(500)는 오류 토스트로 알린다', async () => {
    const utils = await renderButton([], registry);
    utils.mockApiError('addresses', 500, '서버 오류');

    await utils.triggerAction(find(saveButton, (n) => n.handler === 'apiCall') as any);

    const toasts = engineToasts(utils);
    expect(toasts).toHaveLength(1);
    expect(toasts[0].type).toBe('error');
    utils.cleanup();
  });

  /**
   * @scenario case=different_address
   * @effects save_address_success_refetches_address_book
   */
  it('저장에 성공하면 성공 토스트가 뜬다', async () => {
    const utils = await renderButton([], registry);
    utils.mockApi('addresses', { response: { id: 1 } });

    await utils.triggerAction(find(saveButton, (n) => n.handler === 'apiCall') as any);

    expect(engineToasts(utils).map((t) => t.type)).toEqual(['success']);
    utils.cleanup();
  });

  /**
   * @scenario case=address_book_unavailable
   * @effects address_book_failure_does_not_break_screen
   */
  it('주소록 소스는 실패를 화면 오류로 키우지 않고 빈 목록으로 떨어진다', () => {
    const ds = ((layout as Node).data_sources ?? []).find((d: Node) => d.id === 'userAddresses');

    expect(ds).toBeTruthy();
    expect(ds.errorHandling?.default?.handler).toBe('suppress');
    expect(ds.fallback?.data?.addresses?.data).toEqual([]);
  });
});
