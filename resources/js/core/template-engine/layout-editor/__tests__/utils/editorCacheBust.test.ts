/**
 * editorCacheBust.test.ts — 편집기 캐시-버스트 nonce SSoT 단위 테스트
 *
 * nonce 는 모듈 전역 단조 증가 카운터다. getCacheBustNonce 가 현재 값을 반환하고
 * bumpCacheBustNonce 가 1 증가시킨다 — useLayoutDocument/useExtensionDocument 가 공유해
 * 저장·버전 복원 후 fetch URL `?v=<ver>.<nonce>` 을 달리해 HTTP 캐시 stale 응답을 우회한다.
 */

import { describe, it, expect } from 'vitest';
import { getCacheBustNonce, bumpCacheBustNonce } from '../../utils/editorCacheBust';

describe('editorCacheBust — 캐시-버스트 nonce SSoT', () => {
  it('bump 마다 단조 증가 + get 이 현재 값을 반환', () => {
    const start = getCacheBustNonce();
    bumpCacheBustNonce();
    expect(getCacheBustNonce()).toBe(start + 1);
    bumpCacheBustNonce();
    bumpCacheBustNonce();
    expect(getCacheBustNonce()).toBe(start + 3);
  });

  it('get 은 부작용 없이 같은 값을 반복 반환', () => {
    const v = getCacheBustNonce();
    expect(getCacheBustNonce()).toBe(v);
    expect(getCacheBustNonce()).toBe(v);
  });
});
