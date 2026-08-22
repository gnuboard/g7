import React from 'react';
import { Nav } from '../basic/Nav';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Select } from '../basic/Select';
import type { EditorAttrs } from '../../types';

export interface Tab {
  id: string | number;
  label: string;
  iconName?: IconName;
  disabled?: boolean;
  badge?: string | number;
}

export interface TabNavigationProps {
  tabs: Tab[];
  activeTabId?: string | number;
  onTabChange?: (tabId: string | number) => void;
  variant?: 'default' | 'pills' | 'underline';
  className?: string;
  style?: React.CSSProperties;
  /** 모바일 전환 임계값 (px). 기본값 768 — G7 ResponsiveContext mobile 프리셋과 동일 */
  mobileBreakpoint?: number;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * TabNavigation 집합 컴포넌트
 *
 * 탭 네비게이션을 제공하는 컴포넌트입니다.
 * 여러 탭을 전환할 수 있으며, 아이콘과 뱃지를 지원합니다.
 *
 * 반응형: G7Core.useResponsive() hook으로 화면 너비를 구독하여
 * mobileBreakpoint(기본 768px) 미만일 때 Select 드롭다운으로 자동 전환됩니다.
 * Tailwind hidden md:flex 분기를 사용하지 않으므로 위지윅 편집기의 디바이스 미리보기와도 호환됩니다.
 *
 * **주의**: 이 컴포넌트는 순수 네비게이션 UI만 제공하며,
 * 실제 탭 컨텐츠는 부모 컴포넌트에서 activeTabId를 기반으로 조건부 렌더링해야 합니다.
 *
 * 접근성: WAI-ARIA Tabs 규약을 따릅니다 — 목록에 `role="tablist"`, 각 탭에 `role="tab"` +
 * `aria-selected` 를 부여하고, 활성 탭만 Tab 키 초점을 받는 roving tabindex 로 좌우 화살표와
 * Home/End 로 탭 사이를 이동합니다(비활성 탭은 건너뜁니다). 탭 패널을 이 컴포넌트가 소유하지
 * 않으므로 `aria-controls` 는 부여하지 않습니다 — 존재하지 않는 id 를 가리키면 보조기기가
 * 패널을 찾지 못해 아예 없는 것보다 나쁩니다. 패널을 렌더하는 부모가 필요 시 연결합니다.
 * 모바일 전환 시에는 native select 로 렌더되어 그 자체로 접근 가능합니다.
 *
 * 기본 컴포넌트 조합: Nav + Button + Icon + Div + Span + Select
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "TabNavigation",
 *   "props": {
 *     "activeTabId": 1,
 *     "tabs": [
 *       {"id": 1, "label": "프로필", "iconName": "user"},
 *       {"id": 2, "label": "설정", "iconName": "cog", "badge": 3}
 *     ]
 *   }
 * }
 */
export const TabNavigation: React.FC<TabNavigationProps> = ({
  tabs,
  activeTabId,
  onTabChange,
  variant = 'default',
  className = '',
  style,
  mobileBreakpoint = 768,
  id,
  editorAttrs,
}) => {
  // G7Core.useResponsive를 통해 반응형 상태 구독 (G7 표준)
  const G7Core = (window as any).G7Core;
  const useResponsive = G7Core?.useResponsive;
  const responsiveValue = useResponsive?.();
  const isMobile = responsiveValue
    ? responsiveValue.width < mobileBreakpoint
    : typeof window !== 'undefined' && window.innerWidth < mobileBreakpoint;

  /** 탭 버튼 DOM 참조 — 화살표 이동 시 초점을 옮기기 위해 보관한다. */
  const tabRefs = React.useRef<Array<HTMLButtonElement | null>>([]);

  const handleTabClick = (tab: Tab) => {
    if (!tab.disabled && tab.id !== activeTabId) {
      onTabChange?.(tab.id);
    }
  };

  /**
   * WAI-ARIA Tabs 키보드 규약 — 좌우 화살표로 탭 이동, Home/End 로 양 끝 이동.
   *
   * 비활성 탭은 건너뛴다. 활성 탭만 Tab 키 초점을 받는 roving tabindex 를 쓰므로,
   * 탭 목록 안에서의 이동은 화살표가 담당한다.
   *
   * @param e 키보드 이벤트
   * @param currentIndex 이벤트가 발생한 탭의 인덱스
   */
  const handleTabKeyDown = (e: React.KeyboardEvent<HTMLButtonElement>, currentIndex: number) => {
    const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

    let nextIndex: number | null = null;

    if (step !== 0) {
      // 비활성 탭을 건너뛰며 순환한다.
      for (let i = 1; i <= tabs.length; i += 1) {
        const candidate = (currentIndex + step * i + tabs.length * i) % tabs.length;
        if (!tabs[candidate]?.disabled) {
          nextIndex = candidate;
          break;
        }
      }
    } else if (e.key === 'Home') {
      nextIndex = tabs.findIndex((tab) => !tab.disabled);
    } else if (e.key === 'End') {
      for (let i = tabs.length - 1; i >= 0; i -= 1) {
        if (!tabs[i]?.disabled) {
          nextIndex = i;
          break;
        }
      }
    }

    if (nextIndex === null || nextIndex < 0) {
      return;
    }

    e.preventDefault();
    tabRefs.current[nextIndex]?.focus();
    handleTabClick(tabs[nextIndex]);
  };

  const handleSelectChange = (
    e: React.ChangeEvent<HTMLSelectElement> | { target: { value: string | number } }
  ) => {
    const selectedId = e.target.value;
    const numericId = Number(selectedId);
    const finalId =
      typeof selectedId === 'string' && selectedId !== '' && !Number.isNaN(numericId)
        ? numericId
        : selectedId;
    const selectedTab = tabs.find((tab) => String(tab.id) === String(finalId));
    if (selectedTab) {
      handleTabClick(selectedTab);
    }
  };

  // 모바일: Select 드롭다운 단일 렌더
  if (isMobile) {
    return (
      <Div className={className} style={style} id={id} {...editorAttrs}>
        <Select
          value={activeTabId !== undefined ? String(activeTabId) : ''}
          onChange={handleSelectChange}
          options={tabs.map((tab) => ({
            value: String(tab.id),
            label: tab.badge !== undefined ? `${tab.label} (${tab.badge})` : tab.label,
            disabled: tab.disabled,
          }))}
          className="w-full"
        />
      </Div>
    );
  }

  const getTabClasses = (tab: Tab) => {
    const isActive = tab.id === activeTabId;

    if (tab.disabled) {
      return 'tab-btn-base tab-btn-disabled';
    }

    switch (variant) {
      case 'pills':
        return isActive ? 'tab-btn-base tab-btn-pills-active' : 'tab-btn-base tab-btn-pills';

      case 'underline':
        return isActive
          ? 'tab-btn-base tab-btn-underline-active'
          : 'tab-btn-base tab-btn-underline';

      case 'default':
      default:
        return isActive
          ? 'tab-btn-base tab-btn-default-active'
          : 'tab-btn-base tab-btn-default';
    }
  };

  const navClasses = variant === 'underline' ? 'tab-nav-underline' : 'tab-nav-default';

  // 레이아웃 편집기 인플레이스 오버레이용 탭별 측정 마커(편집 모드 전용).
  // 탭은 자식 노드가 아니라 `props.tabs` 배열이라 `data-editor-path` 가 없다. 편집기 코어가
  // 각 탭 헤더 박스를 측정해 인플레이스 오버레이(+추가/✕삭제/정렬)에 넘길 수 있도록, 각 탭
  // 버튼에 `data-editor-item-path="<자기 path>.props.tabs.<i>"` 를 부여한다. 런타임에는
  // editorAttrs 가 주입되지 않으므로(undefined) 본 마커도 렌더되지 않아 사용자 페이지 무영향.
  const editorNodePath = editorAttrs?.['data-editor-path'];

  // 데스크톱: 탭 버튼 단일 렌더
  // 활성 탭이 없으면(초기 렌더 등) 첫 활성화 가능 탭이 Tab 키 초점을 받는다 — roving tabindex 에서
  // 초점 받을 탭이 하나도 없으면 탭 목록 전체가 키보드로 진입 불가해진다.
  const activeIndex = tabs.findIndex((tab) => tab.id === activeTabId);
  const focusableIndex = activeIndex >= 0 ? activeIndex : tabs.findIndex((tab) => !tab.disabled);

  return (
    <Nav
      className={`${navClasses} ${className}`}
      style={style}
      role="tablist"
      aria-orientation="horizontal"
      id={id} {...editorAttrs}
    >
      {tabs.map((tab, tabIndex) => (
        <Button
          key={tab.id}
          ref={(el) => {
            tabRefs.current[tabIndex] = el;
          }}
          type="button"
          role="tab"
          aria-selected={tab.id === activeTabId}
          tabIndex={tabIndex === focusableIndex ? 0 : -1}
          onClick={() => handleTabClick(tab)}
          onKeyDown={(e) => handleTabKeyDown(e, tabIndex)}
          disabled={tab.disabled}
          className={getTabClasses(tab)}
          {...(editorNodePath
            ? { 'data-editor-item-path': `${editorNodePath}.props.tabs.${tabIndex}` }
            : {})}
        >
          {tab.iconName && (
            <Icon name={tab.iconName} className="w-4 h-4" />
          )}

          <Span>{tab.label}</Span>

          {tab.badge !== undefined && (
            <Div className="flex items-center justify-center min-w-[20px] h-5 px-1.5 bg-red-500 dark:bg-red-600 text-white dark:text-white text-xs font-bold rounded-full">
              <Span>{tab.badge}</Span>
            </Div>
          )}
        </Button>
      ))}
    </Nav>
  );
};
