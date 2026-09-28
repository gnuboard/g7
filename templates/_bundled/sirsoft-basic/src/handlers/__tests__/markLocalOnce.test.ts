/**
 * @file markLocalOnce.test.ts
 * @description 데이터소스 onSuccess 의 「한 번만」 가드 핸들러 (#599 validate 2026-09-23)
 *
 * 데이터소스 onSuccess 컨텍스트에는 `_local` 이 실리지 않는다(`response` 만 있다) — 그래서 레이아웃
 * 표현식으로는 「이미 채웠는가」 를 판정할 수 없다. 주문서의 기본 배송지 채움이 그 때문에 배송지 관리
 * 창이 목록을 다시 받을 때마다 돌아, 구매자가 고른 배송지를 기본 배송지로 덮었다.
 * 이 핸들러가 현재 로컬 상태를 읽어 첫 회에만 참을 돌려주고 플래그를 세운다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { markLocalOnceHandler } from '../markLocalOnce';

let local: Record<string, any> = {};

beforeEach(() => {
  local = {};
  (window as any).G7Core = {
    state: {
      getLocal: () => local,
      setLocal: vi.fn((updates: Record<string, any>) => {
        local = { ...local, ...updates };
      }),
    },
  };
});

describe('markLocalOnceHandler', () => {
  /**
   * @scenario screen=checkout, fault=prefill_once
   * @effects checkout_default_address_prefills_once
   */
  it('첫 호출만 참이고 플래그를 세운다 — 두 번째부터는 거짓', () => {
    const action = { handler: 'sirsoft-basic.markLocalOnce', params: { key: 'checkoutDefaultAddressPrefilled' } };

    expect(markLocalOnceHandler(action)).toBe(true);
    expect(local.checkoutDefaultAddressPrefilled).toBe(true);
    expect(markLocalOnceHandler(action)).toBe(false);
  });

  /**
   * 화면 진입 때 플래그를 거짓으로 다시 세우면 다음 진입에서 한 번 더 참이 된다.
   *
   * @scenario screen=checkout, fault=prefill_once
   * @effects checkout_default_address_prefills_once
   */
  it('플래그가 거짓이면 다시 참을 돌려준다', () => {
    local = { checkoutDefaultAddressPrefilled: false };

    expect(markLocalOnceHandler({ handler: 'x', params: { key: 'checkoutDefaultAddressPrefilled' } })).toBe(true);
  });

  /**
   * @scenario screen=checkout, fault=prefill_once
   * @effects checkout_default_address_prefills_once
   */
  it('키가 없거나 상태 API 가 없으면 거짓이다 (아무것도 쓰지 않는다)', () => {
    expect(markLocalOnceHandler({ handler: 'x', params: {} })).toBe(false);

    (window as any).G7Core = {};
    expect(markLocalOnceHandler({ handler: 'x', params: { key: 'k' } })).toBe(false);
  });
});
