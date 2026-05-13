/**
 * usePerformance.ts
 *
 * G7 위지윅 레이아웃 편집기의 성능 최적화 훅
 *
 * 역할:
 * - 디바운스된 값 관리
 * - 쓰로틀링된 콜백
 * - 지연 로딩
 * - 렌더링 최적화
 */

import { useState, useEffect, useRef, useCallback, useMemo } from 'react';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Core:Performance')) ?? {
    log: (...args: unknown[]) => console.log('[Core:Performance]', ...args),
    warn: (...args: unknown[]) => console.warn('[Core:Performance]', ...args),
    error: (...args: unknown[]) => console.error('[Core:Performance]', ...args),
};

// ============================================================================
// useDebounce - 디바운스된 값
// ============================================================================

/**
 * 값의 업데이트를 지연시키는 훅
 *
 * @param value 원본 값
 * @param delay 지연 시간 (ms)
 * @returns 디바운스된 값
 *
 * @example
 * const debouncedSearchQuery = useDebounce(searchQuery, 300);
 */
export function useDebounce<T>(value: T, delay: number): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    return () => {
      clearTimeout(timer);
    };
  }, [value, delay]);

  return debouncedValue;
}

// ============================================================================
// useDebouncedCallback - 디바운스된 콜백
// ============================================================================

/**
 * 콜백 함수를 디바운스하는 훅
 *
 * @param callback 원본 콜백
 * @param delay 지연 시간 (ms)
 * @returns 디바운스된 콜백
 *
 * @example
 * const debouncedSave = useDebouncedCallback((data) => save(data), 500);
 */
export function useDebouncedCallback<T extends (...args: unknown[]) => unknown>(
  callback: T,
  delay: number
): (...args: Parameters<T>) => void {
  const callbackRef = useRef(callback);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // 콜백이 변경되면 ref 업데이트
  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);

  // 클린업
  useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
      }
    };
  }, []);

  return useCallback(
    (...args: Parameters<T>) => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
      }

      timerRef.current = setTimeout(() => {
        callbackRef.current(...args);
      }, delay);
    },
    [delay]
  );
}

// ============================================================================
// useThrottledCallback - 쓰로틀링된 콜백
// ============================================================================

/**
 * 콜백 함수를 쓰로틀링하는 훅
 *
 * @param callback 원본 콜백
 * @param limit 최소 호출 간격 (ms)
 * @returns 쓰로틀링된 콜백
 *
 * @example
 * const throttledScroll = useThrottledCallback((e) => handleScroll(e), 100);
 */
export function useThrottledCallback<T extends (...args: unknown[]) => unknown>(
  callback: T,
  limit: number
): (...args: Parameters<T>) => void {
  const callbackRef = useRef(callback);
  const lastRunRef = useRef<number>(0);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // 콜백이 변경되면 ref 업데이트
  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);

  // 클린업
  useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
      }
    };
  }, []);

  return useCallback(
    (...args: Parameters<T>) => {
      const now = Date.now();
      const timeSinceLastRun = now - lastRunRef.current;

      if (timeSinceLastRun >= limit) {
        lastRunRef.current = now;
        callbackRef.current(...args);
      } else {
        // 마지막 호출을 예약
        if (timerRef.current) {
          clearTimeout(timerRef.current);
        }
        timerRef.current = setTimeout(() => {
          lastRunRef.current = Date.now();
          callbackRef.current(...args);
        }, limit - timeSinceLastRun);
      }
    },
    [limit]
  );
}

// ============================================================================
// useLazyValue - 지연 초기화 값
// ============================================================================

/**
 * 비용이 많이 드는 초기값을 지연 계산하는 훅
 *
 * @param factory 값을 생성하는 팩토리 함수
 * @returns 지연 초기화된 값
 *
 * @example
 * const expensiveData = useLazyValue(() => computeExpensiveData());
 */
export function useLazyValue<T>(factory: () => T): T {
  const [value] = useState(factory);
  return value;
}

// ============================================================================
// useRenderCount - 렌더링 횟수 추적 (개발용)
// ============================================================================

/**
 * 컴포넌트 렌더링 횟수를 추적하는 훅 (개발 모드 전용)
 *
 * @param componentName 컴포넌트 이름 (로깅용)
 * @returns 렌더링 횟수
 */
export function useRenderCount(componentName?: string): number {
  const countRef = useRef(0);
  countRef.current += 1;

  if (process.env.NODE_ENV === 'development' && componentName) {
    logger.log(`[Render] ${componentName}: ${countRef.current}`);
  }

  return countRef.current;
}

// ============================================================================
// useStableCallback - 안정적인 콜백 참조
// ============================================================================

/**
 * 항상 최신 콜백을 참조하지만 참조가 변경되지 않는 콜백을 반환
 *
 * @param callback 원본 콜백
 * @returns 안정적인 콜백 참조
 *
 * @example
 * const stableOnChange = useStableCallback((value) => {
 *   // 항상 최신 상태에 접근 가능
 *   console.log(currentState, value);
 * });
 */
export function useStableCallback<T extends (...args: unknown[]) => unknown>(
  callback: T
): T {
  const callbackRef = useRef(callback);

  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);

  return useCallback(
    ((...args: unknown[]) => callbackRef.current(...args)) as T,
    []
  );
}

// ============================================================================
// usePrevious - 이전 값 추적
// ============================================================================

/**
 * 이전 렌더링의 값을 반환하는 훅
 *
 * @param value 현재 값
 * @returns 이전 값 (첫 렌더링에서는 undefined)
 *
 * @example
 * const previousCount = usePrevious(count);
 * if (previousCount !== count) {
 *   console.log('count changed:', previousCount, '->', count);
 * }
 */
export function usePrevious<T>(value: T): T | undefined {
  const ref = useRef<T | undefined>(undefined);

  useEffect(() => {
    ref.current = value;
  }, [value]);

  return ref.current;
}

// ============================================================================
// useUpdateEffect - 업데이트 시에만 실행되는 effect
// ============================================================================

/**
 * 마운트 시에는 실행되지 않고, 업데이트 시에만 실행되는 effect
 *
 * @param effect 이펙트 함수
 * @param deps 의존성 배열
 *
 * @example
 * useUpdateEffect(() => {
 *   console.log('value was updated:', value);
 * }, [value]);
 */
export function useUpdateEffect(
  effect: React.EffectCallback,
  deps: React.DependencyList
): void {
  const isFirstMount = useRef(true);

  useEffect(() => {
    if (isFirstMount.current) {
      isFirstMount.current = false;
      return;
    }

    return effect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
}

// ============================================================================
// useDeepCompareMemo - 깊은 비교 메모이제이션
// ============================================================================

/**
 * 객체/배열을 깊은 비교하여 메모이제이션하는 훅
 *
 * @param factory 값을 생성하는 팩토리 함수
 * @param deps 의존성 배열 (깊은 비교)
 * @returns 메모이제이션된 값
 *
 * @example
 * const memoizedConfig = useDeepCompareMemo(
 *   () => processConfig(config),
 *   [config]
 * );
 */
export function useDeepCompareMemo<T>(
  factory: () => T,
  deps: React.DependencyList
): T {
  const prevDepsRef = useRef<React.DependencyList | undefined>(undefined);
  const valueRef = useRef<T | undefined>(undefined);

  // 깊은 비교 함수
  const deepEqual = (a: unknown, b: unknown): boolean => {
    if (a === b) return true;
    if (typeof a !== typeof b) return false;
    if (a === null || b === null) return a === b;
    if (typeof a !== 'object') return a === b;

    const aObj = a as Record<string, unknown>;
    const bObj = b as Record<string, unknown>;

    const aKeys = Object.keys(aObj);
    const bKeys = Object.keys(bObj);

    if (aKeys.length !== bKeys.length) return false;

    return aKeys.every((key) => deepEqual(aObj[key], bObj[key]));
  };

  // deps 비교
  const depsEqual =
    prevDepsRef.current !== undefined &&
    deps.length === prevDepsRef.current.length &&
    deps.every((dep, i) => deepEqual(dep, prevDepsRef.current![i]));

  if (!depsEqual) {
    valueRef.current = factory();
    prevDepsRef.current = deps;
  }

  return valueRef.current as T;
}

// ============================================================================
// useAnimationFrame - requestAnimationFrame 훅
// ============================================================================

/**
 * requestAnimationFrame을 사용하는 훅
 *
 * @param callback 프레임마다 호출되는 콜백
 * @param active 활성화 여부
 */
export function useAnimationFrame(
  callback: (deltaTime: number) => void,
  active: boolean = true
): void {
  const requestRef = useRef<number | null>(null);
  const previousTimeRef = useRef<number | null>(null);
  const callbackRef = useRef(callback);

  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);

  useEffect(() => {
    if (!active) return;

    const animate = (time: number) => {
      if (previousTimeRef.current !== null) {
        const deltaTime = time - previousTimeRef.current;
        callbackRef.current(deltaTime);
      }
      previousTimeRef.current = time;
      requestRef.current = requestAnimationFrame(animate);
    };

    requestRef.current = requestAnimationFrame(animate);

    return () => {
      if (requestRef.current !== null) {
        cancelAnimationFrame(requestRef.current);
      }
    };
  }, [active]);
}

// ============================================================================
// useIdle - 유휴 상태 감지
// ============================================================================

/**
 * 사용자 유휴 상태를 감지하는 훅
 *
 * @param timeout 유휴 판정 시간 (ms)
 * @returns 유휴 상태 여부
 */
export function useIdle(timeout: number = 5000): boolean {
  const [isIdle, setIsIdle] = useState(false);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const resetTimer = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
    }
    setIsIdle(false);
    timerRef.current = setTimeout(() => {
      setIsIdle(true);
    }, timeout);
  }, [timeout]);

  useEffect(() => {
    const events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];

    events.forEach((event) => {
      document.addEventListener(event, resetTimer);
    });

    resetTimer();

    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
      }
      events.forEach((event) => {
        document.removeEventListener(event, resetTimer);
      });
    };
  }, [resetTimer]);

  return isIdle;
}

// ============================================================================
// useIntersectionObserver - 요소 가시성 감지
// ============================================================================

interface UseIntersectionObserverOptions {
  threshold?: number | number[];
  root?: Element | null;
  rootMargin?: string;
}

/**
 * 요소가 뷰포트에 보이는지 감지하는 훅
 *
 * @param options IntersectionObserver 옵션
 * @returns [ref, isIntersecting]
 */
export function useIntersectionObserver<T extends Element>(
  options: UseIntersectionObserverOptions = {}
): [React.RefObject<T>, boolean] {
  const { threshold = 0, root = null, rootMargin = '0px' } = options;
  const ref = useRef<T>(null);
  const [isIntersecting, setIsIntersecting] = useState(false);

  useEffect(() => {
    const element = ref.current;
    if (!element) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        setIsIntersecting(entry.isIntersecting);
      },
      { threshold, root, rootMargin }
    );

    observer.observe(element);

    return () => {
      observer.disconnect();
    };
  }, [threshold, root, rootMargin]);

  return [ref, isIntersecting];
}

// ============================================================================
// useMeasure - 요소 크기 측정
// ============================================================================

interface Dimensions {
  width: number;
  height: number;
  top: number;
  left: number;
  right: number;
  bottom: number;
}

/**
 * 요소의 크기를 측정하는 훅
 *
 * @returns [ref, dimensions]
 */
export function useMeasure<T extends Element>(): [
  React.RefObject<T>,
  Dimensions
] {
  const ref = useRef<T>(null);
  const [dimensions, setDimensions] = useState<Dimensions>({
    width: 0,
    height: 0,
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
  });

  useEffect(() => {
    const element = ref.current;
    if (!element) return;

    const resizeObserver = new ResizeObserver((entries) => {
      if (entries[0]) {
        const { width, height, top, left, right, bottom } =
          entries[0].target.getBoundingClientRect();
        setDimensions({ width, height, top, left, right, bottom });
      }
    });

    resizeObserver.observe(element);

    return () => {
      resizeObserver.disconnect();
    };
  }, []);

  return [ref, dimensions];
}

// ============================================================================
// Export
// ============================================================================

export default {
  useDebounce,
  useDebouncedCallback,
  useThrottledCallback,
  useLazyValue,
  useRenderCount,
  useStableCallback,
  usePrevious,
  useUpdateEffect,
  useDeepCompareMemo,
  useAnimationFrame,
  useIdle,
  useIntersectionObserver,
  useMeasure,
};
