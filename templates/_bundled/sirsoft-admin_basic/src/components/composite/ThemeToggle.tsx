import React, { useState, useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Span } from '../basic/Span';

/**
 * 테마 모드 타입
 */
export type ThemeMode = 'auto' | 'light' | 'dark';

/**
 * ThemeToggle Props
 */
export interface ThemeToggleProps {
  /** 테마 변경 콜백 */
  onThemeChange?: (theme: ThemeMode) => void;
  /** 추가 CSS 클래스 */
  className?: string;
  /** 자동 모드 텍스트 (다국어 키 사용 권장) */
  autoText?: string;
  /** 라이트 모드 텍스트 (다국어 키 사용 권장) */
  lightText?: string;
  /** 다크 모드 텍스트 (다국어 키 사용 권장) */
  darkText?: string;
}

/**
 * ThemeToggle 컴포넌트
 *
 * 다크/라이트 모드 전환 버튼
 * - 3가지 모드: 자동(시스템 설정 따름), 라이트, 다크
 * - localStorage에 'g7_color_scheme' 키로 저장
 * - 시스템 prefers-color-scheme 감지 지원
 *
 * @example
 * ```json
 * {
 *   "type": "composite",
 *   "name": "ThemeToggle",
 *   "props": {
 *     "autoText": "$t:admin.theme.auto",
 *     "lightText": "$t:admin.theme.light",
 *     "darkText": "$t:admin.theme.dark"
 *   }
 * }
 * ```
 */
/**
 * 실제 적용될 테마 결정 (auto인 경우 시스템 설정 반영)
 */
const getEffectiveTheme = (mode: ThemeMode): 'light' | 'dark' => {
  if (mode === 'auto') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';
  }
  return mode;
};

/**
 * 테마 적용
 */
const applyTheme = (mode: ThemeMode) => {
  const effectiveTheme = getEffectiveTheme(mode);
  document.documentElement.setAttribute('data-theme', effectiveTheme);

  // dark 클래스 토글 (Tailwind CSS dark: 모드 지원)
  if (effectiveTheme === 'dark') {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }
};

/**
 * 초기 테마 로드 및 적용 (즉시 실행)
 */
const getInitialTheme = (): ThemeMode => {
  const savedTheme = localStorage.getItem('g7_color_scheme') as ThemeMode | null;
  if (savedTheme && ['auto', 'light', 'dark'].includes(savedTheme)) {
    return savedTheme;
  }
  return 'auto';
};

export const ThemeToggle: React.FC<ThemeToggleProps> = ({
  onThemeChange,
  className = '',
  autoText = 'System',
  lightText = 'Light',
  darkText = 'Dark',
}) => {
  // 초기 테마를 즉시 로드하고 적용
  const initialTheme = getInitialTheme();
  const [currentMode, setCurrentMode] = useState<ThemeMode>(initialTheme);
  const [showMenu, setShowMenu] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  /**
   * 컴포넌트 마운트 시 즉시 테마 적용
   */
  useEffect(() => {
    applyTheme(currentMode);
  }, []);

  /**
   * 시스템 테마 변경 감지
   */
  useEffect(() => {
    if (currentMode !== 'auto') return;

    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const handleChange = () => {
      applyTheme('auto');
    };

    mediaQuery.addEventListener('change', handleChange);
    return () => mediaQuery.removeEventListener('change', handleChange);
  }, [currentMode]);

  /**
   * 외부 클릭 감지
   */
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setShowMenu(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  /**
   * 테마 변경 핸들러
   */
  const handleThemeChange = (mode: ThemeMode) => {
    setCurrentMode(mode);
    localStorage.setItem('g7_color_scheme', mode);
    applyTheme(mode);
    setShowMenu(false);
    onThemeChange?.(mode);
  };

  /**
   * 현재 표시할 아이콘 결정
   */
  const getCurrentIcon = (): IconName => {
    const effectiveTheme = getEffectiveTheme(currentMode);
    return effectiveTheme === 'dark' ? IconName.Moon : IconName.Sun;
  };

  return (
    <Div ref={menuRef} className={`relative ${className}`}>
      {/* 테마 토글 버튼 */}
      <Button
        onClick={() => setShowMenu(!showMenu)}
        className="p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors"
        aria-label="Toggle theme"
      >
        <Icon
          name={getCurrentIcon()}
          className="w-5 h-5 text-gray-600 dark:text-gray-400"
        />
      </Button>

      {/* 테마 선택 드롭다운 */}
      {showMenu && (
        <Div className="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
          <Div className="py-2">
            {/* 자동 모드 */}
            <Button
              onClick={() => handleThemeChange('auto')}
              className={`
                w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors
                ${currentMode === 'auto' ? 'bg-gray-50 dark:bg-gray-700' : ''}
              `}
            >
              <Icon
                name={IconName.Settings}
                className="w-5 h-5 text-gray-600 dark:text-gray-400"
              />
              <Span className="flex-1 text-left text-gray-900 dark:text-white">{autoText}</Span>
              {currentMode === 'auto' && (
                <Icon
                  name={IconName.Check}
                  className="w-4 h-4 text-blue-600 dark:text-blue-400 ml-auto"
                />
              )}
            </Button>

            {/* 라이트 모드 */}
            <Button
              onClick={() => handleThemeChange('light')}
              className={`
                w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors
                ${currentMode === 'light' ? 'bg-gray-50 dark:bg-gray-700' : ''}
              `}
            >
              <Icon
                name={IconName.Sun}
                className="w-5 h-5 text-gray-600 dark:text-gray-400"
              />
              <Span className="flex-1 text-left text-gray-900 dark:text-white">{lightText}</Span>
              {currentMode === 'light' && (
                <Icon
                  name={IconName.Check}
                  className="w-4 h-4 text-blue-600 dark:text-blue-400 ml-auto"
                />
              )}
            </Button>

            {/* 다크 모드 */}
            <Button
              onClick={() => handleThemeChange('dark')}
              className={`
                w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors
                ${currentMode === 'dark' ? 'bg-gray-50 dark:bg-gray-700' : ''}
              `}
            >
              <Icon
                name={IconName.Moon}
                className="w-5 h-5 text-gray-600 dark:text-gray-400"
              />
              <Span className="flex-1 text-left text-gray-900 dark:text-white">{darkText}</Span>
              {currentMode === 'dark' && (
                <Icon
                  name={IconName.Check}
                  className="w-4 h-4 text-blue-600 dark:text-blue-400 ml-auto"
                />
              )}
            </Button>
          </Div>
        </Div>
      )}
    </Div>
  );
};
