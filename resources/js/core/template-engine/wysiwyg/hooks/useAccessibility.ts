/**
 * useAccessibility.ts
 *
 * G7 위지윅 레이아웃 편집기의 접근성 훅
 *
 * 역할:
 * - 포커스 관리
 * - ARIA 속성 관리
 * - 스크린 리더 알림
 * - 키보드 내비게이션
 */

import { useRef, useCallback, useEffect, useState } from 'react';

// ============================================================================
// 타입 정의
// ============================================================================

export interface FocusTrapOptions {
  /** 초기 포커스 요소 선택자 */
  initialFocus?: string;
  /** 포커스 반환 여부 */
  returnFocus?: boolean;
  /** 포커스 트랩 활성화 여부 */
  enabled?: boolean;
}

export interface AriaLiveOptions {
  /** 알림 우선순위 */
  priority?: 'polite' | 'assertive';
  /** 알림 지속 시간 (ms) */
  clearAfter?: number;
}

export interface KeyboardNavigationOptions {
  /** 방향키 지원 */
  arrows?: boolean;
  /** Home/End 키 지원 */
  homeEnd?: boolean;
  /** 순환 내비게이션 */
  loop?: boolean;
}

// ============================================================================
// useFocusTrap - 포커스 트랩
// ============================================================================

/**
 * 포커스를 특정 영역 내에 가두는 훅
 *
 * @param options 포커스 트랩 옵션
 * @returns 컨테이너 ref
 *
 * @example
 * const containerRef = useFocusTrap({ enabled: isModalOpen });
 * <div ref={containerRef}>...</div>
 */
export function useFocusTrap<T extends HTMLElement>(
  options: FocusTrapOptions = {}
): React.RefObject<T> {
  const { initialFocus, returnFocus = true, enabled = true } = options;
  const containerRef = useRef<T>(null);
  const previousActiveElement = useRef<Element | null>(null);

  // 포커스 가능한 요소 선택자
  const FOCUSABLE_SELECTOR = [
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'a[href]',
    '[tabindex]:not([tabindex="-1"])',
  ].join(', ');

  // 포커스 가능한 요소 목록 가져오기
  const getFocusableElements = useCallback((): HTMLElement[] => {
    if (!containerRef.current) return [];
    return Array.from(
      containerRef.current.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)
    );
  }, [FOCUSABLE_SELECTOR]);

  // Tab 키 핸들러
  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (!enabled || e.key !== 'Tab') return;

      const focusableElements = getFocusableElements();
      if (focusableElements.length === 0) return;

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];

      if (e.shiftKey) {
        // Shift+Tab: 첫 번째 요소에서 마지막으로 이동
        if (document.activeElement === firstElement) {
          e.preventDefault();
          lastElement.focus();
        }
      } else {
        // Tab: 마지막 요소에서 첫 번째로 이동
        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      }
    },
    [enabled, getFocusableElements]
  );

  useEffect(() => {
    if (!enabled || !containerRef.current) return;

    // 이전 활성 요소 저장
    previousActiveElement.current = document.activeElement;

    // 초기 포커스 설정
    const setInitialFocus = () => {
      if (initialFocus) {
        const element = containerRef.current?.querySelector<HTMLElement>(initialFocus);
        if (element) {
          element.focus();
          return;
        }
      }

      // 기본: 첫 번째 포커스 가능한 요소
      const focusableElements = getFocusableElements();
      if (focusableElements.length > 0) {
        focusableElements[0].focus();
      }
    };

    setInitialFocus();

    // 키보드 이벤트 리스너
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('keydown', handleKeyDown);

      // 포커스 반환
      if (returnFocus && previousActiveElement.current instanceof HTMLElement) {
        previousActiveElement.current.focus();
      }
    };
  }, [enabled, initialFocus, returnFocus, handleKeyDown, getFocusableElements]);

  return containerRef;
}

// ============================================================================
// useAriaLive - 스크린 리더 알림
// ============================================================================

/**
 * 스크린 리더에 동적 알림을 제공하는 훅
 *
 * @param options ARIA Live 옵션
 * @returns announce 함수
 *
 * @example
 * const announce = useAriaLive({ priority: 'polite' });
 * announce('저장이 완료되었습니다.');
 */
export function useAriaLive(
  options: AriaLiveOptions = {}
): (message: string) => void {
  const { priority = 'polite', clearAfter = 5000 } = options;
  const liveRegionRef = useRef<HTMLDivElement | null>(null);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Live 영역 생성
  useEffect(() => {
    // 이미 존재하는지 확인
    let liveRegion = document.getElementById('g7-aria-live') as HTMLDivElement | null;

    if (!liveRegion) {
      liveRegion = document.createElement('div');
      liveRegion.id = 'g7-aria-live';
      liveRegion.setAttribute('role', 'status');
      liveRegion.setAttribute('aria-live', priority);
      liveRegion.setAttribute('aria-atomic', 'true');
      liveRegion.style.cssText = `
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
      `;
      document.body.appendChild(liveRegion);
    }

    liveRegionRef.current = liveRegion;

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, [priority]);

  // 알림 함수
  const announce = useCallback(
    (message: string) => {
      if (!liveRegionRef.current) return;

      // 이전 타이머 취소
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }

      // 메시지 설정
      liveRegionRef.current.textContent = message;

      // 일정 시간 후 클리어
      if (clearAfter > 0) {
        timeoutRef.current = setTimeout(() => {
          if (liveRegionRef.current) {
            liveRegionRef.current.textContent = '';
          }
        }, clearAfter);
      }
    },
    [clearAfter]
  );

  return announce;
}

// ============================================================================
// useKeyboardNavigation - 키보드 내비게이션
// ============================================================================

/**
 * 목록 내 키보드 내비게이션을 제공하는 훅
 *
 * @param itemCount 아이템 개수
 * @param options 내비게이션 옵션
 * @returns { currentIndex, setIndex, handleKeyDown }
 *
 * @example
 * const { currentIndex, handleKeyDown } = useKeyboardNavigation(items.length);
 * <ul onKeyDown={handleKeyDown}>...</ul>
 */
export function useKeyboardNavigation(
  itemCount: number,
  options: KeyboardNavigationOptions = {}
): {
  currentIndex: number;
  setIndex: (index: number) => void;
  handleKeyDown: (e: React.KeyboardEvent) => void;
} {
  const { arrows = true, homeEnd = true, loop = true } = options;
  const [currentIndex, setCurrentIndex] = useState(0);

  // 인덱스 범위 제한
  const clampIndex = useCallback(
    (index: number): number => {
      if (itemCount === 0) return 0;

      if (loop) {
        if (index < 0) return itemCount - 1;
        if (index >= itemCount) return 0;
        return index;
      }

      return Math.max(0, Math.min(itemCount - 1, index));
    },
    [itemCount, loop]
  );

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      let newIndex = currentIndex;

      if (arrows) {
        switch (e.key) {
          case 'ArrowDown':
          case 'ArrowRight':
            e.preventDefault();
            newIndex = clampIndex(currentIndex + 1);
            break;
          case 'ArrowUp':
          case 'ArrowLeft':
            e.preventDefault();
            newIndex = clampIndex(currentIndex - 1);
            break;
        }
      }

      if (homeEnd) {
        switch (e.key) {
          case 'Home':
            e.preventDefault();
            newIndex = 0;
            break;
          case 'End':
            e.preventDefault();
            newIndex = itemCount - 1;
            break;
        }
      }

      if (newIndex !== currentIndex) {
        setCurrentIndex(newIndex);
      }
    },
    [currentIndex, itemCount, arrows, homeEnd, clampIndex]
  );

  // 아이템 개수가 변경되면 인덱스 조정
  useEffect(() => {
    if (currentIndex >= itemCount && itemCount > 0) {
      setCurrentIndex(itemCount - 1);
    }
  }, [itemCount, currentIndex]);

  return {
    currentIndex,
    setIndex: setCurrentIndex,
    handleKeyDown,
  };
}

// ============================================================================
// useFocusVisible - 포커스 가시성 감지
// ============================================================================

/**
 * 키보드 포커스 vs 마우스 포커스를 구분하는 훅
 *
 * @returns 키보드 포커스 여부
 *
 * @example
 * const isFocusVisible = useFocusVisible();
 * <button className={isFocusVisible ? 'focus-visible' : ''}>...</button>
 */
export function useFocusVisible(): boolean {
  const [isFocusVisible, setIsFocusVisible] = useState(false);
  const hadKeyboardEvent = useRef(false);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Tab' || e.key === 'Escape') {
        hadKeyboardEvent.current = true;
      }
    };

    const handleMouseDown = () => {
      hadKeyboardEvent.current = false;
    };

    const handleFocus = () => {
      setIsFocusVisible(hadKeyboardEvent.current);
    };

    const handleBlur = () => {
      setIsFocusVisible(false);
    };

    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('mousedown', handleMouseDown);
    document.addEventListener('focus', handleFocus, true);
    document.addEventListener('blur', handleBlur, true);

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.removeEventListener('mousedown', handleMouseDown);
      document.removeEventListener('focus', handleFocus, true);
      document.removeEventListener('blur', handleBlur, true);
    };
  }, []);

  return isFocusVisible;
}

// ============================================================================
// useReducedMotion - 모션 감소 선호도 감지
// ============================================================================

/**
 * 사용자의 모션 감소 선호도를 감지하는 훅
 *
 * @returns 모션 감소 선호 여부
 *
 * @example
 * const prefersReducedMotion = useReducedMotion();
 * const animationDuration = prefersReducedMotion ? 0 : 300;
 */
export function useReducedMotion(): boolean {
  const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);

  useEffect(() => {
    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    setPrefersReducedMotion(mediaQuery.matches);

    const handleChange = (e: MediaQueryListEvent) => {
      setPrefersReducedMotion(e.matches);
    };

    mediaQuery.addEventListener('change', handleChange);

    return () => {
      mediaQuery.removeEventListener('change', handleChange);
    };
  }, []);

  return prefersReducedMotion;
}

// ============================================================================
// useSkipLink - 스킵 링크
// ============================================================================

/**
 * 메인 콘텐츠로 건너뛰는 스킵 링크 기능을 제공하는 훅
 *
 * @param targetId 대상 요소 ID
 * @returns { skipToContent, SkipLink }
 *
 * @example
 * const { SkipLink } = useSkipLink('main-content');
 * <SkipLink />
 * <main id="main-content">...</main>
 */
export function useSkipLink(targetId: string): {
  skipToContent: () => void;
  SkipLinkProps: {
    href: string;
    onClick: (e: React.MouseEvent) => void;
    className: string;
  };
} {
  const skipToContent = useCallback(() => {
    const target = document.getElementById(targetId);
    if (target) {
      target.setAttribute('tabindex', '-1');
      target.focus();
      target.removeAttribute('tabindex');
    }
  }, [targetId]);

  const handleClick = useCallback(
    (e: React.MouseEvent) => {
      e.preventDefault();
      skipToContent();
    },
    [skipToContent]
  );

  return {
    skipToContent,
    SkipLinkProps: {
      href: `#${targetId}`,
      onClick: handleClick,
      className:
        'sr-only focus:not-sr-only focus:absolute focus:top-0 focus:left-0 focus:z-50 focus:p-4 focus:bg-white focus:text-black',
    },
  };
}

// ============================================================================
// useAnnounceOnChange - 값 변경 시 알림
// ============================================================================

/**
 * 값이 변경될 때 스크린 리더에 알림을 제공하는 훅
 *
 * @param value 감시할 값
 * @param getMessage 메시지 생성 함수
 * @param options ARIA Live 옵션
 *
 * @example
 * useAnnounceOnChange(itemCount, (count) => `${count}개의 항목`);
 */
export function useAnnounceOnChange<T>(
  value: T,
  getMessage: (value: T) => string,
  options: AriaLiveOptions = {}
): void {
  const announce = useAriaLive(options);
  const isFirstRender = useRef(true);
  const previousValue = useRef(value);

  useEffect(() => {
    // 첫 렌더링에서는 알림하지 않음
    if (isFirstRender.current) {
      isFirstRender.current = false;
      return;
    }

    // 값이 변경되었을 때만 알림
    if (value !== previousValue.current) {
      announce(getMessage(value));
      previousValue.current = value;
    }
  }, [value, getMessage, announce]);
}

// ============================================================================
// useRovingTabIndex - Roving TabIndex 패턴
// ============================================================================

/**
 * Roving TabIndex 패턴을 구현하는 훅
 * (그룹 내에서 하나의 요소만 tabindex=0, 나머지는 -1)
 *
 * @param itemCount 아이템 개수
 * @returns { activeIndex, getTabIndex, handleKeyDown, handleFocus }
 *
 * @example
 * const { activeIndex, getTabIndex, handleKeyDown } = useRovingTabIndex(items.length);
 * items.map((item, i) => (
 *   <button
 *     tabIndex={getTabIndex(i)}
 *     onKeyDown={handleKeyDown}
 *   >{item}</button>
 * ));
 */
export function useRovingTabIndex(
  itemCount: number
): {
  activeIndex: number;
  setActiveIndex: (index: number) => void;
  getTabIndex: (index: number) => 0 | -1;
  handleKeyDown: (e: React.KeyboardEvent, index: number) => void;
  handleFocus: (index: number) => void;
} {
  const [activeIndex, setActiveIndex] = useState(0);

  const getTabIndex = useCallback(
    (index: number): 0 | -1 => {
      return index === activeIndex ? 0 : -1;
    },
    [activeIndex]
  );

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent, index: number) => {
      let newIndex = index;

      switch (e.key) {
        case 'ArrowDown':
        case 'ArrowRight':
          e.preventDefault();
          newIndex = (index + 1) % itemCount;
          break;
        case 'ArrowUp':
        case 'ArrowLeft':
          e.preventDefault();
          newIndex = (index - 1 + itemCount) % itemCount;
          break;
        case 'Home':
          e.preventDefault();
          newIndex = 0;
          break;
        case 'End':
          e.preventDefault();
          newIndex = itemCount - 1;
          break;
      }

      if (newIndex !== index) {
        setActiveIndex(newIndex);
        // 새 요소에 포커스
        const target = e.currentTarget.parentElement?.children[newIndex] as HTMLElement;
        target?.focus();
      }
    },
    [itemCount]
  );

  const handleFocus = useCallback((index: number) => {
    setActiveIndex(index);
  }, []);

  // 아이템 개수가 변경되면 인덱스 조정
  useEffect(() => {
    if (activeIndex >= itemCount && itemCount > 0) {
      setActiveIndex(itemCount - 1);
    }
  }, [itemCount, activeIndex]);

  return {
    activeIndex,
    setActiveIndex,
    getTabIndex,
    handleKeyDown,
    handleFocus,
  };
}

// ============================================================================
// Export
// ============================================================================

export default {
  useFocusTrap,
  useAriaLive,
  useKeyboardNavigation,
  useFocusVisible,
  useReducedMotion,
  useSkipLink,
  useAnnounceOnChange,
  useRovingTabIndex,
};
