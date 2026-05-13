/**
 * TabNavigation 컴포넌트 테스트
 *
 * @description 탭 네비게이션 컴포넌트의 동작을 테스트합니다.
 * 주요 검증: 탭 렌더링, 탭 전환, 뱃지 표시, hiddenTabIds, disabled, 반응형 분기 (G7Core.useResponsive)
 */

import React, { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { TabNavigation, type Tab } from '../TabNavigation';

const mockUseResponsive = vi.fn(() => ({
  width: 1024,
  isMobile: false,
  isTablet: false,
  isDesktop: true,
  matchedPreset: 'desktop' as const,
}));

const setMobile = () => {
  mockUseResponsive.mockReturnValue({
    width: 375,
    isMobile: true,
    isTablet: false,
    isDesktop: false,
    matchedPreset: 'mobile' as const,
  });
};

const defaultTabs: Tab[] = [
  { id: 'detail', label: '상세정보' },
  { id: 'reviews', label: '리뷰', badge: 12 },
  { id: 'qna', label: '문의', badge: 3 },
];

describe('TabNavigation 컴포넌트', () => {
  let originalG7Core: any;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      useResponsive: mockUseResponsive,
    };
    mockUseResponsive.mockReturnValue({
      width: 1024,
      isMobile: false,
      isTablet: false,
      isDesktop: true,
      matchedPreset: 'desktop' as const,
    });
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    vi.clearAllMocks();
  });

  describe('데스크톱 렌더링', () => {
    it('Nav 안의 버튼으로 모든 탭이 렌더링되어야 함', () => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" />
      );

      expect(container.querySelector('nav')).toBeInTheDocument();
      expect(screen.getByText('상세정보')).toBeInTheDocument();
      expect(screen.getByText('리뷰')).toBeInTheDocument();
      expect(screen.getByText('문의')).toBeInTheDocument();
    });

    it('Select 드롭다운은 렌더링되지 않아야 함', () => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" />
      );

      const selects = container.querySelectorAll('select');
      expect(selects.length).toBe(0);
    });

    it('활성 탭이 활성화 스타일을 가져야 함', () => {
      render(
        <TabNavigation tabs={defaultTabs} activeTabId="reviews" variant="underline" />
      );

      const activeButton = screen.getByText('리뷰').closest('button');
      expect(activeButton).toHaveClass('border-b-2');
    });
  });

  describe('뱃지(badge)', () => {
    it('뱃지가 표시되어야 함', () => {
      render(<TabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByText('12')).toBeInTheDocument();
      expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('badge 값이 0일 때도 표시되어야 함', () => {
      const tabs: Tab[] = [{ id: 'qna', label: '문의', badge: 0 }];
      render(<TabNavigation tabs={tabs} activeTabId="qna" />);

      expect(screen.getByText('0')).toBeInTheDocument();
    });
  });

  describe('탭 전환', () => {
    it('탭 클릭 시 onTabChange가 호출되어야 함', () => {
      const onTabChange = vi.fn();
      render(
        <TabNavigation
          tabs={defaultTabs}
          activeTabId="detail"
          onTabChange={onTabChange}
        />
      );

      fireEvent.click(screen.getByText('리뷰').closest('button')!);
      expect(onTabChange).toHaveBeenCalledWith('reviews');
    });

    it('상태로 activeTabId를 관리하면 클릭 시 탭이 전환되어야 함', () => {
      const Wrapper = () => {
        const [activeTabId, setActiveTabId] = useState<string | number>('detail');
        return (
          <TabNavigation
            tabs={defaultTabs}
            activeTabId={activeTabId}
            onTabChange={setActiveTabId}
            variant="underline"
          />
        );
      };
      render(<Wrapper />);

      expect(screen.getByText('상세정보').closest('button')).toHaveClass('border-b-2');

      fireEvent.click(screen.getByText('리뷰').closest('button')!);

      expect(screen.getByText('리뷰').closest('button')).toHaveClass('border-b-2');
    });
  });

  describe('disabled 탭', () => {
    it('disabled 탭은 클릭해도 onTabChange가 호출되지 않아야 함', () => {
      const onTabChange = vi.fn();
      const tabs: Tab[] = [
        { id: 'detail', label: '상세정보' },
        { id: 'reviews', label: '리뷰', disabled: true },
      ];
      render(
        <TabNavigation
          tabs={tabs}
          activeTabId="detail"
          onTabChange={onTabChange}
        />
      );

      fireEvent.click(screen.getByText('리뷰').closest('button')!);
      expect(onTabChange).not.toHaveBeenCalled();
    });

    it('disabled 탭은 disabled 속성이 있어야 함', () => {
      const tabs: Tab[] = [{ id: 'reviews', label: '리뷰', disabled: true }];
      render(<TabNavigation tabs={tabs} activeTabId="detail" />);

      expect(screen.getByText('리뷰').closest('button')).toBeDisabled();
    });
  });

  describe('hiddenTabIds', () => {
    it('hiddenTabIds에 포함된 탭은 렌더링되지 않아야 함', () => {
      render(
        <TabNavigation
          tabs={defaultTabs}
          activeTabId="detail"
          hiddenTabIds={['qna']}
        />
      );

      expect(screen.getByText('상세정보')).toBeInTheDocument();
      expect(screen.getByText('리뷰')).toBeInTheDocument();
      expect(screen.queryByText('문의')).not.toBeInTheDocument();
    });

    it('hiddenTabIds가 빈 배열이면 모든 탭이 표시되어야 함', () => {
      render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" hiddenTabIds={[]} />
      );

      expect(screen.getByText('상세정보')).toBeInTheDocument();
      expect(screen.getByText('리뷰')).toBeInTheDocument();
      expect(screen.getByText('문의')).toBeInTheDocument();
    });
  });

  describe('모바일 (useResponsive isMobile=true)', () => {
    beforeEach(() => {
      setMobile();
    });

    it('Nav 대신 Select 드롭다운만 렌더링해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" />
      );

      expect(container.querySelector('nav')).not.toBeInTheDocument();
      // custom Select trigger button이 존재해야 함
      const trigger = container.querySelector('button');
      expect(trigger).toBeInTheDocument();
    });

    it('현재 활성 탭의 label을 Select trigger에 표시해야 함', () => {
      render(<TabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByText('상세정보')).toBeInTheDocument();
    });

    it('뱃지가 있는 탭은 label과 함께 뱃지 수를 병기해야 함', () => {
      render(<TabNavigation tabs={defaultTabs} activeTabId="reviews" />);

      expect(screen.getByText('리뷰 (12)')).toBeInTheDocument();
    });

    it('hiddenTabIds가 모바일에서도 적용되어야 함', () => {
      render(
        <TabNavigation
          tabs={defaultTabs}
          activeTabId="detail"
          hiddenTabIds={['qna']}
        />
      );

      // 활성 라벨은 보이지만 숨김 처리된 항목은 보이지 않음 (Select 닫힌 상태)
      expect(screen.getByText('상세정보')).toBeInTheDocument();
      expect(screen.queryByText('문의')).not.toBeInTheDocument();
    });
  });
});
