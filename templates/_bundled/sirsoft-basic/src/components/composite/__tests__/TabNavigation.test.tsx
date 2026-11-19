// e2e:allow 탭 줄 넘침 회귀 단위 시험 — 768px 브라우저 재측정은 F1(M14).
/**
 * TabNavigation 컴포넌트 테스트
 *
 * @description 탭 네비게이션 컴포넌트의 동작을 테스트합니다.
 * 주요 검증: 탭 렌더링, 탭 전환, 뱃지 표시, hiddenTabIds, disabled, 반응형 분기 (G7Core.useResponsive), 탭 줄 가로 넘침
 *
 * @scenario-manifest tests/scenarios/tab-navigation.yaml
 */

import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { TabNavigation, type Tab } from '../TabNavigation';

const mockUseResponsive = vi.fn(() => ({
  width: 1024,
  isMobile: false,
  isTablet: false,
  isDesktop: true,
  matchedPreset: 'desktop' as 'desktop' | 'mobile',
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
    // @scenario case=desktop_render
    // @effects tabs_rendered
    it('Nav 안의 버튼으로 모든 탭이 렌더링되어야 함', () => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" />
      );

      expect(container.querySelector('nav')).toBeInTheDocument();
      expect(screen.getByText('상세정보')).toBeInTheDocument();
      expect(screen.getByText('리뷰')).toBeInTheDocument();
      expect(screen.getByText('문의')).toBeInTheDocument();
    });

    // @scenario case=desktop_render
    // @effects desktop_has_no_select
    it('Select 드롭다운은 렌더링되지 않아야 함', () => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" />
      );

      const selects = container.querySelectorAll('select');
      expect(selects.length).toBe(0);
    });

    // @scenario case=desktop_render
    // @effects active_tab_styled
    it('활성 탭이 활성화 스타일을 가져야 함', () => {
      render(
        <TabNavigation tabs={defaultTabs} activeTabId="reviews" variant="underline" />
      );

      const activeButton = screen.getByText('리뷰').closest('button');
      expect(activeButton).toHaveClass('border-b-2');
    });

    /**
     * 탭이 많아 화면보다 넓으면 탭 줄만 가로로 스크롤되어야 한다 — 탭은 줄어들지 않고(`shrink-0`·`whitespace-nowrap`)
     * 넘침 처리가 없어, 768px 마이페이지(탭 9개)에서 페이지 전체가 가로로 밀렸다(ko 824·en 910·ja 1043px).
     */
    // @scenario case=overflow
    // @effects tab_row_scrolls_horizontally
    it.each(['underline', 'default', 'pills'] as const)('탭 줄이 넘치면 nav 가 가로 스크롤 영역이다 (%s)', (variant) => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" variant={variant} />
      );

      const nav = container.querySelector('nav');
      expect(nav).toHaveClass('overflow-x-auto');
      expect(screen.getByText('상세정보').closest('button')).toHaveClass('shrink-0');
    });

    /**
     * 가로 스크롤이 생긴 탭 줄에서 오른쪽 끝 탭이 활성이면 처음(scrollLeft 0)에 그 탭이 보이지 않았다(768px en·ja 실측).
     * 마운트·활성 변경 때 활성 탭이 보이도록 탭 줄의 가로 위치만 옮긴다 — 페이지 세로 스크롤은 건드리지 않는다.
     */
    describe('활성 탭을 탭 줄 안에 보이게', () => {
      let rectSpy: ReturnType<typeof vi.spyOn>;
      const scrollLefts = new WeakMap<Element, number>();
      const original = Object.getOwnPropertyDescriptor(Element.prototype, 'scrollLeft');

      beforeEach(() => {
        // jsdom 은 배치를 계산하지 않는다 — 탭 줄 폭 300, 탭마다 120 폭으로 늘어선 것처럼 꾸민다(스크롤 위치만큼 왼쪽으로 밀린다)
        Object.defineProperty(Element.prototype, 'scrollLeft', {
          configurable: true,
          get() { return scrollLefts.get(this) ?? 0; },
          set(value: number) { scrollLefts.set(this, value); },
        });
        rectSpy = vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
          const nav = this.closest('nav');
          if (this.tagName === 'NAV') {
            return { left: 0, right: 300, top: 0, bottom: 40, width: 300, height: 40, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
          }
          const index = nav ? Array.from(nav.children).indexOf(this) : -1;
          const left = index * 120 - (nav ? (scrollLefts.get(nav) ?? 0) : 0);
          return { left, right: left + 120, top: 0, bottom: 40, width: 120, height: 40, x: left, y: 0, toJSON: () => ({}) } as DOMRect;
        });
      });

      afterEach(() => {
        rectSpy.mockRestore();
        if (original) Object.defineProperty(Element.prototype, 'scrollLeft', original);
      });

      // @scenario case=active_visible
      // @effects active_tab_scrolled_into_view
      it('끝 탭이 활성이면 마운트 때 그 탭이 보이도록 탭 줄을 가로로 옮긴다', () => {
        const { container } = render(<TabNavigation tabs={defaultTabs} activeTabId="qna" />);

        // 세 번째 탭(240~360)이 300 폭 안에 들어오려면 60 만큼 민다
        expect(container.querySelector('nav')!.scrollLeft).toBe(60);
      });

      // @scenario case=active_visible
      // @effects active_tab_scrolled_into_view
      it('활성 탭이 바뀌면 그 탭이 보이도록 옮기고, 이미 보이는 탭이면 그대로 둔다', () => {
        const { container, rerender } = render(<TabNavigation tabs={defaultTabs} activeTabId="detail" />);
        const nav = container.querySelector('nav')!;
        expect(nav.scrollLeft).toBe(0);

        rerender(<TabNavigation tabs={defaultTabs} activeTabId="qna" />);
        expect(nav.scrollLeft).toBe(60);

        // 두 번째 탭(120~240, 지금 60~180)은 이미 보인다 — 움직이지 않는다
        rerender(<TabNavigation tabs={defaultTabs} activeTabId="reviews" />);
        expect(nav.scrollLeft).toBe(60);

        // 첫 탭(지금 -60~60)은 왼쪽으로 잘렸다 — 0 으로 돌아간다
        rerender(<TabNavigation tabs={defaultTabs} activeTabId="detail" />);
        expect(nav.scrollLeft).toBe(0);
      });
    });
  });

  describe('뱃지(badge)', () => {
    // @scenario case=badge
    // @effects badge_shown
    it('뱃지가 표시되어야 함', () => {
      render(<TabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByText('12')).toBeInTheDocument();
      expect(screen.getByText('3')).toBeInTheDocument();
    });

    // @scenario case=badge
    // @effects badge_shown
    it('badge 값이 0일 때도 표시되어야 함', () => {
      const tabs: Tab[] = [{ id: 'qna', label: '문의', badge: 0 }];
      render(<TabNavigation tabs={tabs} activeTabId="qna" />);

      expect(screen.getByText('0')).toBeInTheDocument();
    });
  });

  describe('탭 전환', () => {
    // @scenario case=tab_change
    // @effects tab_change_called
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

    // @scenario case=tab_change
    // @effects tab_change_called
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
    // @scenario case=disabled
    // @effects disabled_tab_inert
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

    // @scenario case=disabled
    // @effects disabled_tab_inert
    it('disabled 탭은 disabled 속성이 있어야 함', () => {
      const tabs: Tab[] = [{ id: 'reviews', label: '리뷰', disabled: true }];
      render(<TabNavigation tabs={tabs} activeTabId="detail" />);

      expect(screen.getByText('리뷰').closest('button')).toBeDisabled();
    });
  });

  describe('hiddenTabIds', () => {
    // @scenario case=hidden_tabs
    // @effects hidden_tabs_omitted
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

    // @scenario case=hidden_tabs
    // @effects hidden_tabs_omitted
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

    // @scenario case=mobile_select
    // @effects mobile_renders_select
    it('Nav 대신 Select 드롭다운만 렌더링해야 함', () => {
      const { container } = render(
        <TabNavigation tabs={defaultTabs} activeTabId="detail" />
      );

      expect(container.querySelector('nav')).not.toBeInTheDocument();
      // custom Select trigger button이 존재해야 함
      const trigger = container.querySelector('button');
      expect(trigger).toBeInTheDocument();
    });

    // 회귀: 모바일 분기 루트에도 id/editorAttrs 가
    // spread 되어야 레이아웃 편집기가 모바일 디바이스 미리보기에서 이 노드를 선택/측정할 수
    // 있다. 종전 모바일 분기는 데스크톱 분기와 달리 id/editorAttrs 누락 → 모바일 프리뷰에서
    // data-editor-* 가 사라져 캔버스 선택 불가(모든 렌더 분기 editorAttrs 패리티 규율).
    // @scenario case=mobile_select
    // @effects mobile_root_keeps_editor_attrs
    it('모바일 분기 루트에도 id/editorAttrs(data-editor-*)가 spread 되어야 함', () => {
      const { container } = render(
        <TabNavigation
          tabs={defaultTabs}
          activeTabId="detail"
          id="mypageTabNavigation"
          editorAttrs={{
            'data-editor-path': '2.children.1',
            'data-editor-name': 'TabNavigation',
            'data-editor-type': 'composite',
          } as any}
        />
      );
      const root = container.firstElementChild as HTMLElement;
      expect(root).toBeTruthy();
      expect(root.id).toBe('mypageTabNavigation');
      expect(root.getAttribute('data-editor-path')).toBe('2.children.1');
      expect(root.getAttribute('data-editor-name')).toBe('TabNavigation');
    });

    // @scenario case=mobile_select
    // @effects mobile_renders_select
    it('현재 활성 탭의 label을 Select trigger에 표시해야 함', () => {
      render(<TabNavigation tabs={defaultTabs} activeTabId="detail" />);

      expect(screen.getByText('상세정보')).toBeInTheDocument();
    });

    // @scenario case=mobile_select
    // @effects mobile_renders_select
    it('뱃지가 있는 탭은 label과 함께 뱃지 수를 병기해야 함', () => {
      render(<TabNavigation tabs={defaultTabs} activeTabId="reviews" />);

      expect(screen.getByText('리뷰 (12)')).toBeInTheDocument();
    });

    // @scenario case=mobile_select
    // @effects hidden_tabs_omitted
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
