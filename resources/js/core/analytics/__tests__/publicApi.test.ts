// e2e:allow 단위 계약 테스트 파일만 더했다 — 제품 코드·화면 변경 없음(트래커 동작은 analytics-collect-e2e.spec.ts 가 잰다)
/**
 * 전역 공개 API(`G7Core.analytics`) — 확장이 부르는 여섯 메서드는 언제나 있고 절대 던지지 않는다.
 *
 * 확장 코드는 통계가 켜져 있는지 모르고 이 API 를 부른다. 트래커가 없거나(관리자 템플릿·수집 꺼짐)
 * 트래커가 던져도 확장의 화면이 깨지면 안 된다 — 공개 API 는 그 경계다.
 *
 * @scenario source=beacon, exclusion=disabled
 * @effects public_analytics_api_never_throws
 */
import { describe, it, expect, vi } from 'vitest';
import { createPublicApi, type TrackerHandle } from '../index';

const METHODS = ['trackGoal', 'trackEvent', 'setDimension', 'optOut', 'optIn', 'isOptedOut'] as const;

/** 모든 메서드가 던지는 트래커 핸들 */
function throwingHandle(): TrackerHandle {
  const boom = vi.fn(() => {
    throw new Error('tracker bug');
  });
  const rejecting = vi.fn(async () => {
    throw new Error('network');
  });

  return {
    tracker: {
      trackGoal: boom,
      trackEvent: boom,
      setDimension: boom,
      optOut: rejecting,
      optIn: rejecting,
      isOptedOut: boom,
    },
  } as unknown as TrackerHandle;
}

describe('G7Core.analytics 공개 API', () => {
  // @scenario source=beacon, exclusion=disabled
  // @effects public_analytics_api_never_throws
  it('트래커가 없어도 여섯 메서드가 있고, 호출해도 아무 일 없이 끝난다', async () => {
    const api = createPublicApi(null) as Record<string, (...args: unknown[]) => unknown>;
    expect(Object.keys(api).sort()).toEqual([...METHODS].sort());

    expect(() => api.trackGoal('vendor.x', {})).not.toThrow();
    expect(() => api.trackEvent('x')).not.toThrow();
    expect(() => api.setDimension('k', 'v')).not.toThrow();
    await expect(api.optOut()).resolves.toBeUndefined();
    await expect(api.optIn()).resolves.toBeUndefined();
    expect(api.isOptedOut()).toBe(false);
  });

  // @scenario source=beacon, exclusion=none
  // @effects public_analytics_api_never_throws
  it('트래커가 던지거나 거부해도 삼키고, 수집 제외 여부는 false 로 답한다', async () => {
    const api = createPublicApi(throwingHandle()) as Record<string, (...args: unknown[]) => unknown>;
    expect(Object.keys(api).sort()).toEqual([...METHODS].sort());

    expect(() => api.trackGoal('vendor.x', { amount: 1 })).not.toThrow();
    expect(() => api.trackEvent('x', {})).not.toThrow();
    expect(() => api.setDimension('k', 'v')).not.toThrow();
    await expect(api.optOut()).resolves.toBeUndefined();
    await expect(api.optIn()).resolves.toBeUndefined();
    expect(api.isOptedOut()).toBe(false);
  });
});
