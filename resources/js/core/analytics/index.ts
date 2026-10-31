/**
 * 방문자 통계 트래커 진입점
 *
 * 부팅 코드가 여기만 보면 되도록 생성·시작·전역 노출을 묶습니다.
 *
 * 트래커는 **사용자 템플릿에서만** 돕니다. 관리자 화면까지 세면 인기 페이지 1위가
 * 관리자 대시보드가 되어 통계가 쓸모없어집니다.
 */

import { startClickTracking } from './clickTracking';
import type { AnalyticsClientSettings } from './exclusions';
import { AnalyticsTracker, type TrackerOptions } from './Tracker';

export { AnalyticsTracker } from './Tracker';
export type { BeaconPayload, TrackerOptions } from './Tracker';
export type { AnalyticsClientSettings } from './exclusions';
export { resolvePageTitle } from './pageTitle';
export { ConsentBridge, permitsAnalytics } from './consent';
export type { ConsentSource, ConsentSnapshotLike } from './consent';

/** 트래커와 정리 함수 */
export interface TrackerHandle {
  /** 트래커 인스턴스 */
  tracker: AnalyticsTracker;
  /** 추적을 멈추고 리스너를 떼는 함수 */
  dispose: () => void;
}

/**
 * 트래커를 만들고 시작합니다.
 *
 * @param options 생성 옵션
 * @returns 트래커와 정리 함수 (수집이 꺼져 있으면 null)
 *
 * @since engine-v1.72.0
 */
export function createTracker(options: TrackerOptions): TrackerHandle | null {
  const settings: AnalyticsClientSettings = options.settings ?? {};

  if (settings.enabled === false) return null;

  const tracker = new AnalyticsTracker(options);

  // 동의 조회는 비동기라 기다리지 않는다 — 첫 화면 표시를 늦추지 않기 위함이고,
  // 조회가 끝나기 전의 비콘은 동의를 모르는 상태라 보내지 않는다.
  void tracker.start();

  const stopClicks = startClickTracking(tracker, settings.download_extensions ?? []);

  return {
    tracker,
    dispose: () => {
      stopClicks();
      tracker.stop();
    },
  };
}

/**
 * 확장이 쓸 수 있도록 전역에 노출할 API 를 만듭니다.
 *
 * 모든 메서드가 값을 돌려주지 않고 예외를 삼킵니다 — 확장이 통계 호출 때문에
 * 깨지지 않게 하기 위함입니다.
 *
 * - `trackEvent(name, attrs)` 의 이름은 저장·집계되고(타임라인 한 줄 + 「이벤트」 리포트) 속성은
 *   저장되지 않습니다. 정해진 뜻으로 셀 행동은 `trackGoal`, 가르는 값은 `setDimension` 을 씁니다.
 * - `isOptedOut()` 은 수집 제외 쿠키가 **결정 사유일 때만** true 입니다. 판정 순서상 앞선 사유
 *   (수집 꺼짐·미리 그리기·추적 거부)가 먼저 걸리면 쿠키가 있어도 false 입니다.
 *
 * @param handle 트래커 핸들
 * @returns 전역 노출용 API (`window.G7Core.analytics`)
 *
 * @since engine-v1.72.0
 */
export function createPublicApi(handle: TrackerHandle | null): Record<string, unknown> {
  const noop = (): void => undefined;

  if (!handle) {
    return {
      trackGoal: noop,
      trackEvent: noop,
      setDimension: noop,
      optOut: (): Promise<void> => Promise.resolve(),
      optIn: (): Promise<void> => Promise.resolve(),
      isOptedOut: () => false,
    };
  }

  const { tracker } = handle;

  return {
    trackGoal: (key: string, attrs?: Record<string, unknown>): void => {
      try {
        tracker.trackGoal(key, attrs ?? {});
      } catch {
        // 삼킨다.
      }
    },
    trackEvent: (name: string, attrs?: Record<string, unknown>): void => {
      try {
        tracker.trackEvent(name, attrs ?? {});
      } catch {
        // 삼킨다.
      }
    },
    setDimension: (key: string, value: string): void => {
      try {
        tracker.setDimension(key, value);
      } catch {
        // 삼킨다.
      }
    },
    optOut: async (): Promise<void> => {
      try {
        await tracker.optOut();
      } catch {
        // 삼킨다.
      }
    },
    optIn: async (): Promise<void> => {
      try {
        await tracker.optIn();
      } catch {
        // 삼킨다.
      }
    },
    isOptedOut: (): boolean => {
      try {
        return tracker.isOptedOut();
      } catch {
        return false;
      }
    },
  };
}
