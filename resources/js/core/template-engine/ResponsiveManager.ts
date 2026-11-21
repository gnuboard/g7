/**
 * ResponsiveManager.ts
 *
 * 반응형 레이아웃 시스템의 핵심 싱글톤 모듈
 * 화면 너비 감지, 범위 파싱, 매칭 키 찾기 기능을 제공합니다.
 *
 * 화면 너비는 한 번 읽고 굳히지 않는다(engine-v1.97.1). 휴대폰에서 다른 앱(카카오톡 로그인·카드앱 결제 등)을
 * 다녀오면 화면이 가려진 채 페이지를 불러오거나, PC 화면이 한 번 그려져 브라우저가 화면을 축소해
 * `innerWidth` 가 부푼다. 그래서 폭은 `measureViewportWidth()`(축소로 부푼 값 대신 레이아웃 기준 폭)로 재고,
 * resize 외에 화면이 다시 보일 때(visibilitychange)·페이지가 보일 때(pageshow)도 다시 잰다.
 */

import { createLogger } from '../utils/Logger';
import { measureViewportWidth } from './viewportMeasure';

const logger = createLogger('ResponsiveManager');

/** resize 디바운스 지연(ms) */
const RESIZE_DEBOUNCE_MS = 150;

/**
 * 화면이 다시 보일 때·pageshow 뒤 한 번 더 재는 지연(ms).
 * 안드로이드에서 visible 이 복원된 화면 크기보다 먼저 오는 경우를 잡는다.
 */
const FOLLOW_UP_REMEASURE_MS = 250;

/**
 * 파싱된 범위 인터페이스
 */
interface ParsedRange {
  min: number;
  max: number;
}

/**
 * Breakpoint 프리셋 정의
 * mobile: 0-767px
 * tablet: 768-1023px
 * desktop: 1024px+
 * portable: 0-1023px (mobile + tablet)
 */
const BREAKPOINT_PRESETS: Record<string, ParsedRange> = {
  mobile: { min: 0, max: 767 },
  tablet: { min: 768, max: 1023 },
  desktop: { min: 1024, max: Infinity },
  portable: { min: 0, max: 1023 },
};

/**
 * 범위 파싱 결과 캐시 (성능 최적화)
 */
const parsedRangeCache = new Map<string, ParsedRange | null>();

/**
 * 대기 중인 실행을 취소할 수 있는 debounce 함수
 */
type CancelableDebounced<T extends (...args: any[]) => void> = T & { cancel: () => void };

/**
 * debounce 함수
 *
 * @param fn 실행할 함수
 * @param delay 지연 시간 (ms)
 * @returns debounced 함수 (`cancel()` 로 대기 중인 실행을 취소 — destroy 용, engine-v1.97.1)
 */
function debounce<T extends (...args: any[]) => void>(fn: T, delay: number): CancelableDebounced<T> {
  let timeoutId: ReturnType<typeof setTimeout> | null = null;

  const debounced = ((...args: Parameters<T>) => {
    if (timeoutId !== null) {
      clearTimeout(timeoutId);
    }
    timeoutId = setTimeout(() => {
      timeoutId = null;
      fn(...args);
    }, delay);
  }) as CancelableDebounced<T>;

  debounced.cancel = () => {
    if (timeoutId !== null) {
      clearTimeout(timeoutId);
      timeoutId = null;
    }
  };

  return debounced;
}

/**
 * ResponsiveManager 클래스
 *
 * 싱글톤 패턴으로 화면 너비 감지 및 구독자 관리를 담당합니다.
 * TransitionManager와 동일한 패턴을 사용합니다.
 */
class ResponsiveManager {
  private subscribers: Set<(width: number) => void> = new Set();
  private currentWidth: number = 1024; // 데스크톱 기본값
  private debouncedHandler: CancelableDebounced<() => void> | null = null;
  private visibilityHandler: (() => void) | null = null;
  private pageShowHandler: (() => void) | null = null;
  private followUpTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    this.initialize();
  }

  /**
   * 초기화
   * 브라우저 환경에서만 폭을 재고 재측정 계기(resize · visibilitychange · pageshow)를 등록합니다.
   */
  private initialize(): void {
    if (typeof window === 'undefined') {
      return;
    }

    this.currentWidth = measureViewportWidth(window) ?? 1024;

    this.debouncedHandler = debounce(() => {
      this.remeasure();
    }, RESIZE_DEBOUNCE_MS);
    window.addEventListener('resize', this.debouncedHandler);

    // 다른 앱을 다녀와 화면이 다시 보일 때 — 가려진 동안 읽은 폭을 바로잡는다 (engine-v1.97.1)
    if (typeof document !== 'undefined') {
      this.visibilityHandler = () => {
        if (document.visibilityState !== 'visible') {
          return;
        }

        this.remeasure();
        this.scheduleFollowUp();
      };
      document.addEventListener('visibilitychange', this.visibilityHandler);
    }

    // 뒤로가기 캐시 복귀·첫 로드 완료 직후 (engine-v1.97.1)
    this.pageShowHandler = () => {
      this.remeasure();
      this.scheduleFollowUp();
    };
    window.addEventListener('pageshow', this.pageShowHandler);
  }

  /**
   * 화면 폭을 다시 재고, 바뀌었으면 구독자에게 알립니다.
   *
   * 폭이 같으면 알리지 않는다 — 탭을 오갈 때마다 다시 재도 다시 그리기는 실제 폭이 바뀔 때만 생긴다.
   *
   * @returns 폭이 바뀌었으면 true
   * @since engine-v1.97.1
   */
  remeasure(): boolean {
    if (typeof window === 'undefined') {
      return false;
    }

    const width = measureViewportWidth(window);

    if (width === null || width === this.currentWidth) {
      return false;
    }

    if (logger.isDebugEnabled?.()) {
      logger.log('width changed', {
        iw: window.innerWidth,
        cw: typeof document !== 'undefined' ? document.documentElement?.clientWidth : undefined,
        from: this.currentWidth,
        width,
        visibility: typeof document !== 'undefined' ? document.visibilityState : undefined,
      });
    }

    this.currentWidth = width;
    this.notifySubscribers();

    return true;
  }

  /**
   * 잠시 뒤 한 번 더 잽니다 (타이머 1개 — 다시 부르면 기존 타이머를 지우고 새로 건다).
   */
  private scheduleFollowUp(): void {
    if (this.followUpTimer !== null) {
      clearTimeout(this.followUpTimer);
    }

    this.followUpTimer = setTimeout(() => {
      this.followUpTimer = null;
      this.remeasure();
    }, FOLLOW_UP_REMEASURE_MS);
  }

  /**
   * 모든 구독자에게 현재 너비를 알립니다.
   */
  private notifySubscribers(): void {
    this.subscribers.forEach((callback) => {
      callback(this.currentWidth);
    });
  }

  /**
   * 화면 너비 변경을 구독합니다.
   *
   * 등록 즉시 현재 값으로 한 번 부르고, 그 뒤로는 폭이 **바뀔 때만** 부른다(같은 폭 resize 에는 부르지 않음 — engine-v1.97.1).
   *
   * @param callback 너비 변경 시 호출될 콜백
   * @returns 구독 해제 함수
   */
  subscribe(callback: (width: number) => void): () => void {
    this.subscribers.add(callback);
    // 즉시 현재값 전달
    callback(this.currentWidth);

    return () => {
      this.subscribers.delete(callback);
    };
  }

  /**
   * 현재 화면 너비를 반환합니다.
   *
   * @returns 현재 화면 너비 (px)
   */
  getWidth(): number {
    return this.currentWidth;
  }

  /**
   * Breakpoint 키를 파싱하여 범위를 반환합니다.
   *
   * 지원 형식:
   * - 프리셋: 'mobile', 'tablet', 'desktop'
   * - 범위: '0-767', '768-1023', '1024-'
   * - 열린 범위: '-599', '1200-'
   *
   * @param key Breakpoint 키
   * @returns 파싱된 범위 또는 null (잘못된 형식)
   */
  parseRange(key: string): ParsedRange | null {
    // 캐시 확인
    if (parsedRangeCache.has(key)) {
      return parsedRangeCache.get(key) ?? null;
    }

    let result: ParsedRange | null = null;

    // 프리셋 확인
    if (BREAKPOINT_PRESETS[key]) {
      result = { ...BREAKPOINT_PRESETS[key] };
    } else {
      // 커스텀 범위 파싱
      // 패턴: "min-max", "-max", "min-"
      const rangeMatch = key.match(/^(-?\d*)-(-?\d*)$/);

      if (rangeMatch) {
        const [, minStr, maxStr] = rangeMatch;

        // min 파싱 (빈 문자열이면 0)
        const min = minStr === '' ? 0 : parseInt(minStr, 10);

        // max 파싱 (빈 문자열이면 Infinity)
        const max = maxStr === '' ? Infinity : parseInt(maxStr, 10);

        // 유효성 검사
        if (!isNaN(min) && !isNaN(max) && min <= max) {
          result = { min, max };
        }
      }
    }

    // 캐시에 저장
    parsedRangeCache.set(key, result);

    return result;
  }

  /**
   * 주어진 너비에 매칭되는 가장 우선순위가 높은 키를 찾습니다.
   *
   * 우선순위 규칙:
   * 1. 커스텀 범위 > 프리셋
   * 2. 좁은 범위 > 넓은 범위
   *
   * @param responsive responsive 객체 (breakpoint 키 -> 오버라이드 값)
   * @param width 현재 화면 너비
   * @returns 매칭된 키 또는 null
   */
  getMatchingKey(responsive: Record<string, any>, width: number): string | null {
    const matchedKeys: Array<{
      key: string;
      range: ParsedRange;
      isPreset: boolean;
    }> = [];

    // 모든 키에 대해 매칭 검사
    for (const key of Object.keys(responsive)) {
      const range = this.parseRange(key);
      if (!range) {
        continue;
      }

      // 너비가 범위 내에 있는지 확인
      if (width >= range.min && width <= range.max) {
        matchedKeys.push({
          key,
          range,
          isPreset: !!BREAKPOINT_PRESETS[key],
        });
      }
    }

    // 매칭된 키가 없으면 null
    if (matchedKeys.length === 0) {
      return null;
    }

    // 우선순위에 따라 정렬
    matchedKeys.sort((a, b) => {
      // 1. 커스텀 범위가 프리셋보다 우선
      if (a.isPreset !== b.isPreset) {
        return a.isPreset ? 1 : -1;
      }

      // 2. 좁은 범위가 넓은 범위보다 우선
      const aWidth = a.range.max - a.range.min;
      const bWidth = b.range.max - b.range.min;
      return aWidth - bWidth;
    });

    return matchedKeys[0].key;
  }

  /**
   * 현재 너비가 주어진 breakpoint에 매칭되는지 확인합니다.
   *
   * @param key Breakpoint 키
   * @returns 매칭 여부
   */
  matches(key: string): boolean {
    const range = this.parseRange(key);
    if (!range) {
      return false;
    }
    return this.currentWidth >= range.min && this.currentWidth <= range.max;
  }

  /**
   * 모든 구독을 해제합니다.
   */
  clearSubscribers(): void {
    this.subscribers.clear();
  }

  /**
   * ResponsiveManager를 정리합니다.
   * 테스트 환경에서 사용됩니다.
   */
  destroy(): void {
    if (this.debouncedHandler) {
      this.debouncedHandler.cancel();
      if (typeof window !== 'undefined') {
        window.removeEventListener('resize', this.debouncedHandler);
      }
    }
    if (this.visibilityHandler && typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', this.visibilityHandler);
    }
    if (this.pageShowHandler && typeof window !== 'undefined') {
      window.removeEventListener('pageshow', this.pageShowHandler);
    }
    if (this.followUpTimer !== null) {
      clearTimeout(this.followUpTimer);
      this.followUpTimer = null;
    }
    this.debouncedHandler = null;
    this.visibilityHandler = null;
    this.pageShowHandler = null;
    this.clearSubscribers();
    parsedRangeCache.clear();
  }

  /**
   * 테스트용: 현재 너비를 강제로 설정합니다.
   *
   * 같은 값이어도 구독자에게 알린다(기존 시험 계약 — 실제 측정 경로의 「같은 폭 무알림」과 다르다).
   *
   * @param width 설정할 너비
   */
  _setWidthForTesting(width: number): void {
    this.currentWidth = width;
    this.notifySubscribers();
  }
}

// 싱글톤 인스턴스 export
export const responsiveManager = new ResponsiveManager();

// 타입 및 상수 export
export type { ParsedRange };
export { BREAKPOINT_PRESETS };
