/**
 * useAccessibility.test.ts
 *
 * 접근성 훅 테스트
 */

import { renderHook, act } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  useKeyboardNavigation,
  useReducedMotion,
  useRovingTabIndex,
} from '../hooks/useAccessibility';

describe('useAccessibility', () => {
  // ==========================================================================
  // useKeyboardNavigation 테스트
  // ==========================================================================

  describe('useKeyboardNavigation', () => {
    it('should initialize with index 0', () => {
      const { result } = renderHook(() => useKeyboardNavigation(5));
      expect(result.current.currentIndex).toBe(0);
    });

    it('should handle ArrowDown key', () => {
      const { result } = renderHook(() => useKeyboardNavigation(5));

      act(() => {
        result.current.handleKeyDown({
          key: 'ArrowDown',
          preventDefault: vi.fn(),
        } as unknown as React.KeyboardEvent);
      });

      expect(result.current.currentIndex).toBe(1);
    });

    it('should handle ArrowUp key', () => {
      const { result } = renderHook(() => useKeyboardNavigation(5));

      // 먼저 인덱스 설정
      act(() => {
        result.current.setIndex(2);
      });

      act(() => {
        result.current.handleKeyDown({
          key: 'ArrowUp',
          preventDefault: vi.fn(),
        } as unknown as React.KeyboardEvent);
      });

      expect(result.current.currentIndex).toBe(1);
    });

    it('should loop when reaching end', () => {
      const { result } = renderHook(() => useKeyboardNavigation(3, { loop: true }));

      // 마지막 인덱스로 이동
      act(() => {
        result.current.setIndex(2);
      });

      act(() => {
        result.current.handleKeyDown({
          key: 'ArrowDown',
          preventDefault: vi.fn(),
        } as unknown as React.KeyboardEvent);
      });

      // 처음으로 순환
      expect(result.current.currentIndex).toBe(0);
    });

    it('should not loop when loop is false', () => {
      const { result } = renderHook(() => useKeyboardNavigation(3, { loop: false }));

      // 마지막 인덱스로 이동
      act(() => {
        result.current.setIndex(2);
      });

      act(() => {
        result.current.handleKeyDown({
          key: 'ArrowDown',
          preventDefault: vi.fn(),
        } as unknown as React.KeyboardEvent);
      });

      // 마지막에서 멈춤
      expect(result.current.currentIndex).toBe(2);
    });

    it('should handle Home key', () => {
      const { result } = renderHook(() => useKeyboardNavigation(5, { homeEnd: true }));

      act(() => {
        result.current.setIndex(3);
      });

      act(() => {
        result.current.handleKeyDown({
          key: 'Home',
          preventDefault: vi.fn(),
        } as unknown as React.KeyboardEvent);
      });

      expect(result.current.currentIndex).toBe(0);
    });

    it('should handle End key', () => {
      const { result } = renderHook(() => useKeyboardNavigation(5, { homeEnd: true }));

      act(() => {
        result.current.handleKeyDown({
          key: 'End',
          preventDefault: vi.fn(),
        } as unknown as React.KeyboardEvent);
      });

      expect(result.current.currentIndex).toBe(4);
    });

    it('should adjust index when item count decreases', () => {
      const { result, rerender } = renderHook(
        ({ count }) => useKeyboardNavigation(count),
        { initialProps: { count: 5 } }
      );

      act(() => {
        result.current.setIndex(4);
      });

      // 아이템 개수 감소
      rerender({ count: 3 });

      // 인덱스가 조정됨
      expect(result.current.currentIndex).toBe(2);
    });
  });

  // ==========================================================================
  // useReducedMotion 테스트
  // ==========================================================================

  describe('useReducedMotion', () => {
    const originalMatchMedia = window.matchMedia;

    beforeEach(() => {
      // matchMedia 모킹
      window.matchMedia = vi.fn().mockImplementation((query: string) => ({
        matches: query.includes('reduce'),
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      }));
    });

    afterEach(() => {
      window.matchMedia = originalMatchMedia;
    });

    it('should return true when reduced motion is preferred', () => {
      window.matchMedia = vi.fn().mockImplementation(() => ({
        matches: true,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      }));

      const { result } = renderHook(() => useReducedMotion());
      expect(result.current).toBe(true);
    });

    it('should return false when reduced motion is not preferred', () => {
      window.matchMedia = vi.fn().mockImplementation(() => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      }));

      const { result } = renderHook(() => useReducedMotion());
      expect(result.current).toBe(false);
    });
  });

  // ==========================================================================
  // useRovingTabIndex 테스트
  // ==========================================================================

  describe('useRovingTabIndex', () => {
    it('should initialize with activeIndex 0', () => {
      const { result } = renderHook(() => useRovingTabIndex(5));
      expect(result.current.activeIndex).toBe(0);
    });

    it('should return tabIndex 0 for active item', () => {
      const { result } = renderHook(() => useRovingTabIndex(5));
      expect(result.current.getTabIndex(0)).toBe(0);
    });

    it('should return tabIndex -1 for inactive items', () => {
      const { result } = renderHook(() => useRovingTabIndex(5));
      expect(result.current.getTabIndex(1)).toBe(-1);
      expect(result.current.getTabIndex(2)).toBe(-1);
    });

    it('should update activeIndex on setActiveIndex', () => {
      const { result } = renderHook(() => useRovingTabIndex(5));

      act(() => {
        result.current.setActiveIndex(3);
      });

      expect(result.current.activeIndex).toBe(3);
      expect(result.current.getTabIndex(3)).toBe(0);
      expect(result.current.getTabIndex(0)).toBe(-1);
    });

    it('should update activeIndex on handleFocus', () => {
      const { result } = renderHook(() => useRovingTabIndex(5));

      act(() => {
        result.current.handleFocus(2);
      });

      expect(result.current.activeIndex).toBe(2);
    });

    it('should handle ArrowDown in handleKeyDown', () => {
      const { result } = renderHook(() => useRovingTabIndex(5));

      const mockEvent = {
        key: 'ArrowDown',
        preventDefault: vi.fn(),
        currentTarget: {
          parentElement: {
            children: Array(5).fill({}).map(() => ({
              focus: vi.fn(),
            })),
          },
        },
      } as unknown as React.KeyboardEvent;

      act(() => {
        result.current.handleKeyDown(mockEvent, 0);
      });

      expect(result.current.activeIndex).toBe(1);
    });

    it('should wrap around on ArrowDown at end', () => {
      const { result } = renderHook(() => useRovingTabIndex(3));

      act(() => {
        result.current.setActiveIndex(2);
      });

      const mockEvent = {
        key: 'ArrowDown',
        preventDefault: vi.fn(),
        currentTarget: {
          parentElement: {
            children: Array(3).fill({}).map(() => ({
              focus: vi.fn(),
            })),
          },
        },
      } as unknown as React.KeyboardEvent;

      act(() => {
        result.current.handleKeyDown(mockEvent, 2);
      });

      expect(result.current.activeIndex).toBe(0);
    });

    it('should adjust activeIndex when itemCount decreases', () => {
      const { result, rerender } = renderHook(
        ({ count }) => useRovingTabIndex(count),
        { initialProps: { count: 5 } }
      );

      act(() => {
        result.current.setActiveIndex(4);
      });

      rerender({ count: 3 });

      expect(result.current.activeIndex).toBe(2);
    });
  });
});
