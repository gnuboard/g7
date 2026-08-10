/**
 * @file address-edit-intl-field-mapping.test.tsx
 * @description 배송지 수정 진입 시 해외 주소 필드가 폼 키(intl_*)로 매핑되는지 검증
 *
 * 배경: 배송지 조회 응답은 해외 주소를 city/state/postal_code 로 내려주지만 주소 폼의
 *       입력칸 이름은 intl_city/intl_state/intl_postal_code 다. 수정 진입에서 조회 응답
 *       객체를 그대로 editingAddress 에 넣으면 해외 도시/주/우편번호 입력칸이 빈 값으로
 *       보이고, 그 상태로 저장하면 저장돼 있던 값이 함께 유실된다.
 *       체크아웃 배송지 선택(_checkout_shipping.json)은 이미 같은 매핑을 하고 있었고,
 *       마이페이지 목록과 체크아웃 배송지 관리 모달의 "수정" 진입만 누락돼 있었다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import mypageListPartial from '../../../layouts/partials/mypage/addresses/_list.json';
import checkoutAddressModal from '../../../layouts/partials/shop/_modal_address_manage.json';

/** 레이아웃 트리를 순회하며 조건에 맞는 노드를 모두 수집한다 */
function collectNodes(node: unknown, predicate: (value: Record<string, unknown>) => boolean): Record<string, unknown>[] {
  const found: Record<string, unknown>[] = [];

  const walk = (current: unknown): void => {
    if (Array.isArray(current)) {
      current.forEach(walk);

      return;
    }

    if (current === null || typeof current !== 'object') {
      return;
    }

    const value = current as Record<string, unknown>;

    if (predicate(value)) {
      found.push(value);
    }

    Object.values(value).forEach(walk);
  };

  walk(node);

  return found;
}

/** editingAddress 를 객체로 설정하는 setState 액션을 찾는다 */
function findEditingAddressAssignments(layout: unknown): Record<string, unknown>[] {
  return collectNodes(layout, (node) => {
    if (node.handler !== 'setState') {
      return false;
    }

    const params = node.params as Record<string, unknown> | undefined;

    return Boolean(params && typeof params.editingAddress === 'object' && params.editingAddress !== null);
  });
}

/**
 * 기존 배송지를 채워 넣는 "수정" 진입 액션만 추린다.
 *
 * 같은 레이아웃의 "새 배송지 추가" 액션도 editingAddress 를 객체로 설정하므로
 * (`{ is_default: false }`) 필드 매핑 검사 대상에서 제외해야 한다.
 */
function findEditAssignments(layout: unknown): Record<string, string>[] {
  return findEditingAddressAssignments(layout)
    .map((assignment) => (assignment.params as Record<string, unknown>).editingAddress as Record<string, string>)
    .filter((editingAddress) => typeof editingAddress.intl_city === 'string');
}

describe('배송지 수정 진입 — 해외 주소 필드 매핑', () => {
  it('마이페이지 목록의 수정 액션이 intl_* 키를 city/state/postal_code 에서 채운다', () => {
    const editAssignments = findEditAssignments(mypageListPartial);

    expect(editAssignments.length).toBeGreaterThan(0);

    const editingAddress = editAssignments[0];

    expect(editingAddress.intl_city).toContain('address.city');
    expect(editingAddress.intl_state).toContain('address.state');
    expect(editingAddress.intl_postal_code).toContain('address.postal_code');
  });

  it('마이페이지 목록의 수정 액션이 폼이 요구하는 나머지 키도 함께 채운다', () => {
    const editingAddress = findEditAssignments(mypageListPartial)[0];

    // id 가 없으면 저장이 POST(신규 등록)로 나가 수정이 등록으로 바뀐다
    expect(editingAddress.id).toContain('address.id');

    ['name', 'recipient_name', 'recipient_phone', 'country_code', 'zipcode', 'address', 'address_detail', 'address_line_1', 'address_line_2'].forEach(
      (key) => {
        expect(editingAddress[key]).toBeDefined();
      }
    );
  });

  it('체크아웃 배송지 관리 모달의 수정 액션도 동일하게 매핑한다', () => {
    const editAssignments = findEditAssignments(checkoutAddressModal);

    expect(editAssignments.length).toBeGreaterThan(0);

    const editingAddress = editAssignments[0];

    expect(editingAddress.intl_city).toContain('addr.city');
    expect(editingAddress.intl_state).toContain('addr.state');
    expect(editingAddress.intl_postal_code).toContain('addr.postal_code');
    expect(editingAddress.id).toContain('addr.id');
  });
});
