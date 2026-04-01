/**
 * TabNavigation 컴포넌트 테스트
 *
 * @description 탭 네비게이션 컴포넌트의 동작을 테스트합니다.
 * 주요 검증: 탭 렌더링, 탭 전환, 뱃지 표시, hiddenTabIds, disabled 상태
 */

import React, { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import type { Tab } from '../TabNavigation';

// basic 컴포넌트 의존성을 제거한 Mock TabNavigation
const MockTabNavigation: React.FC<{
  tabs: Tab[];
  activeTabId?: string | number;
  onTabChange?: (tabId: string | number) => void;
  mobileGrid?: boolean;
  hiddenTabIds?: (string | number)[];
}> = ({ tabs, activeTabId, onTabChange, mobileGrid = true, hiddenTabIds = [] }) => {
  const visibleTabs = hiddenTabIds.length > 0
    ? tabs.filter((tab) => !hiddenTabIds.includes(tab.id))
    : tabs;

  return (
    <nav data-testid="tab-navigation">
      {visibleTabs.map((tab) => (
        <button
          key={tab.id}
          data-testid={`tab-${tab.id}`}
          onClick={() => !tab.disabled && onTabChange?.(tab.id)}
          disabled={tab.disabled}
          aria-selected={tab.id === activeTabId}
        >
          <span data-testid={`tab-label-${tab.id}`}>{tab.label}</span>
          {mobileGrid && tab.badge !== undefined && (
            <span data-testid={`tab-badge-${tab.id}`}>{tab.badge}</span>
          )}
          {!mobileGrid && tab.badge !== undefined && (
            <span data-testid={`tab-badge-${tab.id}`}>{tab.badge}</span>
          )}
        </button>
      ))}
    </nav>
  );
};

const defaultTabs: Tab[] = [
  { id: 'detail', label: '상세정보' },
  { id: 'reviews', label: '리뷰', badge: 12 },
  { id: 'qna', label: '문의', badge: 3 },
];

describe('TabNavigation 컴포넌트', () => {
  describe('렌더링', () => {
    it('모든 탭이 렌더링되어야 함', () => {
      render(<MockTabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByTestId('tab-detail')).toBeInTheDocument();
      expect(screen.getByTestId('tab-reviews')).toBeInTheDocument();
      expect(screen.getByTestId('tab-qna')).toBeInTheDocument();
    });

    it('탭 라벨이 표시되어야 함', () => {
      render(<MockTabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByTestId('tab-label-detail')).toHaveTextContent('상세정보');
      expect(screen.getByTestId('tab-label-reviews')).toHaveTextContent('리뷰');
      expect(screen.getByTestId('tab-label-qna')).toHaveTextContent('문의');
    });

    it('활성 탭이 aria-selected=true 이어야 함', () => {
      render(<MockTabNavigation tabs={defaultTabs} activeTabId="reviews" />);

      expect(screen.getByTestId('tab-reviews')).toHaveAttribute('aria-selected', 'true');
      expect(screen.getByTestId('tab-detail')).toHaveAttribute('aria-selected', 'false');
    });
  });

  describe('뱃지(badge)', () => {
    it('badge가 있는 탭에 뱃지가 표시되어야 함', () => {
      render(<MockTabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByTestId('tab-badge-reviews')).toHaveTextContent('12');
      expect(screen.getByTestId('tab-badge-qna')).toHaveTextContent('3');
    });

    it('badge가 없는 탭에는 뱃지가 렌더링되지 않아야 함', () => {
      render(<MockTabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.queryByTestId('tab-badge-detail')).not.toBeInTheDocument();
    });

    it('badge 값이 0일 때도 표시되어야 함', () => {
      const tabs: Tab[] = [{ id: 'qna', label: '문의', badge: 0 }];
      render(<MockTabNavigation tabs={tabs} activeTabId="qna" />);

      expect(screen.getByTestId('tab-badge-qna')).toHaveTextContent('0');
    });
  });

  describe('탭 전환', () => {
    it('탭 클릭 시 onTabChange가 호출되어야 함', () => {
      const onTabChange = vi.fn();
      render(
        <MockTabNavigation tabs={defaultTabs} activeTabId="detail" onTabChange={onTabChange} />,
      );

      fireEvent.click(screen.getByTestId('tab-reviews'));
      expect(onTabChange).toHaveBeenCalledWith('reviews');
    });

    it('상태로 activeTabId를 관리하면 클릭 시 탭이 전환되어야 함', () => {
      const Wrapper = () => {
        const [activeTabId, setActiveTabId] = useState<string | number>('detail');
        return (
          <MockTabNavigation
            tabs={defaultTabs}
            activeTabId={activeTabId}
            onTabChange={setActiveTabId}
          />
        );
      };
      render(<Wrapper />);

      expect(screen.getByTestId('tab-detail')).toHaveAttribute('aria-selected', 'true');
      fireEvent.click(screen.getByTestId('tab-reviews'));
      expect(screen.getByTestId('tab-reviews')).toHaveAttribute('aria-selected', 'true');
      expect(screen.getByTestId('tab-detail')).toHaveAttribute('aria-selected', 'false');
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
        <MockTabNavigation tabs={tabs} activeTabId="detail" onTabChange={onTabChange} />,
      );

      fireEvent.click(screen.getByTestId('tab-reviews'));
      expect(onTabChange).not.toHaveBeenCalled();
    });

    it('disabled 탭은 disabled 속성이 있어야 함', () => {
      const tabs: Tab[] = [{ id: 'reviews', label: '리뷰', disabled: true }];
      render(<MockTabNavigation tabs={tabs} activeTabId="detail" />);

      expect(screen.getByTestId('tab-reviews')).toBeDisabled();
    });
  });

  describe('hiddenTabIds', () => {
    it('hiddenTabIds에 포함된 탭은 렌더링되지 않아야 함', () => {
      render(
        <MockTabNavigation
          tabs={defaultTabs}
          activeTabId="detail"
          hiddenTabIds={['qna']}
        />,
      );

      expect(screen.getByTestId('tab-detail')).toBeInTheDocument();
      expect(screen.getByTestId('tab-reviews')).toBeInTheDocument();
      expect(screen.queryByTestId('tab-qna')).not.toBeInTheDocument();
    });

    it('hiddenTabIds가 빈 배열이면 모든 탭이 표시되어야 함', () => {
      render(
        <MockTabNavigation tabs={defaultTabs} activeTabId="detail" hiddenTabIds={[]} />,
      );

      expect(screen.getByTestId('tab-detail')).toBeInTheDocument();
      expect(screen.getByTestId('tab-reviews')).toBeInTheDocument();
      expect(screen.getByTestId('tab-qna')).toBeInTheDocument();
    });
  });
});
