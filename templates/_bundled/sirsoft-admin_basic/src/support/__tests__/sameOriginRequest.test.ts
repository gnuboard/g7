/**
 * 요청·이동 대상 출처 판정 테스트 (KVE-2026-2350)
 *
 * 토큰을 싣거나 페이지를 옮기기 전에 쓰는 판정이다. 코어가 판정을 제공하면 그 판정(apiCall 과 같은 기준)을
 * 따르고, 제공하지 않는 코어에서는 fetch 와 같은 URL 파서로 판정한다.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { isSameOriginRequest } from '../sameOriginRequest';

const offsite = [
  'https://evil.example/steal',
  '//evil.example/steal',
  '/\\evil.example/steal',
  'javascript:alert(1)',
  ' JaVaScRiPt:alert(1)',
];

const onsite = ['/api/me/avatar', 'api/relative', `${window.location.origin}/api/me/avatar`];

describe('isSameOriginRequest', () => {
  const originalApi = (window as any).G7Core?.api;

  afterEach(() => {
    if ((window as any).G7Core) {
      (window as any).G7Core.api = originalApi;
    }
  });

  /**
   * @effects extension_token_same_origin_only
   */
  it('코어 판정이 없으면 URL 파서로 교차 출처·실행 스킴을 거부하고 동일 출처는 통과시킨다', () => {
    if ((window as any).G7Core) {
      (window as any).G7Core.api = {};
    }

    for (const url of offsite) {
      expect(isSameOriginRequest(url)).toBe(false);
    }
    for (const url of onsite) {
      expect(isSameOriginRequest(url)).toBe(true);
    }
    expect(isSameOriginRequest(undefined)).toBe(false);
  });

  /**
   * @effects extension_token_same_origin_only
   */
  it('코어가 판정을 제공하면 그 판정을 따른다', () => {
    const isSameOriginUrl = vi.fn((url: unknown) => url === '/api/me/avatar');
    (window as any).G7Core = { ...((window as any).G7Core ?? {}), api: { isSameOriginUrl } };

    expect(isSameOriginRequest('/api/me/avatar')).toBe(true);
    expect(isSameOriginRequest('/api/other')).toBe(false);
    expect(isSameOriginUrl).toHaveBeenCalledTimes(2);
  });
});
