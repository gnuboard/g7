/**
 * MobileNav 컴포넌트
 *
 * 모바일 환경에서 표시되는 슬라이드 드로어 네비게이션입니다.
 * 햄버거 메뉴 클릭 시 좌측에서 슬라이드하며 나타납니다.
 */

import React, { useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { P } from '../basic/P';
import { Icon } from '../basic/Icon';
import { Img } from '../basic/Img';
import { Nav } from '../basic/Nav';
import { Ul } from '../basic/Ul';
import { Li } from '../basic/Li';

/**
 * G7Core 번역 함수
 */
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * G7Core navigate 헬퍼
 */
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
  id: number;
  name: string;
  avatar?: string;
}

interface MobileNavProps {
  /** 드로어 열림 상태 */
  isOpen: boolean;
  /** 닫기 콜백 */
  onClose: () => void;
  /** 사이트 로고 URL */
  logo?: string;
  /** 사이트 이름 */
  siteName?: string;
  /** 현재 로그인된 사용자 */
  user?: User | null;
  /** 게시판 목록 */
  boards?: Board[];
  /** 장바구니 아이템 수 */
  cartCount?: number;
}

/**
 * 모바일 네비게이션 드로어
 *
 * @example
 * ```tsx
 * const [isOpen, setIsOpen] = useState(false);
 *
 * <MobileNav
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   user={currentUser}
 *   boards={boards}
 * />
 * ```
 */
const MobileNav: React.FC<MobileNavProps> = ({
  isOpen,
  onClose,
  logo,
  siteName = '그누보드7',
  user,
  boards = [],
  cartCount = 0,
}) => {
  const drawerRef = useRef<HTMLDivElement>(null);

  // ESC 키로 닫기
  useEffect(() => {
    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };

    if (isOpen) {
      document.addEventListener('keydown', handleEscape);
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.removeEventListener('keydown', handleEscape);
      document.body.style.overflow = '';
    };
  }, [isOpen, onClose]);

  // 오버레이 클릭으로 닫기
  const handleOverlayClick = (e: React.MouseEvent) => {
    if (e.target === e.currentTarget) {
      onClose();
    }
  };

  if (!isOpen) return null;

  return (
    <Div
      className="fixed inset-0 z-50 lg:hidden"
      onClick={handleOverlayClick}
    >
      {/* 오버레이 */}
      <Div className="fixed inset-0 bg-black/50 transition-opacity" />

      {/* 드로어 */}
      <Div
        ref={drawerRef}
        className={`fixed inset-y-0 left-0 w-80 max-w-[85vw] bg-white dark:bg-gray-900 shadow-xl transform transition-transform duration-300 ${isOpen ? 'translate-x-0' : '-translate-x-full'}`}
      >
        {/* 헤더 */}
        <Div className="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-800">
          <Button onClick={() => { onClose(); navigate('/'); }} className="flex items-center gap-2 cursor-pointer">
            {logo ? (
              <Img src={logo} alt={siteName} className="h-8" />
            ) : (
              <Span className="text-xl font-bold text-gray-900 dark:text-white">{siteName}</Span>
            )}
          </Button>
          <Button
            onClick={onClose}
            className="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            aria-label="메뉴 닫기"
          >
            <Icon name="x" className="w-6 h-6" />
          </Button>
        </Div>

        {/* 사용자 정보 */}
        {user ? (
          <Div className="p-4 border-b border-gray-200 dark:border-gray-800">
            <Div className="flex items-center gap-3">
              {user.avatar ? (
                <Img src={user.avatar} alt={user.name} className="w-12 h-12 rounded-full" />
              ) : (
                <Div className="w-12 h-12 rounded-full bg-blue-500 flex items-center justify-center text-white text-lg font-medium">
                  {(user.name || 'U').charAt(0).toUpperCase()}
                </Div>
              )}
              <Div>
                <P className="font-medium text-gray-900 dark:text-white">{user.name}</P>
                <Button onClick={() => { onClose(); navigate('/mypage'); }} className="text-sm text-blue-600 dark:text-blue-400 hover:underline cursor-pointer">
                  {t('common.mypage')}
                </Button>
              </Div>
            </Div>
          </Div>
        ) : (
          <Div className="p-4 border-b border-gray-200 dark:border-gray-800">
            <Div className="flex gap-2">
              <Button
                onClick={() => { onClose(); navigate('/login'); }}
                className="flex-1 py-2 text-center text-sm font-medium text-white bg-gray-900 dark:bg-white dark:text-gray-900 rounded-lg cursor-pointer"
              >
                {t('auth.login')}
              </Button>
              <Button
                onClick={() => { onClose(); navigate('/register'); }}
                className="flex-1 py-2 text-center text-sm font-medium text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer"
              >
                {t('auth.register')}
              </Button>
            </Div>
          </Div>
        )}

        {/* 메뉴 */}
        <Nav className="p-4 overflow-y-auto max-h-[calc(100vh-200px)]">
          <Ul className="space-y-1">
            {/* 기본 메뉴 */}
            <Li>
              <Button
                onClick={() => { onClose(); navigate('/'); }}
                className="flex items-center gap-3 px-3 py-2 w-full text-left text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg cursor-pointer"
              >
                <Icon name="home" className="w-5 h-5" />
                {t('nav.home')}
              </Button>
            </Li>
            <Li>
              <Button
                onClick={() => { onClose(); navigate('/popular'); }}
                className="flex items-center gap-3 px-3 py-2 w-full text-left text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg cursor-pointer"
              >
                <Span className="text-orange-500">🔥</Span>
                {t('nav.popular')}
              </Button>
            </Li>
            <Li>
              <Button
                onClick={() => { onClose(); navigate('/shop'); }}
                className="flex items-center gap-3 px-3 py-2 w-full text-left text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg cursor-pointer"
              >
                <Span>🛒</Span>
                {t('nav.shop')}
              </Button>
            </Li>
            <Li>
              <Button
                onClick={() => { onClose(); navigate('/cart'); }}
                className="flex items-center gap-3 px-3 py-2 w-full text-left text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg cursor-pointer"
              >
                <Icon name="shopping-cart" className="w-5 h-5" />
                {t('nav.cart')}
                {cartCount > 0 && (
                  <Span className="ml-auto px-2 py-0.5 text-xs bg-blue-500 text-white rounded-full">
                    {cartCount}
                  </Span>
                )}
              </Button>
            </Li>

            {/* 구분선 */}
            <Li className="my-4 border-t border-gray-200 dark:border-gray-800" />

            {/* 게시판 메뉴 */}
            <Li className="px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
              {t('nav.boards')}
            </Li>
            {boards.map((board) => (
              <Li key={board.id}>
                <Button
                  onClick={() => { onClose(); navigate(`/board/${board.slug}`); }}
                  className="flex items-center gap-3 px-3 py-2 w-full text-left text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg cursor-pointer"
                >
                  {board.icon && <Span>{board.icon}</Span>}
                  {board.name}
                </Button>
              </Li>
            ))}
          </Ul>
        </Nav>

        {/* 하단 링크 */}
        <Div className="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
          <Div className="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
            <Button onClick={() => { onClose(); navigate('/about'); }} className="hover:text-gray-700 dark:hover:text-gray-200 cursor-pointer">
              {t('footer.about')}
            </Button>
            <Button onClick={() => { onClose(); navigate('/terms'); }} className="hover:text-gray-700 dark:hover:text-gray-200 cursor-pointer">
              {t('footer.terms')}
            </Button>
            <Button onClick={() => { onClose(); navigate('/privacy'); }} className="hover:text-gray-700 dark:hover:text-gray-200 cursor-pointer">
              {t('footer.privacy')}
            </Button>
          </Div>
        </Div>
      </Div>
    </Div>
  );
};

export default MobileNav;
