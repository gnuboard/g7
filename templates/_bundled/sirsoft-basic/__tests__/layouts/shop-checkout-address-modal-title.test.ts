/**
 * 주문서 배송지 관리 모달 — 제목이 «추가»와 «수정»을 가르는 기준 계약 테스트 (#599 세션A)
 *
 * 배경: 한 창(`addressManageModal`)이 `_local.addressModalMode` 로 «목록»과 «입력 폼»
 *   두 얼굴을 갖고, 폼 모드에서는 다시 «새 주소 추가»와 «기존 배송지 수정»으로 갈린다.
 *   등록/수정을 가르는 SSoT 는 `_local.editingAddress?.id` 다 — 저장 버튼의 API target·
 *   method·성공 토스트·버튼 라벨이 모두 그 키를 본다.
 *
 * 결함(브라우저 실측 2026-09-21 · 주문서 `배송지 관리` → `새 배송지`):
 *   제목만 `_local.editingAddress` 의 **truthy 여부**로 갈렸다. 목록 헤더의 «새 배송지»
 *   버튼은 기본 배송지 체크박스가 바인딩할 boolean 을 심으려고 `editingAddress` 를
 *   `{ is_default: false }` 객체로 연다. 객체는 truthy 라서 **빈 폼인데 제목이 「배송지 수정」**
 *   으로 떴다(같은 창의 저장 버튼은 「새 주소 추가」 — 한 창 안에서 두 문구가 어긋난다).
 *
 * 회귀 차단 포인트:
 *   1. 제목 표현식은 `editingAddress?.id` 로 갈린다 (`editingAddress` 단독 truthy 금지).
 *   2. 같은 창의 저장 버튼 라벨·API target·method 와 **같은 기준**을 쓴다.
 */

import { describe, it, expect } from 'vitest';

import addressManageModal from '../../layouts/partials/shop/_modal_address_manage.json';

const raw = JSON.stringify(addressManageModal);

/** 등록/수정을 가르는 SSoT 키 */
const DISCRIMINATOR = 'editingAddress?.id';

/** 모달 제목 표현식을 레이아웃에서 꺼낸다. */
function findModalTitle(node: unknown): string | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findModalTitle(child);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Record<string, unknown>;
    if (obj.id === 'addressManageModal') {
      const props = obj.props as Record<string, unknown> | undefined;
      const title = props?.title;
      if (typeof title === 'string') return title;
    }
    for (const value of Object.values(obj)) {
      const found = findModalTitle(value);
      if (found) return found;
    }
  }
  return null;
}

describe('주문서 배송지 관리 모달 제목', () => {
  // @scenario surface=checkout_modal_title
  // @effects address_modal_title_splits_on_id_not_object
  it('제목 표현식이 존재한다', () => {
    expect(findModalTitle(addressManageModal)).toBeTypeOf('string');
  });

  // @scenario surface=checkout_modal_title
  // @effects address_modal_title_splits_on_id_not_object
  it('«수정»/«추가» 를 editingAddress?.id 로 가른다', () => {
    const title = findModalTitle(addressManageModal) as string;

    // 수정 문구와 추가 문구가 모두 한 표현식 안에 있어야 한다.
    expect(title).toContain('$t:shop.checkout.address_edit');
    expect(title).toContain('$t:shop.checkout.address_add');

    // 판정 기준은 id 유무 — 객체 truthy 로 갈리면 빈 폼이 「수정」으로 뜬다.
    expect(title).toContain(DISCRIMINATOR);
  });

  // @scenario surface=checkout_modal_title
  // @effects address_modal_title_splits_on_id_not_object
  it('editingAddress 를 id 없이 단독으로 조건에 쓰지 않는다', () => {
    const title = findModalTitle(addressManageModal) as string;

    // `_local.editingAddress ?` / `_local.editingAddress ?` 같은 단독 truthy 분기 금지.
    const bareTruthy = /_local\.editingAddress\s*\?(?!\.)/g;
    expect(title.match(bareTruthy)).toBeNull();
  });

  // @scenario surface=checkout_modal_title
  // @effects address_modal_title_matches_submit_button
  it('저장 버튼 라벨·API 분기도 같은 기준을 쓴다 (기준 일관성)', () => {
    // 한 창 안에서 제목과 동작이 다른 기준을 쓰면 문구가 어긋난다.
    expect(raw).toContain('_local.editingAddress?.id');
    expect(raw).toContain("$t:mypage.addresses.add");
    expect(raw).toContain("$t:common.save");
  });
});
