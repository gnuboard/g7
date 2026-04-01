/**
 * Header 컴포넌트
 *
 * 사이트 상단 헤더 컴포넌트입니다.
 * 로고, 검색바, 네비게이션, 사용자 메뉴, 장바구니, 알림을 포함합니다.
 *
 * @see 화면 구성:
 * ┌─────────────────────────────────────────────────────────────────┐
 * │ [Logo] [검색바................] [🔔] [🛒(3)] [👤 닉네임 ▼]     │
 * ├─────────────────────────────────────────────────────────────────┤
 * │ [홈] [🔥인기] [🛒쇼핑] [자유게시판] [질문답변] [갤러리] [더보기▼] │
 * └─────────────────────────────────────────────────────────────────┘
 */

import React, { useState, useRef, useEffect } from 'react';

// 기본 컴포넌트 import
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Span } from '../basic/Span';
import { Img } from '../basic/Img';
import { Icon } from '../basic/Icon';
import { Form } from '../basic/Form';
import { Nav } from '../basic/Nav';
import { Header as HeaderBasic } from '../basic/Header';
import { Hr } from '../basic/Hr';
import { A } from '../basic/A';

// ThemeToggle 컴포넌트 import
import { ThemeToggle } from './ThemeToggle';

// Avatar 컴포넌트 import
import { Avatar } from './Avatar';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// G7Core.dispatch() navigate 헬퍼
const navigate = (path: string) => {
  (window as any).G7Core?.dispatch?.({
    handler: 'navigate',
    params: { path },
  });
};

interface Board {
  id: number;
  name: string;
  slug: string;
  icon?: string;
}

interface User {
  uuid: string;
  name: string;
  avatar?: string;
  is_admin?: boolean;
}

interface HeaderProps {
  /** 사이트 로고 URL */
  logo?: string;
  /** 사이트 이름 */
  siteName?: string;
  /** 현재 로그인된 사용자 */
  user?: User | null;
  /** 장바구니 아이템 수 */
  cartCount?: number;
  /** 읽지 않은 알림 수 */
  notificationCount?: number;
  /** 게시판 목록 */
  boards?: Board[];
  /** 탭에 표시할 최대 게시판 수 */
  maxVisibleBoards?: number;
  /** 모바일 메뉴 열기 콜백 */
  onMobileMenuOpen?: () => void;
  /** 사용 가능한 언어 목록 */
  availableLocales?: string[];
  /** 현재 언어 */
  currentLocale?: string;
  /** 쇼핑몰 기본 경로 (예: "/shop", "/") */
  shopBase?: string;
  /** 추가 CSS 클래스 */
  className?: string;
}

/**
 * 사이트 헤더 컴포넌트
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "Header",
 *   "props": {
 *     "logo": "{{_global.settings.site_logo}}",
 *     "siteName": "{{_global.settings.site_name}}",
 *     "user": "{{_global.currentUser}}",
 *     "cartCount": "{{_global.cartCount}}",
 *     "notificationCount": "{{_global.notificationCount}}",
 *     "boards": "{{boards.data}}",
 *     "maxVisibleBoards": 5
 *   }
 * }
 * ```
 */
const Header: React.FC<HeaderProps> = ({
  logo,
  siteName = '그누보드7',
  user,
  cartCount = 0,
  notificationCount = 0,
  boards = [],
  maxVisibleBoards = 5,
  onMobileMenuOpen,
  availableLocales = [],
  currentLocale = 'ko',
  shopBase = '/shop',
  className = '',
}) => {
  const [searchQuery, setSearchQuery] = useState('');
  const [showUserMenu, setShowUserMenu] = useState(false);
  const [showMoreBoards, setShowMoreBoards] = useState(false);
  const [dropdownPosition, setDropdownPosition] = useState<{ top: number; left: number }>({ top: 0, left: 0 });
  const [currentPath, setCurrentPath] = useState(window.location.pathname);
  const userMenuRef = useRef<HTMLDivElement>(null);
  const moreButtonRef = useRef<HTMLDivElement>(null);

  // 경로 변경 감지 (SPA 네비게이션 대응)
  useEffect(() => {
    const handlePopState = () => setCurrentPath(window.location.pathname);
    window.addEventListener('popstate', handlePopState);

    // G7Core의 navigate 이벤트 감지
    const originalPushState = history.pushState;
    history.pushState = function(...args) {
      originalPushState.apply(this, args);
      setCurrentPath(window.location.pathname);
    };

    return () => {
      window.removeEventListener('popstate', handlePopState);
      history.pushState = originalPushState;
    };
  }, []);

  // 경로 매칭 헬퍼 함수
  const isActiveRoute = (path: string, exact = false): boolean => {
    if (exact) {
      return currentPath === path;
    }
    return currentPath === path || currentPath.startsWith(path + '/');
  };

  // 쇼핑 관련 경로 체크 (products, cart, checkout, category 등)
  const isShopActive = (): boolean => {
    const base = shopBase === '/' ? '' : shopBase;
    return currentPath.startsWith(`${base}/products`) ||
           currentPath.startsWith(`${base}/cart`) ||
           currentPath.startsWith(`${base}/checkout`) ||
           currentPath.startsWith(`${base}/category`);
  };

  // 네비게이션 버튼 스타일
  const getNavButtonClass = (isActive: boolean): string => {
    const baseClass = 'px-3 py-2 text-sm font-medium whitespace-nowrap cursor-pointer rounded-lg transition-colors';
    if (isActive) {
      return `${baseClass} bg-gray-900 text-white dark:bg-white dark:text-gray-900`;
    }
    return `${baseClass} text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800`;
  };

  // 메뉴 외부 클릭 시 닫기
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (userMenuRef.current && !userMenuRef.current.contains(event.target as Node)) {
        setShowUserMenu(false);
      }
      if (moreButtonRef.current && !moreButtonRef.current.contains(event.target as Node)) {
        setShowMoreBoards(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const visibleBoards = boards.slice(0, maxVisibleBoards);
  const hiddenBoards = boards.slice(maxVisibleBoards);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (searchQuery.trim()) {
      navigate(`/search?q=${encodeURIComponent(searchQuery)}`);
    }
  };

  const handleLogout = async () => {
    // G7Core.dispatch를 사용하여 logout 핸들러 호출
    // AuthManager가 토큰 삭제, 상태 초기화, 리다이렉트를 처리
    (window as any).G7Core?.dispatch?.({
      handler: 'logout',
    });
  };

  // 언어 변경 핸들러
  const handleLocaleChange = (locale: string) => {
    (window as any).G7Core?.dispatch?.({
      handler: 'setLocale',
      target: locale,
    });
    setShowUserMenu(false);
  };

  return (
    <HeaderBasic className={`sticky top-0 z-50 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 ${className}`}>
      {/* 상단 바 */}
      <Div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <Div className="flex items-center justify-between h-16">
          {/* 로고 */}
          <Button onClick={() => navigate('/')} className="flex items-center gap-2 flex-shrink-0 cursor-pointer">
            {logo ? (
              <Img src={logo} alt={siteName} className="h-8" />
            ) : (
              <Span className="text-xl font-bold text-gray-900 dark:text-white">{siteName}</Span>
            )}
          </Button>

          {/* 검색바 (데스크톱) */}
          <Form onSubmit={handleSearch} className="hidden md:flex flex-1 max-w-lg mx-8">
            <Div className="relative w-full">
              <Input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder={t('common.search_placeholder')}
                className="w-full px-4 py-2 pl-10 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
              <Icon
                name="search"
                className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500"
              />
            </Div>
          </Form>

          {/* 우측 액션 버튼들 */}
          <Div className="flex items-center gap-2">
            {/* 다크모드 전환 */}
            <ThemeToggle
              autoText={t('common.theme.auto')}
              lightText={t('common.theme.light')}
              darkText={t('common.theme.dark')}
            />

            {/* 알림 */}
            <Button onClick={() => navigate('/notifications')} className="relative p-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white cursor-pointer">
              <Icon name="bell" className="w-5 h-5" />
              {notificationCount > 0 && (
                <Span className="absolute -top-1 -right-1 w-5 h-5 flex items-center justify-center text-xs bg-red-500 text-white rounded-full">
                  {notificationCount > 99 ? '99+' : notificationCount}
                </Span>
              )}
            </Button>

            {/* 장바구니 */}
            <Button onClick={() => navigate(`${shopBase}/cart`)} className="relative p-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white cursor-pointer">
              <Icon name="shopping-cart" className="w-5 h-5" />
              {cartCount > 0 && (
                <Span className="absolute -top-1 -right-1 w-5 h-5 flex items-center justify-center text-xs bg-blue-500 text-white rounded-full">
                  {cartCount > 99 ? '99+' : cartCount}
                </Span>
              )}
            </Button>

            {/* 사용자 메뉴 */}
            {user?.uuid ? (
              <Div ref={userMenuRef} className="relative">
                <Button
                  onClick={() => setShowUserMenu(!showUserMenu)}
                  className="flex items-center gap-2 p-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                >
                  <Avatar
                    avatar={user.avatar}
                    name={user.name}
                    size="sm"
                  />
                  <Span className="hidden sm:inline text-sm font-medium">{user.name}</Span>
                  <Icon name="chevron-down" className="w-4 h-4" />
                </Button>

                {/* 드롭다운 메뉴 */}
                {showUserMenu && (
                  <Div className="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 z-50">
                    {/* 사용자 정보 헤더 */}
                    <Div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                      <Div className="flex items-center gap-3">
                        <Avatar
                          avatar={user.avatar}
                          name={user.name}
                          size="md"
                        />
                        <Div>
                          <Div className="text-sm font-medium text-gray-900 dark:text-white">{user.name}</Div>
                          <Div className="text-xs text-gray-500 dark:text-gray-400">{t('common.member')}</Div>
                        </Div>
                      </Div>
                    </Div>

                    {/* 메뉴 항목 */}
                    <Div className="py-1">
                      {/* 관리자 메뉴 (is_admin일 때만 표시) - 하이퍼링크로 전체 페이지 새로고침 */}
                      {user.is_admin && (
                        <>
                          <A
                            href="/admin"
                            className="block w-full text-left px-4 py-2 text-sm text-blue-600 dark:text-blue-400 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer font-medium"
                          >
                            <Icon name="settings" className="inline w-4 h-4 mr-2" />
                            {t('common.admin_menu')}
                          </A>
                          <Hr className="my-1 border-gray-200 dark:border-gray-700" />
                        </>
                      )}
                      <Button onClick={() => { navigate('/mypage'); setShowUserMenu(false); }} className="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                        <Icon name="user" className="inline w-4 h-4 mr-2" />
                        {t('common.mypage')}
                      </Button>
                      <Button onClick={() => { navigate('/mypage/orders'); setShowUserMenu(false); }} className="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                        <Icon name="shopping-bag" className="inline w-4 h-4 mr-2" />
                        {t('mypage.tabs.orders')}
                      </Button>
                      <Button onClick={() => { navigate('/mypage/wishlist'); setShowUserMenu(false); }} className="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
                        <Icon name="heart" className="inline w-4 h-4 mr-2" />
                        {t('mypage.tabs.wishlist')}
                      </Button>
                    </Div>

                    {/* 언어 선택 (availableLocales가 있을 때만 표시) */}
                    {availableLocales && availableLocales.length > 1 && (
                      <>
                        <Hr className="my-1 border-gray-200 dark:border-gray-700" />
                        <Div className="py-1">
                          <Div className="px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                            {t('common.language')}
                          </Div>
                          {availableLocales.map((locale) => (
                            <Button
                              key={locale}
                              onClick={() => handleLocaleChange(locale)}
                              className={`block w-full text-left px-4 py-2 text-sm cursor-pointer ${
                                locale === currentLocale
                                  ? 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20'
                                  : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
                              }`}
                            >
                              <Icon name="globe" className="inline w-4 h-4 mr-2" />
                              {locale === 'ko' ? '한국어' : locale === 'en' ? 'English' : locale.toUpperCase()}
                            </Button>
                          ))}
                        </Div>
                      </>
                    )}

                    <Hr className="my-1 border-gray-200 dark:border-gray-700" />
                    <Button
                      onClick={handleLogout}
                      className="block w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer"
                    >
                      <Icon name="log-out" className="inline w-4 h-4 mr-2" />
                      {t('auth.logout')}
                    </Button>
                  </Div>
                )}
              </Div>
            ) : (
              <Div className="flex items-center gap-2">
                <Button
                  onClick={() => navigate('/login')}
                  className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white cursor-pointer"
                >
                  {t('auth.login')}
                </Button>
                <Button
                  onClick={() => navigate('/register')}
                  className="px-4 py-2 text-sm font-medium text-white bg-emerald-500 rounded-lg hover:bg-emerald-600 cursor-pointer"
                >
                  {t('auth.register_link')}
                </Button>
              </Div>
            )}

            {/* 모바일 햄버거 메뉴 */}
            <Button
              onClick={onMobileMenuOpen}
              className="md:hidden p-2 text-gray-600 dark:text-gray-400"
            >
              <Icon name="menu" className="w-6 h-6" />
            </Button>
          </Div>
        </Div>
      </Div>

      {/* 탭 네비게이션 */}
      <Nav className="hidden md:block border-t border-gray-200 dark:border-gray-800">
        <Div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <Div className="flex items-center gap-1 h-12 overflow-x-auto">
            <Button onClick={() => navigate('/')} className={getNavButtonClass(isActiveRoute('/', true))}>
              {t('nav.home')}
            </Button>
            <Button onClick={() => navigate('/boards/popular')} className={`flex items-center gap-1 ${getNavButtonClass(isActiveRoute('/boards/popular'))}`}>
              <Span className="text-orange-500">🔥</Span>
              {t('nav.popular')}
            </Button>
            <Button onClick={() => navigate(`${shopBase === '/' ? '' : shopBase}/products`)} className={`flex items-center gap-1 ${getNavButtonClass(isShopActive())}`}>
              <Span>🛒</Span>
              {t('nav.shop')}
            </Button>

            {/* 게시판 링크 */}
            {visibleBoards.map((board) => (
              <Button
                key={board.id}
                onClick={() => navigate(`/board/${board.slug}`)}
                className={getNavButtonClass(isActiveRoute(`/board/${board.slug}`))}
              >
                {board.icon && <Span className="mr-1">{board.icon}</Span>}
                {board.name}
              </Button>
            ))}

            {/* 더보기 드롭다운 */}
            {hiddenBoards.length > 0 && (
              <Div ref={moreButtonRef} className="relative">
                <Button
                  onClick={() => {
                    if (!showMoreBoards && moreButtonRef.current) {
                      const rect = moreButtonRef.current.getBoundingClientRect();
                      setDropdownPosition({
                        top: rect.bottom + 4,
                        left: rect.left,
                      });
                    }
                    setShowMoreBoards(!showMoreBoards);
                  }}
                  className="flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white"
                >
                  {t('nav.more')}
                  <Icon name="chevron-down" className="w-4 h-4" />
                </Button>
                {showMoreBoards && (
                  <Div
                    className="fixed w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 z-50"
                    style={{ top: `${dropdownPosition.top}px`, left: `${dropdownPosition.left}px` }}
                  >
                    {hiddenBoards.map((board) => {
                      const isActive = isActiveRoute(`/board/${board.slug}`);
                      return (
                        <Button
                          key={board.id}
                          onClick={() => {
                            navigate(`/board/${board.slug}`);
                            setShowMoreBoards(false);
                          }}
                          className={`block w-full text-left px-4 py-2 text-sm cursor-pointer ${
                            isActive
                              ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'
                              : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
                          }`}
                        >
                          {board.icon && <Span className="mr-1">{board.icon}</Span>}
                          {board.name}
                        </Button>
                      );
                    })}
                  </Div>
                )}
              </Div>
            )}
          </Div>
        </Div>
      </Nav>
    </HeaderBasic>
  );
};

export default Header;
