import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TabNavigation, Tab } from '../TabNavigation';
import { IconName } from '../../basic/IconTypes';

/**
 * window.G7Core.useResponsive mock
 *
 * 기본값은 데스크톱 (1024px). 모바일 케이스는 `mockUseResponsive.mockReturnValueOnce({...})`로 개별 오버라이드.
 */
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

describe('TabNavigation', () => {
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

  const mockTabs: Tab[] = [
    { id: 1, label: '프로필' },
    { id: 2, label: '설정' },
    { id: 3, label: '알림' },
  ];

  describe('데스크톱 렌더링', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c01
     * @effects tab_navigation_behaves
     */
    it('모든 탭을 Nav 안의 버튼으로 렌더링해야 함', () => {
      render(<TabNavigation tabs={mockTabs} />);

      expect(screen.getByText('프로필')).toBeInTheDocument();
      expect(screen.getByText('설정')).toBeInTheDocument();
      expect(screen.getByText('알림')).toBeInTheDocument();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c02
     * @effects tab_navigation_behaves
     */
    it('Nav 요소로 렌더링되어야 함', () => {
      const { container } = render(<TabNavigation tabs={mockTabs} />);

      const nav = container.querySelector('nav');
      expect(nav).toBeInTheDocument();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c03
     * @effects tab_navigation_behaves
     */
    it('Select 드롭다운은 렌더링되지 않아야 함', () => {
      const { container } = render(<TabNavigation tabs={mockTabs} />);

      // 데스크톱 분기에서는 native select도, custom Select trigger도 없어야 함
      const selects = container.querySelectorAll('select');
      expect(selects.length).toBe(0);
    });
  });

  describe('활성 탭', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c04
     * @effects tab_navigation_behaves
     */
    it('activeTabId가 설정된 탭에 활성화 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={2} />);

      const settingsButton = screen.getByText('설정').closest('button');
      expect(settingsButton).toHaveClass('tab-btn-default-active');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c05
     * @effects tab_navigation_behaves
     */
    it('activeTabId가 없으면 활성화 스타일이 없어야 함', () => {
      render(<TabNavigation tabs={mockTabs} />);

      const profileButton = screen.getByText('프로필').closest('button');
      expect(profileButton).not.toHaveClass('tab-btn-default-active');
    });
  });

  describe('탭 클릭 이벤트', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_click_changes_only_to_other_enabled_tab, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c06
     * @effects tab_navigation_behaves
     */
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

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_click_changes_only_to_other_enabled_tab, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c07
     * @effects tab_navigation_behaves
     */
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

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_click_changes_only_to_other_enabled_tab, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c08
     * @effects tab_navigation_behaves
     */
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
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c09
     * @effects tab_navigation_behaves
     */
    it('아이콘이 있는 탭을 렌더링해야 함', () => {
      const tabsWithIcons: Tab[] = [
        { id: 1, label: '프로필', iconName: IconName.User },
        { id: 2, label: '설정', iconName: IconName.Cog },
      ];

      const { container } = render(<TabNavigation tabs={tabsWithIcons} />);

      const icons = container.querySelectorAll('i[class*="fa-"]');
      expect(icons.length).toBe(2);
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c10
     * @effects tab_navigation_behaves
     */
    it('아이콘이 없는 탭은 아이콘을 렌더링하지 않아야 함', () => {
      const { container } = render(<TabNavigation tabs={mockTabs} />);

      const icons = container.querySelectorAll('i[class*="fa-"]');
      expect(icons.length).toBe(0);
    });
  });

  describe('뱃지', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c11
     * @effects tab_navigation_behaves
     */
    it('뱃지가 있는 탭을 렌더링해야 함', () => {
      const tabsWithBadges: Tab[] = [
        { id: 1, label: '알림', badge: 5 },
        { id: 2, label: '메시지', badge: '99+' },
      ];

      render(<TabNavigation tabs={tabsWithBadges} />);

      expect(screen.getByText('5')).toBeInTheDocument();
      expect(screen.getByText('99+')).toBeInTheDocument();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c12
     * @effects tab_navigation_behaves
     */
    it('뱃지가 0일 때도 렌더링해야 함', () => {
      const tabsWithBadges: Tab[] = [{ id: 1, label: '알림', badge: 0 }];

      render(<TabNavigation tabs={tabsWithBadges} />);

      expect(screen.getByText('0')).toBeInTheDocument();
    });
  });

  describe('비활성화된 탭', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c13
     * @effects tab_navigation_behaves
     */
    it('비활성화된 탭에 disabled 속성을 적용해야 함', () => {
      const tabsWithDisabled: Tab[] = [
        { id: 1, label: '활성화' },
        { id: 2, label: '비활성화', disabled: true },
      ];

      render(<TabNavigation tabs={tabsWithDisabled} />);

      const disabledButton = screen.getByText('비활성화').closest('button');
      expect(disabledButton).toBeDisabled();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c14
     * @effects tab_navigation_behaves
     */
    it('비활성화된 탭에 비활성화 스타일을 적용해야 함', () => {
      const tabsWithDisabled: Tab[] = [
        { id: 1, label: '비활성화', disabled: true },
      ];

      render(<TabNavigation tabs={tabsWithDisabled} />);

      const disabledButton = screen.getByText('비활성화').closest('button');
      expect(disabledButton).toHaveClass('tab-btn-disabled');
    });
  });

  describe('variant 스타일', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c15
     * @effects tab_navigation_behaves
     */
    it('variant="default"일 때 기본 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={1} variant="default" />);

      const activeButton = screen.getByText('프로필').closest('button');
      expect(activeButton).toHaveClass('tab-btn-default-active');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c16
     * @effects tab_navigation_behaves
     */
    it('variant="pills"일 때 pill 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={1} variant="pills" />);

      const activeButton = screen.getByText('프로필').closest('button');
      expect(activeButton).toHaveClass('tab-btn-pills-active');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c17
     * @effects tab_navigation_behaves
     */
    it('variant="underline"일 때 underline 스타일을 적용해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={1} variant="underline" />);

      const activeButton = screen.getByText('프로필').closest('button');
      expect(activeButton).toHaveClass('tab-btn-underline-active');
    });
  });

  describe('스타일 커스터마이징', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c18
     * @effects tab_navigation_behaves
     */
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={mockTabs} className="custom-nav" />
      );

      const nav = container.querySelector('nav');
      expect(nav).toHaveClass('custom-nav');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c19
     * @effects tab_navigation_behaves
     */
    it('style prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={mockTabs} style={{ marginTop: '20px' }} />
      );

      const nav = container.querySelector('nav');
      expect(nav).toHaveStyle({ marginTop: '20px' });
    });
  });

  describe('모바일 (useResponsive isMobile=true)', () => {
    beforeEach(() => {
      setMobile();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_mobile_renders_select_dropdown, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c20
     * @effects tab_navigation_behaves
     */
    it('Nav 대신 Select 드롭다운만 렌더링해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={mockTabs} activeTabId={1} />
      );

      // Nav 없음, Select 있음
      expect(container.querySelector('nav')).not.toBeInTheDocument();
      // custom Select는 trigger button 1개와 dropdown div를 가짐
      const triggerButton = container.querySelector('button');
      expect(triggerButton).toBeInTheDocument();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_mobile_renders_select_dropdown, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c21
     * @effects tab_navigation_behaves
     */
    it('현재 활성 탭의 label을 Select trigger에 표시해야 함', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={2} />);

      // 데스크톱 Nav가 없으므로 라벨 충돌 없음
      expect(screen.getByText('설정')).toBeInTheDocument();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_mobile_renders_select_dropdown, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c22
     * @effects tab_navigation_behaves
     */
    it('뱃지가 있는 탭은 label과 함께 뱃지 수를 병기해야 함', () => {
      const tabsWithBadge: Tab[] = [
        { id: 1, label: '알림', badge: 5 },
      ];
      render(<TabNavigation tabs={tabsWithBadge} activeTabId={1} />);

      expect(screen.getByText('알림 (5)')).toBeInTheDocument();
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_mobile_renders_select_dropdown, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c23
     * @effects tab_navigation_behaves
     */
    it('Select trigger 클릭 후 옵션 선택 시 onTabChange가 호출되어야 함', () => {
      const handleTabChange = vi.fn();
      const { container } = render(
        <TabNavigation
          tabs={mockTabs}
          activeTabId={1}
          onTabChange={handleTabChange}
        />
      );

      // Select trigger 열기
      const triggerButton = container.querySelector('button');
      fireEvent.mouseDown(document.body);
      fireEvent.click(triggerButton!);

      // 옵션 클릭 (custom dropdown 내부)
      const settingsOption = screen.getAllByText('설정').find(
        (el) => el.closest('button') !== triggerButton
      );
      if (settingsOption) {
        fireEvent.click(settingsOption.closest('button')!);
        expect(handleTabChange).toHaveBeenCalledWith(2);
      }
    });
  });

  describe('복합 시나리오', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_renders_tabs_with_icon_badge_disabled_variants, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c24
     * @effects tab_navigation_behaves
     */
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

      const icons = container.querySelectorAll('i[class*="fa-"]');
      expect(icons.length).toBe(3);

      expect(screen.getByText('99+')).toBeInTheDocument();
    });
  });

  /**
   * 편집 모드 인플레이스 측정 마커. 캔버스 인플레이스 오버레이가 탭 헤더
   * 박스를 측정할 수 있도록, editorAttrs(data-editor-path) 주입 시 각 탭 버튼에
   * data-editor-item-path="<node path>.props.tabs.<i>" 를 부여한다. 런타임(editorAttrs
   * 미주입)에는 마커가 없어야 사용자 페이지에 무영향.
   * @scenario unit=item_path_marker
   * @effects tabnavigation_emits_item_path_marker_only_in_editor_mode, core_measures_data_editor_item_path_markers_into_cellboxes
   */
  describe('편집 모드 인플레이스 측정 마커(data-editor-item-path)', () => {
    /**
     * @scenario unit=item_path_marker
     * @effects tabnavigation_emits_item_path_marker_only_in_editor_mode, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c25
     * @effects tab_navigation_behaves
     */
    it('editorAttrs 주입 시 각 탭 버튼에 data-editor-item-path 를 부여한다', () => {
      const { container } = render(
        <TabNavigation
          tabs={mockTabs}
          editorAttrs={{ 'data-editor-path': '2.children.0' } as any}
        />
      );
      const marked = container.querySelectorAll('[data-editor-item-path]');
      expect(marked.length).toBe(3);
      expect(marked[0].getAttribute('data-editor-item-path')).toBe('2.children.0.props.tabs.0');
      expect(marked[1].getAttribute('data-editor-item-path')).toBe('2.children.0.props.tabs.1');
      expect(marked[2].getAttribute('data-editor-item-path')).toBe('2.children.0.props.tabs.2');
    });

    /**
     * @scenario unit=item_path_marker
     * @effects tabnavigation_emits_item_path_marker_only_in_editor_mode, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c26
     * @effects tab_navigation_behaves
     */
    it('editorAttrs 미주입(런타임) 시 마커를 부여하지 않는다', () => {
      const { container } = render(<TabNavigation tabs={mockTabs} />);
      expect(container.querySelectorAll('[data-editor-item-path]').length).toBe(0);
    });
  });

  /**
   * 접근성 — WAI-ARIA Tabs 규약.
   *
   * 탭이 평범한 버튼으로만 렌더되면 보조기기가 "탭 목록 중 몇 번째, 선택됨" 을 알릴 수 없고,
   * 목록 안 이동도 Tab 키로 전 탭을 훑어야 한다.
   */
  describe('접근성 (WAI-ARIA Tabs)', () => {
    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c27
     * @effects tab_navigation_behaves
     */
    it('목록은 tablist, 각 항목은 tab 으로 노출된다', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={2} />);

      expect(screen.getByRole('tablist')).toBeInTheDocument();
      expect(screen.getAllByRole('tab')).toHaveLength(3);
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c28
     * @effects tab_navigation_behaves
     */
    it('활성 탭만 aria-selected=true 다', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={2} />);

      const tabs = screen.getAllByRole('tab');
      expect(tabs[0]).toHaveAttribute('aria-selected', 'false');
      expect(tabs[1]).toHaveAttribute('aria-selected', 'true');
      expect(tabs[2]).toHaveAttribute('aria-selected', 'false');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c29
     * @effects tab_navigation_behaves
     */
    it('활성 탭만 Tab 키 초점을 받는다 (roving tabindex)', () => {
      render(<TabNavigation tabs={mockTabs} activeTabId={2} />);

      const tabs = screen.getAllByRole('tab');
      expect(tabs[0]).toHaveAttribute('tabindex', '-1');
      expect(tabs[1]).toHaveAttribute('tabindex', '0');
      expect(tabs[2]).toHaveAttribute('tabindex', '-1');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c30
     * @effects tab_navigation_behaves
     */
    it('활성 탭이 지정되지 않아도 첫 탭이 초점 진입점이 된다', () => {
      render(<TabNavigation tabs={mockTabs} />);

      expect(screen.getAllByRole('tab')[0]).toHaveAttribute('tabindex', '0');
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c31
     * @effects tab_navigation_behaves
     */
    it('좌우 화살표로 이전/다음 탭으로 이동한다', () => {
      const onTabChange = vi.fn();
      render(<TabNavigation tabs={mockTabs} activeTabId={2} onTabChange={onTabChange} />);

      const tabs = screen.getAllByRole('tab');
      fireEvent.keyDown(tabs[1], { key: 'ArrowRight' });
      expect(onTabChange).toHaveBeenLastCalledWith(3);

      fireEvent.keyDown(tabs[1], { key: 'ArrowLeft' });
      expect(onTabChange).toHaveBeenLastCalledWith(1);
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c32
     * @effects tab_navigation_behaves
     */
    it('화살표 이동은 양 끝에서 순환한다', () => {
      const onTabChange = vi.fn();
      render(<TabNavigation tabs={mockTabs} activeTabId={3} onTabChange={onTabChange} />);

      fireEvent.keyDown(screen.getAllByRole('tab')[2], { key: 'ArrowRight' });
      expect(onTabChange).toHaveBeenLastCalledWith(1);
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c33
     * @effects tab_navigation_behaves
     */
    it('Home/End 로 첫/마지막 탭으로 이동한다', () => {
      const onTabChange = vi.fn();
      render(<TabNavigation tabs={mockTabs} activeTabId={2} onTabChange={onTabChange} />);

      const tabs = screen.getAllByRole('tab');
      fireEvent.keyDown(tabs[1], { key: 'End' });
      expect(onTabChange).toHaveBeenLastCalledWith(3);

      fireEvent.keyDown(tabs[1], { key: 'Home' });
      expect(onTabChange).toHaveBeenLastCalledWith(1);
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c34
     * @effects tab_navigation_behaves
     */
    it('화살표 이동은 비활성 탭을 건너뛴다', () => {
      const onTabChange = vi.fn();
      const withDisabled: Tab[] = [
        { id: 1, label: '프로필' },
        { id: 2, label: '설정', disabled: true },
        { id: 3, label: '알림' },
      ];
      render(<TabNavigation tabs={withDisabled} activeTabId={1} onTabChange={onTabChange} />);

      fireEvent.keyDown(screen.getAllByRole('tab')[0], { key: 'ArrowRight' });
      expect(onTabChange).toHaveBeenLastCalledWith(3);
    });

    /**
     * @scenario component=tab_navigation
     * @effects tab_navigation_exposes_wai_aria_tabs_with_keyboard, tab_navigation_mobile_renders_select_dropdown, tab_navigation_contract_with_decorative_icon
     */
    /**
     * @scenario case=c35
     * @effects tab_navigation_behaves
     */
    it('모바일 전환 시에는 tablist 대신 Select 드롭다운으로 렌더된다', () => {
      mockUseResponsive.mockReturnValueOnce({ width: 500 } as any);
      const { container } = render(<TabNavigation tabs={mockTabs} activeTabId={1} />);

      // 모바일은 탭 목록이 아니라 단일 선택 UI 이므로 tablist/tab 을 두지 않는다.
      expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
      expect(screen.queryAllByRole('tab')).toHaveLength(0);
      // custom Select 는 trigger button 을 갖는다 (같은 파일의 모바일 렌더 테스트와 동일 관례)
      expect(container.querySelector('button')).toBeInTheDocument();
    });
  });

  describe('항목별 표시 조건(if)', () => {
    // 확장이 탭 목록에 끼워 넣는 항목은 본문 카드와 같은 조건(`if`)을 단다 — 조건이 거짓이면 갈 곳이 없으므로
    // 탭도 그리지 않는다(관리자 회원 수정 화면의 「배송국가」·신규 등록 화면의 「활동정보」 빈 탭 회귀).
    const conditionalTabs = [
      { id: 11, label: '항상 보임' },
      { id: 12, label: '조건 거짓', if: false },
      { id: 13, label: '조건 참', if: true },
      // `{{route?.id}}` 같은 식은 거짓일 때 false 가 아니라 undefined 로 평가된다 — 키가 있으면 숨는다
      { id: 14, label: '조건 미정', if: undefined },
      { id: 15, label: '조건 문자열', if: 'a2ead72d' },
    ] as Tab[];

    /**
     * @scenario case=c36
     * @effects tab_navigation_behaves
     */
    it('데스크톱: if 가 false 인 탭은 그리지 않고 나머지는 그린다', () => {
      render(<TabNavigation tabs={conditionalTabs} />);

      expect(screen.queryAllByText('조건 거짓')).toHaveLength(0);
      expect(screen.queryAllByText('조건 미정')).toHaveLength(0);
      expect(screen.getAllByText('조건 문자열').length).toBeGreaterThan(0);
      expect(screen.getAllByText('항상 보임').length).toBeGreaterThan(0);
      expect(screen.getAllByText('조건 참').length).toBeGreaterThan(0);
    });

    /**
     * @scenario case=c37
     * @effects tab_navigation_behaves
     */
    it('모바일 선택 목록에도 if 가 false 인 탭은 없다', () => {
      setMobile();
      const { container } = render(<TabNavigation tabs={conditionalTabs} />);

      const trigger = container.querySelector('button');
      expect(trigger).not.toBeNull();
      fireEvent.click(trigger as HTMLButtonElement);

      expect(screen.queryAllByText('조건 거짓')).toHaveLength(0);
      expect(screen.getAllByText('조건 참').length).toBeGreaterThan(0);
    });
  });
});
