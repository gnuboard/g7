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
   * 단일 분기 렌더(useResponsive 기반)이므로 데스크톱 모드에서는 Nav 안의 버튼만 존재합니다.
   */
  const getTabButton = (text: string): HTMLButtonElement | null => {
    const buttons = screen.getAllByRole('button');
    return buttons.find((btn) => btn.textContent === text) as HTMLButtonElement | null;
  };

  describe('기본 렌더링', () => {
    it('모든 탭을 렌더링해야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      // 데스크톱 + 모바일(select option)에서 각각 표시되므로 getAllByText 사용
      expect(screen.getAllByText('기본정보').length).toBeGreaterThan(0);
      expect(screen.getAllByText('목록설정').length).toBeGreaterThan(0);
      expect(screen.getAllByText('권한설정').length).toBeGreaterThan(0);
    });

    it('div 요소로 렌더링되어야 함', () => {
      const { container } = render(<TabNavigationScroll tabs={mockTabs} />);

      const div = container.querySelector('div');
      expect(div).toBeInTheDocument();
    });

    it('첫 번째 탭이 기본 활성화되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      const basicButton = getTabButton('기본정보');
      expect(basicButton).toHaveClass('text-blue-600');
    });

    it('activeTabId가 설정된 탭이 활성화되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="list" />);

      const listButton = getTabButton('목록설정');
      expect(listButton).toHaveClass('text-blue-600');
    });
  });

  describe('탭 클릭 이벤트', () => {
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

    it('탭 클릭 시 활성 탭이 변경되어야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} />);

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(listButton).toHaveClass('text-blue-600');
    });

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
    it('onTabChange가 있으면 실행하고 기본 동작 실행 안 함', () => {
      const onTabChange = vi.fn();

      render(<TabNavigationScroll tabs={mockTabs} onTabChange={onTabChange} />);

      const listButton = getTabButton('목록설정');
      fireEvent.click(listButton!);

      expect(onTabChange).toHaveBeenCalledWith('list');
      expect(scrollHandler.scrollToSectionHandler).not.toHaveBeenCalled();
    });

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
    it('enableScrollSpy가 false면 IntersectionObserver를 생성하지 않음', () => {
      render(<TabNavigationScroll tabs={mockTabs} enableScrollSpy={false} />);

      // enableScrollSpy가 false이면 observe가 호출되지 않아야 함
      expect(mockObserverInstance.observe).not.toHaveBeenCalled();
    });

    it('enableScrollSpy가 true면 IntersectionObserver를 생성함', () => {
      render(<TabNavigationScroll tabs={mockTabs} enableScrollSpy={true} />);

      // enableScrollSpy가 true이면 observe가 호출되어야 함
      expect(mockObserverInstance.observe).toHaveBeenCalled();
    });

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
        expect(listButton).toHaveClass('text-blue-600');
      });
    });

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
        expect(tab2Button).toHaveClass('text-blue-600');
      });
    });

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
      expect(basicButton).toHaveClass('text-blue-600');
    });
  });

  describe('스타일 커스터마이징', () => {
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigationScroll tabs={mockTabs} className="custom-class" />
      );

      const div = container.querySelector('div');
      expect(div).toHaveClass('custom-class');
    });

    it('style prop을 적용해야 함', () => {
      const { container } = render(
        <TabNavigationScroll tabs={mockTabs} style={{ marginTop: '20px' }} />
      );

      const div = container.querySelector('div');
      expect(div).toHaveStyle({ marginTop: '20px' });
    });

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

    it('데스크톱 Nav 버튼 대신 Select 드롭다운만 렌더링해야 함', () => {
      const { container } = render(<TabNavigationScroll tabs={mockTabs} />);

      // 데스크톱 분기의 탭 버튼들이 없어야 함 — Select trigger 1개만 존재
      const buttons = container.querySelectorAll('button');
      expect(buttons.length).toBe(1);
    });

    it('현재 활성 탭의 label을 Select trigger에 표시해야 함', () => {
      render(<TabNavigationScroll tabs={mockTabs} activeTabId="list" />);

      expect(screen.getByText('목록설정')).toBeInTheDocument();
    });

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
});