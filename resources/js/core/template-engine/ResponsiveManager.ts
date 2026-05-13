/**
 * ResponsiveManager.ts
 *
 * 반응형 레이아웃 시스템의 핵심 싱글톤 모듈
 * 화면 너비 감지, 범위 파싱, 매칭 키 찾기 기능을 제공합니다.
 */

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
 * debounce 함수
 *
 * @param fn 실행할 함수
 * @param delay 지연 시간 (ms)
 * @returns debounced 함수
 */
function debounce<T extends (...args: any[]) => void>(fn: T, delay: number): T {
  let timeoutId: ReturnType<typeof setTimeout> | null = null;

  return ((...args: Parameters<T>) => {
    if (timeoutId !== null) {
      clearTimeout(timeoutId);
    }
    timeoutId = setTimeout(() => {
      fn(...args);
    }, delay);
  }) as T;
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
  private debouncedHandler: (() => void) | null = null;
  private isInitialized: boolean = false;

  constructor() {
    this.initialize();
  }

  /**
   * 초기화
   * 브라우저 환경에서만 resize 이벤트를 등록합니다.
   */
  private initialize(): void {
    if (typeof window === 'undefined') {
      return;
    }

    this.currentWidth = window.innerWidth;
    this.isInitialized = true;

    this.debouncedHandler = debounce(() => {
      this.currentWidth = window.innerWidth;
      this.notifySubscribers();
    }, 150);

    window.addEventListener('resize', this.debouncedHandler);
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
    if (this.debouncedHandler && typeof window !== 'undefined') {
      window.removeEventListener('resize', this.debouncedHandler);
    }
    this.clearSubscribers();
    parsedRangeCache.clear();
  }

  /**
   * 테스트용: 현재 너비를 강제로 설정합니다.
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
