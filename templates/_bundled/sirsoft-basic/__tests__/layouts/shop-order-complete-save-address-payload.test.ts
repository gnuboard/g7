/**
 * 주문완료 화면 «이 배송지를 저장하기» — 서버 계약(StoreUserAddressRequest) 일치 테스트 (#599 세션A)
 *
 * 배경: 주문완료 화면은 로그인 회원에게 이번 주문의 배송지를 주소록에 남기는 버튼을 준다.
 *   저장은 `POST /api/modules/sirsoft-ecommerce/user/addresses` 하나뿐이고, 그 계약은
 *   `StoreUserAddressRequest` 다 — `name`(배송지명) 필수 · 국내면 `address` · 해외면
 *   `address_line_1` + `intl_city` + `intl_postal_code` · 상세주소 키는 `address_detail`.
 *
 * 결함(브라우저 실측 2026-09-21 · 주문 `20260921-1316400577`):
 *   이 버튼만 낡은 키(`address1` / `address2`)를 보내고 `name` 을 아예 보내지 않아
 *   **어떤 주문에서 눌러도 422** 였다. 실측 응답:
 *   `{"errors":{"name":["배송지명은 필수입니다."],"address":["주소는 필수입니다."],
 *     "address_line_1":["해외 주소(Address Line 1)는 필수입니다."]}}`
 *   같은 기능의 형제 화면(`/mypage/addresses`, 주문서 배송지 관리 모달)은 올바른 키를 쓴다.
 *
 * 회귀 차단 포인트:
 *   1. `name` 을 보낸다(서버 required).
 *   2. 국내 키는 `address` / `address_detail` — `address1` / `address2` 는 서버에 없는 키다.
 *   3. 해외 주문도 저장되도록 `address_line_1` 계열을 함께 보낸다.
 */

import { describe, it, expect } from 'vitest';

import orderComplete from '../../layouts/shop/order_complete.json';

const raw = JSON.stringify(orderComplete);

/**
 * 배송지 저장 api-call 의 body 를 찾는다.
 * 굽힌 JSON 의 모양: `actions: [{ handler: 'apiCall', target: '…/user/addresses',
 * params: { method: 'POST', body: { … } } }]`
 */
function findSaveAddressBody(node: unknown): Record<string, unknown> | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findSaveAddressBody(child);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Record<string, unknown>;
    const params = obj.params as Record<string, unknown> | undefined;
    const target = (obj.target ?? params?.target) as unknown;
    if (
      typeof target === 'string' &&
      target.endsWith('/user/addresses') &&
      String(params?.method ?? obj.method ?? '').toUpperCase() === 'POST'
    ) {
      const body = (params?.body ?? obj.body) as Record<string, unknown> | undefined;
      if (body && typeof body === 'object') return body;
    }
    for (const value of Object.values(obj)) {
      const found = findSaveAddressBody(value);
      if (found) return found;
    }
  }
  return null;
}

describe('주문완료 «이 배송지를 저장하기» 페이로드', () => {
  // @scenario surface=order_complete_payload
  // @effects order_complete_save_sends_required_name
  it('배송지 저장 api-call 이 있다', () => {
    expect(findSaveAddressBody(orderComplete)).not.toBeNull();
  });

  // @scenario surface=order_complete_payload
  // @effects order_complete_save_sends_required_name
  it('서버가 required 로 두는 name 을 보낸다', () => {
    const body = findSaveAddressBody(orderComplete) as Record<string, unknown>;
    expect(Object.keys(body)).toContain('name');
    expect(String(body.name ?? '')).not.toBe('');
  });

  // @scenario surface=order_complete_payload
  // @effects order_complete_save_name_is_translated
  it('배송지명은 `$t:` 접두가 아니라 $t() 표현식이다 (원문 키가 DB 에 저장되지 않도록)', () => {
    const body = findSaveAddressBody(orderComplete) as Record<string, unknown>;
    const name = String(body.name ?? '');

    // `$t:` 접두는 화면 문구에만 치환되고 API 본문 값으로는 원문 키가 그대로 나간다.
    // 실측(2026-09-21): 배송지명이 `$t:shop.order_complete.shipping_address` 로 저장됐다.
    expect(name.startsWith('$t:')).toBe(false);
    expect(name).toContain("$t('");
  });

  // @scenario surface=order_complete_payload
  // @effects order_complete_save_uses_server_address_keys
  it('국내 주소 키는 address / address_detail 이다', () => {
    const body = findSaveAddressBody(orderComplete) as Record<string, unknown>;
    const keys = Object.keys(body);

    expect(keys).toContain('address');
    expect(keys).toContain('address_detail');

    // 서버 계약에 없는 낡은 키 — 되살아나면 저장이 조용히 422 로 실패한다.
    expect(keys).not.toContain('address1');
    expect(keys).not.toContain('address2');
  });

  // @scenario surface=order_complete_payload
  // @effects order_complete_save_covers_intl_address
  it('해외 주문도 저장되도록 address_line_1 계열을 함께 보낸다', () => {
    const body = findSaveAddressBody(orderComplete) as Record<string, unknown>;
    const keys = Object.keys(body);

    expect(keys).toContain('address_line_1');
    expect(keys).toContain('intl_city');
    expect(keys).toContain('intl_postal_code');
  });

  // @scenario surface=order_complete_payload
  // @effects order_complete_save_uses_server_address_keys
  it('낡은 키가 레이아웃 어디에도 남아 있지 않다', () => {
    // raw 전체를 단언 대상으로 삼으면 실패 출력이 레이아웃 전문이 된다 — 포함 여부만 boolean 으로 잰다.
    expect(raw.includes('"address1"')).toBe(false);
    expect(raw.includes('"address2"')).toBe(false);
  });
});
