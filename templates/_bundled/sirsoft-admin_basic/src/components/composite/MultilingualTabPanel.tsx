/**
 * MultilingualTabPanel 컴포넌트
 *
 * DynamicFieldList 등 다국어 컬럼을 포함하는 컴포넌트에서
 * 로케일을 제어할 수 있는 탭 패널입니다.
 *
 * React Context를 통해 자식 컴포넌트에 활성 로케일을 전파합니다.
 * Stale Closure 방지 패턴이 적용되어 있습니다.
 */

import React, { useState, useRef, useCallback, useMemo, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Nav } from '../basic/Nav';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { MultilingualLocaleContext } from './MultilingualLocaleContext';

// G7Core 로케일 API
const getG7Core = () => (window as any).G7Core;
const getSupportedLocales = (): string[] => getG7Core()?.locale?.supported?.() ?? ['ko', 'en'];
const getCurrentLocale = (): string => getG7Core()?.locale?.current?.() ?? 'ko';

// 로케일 표시명
const LOCALE_NAMES: Record<string, string> = {
  ko: '한국어',
  en: 'English',
  ja: '日本語',
  zh: '中文',
};

/**
 * MultilingualTabPanel Props
 */
export interface MultilingualTabPanelProps {
  /** 자식 요소 (DynamicFieldList 등) */
  children?: React.ReactNode;
  /** 추가 CSS 클래스 */
  className?: string;
  /** 인라인 스타일 */
  style?: React.CSSProperties;
  /** 탭 스타일 variant */
  variant?: 'default' | 'pills' | 'underline';
  /** 기본 선택 로케일 */
  defaultLocale?: string;
  /** 로케일 변경 시 콜백 */
  onLocaleChange?: (locale: string) => void;
  /** 표시할 로케일 목록 (미지정 시 그누보드7 지원 로케일 전체 표시) */
  locales?: string[];
}

/**
 * MultilingualTabPanel
 *
 * @example
 * ```tsx
 * <MultilingualTabPanel variant="underline">
 *   <DynamicFieldList
 *     dataKey="fields"
 *     columns={[
 *       { key: "name", type: "multilingual" },
 *       { key: "content", type: "multilingual" }
 *     ]}
 *   />
 * </MultilingualTabPanel>
 * ```
 */
export const MultilingualTabPanel: React.FC<MultilingualTabPanelProps> = ({
  children,
  className = '',
  style,
  variant = 'default',
  defaultLocale,
  onLocaleChange,
  locales,
}) => {
  // locales prop이 제공되면 해당 로케일만 표시, 미제공 시 그누보드7 지원 로케일 전체 표시
  const supportedLocales = useMemo(
    () => (locales && locales.length > 0 ? locales : getSupportedLocales()),
    [locales]
  );
  const [activeLocale, setActiveLocale] = useState<string>(
    defaultLocale || getCurrentLocale()
  );

  // locales가 변경될 때 activeLocale이 새 목록에 없으면 첫 번째 로케일로 변경
  useEffect(() => {
    if (supportedLocales.length > 0 && !supportedLocales.includes(activeLocale)) {
      const newLocale = supportedLocales[0];
      setActiveLocale(newLocale);
      onLocaleChange?.(newLocale);
    }
  }, [supportedLocales, activeLocale, onLocaleChange]);

  // Stale Closure 방지: useRef로 최신 값 유지
  const activeLocaleRef = useRef(activeLocale);
  activeLocaleRef.current = activeLocale;

  const getActiveLocale = useCallback(() => activeLocaleRef.current, []);

  const handleTabClick = useCallback(
    (locale: string) => {
      setActiveLocale(locale);
      onLocaleChange?.(locale);
    },
    [onLocaleChange]
  );

  // Context value (memoized)
  const contextValue = useMemo(
    () => ({
      activeLocale,
      getActiveLocale,
      supportedLocales,
    }),
    [activeLocale, getActiveLocale, supportedLocales]
  );

  // 탭 스타일 함수
  const getTabClasses = (locale: string) => {
    const isActive = locale === activeLocale;
    const baseClasses = 'px-4 py-2 text-sm font-medium transition-all';

    if (variant === 'pills') {
      return isActive
        ? `${baseClasses} bg-blue-600 text-white rounded-lg`
        : `${baseClasses} text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg`;
    }

    if (variant === 'underline') {
      return isActive
        ? `${baseClasses} text-blue-600 dark:text-blue-400 border-b-2 border-blue-600 dark:border-blue-400`
        : `${baseClasses} text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:border-gray-300 dark:hover:border-gray-500`;
    }

    // default
    return isActive
      ? `${baseClasses} text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 border-b-2 border-blue-600`
      : `${baseClasses} text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 border-b-2 border-transparent`;
  };

  return (
    <Div className={className} style={style}>
      {/* 탭 네비게이션 */}
      <Nav className="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-4">
        {supportedLocales.map((locale) => (
          <Button
            key={locale}
            type="button"
            onClick={() => handleTabClick(locale)}
            className={getTabClasses(locale)}
          >
            <Span>{LOCALE_NAMES[locale] || locale}</Span>
          </Button>
        ))}
      </Nav>

      {/* Context를 통해 children에 activeLocale 전달 */}
      <MultilingualLocaleContext.Provider value={contextValue}>
        {children}
      </MultilingualLocaleContext.Provider>
    </Div>
  );
};

export default MultilingualTabPanel;
