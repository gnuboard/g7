import React, { useState, useRef, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Img } from '../basic/Img';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { LanguageSelector } from './LanguageSelector';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:UserProfile')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:UserProfile]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:UserProfile]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:UserProfile]', ...args),
};

/**
 * 사용자 정보 인터페이스
 */
export interface User {
  id: number | string;
  uuid?: string;
  name: string;
  email: string;
  avatar?: string;
  role?: string;
}

/**
 * UserProfile Props
 */
export interface UserProfileProps {
  user: User;
  /** 프로필 설정 텍스트 */
  profileText?: string;
  /** 로그아웃 텍스트 */
  logoutText?: string;
  /** 언어 설정 텍스트 */
  languageText?: string;
  /** 사용 가능한 언어 목록 */
  availableLocales?: string[];
  onProfileClick?: () => void;
  onLogoutClick?: () => void;
  className?: string;
  /** 로그아웃 API 엔드포인트 (기본값: /api/admin/auth/logout) */
  logoutEndpoint?: string;
  /** 로그아웃 후 리다이렉션 경로 (기본값: /admin/login) */
  redirectPath?: string;
}

/**
 * UserProfile 컴포넌트
 *
 * 사용자 프로필 표시 및 드롭다운 메뉴 제공
 *
 * @example
 * ```tsx
 * <UserProfile
 *   user={{
 *     id: 1,
 *     name: '홍길동',
 *     email: 'hong@example.com',
 *     avatar: '/avatar.png',
 *     role: '관리자'
 *   }}
 *   onProfileClick={() => console.log('프로필 클릭')}
 *   onLogoutClick={() => console.log('로그아웃')}
 * />
 * ```
 */
export const UserProfile: React.FC<UserProfileProps> = ({
  user = { name: '', email: '' },
  profileText = 'Profile Settings',
  logoutText = 'Logout',
  languageText = 'Language',
  availableLocales = ['ko', 'en'],
  onProfileClick,
  onLogoutClick,
  className = '',
  logoutEndpoint = '/api/admin/auth/logout',
  redirectPath = '/admin/login',
}) => {
  const [showProfileMenu, setShowProfileMenu] = useState(false);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const profileRef = useRef<HTMLDivElement>(null);

  /**
   * 외부 클릭 감지
   */
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        profileRef.current &&
        !profileRef.current.contains(event.target as Node)
      ) {
        setShowProfileMenu(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

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
   * 로그아웃 처리
   */
  const handleLogout = async () => {
    if (isLoggingOut) return;

    try {
      setIsLoggingOut(true);
      setShowProfileMenu(false);

      // 커스텀 로그아웃 핸들러 실행
      if (onLogoutClick) {
        onLogoutClick();
        return;
      }

      // 토큰 가져오기
      const xsrfToken = getXsrfToken();
      const bearerToken = getBearerToken();

      // API 호출
      const response = await fetch(logoutEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(xsrfToken && { 'X-XSRF-TOKEN': xsrfToken }),
          ...(bearerToken && { Authorization: `Bearer ${bearerToken}` }),
        },
        credentials: 'include',
      });

      if (response.ok) {
        // 로그아웃 성공 - 로컬 스토리지에서 토큰 제거
        localStorage.removeItem('auth_token');
        // 로그인 페이지로 리다이렉션
        window.location.href = redirectPath;
      } else {
        logger.error('로그아웃 실패:', response.statusText);
        // 실패해도 토큰 제거 및 로그인 페이지로 이동 (토큰 만료 등의 경우)
        localStorage.removeItem('auth_token');
        window.location.href = redirectPath;
      }
    } catch (error) {
      logger.error('로그아웃 오류:', error);
      // 에러 발생 시에도 토큰 제거 및 로그인 페이지로 이동
      localStorage.removeItem('auth_token');
      window.location.href = redirectPath;
    } finally {
      setIsLoggingOut(false);
    }
  };

  return (
    <Div ref={profileRef} className={`relative ${className}`}>
      <Button
        onClick={() => setShowProfileMenu(!showProfileMenu)}
        className="flex items-center gap-2 px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors w-full"
      >
        {user.avatar ? (
          <Img
            src={user.avatar}
            alt={user.name}
            className="w-8 h-8 rounded-full object-cover"
          />
        ) : (
          <Div className="w-8 h-8 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center">
            <Icon name={IconName.User} className="w-5 h-5 text-gray-600 dark:text-gray-400" />
          </Div>
        )}
        <Div className="flex-1 text-left">
          <Div className="font-semibold text-gray-900 dark:text-white text-sm">{user.name}</Div>
          {user.role && (
            <Div className="text-gray-500 dark:text-gray-400 text-xs">{user.role}</Div>
          )}
        </Div>
        <Icon name={IconName.ChevronDown} className="w-4 h-4 text-gray-600 dark:text-gray-400" />
      </Button>

      {/* 프로필 드롭다운 */}
      {showProfileMenu && (
        <Div className="absolute bottom-full left-0 mb-2 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
          <Div
            className="px-4 py-3 border-b border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            onClick={() => {
              setShowProfileMenu(false);
              (window as any).G7Core?.dispatch({
                handler: 'navigate',
                params: { path: `/admin/users/${user.uuid}` },
              });
            }}
          >
            <Div className="font-semibold text-gray-900 dark:text-white">{user.name}</Div>
            <Div className="text-gray-500 dark:text-gray-400 text-sm">{user.email}</Div>
          </Div>
          {/* 언어 선택 섹션 */}
          {availableLocales.length > 1 && (
            <Div className="border-b border-gray-200 dark:border-gray-700">
              <LanguageSelector
                availableLocales={availableLocales}
                languageText={languageText}
                inline
                onLanguageChange={() => setShowProfileMenu(false)}
              />
            </Div>
          )}
          <Div className="py-2">
            <Button
              onClick={() => {
                setShowProfileMenu(false);
                if (onProfileClick) {
                  onProfileClick();
                } else {
                  // 기본 동작: 사용자 수정 페이지로 이동 (SPA 네비게이션)
                  (window as any).G7Core?.dispatch({
                    handler: 'navigate',
                    params: { path: `/admin/users/${user.uuid}/edit` },
                  });
                }
              }}
              className="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center justify-start gap-3 transition-colors"
            >
              <Icon name={IconName.User} className="w-5 h-5 text-gray-600 dark:text-gray-400" />
              <Span className="text-gray-900 dark:text-white">{profileText}</Span>
            </Button>
            <Button
              onClick={handleLogout}
              disabled={isLoggingOut}
              className="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center justify-start gap-3 text-red-600 dark:text-red-400 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <Icon name={IconName.ArrowRight} className="w-5 h-5" />
              <Span>{isLoggingOut ? 'Logging out...' : logoutText}</Span>
            </Button>
          </Div>
        </Div>
      )}
    </Div>
  );
};
