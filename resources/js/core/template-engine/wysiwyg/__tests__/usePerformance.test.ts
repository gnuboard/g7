/**
 * usePerformance.test.ts
 *
 * 성능 최적화 훅 테스트
 */

import { renderHook, act, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  useDebounce,
  useDebouncedCallback,
  useThrottledCallback,
  useLazyValue,
  usePrevious,
  useStableCallback,
} from '../hooks/usePerformance';

describe('usePerformance', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  // ==========================================================================
  // useDebounce 테스트
  // ==========================================================================

  describe('useDebounce', () => {
    it('should return initial value immediately', () => {
      const { result } = renderHook(() => useDebounce('initial', 500));
      expect(result.current).toBe('initial');
    });

    it('should debounce value updates', async () => {
      const { result, rerender } = renderHook(
        ({ value, delay }) => useDebounce(value, delay),
        { initialProps: { value: 'initial', delay: 500 } }
      );

      expect(result.current).toBe('initial');

      // 값 변경
      rerender({ value: 'updated', delay: 500 });

      // 아직 변경되지 않음
      expect(result.current).toBe('initial');

      // 타이머 실행
      act(() => {
        vi.advanceTimersByTime(500);
      });

      // 이제 변경됨
      expect(result.current).toBe('updated');
    });

    it('should cancel previous timer on rapid updates', () => {
      const { result, rerender } = renderHook(
        ({ value }) => useDebounce(value, 500),
        { initialProps: { value: 'a' } }
      );

      // 빠른 연속 업데이트
      rerender({ value: 'b' });
      act(() => {
        vi.advanceTimersByTime(200);
      });

      rerender({ value: 'c' });
      act(() => {
        vi.advanceTimersByTime(200);
      });

      rerender({ value: 'd' });

      // 아직 초기값
      expect(result.current).toBe('a');

      // 마지막 업데이트 후 500ms
      act(() => {
        vi.advanceTimersByTime(500);
      });

      // 최종 값만 반영
      expect(result.current).toBe('d');
    });
  });

  // ==========================================================================
  // useDebouncedCallback 테스트
  // ==========================================================================

  describe('useDebouncedCallback', () => {
    it('should debounce callback execution', () => {
      const callback = vi.fn();
      const { result } = renderHook(() => useDebouncedCallback(callback, 300));

      // 콜백 호출
      act(() => {
        result.current('arg1');
      });

      // 아직 실행되지 않음
      expect(callback).not.toHaveBeenCalled();

      // 타이머 실행
      act(() => {
        vi.advanceTimersByTime(300);
      });

      // 이제 실행됨
      expect(callback).toHaveBeenCalledWith('arg1');
      expect(callback).toHaveBeenCalledTimes(1);
    });

    it('should cancel previous calls on rapid invocation', () => {
      const callback = vi.fn();
      const { result } = renderHook(() => useDebouncedCallback(callback, 300));

      // 빠른 연속 호출
      act(() => {
        result.current('a');
        result.current('b');
        result.current('c');
      });

      act(() => {
        vi.advanceTimersByTime(300);
      });

      // 마지막 호출만 실행
      expect(callback).toHaveBeenCalledWith('c');
      expect(callback).toHaveBeenCalledTimes(1);
    });
  });

  // ==========================================================================
  // useThrottledCallback 테스트
  // ==========================================================================

  describe('useThrottledCallback', () => {
    it('should execute callback immediately on first call', () => {
      const callback = vi.fn();
      const { result } = renderHook(() => useThrottledCallback(callback, 300));

      act(() => {
        result.current('arg1');
      });

      // 즉시 실행됨
      expect(callback).toHaveBeenCalledWith('arg1');
      expect(callback).toHaveBeenCalledTimes(1);
    });

    it('should throttle subsequent calls', () => {
      const callback = vi.fn();
      const { result } = renderHook(() => useThrottledCallback(callback, 300));

      // 첫 번째 호출
      act(() => {
        result.current('first');
      });
      expect(callback).toHaveBeenCalledTimes(1);

      // 즉시 두 번째 호출 - 쓰로틀링됨
      act(() => {
        result.current('second');
      });
      expect(callback).toHaveBeenCalledTimes(1);

      // 시간 경과 후 마지막 호출 실행
      act(() => {
        vi.advanceTimersByTime(300);
      });
      expect(callback).toHaveBeenCalledTimes(2);
      expect(callback).toHaveBeenLastCalledWith('second');
    });
  });

  // ==========================================================================
  // useLazyValue 테스트
  // ==========================================================================

  describe('useLazyValue', () => {
    it('should call factory only once', () => {
      const factory = vi.fn(() => 'value');
      const { result, rerender } = renderHook(() => useLazyValue(factory));

      expect(result.current).toBe('value');
      expect(factory).toHaveBeenCalledTimes(1);

      // 리렌더 해도 팩토리 다시 호출 안 함
      rerender();
      expect(factory).toHaveBeenCalledTimes(1);
    });
  });

  // ==========================================================================
  // usePrevious 테스트
  // ==========================================================================

  describe('usePrevious', () => {
    it('should return undefined on first render', () => {
      const { result } = renderHook(() => usePrevious('initial'));
      expect(result.current).toBeUndefined();
    });

    it('should return previous value after update', () => {
      const { result, rerender } = renderHook(
        ({ value }) => usePrevious(value),
        { initialProps: { value: 'first' } }
      );

      expect(result.current).toBeUndefined();

      rerender({ value: 'second' });
      expect(result.current).toBe('first');

      rerender({ value: 'third' });
      expect(result.current).toBe('second');
    });
  });

  // ==========================================================================
  // useStableCallback 테스트
  // ==========================================================================

  describe('useStableCallback', () => {
    it('should maintain stable reference', () => {
      const { result, rerender } = renderHook(
        ({ value }) => useStableCallback(() => value),
        { initialProps: { value: 1 } }
      );

      const firstCallback = result.current;

      rerender({ value: 2 });
      const secondCallback = result.current;

      // 참조가 동일함
      expect(firstCallback).toBe(secondCallback);
    });

    it('should always call latest callback', () => {
      let latestValue = 0;
      const { result, rerender } = renderHook(
        ({ value }) =>
          useStableCallback(() => {
            latestValue = value;
          }),
        { initialProps: { value: 1 } }
      );

      result.current();
      expect(latestValue).toBe(1);

      rerender({ value: 2 });
      result.current();
      expect(latestValue).toBe(2);
    });
  });
});
