import React, { useState, useRef, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Span } from '../basic/Span';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:LanguageSelector')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:LanguageSelector]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:LanguageSelector]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:LanguageSelector]', ...args),
};

/**
 * 로케일 코드를 사람이 읽을 수 있는 이름으로 변환
 */
const getLocaleName = (locale: string): string => {
  // 활성 언어팩의 native_name 을 우선 사용 (LanguagePackServiceProvider 가 주입)
  const fromAppConfig = (window as any).G7Core?.state?.get?.('_global.appConfig.localeNames')?.[locale];
  if (fromAppConfig) {
    return fromAppConfig;
  }
  const fallback: Record<string, string> = {
    ko: '한국어',
    en: 'English',
    ja: '日本語',
    zh: '中文',
    es: 'Español',
    fr: 'Français',
    de: 'Deutsch',
  };
  return fallback[locale] || locale;
};

/**
 * LanguageSelector Props
 */
export interface LanguageSelectorProps {
  /** 사용 가능한 언어 목록 (template.json의 locales에서 자동 바인딩) */
  availableLocales?: string[];
  /** 언어 설정 텍스트 */
  languageText?: string;
  /** 언어 변경 API 엔드포인트 */
  apiEndpoint?: string;
  /** 언어 변경 후 콜백 */
  onLanguageChange?: (locale: string) => void;
  /** 추가 CSS 클래스 */
  className?: string;
  /** 인라인 모드 (드롭다운 메뉴 내에서 사용) */
  inline?: boolean;
}

/**
 * LanguageSelector 컴포넌트
 *
 * 언어 전환 드롭다운 제공
 * - DB에 언어 설정 저장
 * - localStorage에 로케일 저장
 * - 새로고침 없이 UI 리렌더링
 *
 * @example
 * ```tsx
 * // 독립적으로 사용
 * <LanguageSelector
 *   availableLocales={['ko', 'en']}
 *   languageText="언어"
 * />
 *
 * // 인라인 모드 (다른 드롭다운 내에서 사용)
 * <LanguageSelector
 *   availableLocales={['ko', 'en']}
 *   languageText="언어"
 *   inline
 * />
 * ```
 */
export const LanguageSelector: React.FC<LanguageSelectorProps> = ({
  availableLocales = ['ko', 'en'],
  languageText = 'Language',
  apiEndpoint = '/api/admin/users/me/language',
  onLanguageChange,
  className = '',
  inline = false,
}) => {
  const [showMenu, setShowMenu] = useState(false);
  const [isChanging, setIsChanging] = useState(false);
  const [currentLocale, setCurrentLocale] = useState<string>(() => {
    return localStorage.getItem('g7_locale') || 'ko';
  });
  const menuRef = useRef<HTMLDivElement>(null);

  /**
   * 외부 클릭 감지 (인라인 모드가 아닐 때만)
   */
  useEffect(() => {
    if (inline) return;

    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setShowMenu(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [inline]);

  /**
   * 쿠키에서 XSRF 토큰 읽기
   */
  const getXsrfToken = (): string | null => {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; XSRF-TOKEN=`);
    if (parts.length === 2) {
      return decodeURIComponent(parts.pop()?.split(';').shift() || '');
    }
    return null;
  };

  /**
   * 로컬 스토리지에서 Bearer 토큰 읽기
   */
  const getBearerToken = (): string | null => {
    return localStorage.getItem('auth_token');
  };

  /**
   * 언어 변경 처리
   */
  const handleLanguageChange = async (locale: string) => {
    if (isChanging || locale === currentLocale) return;

    try {
      setIsChanging(true);

      const xsrfToken = getXsrfToken();
      const bearerToken = getBearerToken();

      // API 호출로 DB에 저장 (실패해도 UI는 변경)
      try {
        const response = await fetch(apiEndpoint, {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(xsrfToken && { 'X-XSRF-TOKEN': xsrfToken }),
            ...(bearerToken && { Authorization: `Bearer ${bearerToken}` }),
          },
          credentials: 'include',
          body: JSON.stringify({ language: locale }),
        });

        if (!response.ok) {
          logger.warn('언어 설정 저장 실패 (UI는 변경됨):', response.statusText);
        }
      } catch (apiError) {
        logger.warn('언어 설정 API 호출 실패 (UI는 변경됨):', apiError);
      }

      // UI 상태 업데이트
      setCurrentLocale(locale);
      setShowMenu(false);

      // 콜백 실행
      onLanguageChange?.(locale);

      // TemplateApp의 changeLocale 호출하여 UI 리렌더링
      if ((window as any).__templateApp) {
        await (window as any).__templateApp.changeLocale(locale);
      } else {
        logger.warn('TemplateApp not found, reloading page');
        window.location.reload();
      }
    } catch (error) {
      logger.error('언어 변경 오류:', error);
    } finally {
      setIsChanging(false);
    }
  };

  // 인라인 모드: 접기/펼치기 가능한 뱃지 방식
  if (inline) {
    return (
      <Div className={`${className}`}>
        <Button
          onClick={() => setShowMenu(!showMenu)}
          disabled={isChanging}
          className="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors"
        >
          <Icon
            name={IconName.Globe}
            className="w-5 h-5 text-gray-600 dark:text-gray-400"
          />
          <Span className="text-gray-900 dark:text-white flex-1">
            {languageText}
          </Span>
          <Span className="px-2 py-0.5 text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded">
            {currentLocale.toUpperCase()}
          </Span>
          <Icon
            name={IconName.ChevronDown}
            className={`w-4 h-4 text-gray-400 dark:text-gray-500 transition-transform ${showMenu ? 'rotate-180' : ''}`}
          />
        </Button>
        {showMenu && (
          <Div className="px-4 py-2 flex flex-wrap gap-2">
            {availableLocales.map((locale) => (
              <Button
                key={locale}
                onClick={() => handleLanguageChange(locale)}
                disabled={isChanging}
                className={`
                  px-3 py-1.5 text-sm font-medium rounded-md transition-colors
                  ${
                    currentLocale === locale
                      ? 'bg-blue-600 text-white'
                      : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'
                  }
                  ${isChanging ? 'opacity-50 cursor-not-allowed' : ''}
                `}
              >
                {getLocaleName(locale)}
              </Button>
            ))}
          </Div>
        )}
      </Div>
    );
  }

  // 독립 모드: 드롭다운 버튼과 메뉴
  return (
    <Div ref={menuRef} className={`relative ${className}`}>
      <Button
        onClick={() => setShowMenu(!showMenu)}
        className="p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors"
        aria-label={languageText}
      >
        <Icon
          name={IconName.Globe}
          className="w-5 h-5 text-gray-600 dark:text-gray-400"
        />
      </Button>

      {showMenu && (
        <Div className="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
          <Div className="py-2">
            {availableLocales.map((locale) => (
              <Button
                key={locale}
                onClick={() => handleLanguageChange(locale)}
                disabled={isChanging}
                className={`
                  w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors
                  ${currentLocale === locale ? 'bg-gray-50 dark:bg-gray-700' : ''}
                  ${isChanging ? 'opacity-50 cursor-not-allowed' : ''}
                `}
              >
                <Span className="text-gray-900 dark:text-white flex-1">
                  {getLocaleName(locale)}
                </Span>
                {currentLocale === locale && (
                  <Icon
                    name={IconName.Check}
                    className="w-4 h-4 text-blue-600 dark:text-blue-400"
                  />
                )}
              </Button>
            ))}
          </Div>
        </Div>
      )}
    </Div>
  );
};
