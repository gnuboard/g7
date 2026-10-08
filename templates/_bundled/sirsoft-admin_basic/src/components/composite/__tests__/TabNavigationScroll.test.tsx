import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { TabNavigationScroll, Tab } from '../TabNavigationScroll';
import * as scrollHandler from '../../../handlers/scrollToSectionHandler';

/**
 * window.G7Core.useResponsive mock — 기본값은 데스크톱 (1024px).
 * 모바일 케이스는 setMobile() 호출.
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

describe('TabNavigationScroll', () => {
  const mockTabs: Tab[] = [
    { id: 'basic', label: '기본정보' },
    { id: 'list', label: '목록설정' },
    { id: 'permissions', label: '권한설정' },
  ];

  let originalG7Core: any;

  // IntersectionObserver Mock
  let observerCallback: IntersectionObserverCallback;
  let mockObserverInstance: {
    observe: ReturnType<typeof vi.fn>;
    unobserve: ReturnType<typeof vi.fn>;
    disconnect: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    // G7Core.useResponsive mock — 기본 데스크톱
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

    // scrollToSectionHandler mock
    vi.spyOn(scrollHandler, 'scrollToSectionHandler').mockImplementation(async () => {});

    // IntersectionObserver mock (클래스 기반)
    mockObserverInstance = {
      observe: vi.fn(),
      unobserve: vi.fn(),
      disconnect: vi.fn(),
    };

    class MockIntersectionObserver implements IntersectionObserver {
      readonly root: Element | Document | null = null;
      readonly rootMargin: string = '';
      readonly thresholds: readonly number[] = [];

      constructor(callback: IntersectionObserverCallback) {
        observerCallback = callback;
      }

      observe = mockObserverInstance.observe;
      unobserve = mockObserverInstance.unobserve;
      disconnect = mockObserverInstance.disconnect;
      takeRecords(): IntersectionObserverEntry[] {
        return [];
      }
    }

    global.IntersectionObserver = MockIntersectionObserver;

    // getElementById mock (Scroll Spy용)
    global.document.getElementById = vi.fn((id: string) => {
      return { id } as HTMLElement;
    });

  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    vi.clearAllMocks();
  });

  /**
   * 헬퍼 함수: 데스크톱 뷰의 탭 버튼을 찾습니다.
   *
   * 단일 분기 렌더(useResponsive 기반)이므로 데스크톱 모드에서는 tablist 안의 탭만 존재합니다.
   * 탭은 WAI-ARIA 규약에 따라 role="tab" 으로 노출되므로 button 이 아니라 tab 으로 찾습니다.
   */
  const getTabButton = (text: string): HTMLButtonElement | null => {
    const tabs = screen.getAllByRole('tab');
    return tabs.find((tab) => tab.textContent === text) as HTMLButtonElement | null;
  };

  describe('기본 렌더링', () => {
    /**
     * @scenario case=c01
     * @effects tab_navigation_scrolls
     */
    it('모든 탭을 렌더링해야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      // 데스크톱 + 모바일(select option)에서 각각 표시되므로 getAllByText 사용
      expect(screen.getAllByText('기본정보').length).toBeGreaterThan(0);
      expect(screen.getAllByText('목록설정').length).toBeGreaterThan(0);
      expect(screen.getAllByText('권한설정').length).toBeGreaterThan(0);
    });

    /**
     * @scenario case=c02
     * @effects tab_navigation_scrolls
     */
    it('div 요소로 렌더링되어야 함', () => {
      const { container } = render(<TabNavigationScroll tabs={mockTabs} />);

      const div = container.querySelector('div');
      expect(div).toBeInTheDocument();
    });

    /**
     * @scenario case=c03
     * @effects tab_navigation_scrolls
     */
    it('첫 번째 탭이 기본 활성화되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      const basicButton = getTabButton('기본정보');
      expect(basicButton).toHaveClass('tab-btn-default-active');
    });

    /**
     * @scenario case=c04
     * @effects tab_navigation_scrolls
     */
    it('activeTabId가 설정된 탭이 활성화되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="list" />);

      const listButton = getTabButton('목록설정');
      expect(listButton).toHaveClass('tab-btn-default-active');
    });
  });

  describe('탭 클릭 이벤트', () => {
    /**
     * @scenario case=c05
     * @effects tab_navigation_scrolls
     */
    it('탭 클릭 시 scrollToSectionHandler가 호출되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalledWith(
        {
          params: {
            targetId: 'tab_content_list',
            offset: 120,
            delay: 100,
          },
        },
        {}
      );
    });

    /**
     * @scenario case=c06
     * @effects tab_navigation_scrolls
     */
    it('탭 클릭 시 활성 탭이 변경되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(listButton).toHaveClass('tab-btn-default-active');
    });

    /**
     * @scenario case=c07
     * @effects tab_navigation_scrolls
     */
    it('비활성화된 탭은 클릭할 수 없어야 함', () => {
      const tabsWithDisabled: Tab[] = [
        ...mockTabs,
        { id: 'disabled', label: '비활성화', disabled: true },
      ];

      render(<TabNavigationScroll tabs={tabsWithDisabled} />);

      const disabledButton = getTabButton('비활성화');
      expect(disabledButton).toBeDisabled();
    });
  });

  describe('개별 탭 onClick 콜백 (우선순위 1)', () => {
    /**
     * @scenario case=c08
     * @effects tab_navigation_scrolls
     */
    it('개별 탭 onClick이 있으면 실행하고 기본 동작 실행 안 함', () => {
      const individualOnClick = vi.fn();
      const tabsWithOnClick: Tab[] = [
        { id: 'basic', label: '기본정보', onClick: individualOnClick },
        { id: 'list', label: '목록설정' },
      ];

      render(<TabNavigationScroll tabs={tabsWithOnClick} />);

      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);

      expect(individualOnClick).toHaveBeenCalledWith('basic');
      expect(scrollHandler.scrollToSectionHandler).not.toHaveBeenCalled();
    });
  });

  describe('공통 onTabChange 콜백 (우선순위 2)', () => {
    /**
     * @scenario case=c09
     * @effects tab_navigation_scrolls
     */
    it('onTabChange가 있으면 실행하고 기본 동작 실행 안 함', () => {
      const onTabChange = vi.fn();

      render(<TabNavigationScroll tabs={mockTabs} onTabChange={onTabChange} />);

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(onTabChange).toHaveBeenCalledWith('list');
      expect(scrollHandler.scrollToSectionHandler).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=c10
     * @effects tab_navigation_scrolls
     */
    it('개별 onClick이 없고 onTabChange만 있으면 onTabChange 실행', () => {
      const onTabChange = vi.fn();
      const tabsWithMixed: Tab[] = [
        { id: 'basic', label: '기본정보', onClick: vi.fn() },
        { id: 'list', label: '목록설정' },
      ];

      render(
        <TabNavigationScroll tabs={tabsWithMixed} onTabChange={onTabChange} />
      );

      // 개별 onClick이 있는 탭
      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);
      expect(onTabChange).not.toHaveBeenCalled();

      // 개별 onClick이 없는 탭
      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);
      expect(onTabChange).toHaveBeenCalledWith('list');
    });
  });

  describe('개별 탭 스크롤 설정', () => {
    /**
     * @scenario case=c11
     * @effects tab_navigation_scrolls
     */
    it('탭별로 다른 scrollOffset을 사용해야 함', () => {
      const tabsWithOffset: Tab[] = [
        { id: 'basic', label: '기본정보', scrollOffset: 200 },
        { id: 'list', label: '목록설정' },
      ];

      render(<TabNavigationScroll tabs={tabsWithOffset} scrollOffset={120} />);

      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);

      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalledWith(
        {
          params: {
            targetId: 'tab_content_basic',
            offset: 200,
            delay: 100,
          },
        },
        {}
      );
    });

    /**
     * @scenario case=c12
     * @effects tab_navigation_scrolls
     */
    it('탭별로 다른 scrollDelay를 사용해야 함', () => {
      const tabsWithDelay: Tab[] = [
        { id: 'basic', label: '기본정보', scrollDelay: 500 },
        { id: 'list', label: '목록설정' },
      ];

      render(<TabNavigationScroll tabs={tabsWithDelay} scrollDelay={100} />);

      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);

      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalledWith(
        {
          params: {
            targetId: 'tab_content_basic',
            offset: 120,
            delay: 500,
          },
        },
        {}
      );
    });
  });

  describe('Scroll Spy 기능', () => {
    /**
     * @scenario case=c13
     * @effects tab_navigation_scrolls
     */
    it('enableScrollSpy가 false면 IntersectionObserver를 생성하지 않음', () => {
      render(<TabNavigationScroll tabs={mockTabs} enableScrollSpy={false} />);

      // enableScrollSpy가 false이면 observe가 호출되지 않아야 함
      expect(mockObserverInstance.observe).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=c14
     * @effects tab_navigation_scrolls
     */
    it('enableScrollSpy가 true면 IntersectionObserver를 생성함', () => {
      render(<TabNavigationScroll tabs={mockTabs} enableScrollSpy={true} />);

      // enableScrollSpy가 true이면 observe가 호출되어야 함
      expect(mockObserverInstance.observe).toHaveBeenCalled();
    });

    /**
     * @scenario case=c15
     * @effects tab_navigation_scrolls
     */
    it('섹션이 화면에 보이면 해당 탭이 활성화되어야 함', async () => {
      render(
        <TabNavigationScroll
          tabs={mockTabs}
          enableScrollSpy={true}
          activeTabId="basic"
        />
      );

      // IntersectionObserver 콜백 시뮬레이션
      const mockEntry: Partial<IntersectionObserverEntry> = {
        isIntersecting: true,
        target: { id: 'tab_content_list' } as HTMLElement,
        intersectionRatio: 0.5,
      };

      await waitFor(() => {
        observerCallback([mockEntry as IntersectionObserverEntry], {} as any);
      });

      await waitFor(() => {
        const listButton = getTabButton('목록설정');
        expect(listButton).toHaveClass('tab-btn-default-active');
      });
    });

    /**
     * @scenario case=c16
     * @effects tab_navigation_scrolls
     */
    it('숫자형 ID도 올바르게 처리해야 함', async () => {
      const numericTabs: Tab[] = [
        { id: 1, label: '탭1' },
        { id: 2, label: '탭2' },
      ];

      render(
        <TabNavigationScroll
          tabs={numericTabs}
          enableScrollSpy={true}
          activeTabId={1}
        />
      );

      const mockEntry: Partial<IntersectionObserverEntry> = {
        isIntersecting: true,
        target: { id: 'tab_content_2' } as HTMLElement,
        intersectionRatio: 0.5,
      };

      await waitFor(() => {
        observerCallback([mockEntry as IntersectionObserverEntry], {} as any);
      });

      await waitFor(() => {
        const tab2Button = getTabButton('탭2');
        expect(tab2Button).toHaveClass('tab-btn-default-active');
      });
    });

    /**
     * @scenario case=c17
     * @effects tab_navigation_scrolls
     */
    it('존재하지 않는 탭 ID는 무시해야 함', async () => {
      render(
        <TabNavigationScroll
          tabs={mockTabs}
          enableScrollSpy={true}
          activeTabId="basic"
        />
      );

      const mockEntry: Partial<IntersectionObserverEntry> = {
        isIntersecting: true,
        target: { id: 'tab_content_nonexistent' } as HTMLElement,
        intersectionRatio: 0.5,
      };

      await waitFor(() => {
        observerCallback([mockEntry as IntersectionObserverEntry], {} as any);
      });

      // 활성 탭이 변경되지 않아야 함
      const basicButton = getTabButton('기본정보');
      expect(basicButton).toHaveClass('tab-btn-default-active');
    });
  });

  describe('스타일 커스터마이징', () => {
    /**
     * @scenario case=c18
     * @effects tab_navigation_scrolls
     */
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigationScroll tabs={mockTabs} className="custom-class" />
      );

      const div = container.querySelector('div');
      expect(div).toHaveClass('custom-class');
    });

    /**
     * @scenario case=c19
     * @effects tab_navigation_scrolls
     */
    it('style prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigationScroll tabs={mockTabs} style={{ marginTop: '20px' }} />
      );

      const div = container.querySelector('div');
      expect(div).toHaveStyle({ marginTop: '20px' });
    });

    /**
     * @scenario case=c20
     * @effects tab_navigation_scrolls
     */
    it('activeClassName을 커스터마이징할 수 있어야 함', () => {
      render(
        <TabNavigationScroll
          tabs={mockTabs}
          activeTabId="basic"
          activeClassName="custom-active"
        />
      );

      const basicButton = getTabButton('기본정보');
      expect(basicButton).toHaveClass('custom-active');
    });

    /**
     * @scenario case=c21
     * @effects tab_navigation_scrolls
     */
    it('inactiveClassName을 커스터마이징할 수 있어야 함', () => {
      render(
        <TabNavigationScroll
          tabs={mockTabs}
          activeTabId="basic"
          inactiveClassName="custom-inactive"
        />
      );

      const listButton = getTabButton('목록설정');
      expect(listButton).toHaveClass('custom-inactive');
    });
  });

  describe('scrollContainerId 커스터마이징', () => {
    /**
     * @scenario case=c22
     * @effects tab_navigation_scrolls
     */
    it('scrollContainerId가 지정되면 scrollToSectionHandler에 전달되어야 함', () => {
      render(
        <TabNavigationScroll
          tabs={mockTabs}
          scrollContainerId="my-container"
        />
      );

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalledWith(
        {
          params: {
            targetId: 'tab_content_list',
            offset: 120,
            delay: 100,
            scrollContainerId: 'my-container',
          },
        },
        {}
      );
    });

    /**
     * @scenario case=c23
     * @effects tab_navigation_scrolls
     */
    it('scrollContainerId가 없으면 params에 undefined로 전달되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalledWith(
        {
          params: {
            targetId: 'tab_content_list',
            offset: 120,
            delay: 100,
            scrollContainerId: undefined,
          },
        },
        {}
      );
    });

    /**
     * @scenario case=c24
     * @effects tab_navigation_scrolls
     */
    it('scrollContainerId가 지정되면 IntersectionObserver root를 해당 element로 설정해야 함', () => {
      const mockContainer = document.createElement('div');
      mockContainer.id = 'scroll-container';
      document.body.appendChild(mockContainer);

      // scrollContainerId에 해당하는 element를 반환하도록 설정
      global.document.getElementById = vi.fn((id: string) => {
        if (id === 'scroll-container') return mockContainer;
        return { id } as HTMLElement;
      });

      let capturedRoot: Element | Document | null | undefined;
      class MockIntersectionObserverWithRoot implements IntersectionObserver {
        readonly root: Element | Document | null = null;
        readonly rootMargin: string = '';
        readonly thresholds: readonly number[] = [];

        constructor(callback: IntersectionObserverCallback, options?: IntersectionObserverInit) {
          observerCallback = callback;
          capturedRoot = options?.root;
        }

        observe = mockObserverInstance.observe;
        unobserve = mockObserverInstance.unobserve;
        disconnect = mockObserverInstance.disconnect;
        takeRecords(): IntersectionObserverEntry[] {
          return [];
        }
      }

      global.IntersectionObserver = MockIntersectionObserverWithRoot;

      render(
        <TabNavigationScroll
          tabs={mockTabs}
          enableScrollSpy={true}
          scrollContainerId="scroll-container"
        />
      );

      expect(capturedRoot).toBe(mockContainer);

      document.body.removeChild(mockContainer);
    });
  });

  describe('sectionIdPrefix 커스터마이징', () => {
    /**
     * @scenario case=c25
     * @effects tab_navigation_scrolls
     */
    it('sectionIdPrefix를 변경할 수 있어야 함', () => {
      render(
        <TabNavigationScroll
          tabs={mockTabs}
          sectionIdPrefix="custom_section_"
        />
      );

      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);

      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalledWith(
        {
          params: {
            targetId: 'custom_section_basic',
            offset: 120,
            delay: 100,
          },
        },
        {}
      );
    });
  });

  describe('enableScrollSpy + onTabChange 조합', () => {
    /**
     * @scenario case=c26
     * @effects tab_navigation_scrolls
     */
    it('enableScrollSpy=true이면 onTabChange 실행 후에도 스크롤이 실행되어야 함', () => {
      const onTabChange = vi.fn();

      render(
        <TabNavigationScroll
          tabs={mockTabs}
          enableScrollSpy={true}
          onTabChange={onTabChange}
        />
      );

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(onTabChange).toHaveBeenCalledWith('list');
      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalled();
    });

    /**
     * @scenario case=c27
     * @effects tab_navigation_scrolls
     */
    it('enableScrollSpy=false이면 onTabChange 실행 후 스크롤 실행 안 함 (기존 동작)', () => {
      const onTabChange = vi.fn();

      render(
        <TabNavigationScroll
          tabs={mockTabs}
          enableScrollSpy={false}
          onTabChange={onTabChange}
        />
      );

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(onTabChange).toHaveBeenCalledWith('list');
      expect(scrollHandler.scrollToSectionHandler).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=c28
     * @effects tab_navigation_scrolls
     */
    it('enableScrollSpy=true + 개별 onClick이면 onClick 실행 후에도 스크롤이 실행되어야 함', () => {
      const individualOnClick = vi.fn();
      const tabsWithClick: Tab[] = [
        { id: 'basic', label: '기본정보', onClick: individualOnClick },
        { id: 'list', label: '목록설정' },
      ];

      render(
        <TabNavigationScroll
          tabs={tabsWithClick}
          enableScrollSpy={true}
        />
      );

      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);

      expect(individualOnClick).toHaveBeenCalledWith('basic');
      expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalled();
    });
  });

  describe('모바일 (useResponsive isMobile=true)', () => {
    beforeEach(() => {
      setMobile();
    });

    /**
     * @scenario case=c29
     * @effects tab_navigation_scrolls
     */
    it('데스크톱 Nav 버튼 대신 Select 드롭다운만 렌더링해야 함', () => {
      const { container } = render(<TabNavigationScroll tabs={mockTabs} />);

      // 데스크톱 분기의 탭 버튼들이 없어야 함 — Select trigger 1개만 존재
      const buttons = container.querySelectorAll('button');
      expect(buttons.length).toBe(1);
    });

    /**
     * @scenario case=c30
     * @effects tab_navigation_scrolls
     */
    it('현재 활성 탭의 label을 Select trigger에 표시해야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="list" />);

      expect(screen.getByText('목록설정')).toBeInTheDocument();
    });

    /**
     * @scenario case=c31
     * @effects tab_navigation_scrolls
     */
    it('Select trigger 클릭 → 옵션 선택 시 scrollToSectionHandler가 호출되어야 함', () => {
      const { container } = render(<TabNavigationScroll tabs={mockTabs} />);

      // Select trigger 열기
      const trigger = container.querySelector('button');
      fireEvent.click(trigger!);

      // 옵션 클릭 (custom Select dropdown 내부)
      const options = screen.getAllByText('목록설정');
      const optionButton = options
        .map((el) => el.closest('button'))
        .find((btn) => btn !== trigger);

      if (optionButton) {
        fireEvent.click(optionButton);
        expect(scrollHandler.scrollToSectionHandler).toHaveBeenCalled();
      }
    });
  });

  describe('복합 시나리오', () => {
    /**
     * @scenario case=c32
     * @effects tab_navigation_scrolls
     */
    it('개별 설정, 공통 설정, 기본값을 모두 함께 사용할 수 있어야 함', () => {
      const onTabChange = vi.fn();
      const individualOnClick = vi.fn();

      const complexTabs: Tab[] = [
        {
          id: 'basic',
          label: '기본정보',
          onClick: individualOnClick,
          scrollOffset: 200,
        },
        { id: 'list', label: '목록설정', scrollDelay: 300 },
        { id: 'permissions', label: '권한설정', disabled: true },
      ];

      render(
        <TabNavigationScroll
          tabs={complexTabs}
          scrollOffset={120}
          scrollDelay={100}
          onTabChange={onTabChange}
        />
      );

      // 개별 onClick이 있는 탭
      const basicButton = getTabButton('기본정보');
      fireEvent.click(basicButton!);
      expect(individualOnClick).toHaveBeenCalledWith('basic');
      expect(onTabChange).not.toHaveBeenCalled();

      // 공통 onTabChange 사용하는 탭
      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);
      expect(onTabChange).toHaveBeenCalledWith('list');

      // 비활성화된 탭
      const permissionsButton = getTabButton('권한설정');
      expect(permissionsButton).toBeDisabled();
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
     * @scenario case=c33
     * @effects tab_navigation_scrolls
     */
    it('목록은 tablist, 각 항목은 tab 으로 노출된다', () => {
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="list" />);

      expect(screen.getByRole('tablist')).toBeInTheDocument();
      expect(screen.getAllByRole('tab')).toHaveLength(3);
    });

    /**
     * @scenario case=c34
     * @effects tab_navigation_scrolls
     */
    it('활성 탭만 aria-selected=true 이고 Tab 키 초점을 받는다', () => {
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="list" />);

      const tabs = screen.getAllByRole('tab');
      expect(tabs[0]).toHaveAttribute('aria-selected', 'false');
      expect(tabs[1]).toHaveAttribute('aria-selected', 'true');
      expect(tabs[0]).toHaveAttribute('tabindex', '-1');
      expect(tabs[1]).toHaveAttribute('tabindex', '0');
    });

    /**
     * @scenario case=c35
     * @effects tab_navigation_scrolls
     */
    it('좌우 화살표로 이동하며 비활성 탭은 건너뛴다', () => {
      const onTabChange = vi.fn();
      const withDisabled: Tab[] = [
        { id: 'basic', label: '기본정보' },
        { id: 'list', label: '목록설정', disabled: true },
        { id: 'permissions', label: '권한설정' },
      ];
      render(
        <TabNavigationScroll tabs={withDisabled} activeTabId="basic" onTabChange={onTabChange} />
      );

      fireEvent.keyDown(screen.getAllByRole('tab')[0], { key: 'ArrowRight' });
      expect(onTabChange).toHaveBeenLastCalledWith('permissions');
    });

    /**
     * @scenario case=c36
     * @effects tab_navigation_scrolls
     */
    it('Home/End 로 첫/마지막 탭으로 이동한다', () => {
      const onTabChange = vi.fn();
      render(
        <TabNavigationScroll tabs={mockTabs} activeTabId="list" onTabChange={onTabChange} />
      );

      const tabs = screen.getAllByRole('tab');
      fireEvent.keyDown(tabs[1], { key: 'End' });
      expect(onTabChange).toHaveBeenLastCalledWith('permissions');

      fireEvent.keyDown(tabs[1], { key: 'Home' });
      expect(onTabChange).toHaveBeenLastCalledWith('basic');
    });

    /**
     * @scenario case=c37
     * @effects tab_navigation_scrolls
     */
    it('모바일 전환 시에는 tablist/tab 을 두지 않는다', () => {
      setMobile();
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="basic" />);

      // 모바일은 탭 목록이 아니라 단일 선택 UI 이므로 tablist 규약을 적용하지 않는다.
      expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
      expect(screen.queryAllByRole('tab')).toHaveLength(0);
    });
  });

  describe('항목별 표시 조건(if)', () => {
    // 확장이 탭 목록에 끼워 넣는 항목은 본문 카드와 같은 조건(`if`)을 단다 — 조건이 거짓이면 갈 곳이 없으므로
    // 탭도 그리지 않는다(관리자 회원 수정 화면의 「배송국가」·신규 등록 화면의 「활동정보」 빈 탭 회귀).
    const conditionalTabs = [
      { id: 'always', label: '항상 보임' },
      { id: 'hidden', label: '조건 거짓', if: false },
      { id: 'shown', label: '조건 참', if: true },
      // `{{route?.id}}` 같은 식은 거짓일 때 false 가 아니라 undefined 로 평가된다 — 키가 있으면 숨는다
      { id: 'u', label: '조건 미정', if: undefined },
      { id: 's', label: '조건 문자열', if: 'a2ead72d' },
    ] as Tab[];

    /**
     * @scenario case=c38
     * @effects tab_navigation_scrolls
     */
    it('데스크톱: if 가 false 인 탭은 그리지 않고 나머지는 그린다', () => {
      render(<TabNavigationScroll tabs={conditionalTabs} />);

      expect(screen.queryAllByText('조건 거짓')).toHaveLength(0);
      expect(screen.queryAllByText('조건 미정')).toHaveLength(0);
      expect(screen.getAllByText('조건 문자열').length).toBeGreaterThan(0);
      expect(screen.getAllByText('항상 보임').length).toBeGreaterThan(0);
      expect(screen.getAllByText('조건 참').length).toBeGreaterThan(0);
    });

    /**
     * @scenario case=c39
     * @effects tab_navigation_scrolls
     */
    it('모바일 선택 목록에도 if 가 false 인 탭은 없다', () => {
      setMobile();
      const { container } = render(<TabNavigationScroll tabs={conditionalTabs} />);

      const trigger = container.querySelector('button');
      expect(trigger).not.toBeNull();
      fireEvent.click(trigger as HTMLButtonElement);

      expect(screen.queryAllByText('조건 거짓')).toHaveLength(0);
      expect(screen.getAllByText('조건 참').length).toBeGreaterThan(0);
    });
  });
});
