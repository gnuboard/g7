import React, { useState, useRef, useEffect, useId } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';
import { isSameOriginRequest } from '../../support/sameOriginRequest';

// G7Core.t() 번역 함수 참조 — 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다.
// 레이아웃이 문구를 넘기지 않으면 기본 문구가 언어와 무관하게 영어였다(7.1.0 O-C3b-4).
const tOr = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = (window as any).G7Core?.t?.(key, params);
  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

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
  /** 언어 변경 API 엔드포인트 (G7Core.api 로 요청 — 토큰은 코어가 동일 출처 대상에만 싣는다) */
  apiEndpoint?: string;
  /** 언어 변경 후 콜백 */
  onLanguageChange?: (locale: string) => void;
  /** 추가 CSS 클래스 */
  className?: string;
  /** 인라인 모드 (드롭다운 메뉴 내에서 사용) */
  inline?: boolean;
  /**
   * 독립 모드 버튼에 현재 선택 언어 코드를 globe 아이콘 옆에 표시한다.
   * 유저 템플릿 헤더 언어 버튼과 동일한 표기(아이콘 + 로케일 코드)를 위해 사용.
   */
  showCode?: boolean;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
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
  languageText: languageTextProp,
  apiEndpoint = '/api/admin/users/me/language',
  onLanguageChange,
  className = '',
  inline = false,
  showCode = false,
  id,
  editorAttrs,
}) => {
  // 기본 문구는 화면 언어를 따른다 — 레이아웃이 넘긴 문구가 우선이다(7.1.0 O-C3b-4)
  const languageText = languageTextProp ?? tOr('admin.language', 'Language');
  const [showMenu, setShowMenu] = useState(false);
  const [isChanging, setIsChanging] = useState(false);
  const [currentLocale, setCurrentLocale] = useState<string>(() => {
    return localStorage.getItem('g7_locale') || 'ko';
  });
  const menuRef = useRef<HTMLDivElement>(null);
  // 독립 모드 목록은 listbox 패턴이다(7.1.0 O-PF-1) — 단추는 aria-haspopup="listbox"·aria-controls 로 목록을 가리키고,
  // 열면 현재 언어 항목에 초점을 두며 ↓/↑/Home/End 로 옮기고 Enter 로 고른다. Esc 는 닫고 단추로 초점을 돌린다.
  const listboxId = `${useId()}-language-listbox`;
  const triggerRef = useRef<HTMLButtonElement>(null);
  const optionRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const pendingFocusRef = useRef<number | null>(null);

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
   * 목록이 열린 뒤 맡겨 둔 항목으로 초점을 옮긴다
   */
  useEffect(() => {
    if (!showMenu || pendingFocusRef.current === null) return;
    optionRefs.current[pendingFocusRef.current]?.focus();
    pendingFocusRef.current = null;
  }, [showMenu]);

  /**
   * 목록을 연다 — 초점은 현재 언어 항목(없으면 where 쪽 끝 항목)에 둔다
   *
   * @param where 현재 언어가 목록에 없을 때 초점을 둘 쪽
   */
  const openMenu = (where: 'first' | 'last' = 'first') => {
    const selected = availableLocales.indexOf(currentLocale);
    const index = selected >= 0 ? selected : (where === 'last' ? availableLocales.length - 1 : 0);
    if (showMenu) {
      optionRefs.current[index]?.focus();
      return;
    }
    pendingFocusRef.current = index;
    setShowMenu(true);
  };

  /**
   * 목록을 닫는다
   *
   * @param returnFocus 단추로 초점을 돌릴지
   */
  const closeMenu = (returnFocus: boolean) => {
    setShowMenu(false);
    if (returnFocus) triggerRef.current?.focus();
  };

  /**
   * 단추 키보드 — ↓/↑ 는 목록을 열고 항목으로 들어간다(Enter·Space 는 단추 기본 동작인 클릭으로 연다)
   */
  const handleTriggerKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      openMenu(event.key === 'ArrowUp' ? 'last' : 'first');
    }
  };

  /**
   * 목록 키보드 — 항목 사이 이동, Esc 로 닫기, Tab 으로 벗어나면 닫기
   */
  const handleListboxKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const count = availableLocales.length;
    if (count === 0) return;
    const current = optionRefs.current.findIndex((el) => el === document.activeElement);
    let next: number | null = null;
    switch (event.key) {
      case 'ArrowDown':
        next = current < 0 ? 0 : (current + 1) % count;
        break;
      case 'ArrowUp':
        next = current < 0 ? count - 1 : (current - 1 + count) % count;
        break;
      case 'Home':
        next = 0;
        break;
      case 'End':
        next = count - 1;
        break;
      case 'Escape':
        event.preventDefault();
        event.stopPropagation();
        closeMenu(true);
        return;
      case 'Tab':
        closeMenu(false);
        return;
      default:
        return;
    }
    event.preventDefault();
    optionRefs.current[next]?.focus();
  };

  /**
   * 언어 변경 처리
   *
   * 저장 요청은 코어 `G7Core.api` 로 보낸다 — Bearer·XSRF 첨부, 교차 출처 대상의 토큰 제외,
   * 401 처리를 코어가 맡는다. 화면 언어 전환은 공개 API `G7Core.locale.change()` 를 쓴다.
   */
  const handleLanguageChange = async (locale: string) => {
    if (isChanging) return;
    // 현재 언어를 다시 고르면 화면은 그대로 두고 목록만 닫는다. 회원 언어는 맞춘다 — 다른 기기에서 바뀐 회원 언어가
    // 이 화면 언어와 갈려 있을 수 있어, 코어 언어 전환이 같은 언어일 때 로그인 회원의 언어만 저장한다(7.1.0 결정)
    if (locale === currentLocale) {
      void (window as any).G7Core?.locale?.change?.(locale);
      closeMenu(true);
      return;
    }

    const G7Core = (window as any).G7Core;

    try {
      setIsChanging(true);

      // 엔드포인트는 레이아웃 prop 이라 표현식으로 외부 주소를 만들 수 있다. 토큰은 코어가 동일 출처
      // 대상에만 싣지만(KVE-2026-2350), 교차 출처 주소가 들어온 사실은 남긴다
      if (!isSameOriginRequest(apiEndpoint)) {
        logger.warn('교차 출처 언어 저장 주소라 인증 정보를 싣지 않습니다.', apiEndpoint);
      }

      // API 호출로 DB에 저장 (실패해도 UI는 변경)
      try {
        if (typeof G7Core?.api?.patch !== 'function') {
          throw new Error('G7Core.api is not available');
        }
        await G7Core.api.patch(apiEndpoint, { language: locale });
      } catch (apiError: any) {
        if (apiError?.response) {
          logger.warn('언어 설정 저장 실패 (UI는 변경됨):', apiError.response.statusText ?? apiError.response.status);
        } else {
          logger.warn('언어 설정 API 호출 실패 (UI는 변경됨):', apiError);
        }
      }

      // UI 상태 업데이트
      setCurrentLocale(locale);
      setShowMenu(false);

      // 콜백 실행
      onLanguageChange?.(locale);

      // 공개 API 로 화면 언어 전환 (새로고침 없이 리렌더링). 회원 언어는 위에서 이미 저장했으므로
      // 코어가 다시 저장하지 않게 한다(저장 요청 1회 — 7.1.0 O-C3-3)
      if (typeof G7Core?.locale?.change === 'function') {
        await G7Core.locale.change(locale, { persist: false });
      } else {
        logger.warn('G7Core.locale not found, reloading page');
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
      <Div className={`${className}`} id={id} {...editorAttrs}>
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
    <Div ref={menuRef} className={`relative ${className}`} id={id} {...editorAttrs}>
      <Button
        ref={triggerRef}
        onClick={() => (showMenu ? closeMenu(false) : openMenu())}
        onKeyDown={handleTriggerKeyDown}
        className={
          showCode
            ? 'flex items-center gap-1.5 px-2.5 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors cursor-pointer'
            : 'p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors'
        }
        aria-haspopup="listbox"
        aria-expanded={showMenu}
        aria-controls={showMenu ? listboxId : undefined}
        aria-label={languageText}
      >
        <Icon
          name={IconName.Globe}
          className={showCode ? 'w-4 h-4' : 'w-5 h-5 text-gray-600 dark:text-gray-400'}
        />
        {showCode && (
          <Span className="font-medium uppercase">{currentLocale}</Span>
        )}
        {showCode && (
          <Icon name={IconName.ChevronDown} className="w-3 h-3" />
        )}
      </Button>

      {showMenu && (
        <Div className="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
          <Div
            id={listboxId}
            role="listbox"
            aria-label={languageText}
            className="py-2"
            onKeyDown={handleListboxKeyDown}
          >
            {availableLocales.map((locale, index) => (
              <Button
                key={locale}
                ref={(el: HTMLButtonElement | null) => { optionRefs.current[index] = el; }}
                type="button"
                role="option"
                aria-selected={currentLocale === locale}
                tabIndex={currentLocale === locale ? 0 : -1}
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
