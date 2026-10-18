/**
 * @file checkout-address-save-feedback.test.ts
 * @description 주문서 배송지 관리 창 — 저장 완료 안내 문구와 「기본 배송지로 설정」 라벨 (레이아웃 구조 검증).
 *
 * 결함 1: 저장 성공 처리가 편집 중 배송지(`editingAddress`)를 먼저 비운 뒤 안내 문구를
 *   `editingAddress?.id` 로 골라, 기존 배송지를 고쳐도 「배송지가 추가되었습니다.」가 떴다.
 * 결함 2: 「기본 배송지로 설정」 라벨이 체크박스와 이어져 있지 않아(`for`·`id` 없음) 문구를 눌러도
 *   체크되지 않았다. 마이페이지 배송지 창은 `address_is_default` 로 이어져 있다.
 * (이커머스 전수 실측 S4 — S4-D9)
 */

import { describe, it, expect } from 'vitest';

import checkoutAddressModal from '../../../layouts/partials/shop/_modal_address_manage.json';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

describe('주문서 배송지 관리 창 — 저장 안내와 기본 배송지 라벨', () => {
  const saveCall = collect(
    checkoutAddressModal,
    (value) => value.handler === 'apiCall' && String(value.target ?? '').includes("editingAddress?.id ? '/api/modules/sirsoft-ecommerce/user/addresses/'"),
  )[0];

  /**
   * @scenario save_mode=update
   * @effects update_success_says_updated
   */
  it('수정 성공 안내는 편집 중 배송지를 비우기 전에 문구를 고른다', () => {
    const onSuccess = (saveCall?.onSuccess ?? []) as Record<string, unknown>[];
    const toastIndex = onSuccess.findIndex((action) => action.handler === 'toast');
    const resetIndex = onSuccess.findIndex(
      (action) => action.handler === 'setState'
        && (action.params as Record<string, unknown> | undefined)?.editingAddress === null,
    );

    expect(toastIndex).toBeGreaterThanOrEqual(0);
    expect(resetIndex).toBeGreaterThanOrEqual(0);
    expect(toastIndex).toBeLessThan(resetIndex);
    expect(String((onSuccess[toastIndex].params as Record<string, unknown>).message)).toContain('editingAddress?.id');
  });

  /**
   * @scenario save_mode=create
   * @effects create_success_says_added
   */
  it('추가 성공 안내는 「추가」 문구를 그대로 쓴다', () => {
    const onSuccess = (saveCall?.onSuccess ?? []) as Record<string, unknown>[];
    const toast = onSuccess.find((action) => action.handler === 'toast');

    expect(String((toast?.params as Record<string, unknown>).message)).toContain('$t:mypage.addresses.added');
  });

  /**
   * @scenario save_mode=label_click
   * @effects default_label_toggles_checkbox
   */
  it('「기본 배송지로 설정」 라벨은 체크박스 id 를 가리킨다', () => {
    const checkbox = collect(
      checkoutAddressModal,
      (value) => value.name === 'Checkbox' && (value.props as Record<string, unknown> | undefined)?.name === 'is_default',
    )[0];
    const label = collect(
      checkoutAddressModal,
      (value) => value.name === 'Label' && value.text === '$t:mypage.addresses.form.set_default',
    )[0];
    const checkboxId = (checkbox?.props as Record<string, unknown> | undefined)?.id;

    expect(typeof checkboxId).toBe('string');
    expect((label?.props as Record<string, unknown> | undefined)?.htmlFor).toBe(checkboxId);
  });
});
