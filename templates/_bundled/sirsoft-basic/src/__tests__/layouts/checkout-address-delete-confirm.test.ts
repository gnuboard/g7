/**
 * @file checkout-address-delete-confirm.test.ts
 * @description 주문서 배송지 관리 창의 「삭제」가 확인 창을 거쳐 실제로 배송지를 지우는지 (레이아웃 구조 검증).
 *
 * 결함: 삭제 버튼이 `handler: "confirm"` 을 불렀는데 confirm 은 핸들러가 아니라 액션 속성이라
 *   코어·템플릿·확장 어디에도 그런 핸들러가 없었다. 버튼을 눌러도 확인 창도 뜨지 않고 삭제 요청도
 *   나가지 않았다(레이아웃 검사가 「미등록 핸들러」로 경고). 그 안의 삭제 요청은 토큰도 싣지 않아
 *   confirm 만 고쳤다면 회원 배송지 API 가 401 로 거부했을 것이다.
 *
 * 수정: 삭제 요청(apiCall DELETE) 자체에 `confirm` 속성을 달고 `auth_required` 로 토큰을 싣는다.
 *   카드 클릭(배송지 고르기)으로 번지지 않게 `stopPropagation` 을 유지한다.
 */

import { describe, it, expect } from 'vitest';

import checkoutAddressModal from '../../../layouts/partials/shop/_modal_address_manage.json';

/** 레이아웃 트리에서 handler 를 가진 액션을 모두 모은다 */
function collectActions(node: unknown, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collectActions(child, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (typeof value.handler === 'string') {
    out.push(value);
  }

  Object.values(value).forEach((child) => collectActions(child, out));

  return out;
}

describe('주문서 배송지 관리 창 — 배송지 삭제', () => {
  const actions = collectActions(checkoutAddressModal);
  const deleteAction = actions.find(
    (action) => action.handler === 'apiCall'
      && (action.params as Record<string, unknown> | undefined)?.method === 'DELETE'
      && String(action.target ?? '').startsWith('/api/modules/sirsoft-ecommerce/user/addresses/'),
  );

  /**
   * @effects no_unregistered_confirm_handler
   */
  it('존재하지 않는 confirm 핸들러를 부르지 않는다 (삭제 무반응 회귀 차단)', () => {
    expect(actions.map((action) => action.handler)).not.toContain('confirm');
  });

  /**
   * @scenario confirm_answer=accept
   * @scenario confirm_answer=dismiss
   * @effects delete_request_guarded_by_confirm_attribute
   */
  it('삭제 요청이 클릭 액션 자체이고, 실행 전에 확인 창(confirm 속성)을 띄운다', () => {
    expect(deleteAction).toBeDefined();
    expect(deleteAction?.type).toBe('click');
    expect(deleteAction?.confirm).toBe('$t:shop.checkout.address_delete_confirm');
  });

  /**
   * @scenario confirm_answer=accept
   * @effects delete_request_carries_auth_token
   */
  it('회원 배송지 API 이므로 로그인 토큰을 싣는다', () => {
    expect(deleteAction?.auth_required).toBe(true);
  });

  /**
   * @scenario confirm_answer=dismiss
   * @effects delete_click_does_not_select_card
   */
  it('삭제 버튼 클릭이 카드 클릭(배송지 고르기)으로 번지지 않는다', () => {
    expect(deleteAction?.stopPropagation).toBe(true);
  });

  /**
   * @scenario confirm_answer=accept
   * @effects address_list_refetched_and_toasted
   */
  it('지운 뒤 목록을 다시 불러오고 완료 안내를, 실패하면 오류 안내를 띄운다', () => {
    const onSuccess = deleteAction?.onSuccess as Array<Record<string, unknown>>;

    expect(onSuccess.map((action) => action.handler)).toEqual(['refetchDataSource', 'toast']);
    expect((onSuccess[0].params as Record<string, unknown>).dataSourceId).toBe('userAddresses');
    expect((onSuccess[1].params as Record<string, unknown>).message).toBe('$t:shop.checkout.address_deleted');
    expect((deleteAction?.onError as Record<string, unknown>).handler).toBe('toast');
  });
});
