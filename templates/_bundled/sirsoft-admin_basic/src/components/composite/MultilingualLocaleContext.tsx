/**
 * MultilingualLocaleContext
 *
 * MultilingualTabPanel 내부에서 현재 활성 로케일을 전파하는 Context입니다.
 * Stale Closure 방지를 위해 getter 함수를 제공합니다.
 */

import { createContext, useContext } from 'react';

// G7Core 전역 객체 접근
const getG7Core = () => (window as any).G7Core;

/**
 * Context 값 인터페이스
 */
export interface MultilingualLocaleContextValue {
  /** 현재 활성 로케일 (렌더링용) */
  activeLocale: string;
  /** Stale Closure 방지용 getter - 이벤트 핸들러에서 사용 */
  getActiveLocale: () => string;
  /** 지원되는 로케일 목록 */
  supportedLocales: string[];
}

/**
 * useCurrentMultilingualLocale 훅 반환 타입
 */
export interface UseCurrentMultilingualLocaleResult extends MultilingualLocaleContextValue {
  /** MultilingualTabPanel 내부에서 사용 중인지 여부 */
  isInsideMultilingualTabPanel: boolean;
}

/**
 * MultilingualLocaleContext
 *
 * MultilingualTabPanel에서 Provider로 값을 제공합니다.
 */
export const MultilingualLocaleContext = createContext<MultilingualLocaleContextValue | null>(null);

/**
 * MultilingualTabPanel 내부에서 현재 활성 로케일 접근
 *
 * @returns activeLocale, getActiveLocale (Stale Closure 방지), supportedLocales
 * @fallback Context 외부에서는 G7Core.locale.current() 사용
 *
 * @example
 * ```tsx
 * const { activeLocale, getActiveLocale } = useCurrentMultilingualLocale();
 *
 * // 렌더링에서는 activeLocale 직접 사용
 * const displayValue = value?.[activeLocale] || '';
 *
 * // 이벤트 핸들러에서는 getActiveLocale() 사용 (Stale Closure 방지)
 * const handleChange = (newValue: string) => {
 *   const currentLocale = getActiveLocale();
 *   onChange({ ...value, [currentLocale]: newValue });
 * };
 * ```
 */
export const useCurrentMultilingualLocale = (): UseCurrentMultilingualLocaleResult => {
  const context = useContext(MultilingualLocaleContext);

  // Context 외부 (MultilingualTabPanel 없이 사용 시) → G7Core fallback
  if (!context) {
    const g7Core = getG7Core();
    const currentLocale = g7Core?.locale?.current?.() || 'ko';
    const supportedLocales = g7Core?.locale?.supported?.() || ['ko', 'en'];

    return {
      activeLocale: currentLocale,
      getActiveLocale: () => g7Core?.locale?.current?.() || 'ko',
      supportedLocales,
      isInsideMultilingualTabPanel: false,
    };
  }

  return {
    ...context,
    isInsideMultilingualTabPanel: true,
  };
};

export default MultilingualLocaleContext;
