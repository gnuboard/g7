/**
 * @file AdminSidebar.test.tsx
 * @description AdminSidebar 컴포넌트 테스트
 *
 * 테스트 대상:
 * - URL 있는 부모 메뉴: 텍스트 클릭 → 네비게이션, chevron 클릭 → 토글
 * - URL 없는 부모 메뉴: 전체 클릭 → 토글 (기존 동작)
 * - URL 있는 리프 메뉴: 클릭 → 네비게이션
 * - collapsed 모드 동작
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { AdminSidebar, MenuItem } from '../../src/components/composite/AdminSidebar';

// G7Core mock
const mockDispatch = vi.fn();
const mockNavigationOnComplete = vi.fn();

beforeEach(() => {
  mockNavigationOnComplete.mockImplementation(() => {});
  (window as any).G7Core = {
    dispatch: mockDispatch,
    locale: { current: () => 'ko' },
    state: { subscribe: () => () => {} },
    t: (key: string) => key,
    navigation: { onComplete: mockNavigationOnComplete },
    style: { mergeClasses: (...args: string[]) => args.filter(Boolean).join(' ') },
  };
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
});

const createMenu = (): MenuItem[] => [
  {
    id: 1,
    name: { ko: '대시보드', en: 'Dashboard' },
    slug: 'dashboard',
    url: '/admin/dashboard',
    icon: 'fas fa-tachometer-alt',
  },
  {
    id: 2,
    name: { ko: '이커머스', en: 'Ecommerce' },
    slug: 'ecommerce',
    url: '/admin/ecommerce',
    icon: 'fas fa-shopping-cart',
    children: [
      {
        id: 21,
        name: { ko: '상품 관리', en: 'Products' },
        slug: 'products',
        url: '/admin/ecommerce/products',
        icon: 'fas fa-box',
      },
      {
        id: 22,
        name: { ko: '주문 관리', en: 'Orders' },
        slug: 'orders',
        url: '/admin/ecommerce/orders',
        icon: 'fas fa-list',
      },
    ],
  },
  {
    id: 3,
    name: { ko: '콘텐츠', en: 'Content' },
    slug: 'content',
    icon: 'fas fa-file-alt',
    children: [
      {
        id: 31,
        name: { ko: '게시판', en: 'Boards' },
        slug: 'boards',
        url: '/admin/boards',
      },
    ],
  },
];

describe('AdminSidebar', () => {
  describe('URL 있는 부모 메뉴 (클릭/토글 분리)', () => {
    it('텍스트/아이콘 영역 클릭 시 네비게이션이 실행된다', () => {
      render(<AdminSidebar menu={createMenu()} currentLocale="ko" />);

      // "이커머스" 텍스트를 포함하는 버튼 클릭
      const ecommerceLabel = screen.getByText('이커머스');
      const navButton = ecommerceLabel.closest('button');
      expect(navButton).toBeTruthy();

      fireEvent.click(navButton!);

      expect(mockDispatch).toHaveBeenCalledWith(
        expect.objectContaining({
          handler: 'navigate',
          params: { path: '/admin/ecommerce' },
        })
      );
    });

    it('chevron 버튼 클릭 시 토글만 발생하고 네비게이션은 발생하지 않는다', () => {
      render(<AdminSidebar menu={createMenu()} currentLocale="ko" />);

      // chevron 버튼 찾기 (aria-label로 식별)
      const ecommerceChevron = screen.getByLabelText('common.expand');
      expect(ecommerceChevron).toBeTruthy();

      mockDispatch.mockClear();
      fireEvent.click(ecommerceChevron);

      // navigate가 호출되지 않아야 함
      expect(mockDispatch).not.toHaveBeenCalledWith(
        expect.objectContaining({ handler: 'navigate' })
      );

      // 자식 메뉴가 표시되어야 함
      expect(screen.getByText('상품 관리')).toBeTruthy();
    });
  });

  describe('URL 없는 부모 메뉴 (전체 클릭 = 토글)', () => {
    it('전체 영역 클릭 시 하위 메뉴가 토글된다', () => {
      render(<AdminSidebar menu={createMenu()} currentLocale="ko" />);

      // "콘텐츠" 메뉴 클릭 (URL 없음 → 전체가 토글 버튼)
      const contentButton = screen.getByText('콘텐츠').closest('button');
      expect(contentButton).toBeTruthy();

      fireEvent.click(contentButton!);

      // 자식 메뉴가 표시되어야 함
      expect(screen.getByText('게시판')).toBeTruthy();

      // navigate는 호출되지 않아야 함
      expect(mockDispatch).not.toHaveBeenCalledWith(
        expect.objectContaining({ handler: 'navigate' })
      );
    });
  });

  describe('리프 메뉴 (자식 없음 + URL 있음)', () => {
    it('클릭 시 네비게이션이 실행된다', () => {
      render(<AdminSidebar menu={createMenu()} currentLocale="ko" />);

      // 대시보드 클릭
      const dashboardButton = screen.getByText('대시보드').closest('button');
      expect(dashboardButton).toBeTruthy();

      fireEvent.click(dashboardButton!);

      expect(mockDispatch).toHaveBeenCalledWith(
        expect.objectContaining({
          handler: 'navigate',
          params: { path: '/admin/dashboard' },
        })
      );
    });
  });

  describe('collapsed 모드', () => {
    it('collapsed 시 텍스트 라벨이 표시되지 않는다', () => {
      render(<AdminSidebar menu={createMenu()} collapsed={true} currentLocale="ko" />);

      // 텍스트 라벨이 보이지 않아야 함
      expect(screen.queryByText('대시보드')).toBeNull();
      expect(screen.queryByText('이커머스')).toBeNull();
    });

    it('collapsed 시 chevron 버튼이 표시되지 않는다', () => {
      render(<AdminSidebar menu={createMenu()} collapsed={true} currentLocale="ko" />);

      // chevron 버튼 (aria-label)이 없어야 함
      expect(screen.queryByLabelText('common.expand')).toBeNull();
      expect(screen.queryByLabelText('common.collapse')).toBeNull();
    });
  });
});
