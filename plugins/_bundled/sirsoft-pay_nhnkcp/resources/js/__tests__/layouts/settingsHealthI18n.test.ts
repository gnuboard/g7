/**
 * @file settingsHealthI18n.test.ts
 * @description NHN KCP 환경설정 「시스템 점검」 카드와 에스크로 배송 등록의 문구가 번역 키를 거치는지 잰다.
 *
 * 점검 카드의 제목·배지·결제 경로 이름·「다시 검사」가 한국어로 박혀 있어 언어를 바꿔도 한국어로 남았다.
 * 택배사 목록의 「기타」도 같았다(택배사 이름은 회사 고유명이라 그대로 둔다).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import paymentQuery from '../../../extensions/admin_order_payment_query.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

const HANGUL = /[가-힣]/;

/** 주석(`comment`)을 뺀 문자열 값 중 한국어가 든 것을 모은다. */
function hangulValues(node: unknown, path = ''): string[] {
  if (typeof node === 'string') return HANGUL.test(node) ? [`${path} = ${node}`] : [];
  if (!node || typeof node !== 'object') return [];
  return Object.entries(node as Record<string, unknown>).flatMap(([k, v]) =>
    k === 'comment' ? [] : hangulValues(v, `${path}.${k}`),
  );
}

/** 레이아웃이 쓰는 이 플러그인의 번역 키를 모은다. */
function usedKeys(node: unknown): string[] {
  const text = JSON.stringify(node);
  return [...new Set([...text.matchAll(/\$t:(?:defer:)?sirsoft-pay_nhnkcp\.([\w.]+)/g)].map((m) => m[1]))];
}

function lookup(dict: unknown, key: string): unknown {
  return key.split('.').reduce<unknown>((o, k) => (o && typeof o === 'object' ? (o as Record<string, unknown>)[k] : undefined), dict);
}

describe('NHN KCP 환경설정 — 화면 문구 번역', () => {
  // @scenario surface=nhnkcp_settings
  // @effects ui_copy_goes_through_translation_keys
  it('주석을 빼면 한국어 문자열이 남지 않는다', () => {
    expect(hangulValues(settingsLayout)).toEqual([]);
  });

  // @scenario surface=nhnkcp_settings
  // @effects every_used_key_exists_in_ko_and_en
  it('시스템 점검 카드가 쓰는 키가 ko·en 에 모두 있다', () => {
    const keys = usedKeys(settingsLayout).filter((k) => k.startsWith('settings.health.'));
    expect(keys.length).toBeGreaterThanOrEqual(15);
    for (const key of keys) {
      expect(typeof lookup(ko, key), `ko ${key}`).toBe('string');
      expect(typeof lookup(en, key), `en ${key}`).toBe('string');
    }
  });

  // @scenario surface=nhnkcp_escrow_delivery
  // @effects ui_copy_goes_through_translation_keys, every_used_key_exists_in_ko_and_en
  it('택배사 목록의 「기타」가 번역 키를 쓴다', () => {
    const text = JSON.stringify(paymentQuery);
    expect(text).toContain('$t:sirsoft-pay_nhnkcp.admin.escrow_delivery_courier_etc');
    expect(text).not.toContain('"기타"');
    expect(lookup(ko, 'admin.escrow_delivery_courier_etc')).toBe('기타');
    expect(typeof lookup(en, 'admin.escrow_delivery_courier_etc')).toBe('string');
  });
});
