/**
 * ResponsiveContext.tsx
 *
 * 반응형 상태를 React Context로 제공하는 모듈
 * DynamicRenderer에서 사용하여 모든 자식 컴포넌트에 반응형 상태를 전달합니다.
 */

import React, { createContext, useContext, useState, useEffect, useMemo } from 'react';
import { responsiveManager } from './ResponsiveManager';

/**
 * Responsive Context 값 인터페이스
 */
export interface ResponsiveContextValue {
  /** 현재 화면 너비 (px) */
  width: number;
  /** 모바일 여부 (< 768px) */
  isMobile: boolean;
  /** 태블릿 여부 (768-1023px) */
  isTablet: boolean;
  /** 데스크톱 여부 (>= 1024px) */
  isDesktop: boolean;
  /** 현재 매칭된 프리셋 */
  matchedPreset: 'mobile' | 'tablet' | 'desktop';
}

/**
 * 너비에 따른 프리셋 계산
 *
 * @param width 화면 너비
 * @returns 매칭된 프리셋
 */
function getPresetFromWidth(width: number): 'mobile' | 'tablet' | 'desktop' {
  if (width < 768) {
    return 'mobile';
  }
  if (width < 1024) {
    return 'tablet';
  }
  return 'desktop';
}

/**
 * 너비에 따른 Context 값 계산
 *
 * @param width 화면 너비
 * @returns ResponsiveContextValue
 */
function createContextValue(width: number): ResponsiveContextValue {
  const matchedPreset = getPresetFromWidth(width);
  return {
    width,
    isMobile: matchedPreset === 'mobile',
    isTablet: matchedPreset === 'tablet',
    isDesktop: matchedPreset === 'desktop',
    matchedPreset,
  };
}

/**
 * Responsive Context
 * 기본값은 데스크톱 (1024px)
 */
const ResponsiveContext = createContext<ResponsiveContextValue>(
  createContextValue(1024)
);

/**
 * Responsive Provider Props
 */
interface ResponsiveProviderProps {
  children: React.ReactNode;
  /**
   * 뷰포트 너비 오버라이드 (위지윅 편집기용)
   *
   * 이 값이 설정되면 실제 브라우저 창 크기 대신 이 값을 사용합니다.
   * 위지윅 편집기에서 모바일/태블릿 미리보기 시 활용됩니다.
   */
  overrideWidth?: number;
}

/**
 * Responsive Provider 컴포넌트
 *
 * ResponsiveManager를 구독하여 화면 크기 상태를 Context로 제공합니다.
 * template-engine.ts의 renderTemplate에서 최상위에 래핑됩니다.
 *
 * @param overrideWidth - 위지윅 편집기에서 디바이스 미리보기 시 사용할 너비
 */
export const ResponsiveProvider: React.FC<ResponsiveProviderProps> = ({ children, overrideWidth }) => {
  const [actualWidth, setActualWidth] = useState(() => responsiveManager.getWidth());

  useEffect(() => {
    // ResponsiveManager 구독
    const unsubscribe = responsiveManager.subscribe((newWidth) => {
      setActualWidth(newWidth);
    });

    return () => {
      unsubscribe();
    };
  }, []);

  // overrideWidth가 있으면 그 값 사용, 없으면 실제 브라우저 너비 사용
  const effectiveWidth = overrideWidth ?? actualWidth;
  const value = useMemo(() => createContextValue(effectiveWidth), [effectiveWidth]);

  return (
    <ResponsiveContext.Provider value={value}>
      {children}
    </ResponsiveContext.Provider>
  );
};

/**
 * Responsive 상태를 사용하는 Hook
 *
 * @returns ResponsiveContextValue
 *
 * @example
 * // 컴포넌트에서 사용
 * const { width, isMobile, isTablet, isDesktop, matchedPreset } = useResponsive();
 *
 * return (
 *   <div className={isMobile ? 'flex-col' : 'flex-row'}>
 *     {content}
 *   </div>
 * );
 */
export const useResponsive = (): ResponsiveContextValue => {
  return useContext(ResponsiveContext);
};

export { ResponsiveContext };
