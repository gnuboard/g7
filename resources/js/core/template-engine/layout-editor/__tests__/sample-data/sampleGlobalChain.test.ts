/**
 * sampleGlobalChain.test.ts — sampleGlobal deep merge 체인
 *
 * 검증 매트릭스:
 *  - 코어 시드만 (소스 없음)
 *  - 확장 sub-key 보강 (코어가 안 가진 키 → 통과)
 *  - 코어 leaf 충돌 → 코어 우선 + dev 경고
 *  - 배열 통째 교체
 *  - 모듈 → 플러그인 → 템플릿 순서 (뒤가 앞을 deep merge)
 *  - guest_only → currentUser 시드 제외
 */

import { describe, it, expect, vi } from 'vitest';
import { buildSampleGlobalSeed } from '../../sample-data/sampleGlobalChain';
import type { SampleGlobalSource } from '../../spec/editorSpecLoader';

const coreSeed = {
  currentUser: { uuid: 'core-uuid', name: '코어 사용자', is_admin: false },
  settings: { general: { site_name: '코어 사이트' } },
  recent: [1, 2, 3],
};

const src = (
  id: string,
  kind: SampleGlobalSource['kind'],
  sampleGlobal: Record<string, unknown>,
): SampleGlobalSource => ({ id, kind, sampleGlobal });

describe('buildSampleGlobalSeed — sampleGlobal deep merge 체인', () => {
  it('소스가 없으면 코어 시드를 그대로(깊은 복제) 반환한다', () => {
    const result = buildSampleGlobalSeed({ coreSeed, sources: [] });
    expect(result).toEqual(coreSeed);
    // 깊은 복제 — 원본 불변
    (result.settings as any).general.site_name = 'mutated';
    expect((coreSeed.settings as any).general.site_name).toBe('코어 사이트');
  });

  it('확장이 코어가 안 가진 sub-key 를 보강하면 통과시킨다', () => {
    const warn = vi.fn();
    const result = buildSampleGlobalSeed({
      coreSeed,
      sources: [src('mod', 'module', { currentUser: { cart_count: 5 } })],
      warn,
    });
    expect((result.currentUser as any).cart_count).toBe(5);
    // 코어 leaf 는 보존
    expect((result.currentUser as any).uuid).toBe('core-uuid');
    expect(warn).not.toHaveBeenCalled();
  });

  it('확장이 코어 leaf 를 덮으면 코어 값이 이기고 dev 경고를 출력한다', () => {
    const warn = vi.fn();
    const result = buildSampleGlobalSeed({
      coreSeed,
      sources: [src('mod', 'module', { currentUser: { uuid: 'evil-uuid', is_admin: true } })],
      warn,
    });
    expect((result.currentUser as any).uuid).toBe('core-uuid');
    expect((result.currentUser as any).is_admin).toBe(false);
    expect(warn).toHaveBeenCalledTimes(2);
    expect(warn.mock.calls[0][0]).toContain("sampleGlobal key conflict");
    expect(warn.mock.calls[0][0]).toContain("extension 'mod'");
    expect(warn.mock.calls.some((c) => c[0].includes("core key 'currentUser.uuid'"))).toBe(true);
  });

  it('확장 도메인 keyspace(cart/wishlist 등)는 새로 추가된다', () => {
    const result = buildSampleGlobalSeed({
      coreSeed,
      sources: [src('shop', 'module', { cart: { items: [], count: 0 } })],
    });
    expect(result.cart).toEqual({ items: [], count: 0 });
  });

  it('코어가 가진 배열 leaf 는 충돌 정책상 코어 우선(+경고) — 확장이 못 덮는다', () => {
    // recent 는 코어 시드의 배열 leaf → 보호 대상. 충돌 정책(코어 우선)이
    // "배열 통째 교체"보다 우선한다 (>).
    const warn = vi.fn();
    const result = buildSampleGlobalSeed({
      coreSeed,
      sources: [src('tpl', 'template', { recent: [9] })],
      warn,
    });
    expect(result.recent).toEqual([1, 2, 3]);
    expect(warn).toHaveBeenCalledWith(expect.stringContaining("core key 'recent'"));
  });

  it('코어가 안 가진 배열 키는 확장이 통째로 추가/교체한다', () => {
    // wishlist 는 코어에 없는 키 → 보호 대상 아님. 모듈이 추가, 템플릿이 통째 교체.
    const result = buildSampleGlobalSeed({
      coreSeed,
      sources: [
        src('mod', 'module', { wishlist: [1, 2] }),
        src('tpl', 'template', { wishlist: [9] }),
      ],
    });
    expect(result.wishlist).toEqual([9]);
  });

  it('모듈 → 플러그인 → 템플릿 순서로 뒤 단계가 앞 단계를 deep merge 한다', () => {
    const result = buildSampleGlobalSeed({
      coreSeed: {},
      sources: [
        src('mod', 'module', { theme: { color: 'red', size: 'sm' } }),
        src('plg', 'plugin', { theme: { color: 'blue' } }),
        src('tpl', 'template', { theme: { size: 'lg' } }),
      ],
    });
    // color: plugin 이 module 을 덮음, size: template 이 module 을 덮음
    expect(result.theme).toEqual({ color: 'blue', size: 'lg' });
  });

  it('guest_only 면 코어 keyspace currentUser 를 시드에서 제외한다', () => {
    const result = buildSampleGlobalSeed({ coreSeed, sources: [], isGuestOnly: true });
    expect(result.currentUser).toBeUndefined();
    // settings baseline 은 유지
    expect((result.settings as any).general.site_name).toBe('코어 사이트');
  });

  it('guest_only 면 확장/템플릿이 시드한 currentUser 도 최종 결과에서 제외한다 (S6-2 권위 적용)', () => {
    const warn = vi.fn();
    const result = buildSampleGlobalSeed({
      coreSeed,
      sources: [src('mod', 'module', { currentUser: { guest: true } })],
      isGuestOnly: true,
      warn,
    });
    // S6-2 정정: 번들 템플릿이 자기 sampleGlobal 에 currentUser 를 작성하므로,
    // guest 페이지는 코어뿐 아니라 확장/템플릿 currentUser 도 제외해야 로그인 분기
    // 가드 partial 의 토스트/리다이렉트 발화를 막는다. 따라서 currentUser 는 undefined.
    expect(result.currentUser).toBeUndefined();
    // 충돌 경고는 코어 currentUser 가 base 에서 제외되어 보호 leaf 가 없으므로 미발화.
    expect(warn).not.toHaveBeenCalled();
  });

  it('guest_only 가 아니면 템플릿이 시드한 currentUser 가 정상 유지된다 (회귀 가드)', () => {
    const result = buildSampleGlobalSeed({
      coreSeed: {},
      sources: [src('tpl', 'template', { currentUser: { uuid: 'tpl-user', name: '템플릿 사용자' } })],
      isGuestOnly: false,
    });
    expect(result.currentUser).toEqual({ uuid: 'tpl-user', name: '템플릿 사용자' });
  });
});
