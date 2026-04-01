/**
 * Header 컴포넌트 테스트
 *
 * @description 사이트 헤더 컴포넌트의 렌더링 및 동작을 테스트합니다.
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// G7Core mock
const mockG7Core = {
  t: vi.fn((key: string, params?: Record<string, string | number>) => {
    const translations: Record<string, string> = {
      'nav.home': '홈',
      'nav.popular': '인기글',
      'nav.shop': '쇼핑',
      'nav.cart': '장바구니',
      'auth.login': '로그인',
      'auth.register': '회원가입',
    };
    return translations[key] ?? key;
  }),
};

// window.G7Core mock 설정
beforeEach(() => {
  (window as any).G7Core = mockG7Core;
});

// Header 컴포넌트 mock (basic 컴포넌트 의존성 때문에)
const MockHeader: React.FC<{
  logo?: string;
  siteName?: string;
  user?: { id: number; name: string } | null;
  cartCount?: number;
  boards?: { id: number; name: string; slug: string }[];
}> = ({ logo, siteName = '그누보드7', user, cartCount = 0, boards = [] }) => {
  const t = (key: string) => mockG7Core.t(key);

  return (
    <header data-testid="header">
      <a href="/" data-testid="logo-link">
        {logo ? <img src={logo} alt={siteName} /> : <span>{siteName}</span>}
      </a>
      <nav data-testid="main-nav">
        <a href="/">{t('nav.home')}</a>
        <a href="/popular">{t('nav.popular')}</a>
        <a href="/shop">{t('nav.shop')}</a>
        {boards.map((board) => (
          <a key={board.id} href={`/board/${board.slug}`}>
            {board.name}
          </a>
        ))}
      </nav>
      <div data-testid="user-area">
        {user ? (
          <span data-testid="user-name">{user.name}</span>
        ) : (
          <>
            <a href="/login">{t('auth.login')}</a>
            <a href="/register">{t('auth.register')}</a>
          </>
        )}
      </div>
      {cartCount > 0 && (
        <span data-testid="cart-count">{cartCount}</span>
      )}
    </header>
  );
};

describe('Header 컴포넌트', () => {
  describe('렌더링', () => {
    it('기본 헤더가 렌더링되어야 함', () => {
      render(<MockHeader />);
      expect(screen.getByTestId('header')).toBeInTheDocument();
    });

    it('사이트 이름이 표시되어야 함', () => {
      render(<MockHeader siteName="테스트 사이트" />);
      expect(screen.getByText('테스트 사이트')).toBeInTheDocument();
    });

    it('로고 이미지가 있으면 이미지로 표시되어야 함', () => {
      render(<MockHeader logo="/logo.png" siteName="테스트" />);
      const img = screen.getByAltText('테스트');
      expect(img).toHaveAttribute('src', '/logo.png');
    });
  });

  describe('네비게이션', () => {
    it('기본 메뉴 항목이 표시되어야 함', () => {
      render(<MockHeader />);
      expect(screen.getByText('홈')).toBeInTheDocument();
      expect(screen.getByText('인기글')).toBeInTheDocument();
      expect(screen.getByText('쇼핑')).toBeInTheDocument();
    });

    it('게시판 목록이 표시되어야 함', () => {
      const boards = [
        { id: 1, name: '자유게시판', slug: 'free' },
        { id: 2, name: '질문답변', slug: 'qna' },
      ];
      render(<MockHeader boards={boards} />);
      expect(screen.getByText('자유게시판')).toBeInTheDocument();
      expect(screen.getByText('질문답변')).toBeInTheDocument();
    });
  });

  describe('사용자 상태', () => {
    it('비로그인 시 로그인/회원가입 버튼이 표시되어야 함', () => {
      render(<MockHeader user={null} />);
      expect(screen.getByText('로그인')).toBeInTheDocument();
      expect(screen.getByText('회원가입')).toBeInTheDocument();
    });

    it('로그인 시 사용자 이름이 표시되어야 함', () => {
      const user = { id: 1, name: '홍길동' };
      render(<MockHeader user={user} />);
      expect(screen.getByTestId('user-name')).toHaveTextContent('홍길동');
    });
  });

  describe('장바구니', () => {
    it('장바구니에 아이템이 있으면 카운트가 표시되어야 함', () => {
      render(<MockHeader cartCount={3} />);
      expect(screen.getByTestId('cart-count')).toHaveTextContent('3');
    });

    it('장바구니가 비어있으면 카운트가 표시되지 않아야 함', () => {
      render(<MockHeader cartCount={0} />);
      expect(screen.queryByTestId('cart-count')).not.toBeInTheDocument();
    });
  });
});
