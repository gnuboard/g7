import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TabNavigation, Tab } from '../TabNavigation';
import { IconName } from '../../basic/IconTypes';

describe('TabNavigation', () => {
  const mockTabs: Tab[] = [
    { id: 1, label: '프로필' },
    { id: 2, label: '설정' },
    { id: 3, label: '알림' },
  ];

  describe('기본 렌더링', () => {
    it('모든 탭을 렌더링해야 함', () => {
      render(<TabNavigation tabs={mockTabs} />);

      expect(screen.getByText('프로필')).toBeInTheDocument();
      expect(screen.getByText('설정')).toBeInTheDocument();
      expect(screen.getByText('알림')).toBeInTheDocument();
    });

    it('Nav 요소로 렌더링되어야 함', () => {
      const { container } = render(<TabNavigation tabs={mockTabs} />);

      const nav = container.querySelector('nav');
      expect(nav).toBeInTheDocument();
    });
  });

  describe('활성 탭', () => {
    it('activeTabId가 설정된 탭에 활성화 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={2} />);

      const settingsButton = screen.getByText('설정').closest('button');
      expect(settingsButton).toHaveClass('text-blue-600');
    });

    it('activeTabId가 없으면 활성화 스타일이 없어야 함', () => {
      render(<TabNavigation tabs={mockTabs} />);

      const profileButton = screen.getByText('프로필').closest('button');
      expect(profileButton).not.toHaveClass('text-blue-600');
    });
  });

  describe('탭 클릭 이벤트', () => {
    it('탭 클릭 시 onTabChange가 호출되어야 함', () => {
      const handleTabChange = vi.fn();
      render(
        <TabNavigation
          tabs={mockTabs}
          activeTabId={1}
          onTabChange={handleTabChange}
        />
      );

      const settingsButton = screen.getByText('설정').closest('button');
      fireEvent.click(settingsButton!);

      expect(handleTabChange).toHaveBeenCalledWith(2);
    });

    it('현재 활성화된 탭 클릭 시 onTabChange가 호출되지 않아야 함', () => {
      const handleTabChange = vi.fn();
      render(
        <TabNavigation
          tabs={mockTabs}
          activeTabId={1}
          onTabChange={handleTabChange}
        />
      );

      const profileButton = screen.getByText('프로필').closest('button');
      fireEvent.click(profileButton!);

      expect(handleTabChange).not.toHaveBeenCalled();
    });

    it('비활성화된 탭 클릭 시 onTabChange가 호출되지 않아야 함', () => {
      const handleTabChange = vi.fn();
      const tabsWithDisabled: Tab[] = [
        ...mockTabs,
        { id: 4, label: '비활성화', disabled: true },
      ];

      render(
        <TabNavigation
          tabs={tabsWithDisabled}
          activeTabId={1}
          onTabChange={handleTabChange}
        />
      );

      const disabledButton = screen.getByText('비활성화').closest('button');
      fireEvent.click(disabledButton!);

      expect(handleTabChange).not.toHaveBeenCalled();
    });
  });

  describe('아이콘', () => {
    it('아이콘이 있는 탭을 렌더링해야 함', () => {
      const tabsWithIcons: Tab[] = [
        { id: 1, label: '프로필', iconName: IconName.User },
        { id: 2, label: '설정', iconName: IconName.Cog },
      ];

      const { container } = render(<TabNavigation tabs={tabsWithIcons} />);

      const icons = container.querySelectorAll('i[role="img"]');
      expect(icons.length).toBe(2);
    });

    it('아이콘이 없는 탭은 아이콘을 렌더링하지 않아야 함', () => {
      const { container } = render(<TabNavigation tabs={mockTabs} />);

      const icons = container.querySelectorAll('i[role="img"]');
      expect(icons.length).toBe(0);
    });
  });

  describe('뱃지', () => {
    it('뱃지가 있는 탭을 렌더링해야 함', () => {
      const tabsWithBadges: Tab[] = [
        { id: 1, label: '알림', badge: 5 },
        { id: 2, label: '메시지', badge: '99+' },
      ];

      render(<TabNavigation tabs={tabsWithBadges} />);

      expect(screen.getByText('5')).toBeInTheDocument();
      expect(screen.getByText('99+')).toBeInTheDocument();
    });

    it('뱃지가 0일 때도 렌더링해야 함', () => {
      const tabsWithBadges: Tab[] = [{ id: 1, label: '알림', badge: 0 }];

      render(<TabNavigation tabs={tabsWithBadges} />);

      expect(screen.getByText('0')).toBeInTheDocument();
    });
  });

  describe('비활성화된 탭', () => {
    it('비활성화된 탭에 disabled 속성을 적용해야 함', () => {
      const tabsWithDisabled: Tab[] = [
        { id: 1, label: '활성화' },
        { id: 2, label: '비활성화', disabled: true },
      ];

      render(<TabNavigation tabs={tabsWithDisabled} />);

      const disabledButton = screen.getByText('비활성화').closest('button');
      expect(disabledButton).toBeDisabled();
    });

    it('비활성화된 탭에 비활성화 스타일을 적용해야 함', () => {
      const tabsWithDisabled: Tab[] = [
        { id: 1, label: '비활성화', disabled: true },
      ];

      render(<TabNavigation tabs={tabsWithDisabled} />);

      const disabledButton = screen.getByText('비활성화').closest('button');
      expect(disabledButton).toHaveClass('opacity-50', 'cursor-not-allowed');
    });
  });

  describe('variant 스타일', () => {
    it('variant="default"일 때 기본 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={1} variant="default" />);

      const activeButton = screen.getByText('프로필').closest('button');
      expect(activeButton).toHaveClass('bg-blue-50', 'border-b-2');
    });

    it('variant="pills"일 때 pill 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={1} variant="pills" />);

      const activeButton = screen.getByText('프로필').closest('button');
      expect(activeButton).toHaveClass('bg-blue-600', 'rounded-lg');
    });

    it('variant="underline"일 때 underline 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={1} variant="underline" />);

      const activeButton = screen.getByText('프로필').closest('button');
      expect(activeButton).toHaveClass('border-b-2', 'border-blue-600');
    });
  });

  describe('스타일 커스터마이징', () => {
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={mockTabs} className="custom-nav" />
      );

      const nav = container.querySelector('nav');
      expect(nav).toHaveClass('custom-nav');
    });

    it('style prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={mockTabs} style={{ marginTop: '20px' }} />
      );

      const nav = container.querySelector('nav');
      expect(nav).toHaveStyle({ marginTop: '20px' });
    });
  });

  describe('복합 시나리오', () => {
    it('아이콘, 뱃지, 비활성화를 모두 함께 사용할 수 있어야 함', () => {
      const complexTabs: Tab[] = [
        { id: 1, label: '프로필', iconName: IconName.User, badge: 3 },
        { id: 2, label: '설정', iconName: IconName.Cog, disabled: true },
        { id: 3, label: '알림', iconName: IconName.Bell, badge: '99+' },
      ];

      const { container } = render(
        <TabNavigation tabs={complexTabs} activeTabId={1} variant="pills" />
      );

      expect(screen.getByText('프로필')).toBeInTheDocument();
      expect(screen.getByText('3')).toBeInTheDocument();

      const settingsButton = screen.getByText('설정').closest('button');
      expect(settingsButton).toBeDisabled();

      const icons = container.querySelectorAll('i[role="img"]');
      expect(icons.length).toBe(3);

      expect(screen.getByText('99+')).toBeInTheDocument();
    });
  });
});
