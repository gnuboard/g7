import React from 'react';
import { Nav } from '../basic/Nav';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Select } from '../basic/Select';
import type { EditorAttrs } from '../../types';
import { handleLinkClick, mergeQueryIntoHref, resolveLinkHref } from '../../support/navigationLink';

export interface Tab {
  id: string | number;
  label: string;
  iconName?: IconName;
  disabled?: boolean;
  badge?: string | number;
  /**
   * 탭이 가리키는 주소 — 주면 `<a role="tab" href>` 로 그려 가운데 클릭·Ctrl+클릭으로 새 탭에서 열 수 있다.
   * 보통 클릭은 지금처럼 탭 전환(onTabChange)이다(#757).
   */
  href?: string;
}

export interface TabNavigationProps {
  tabs: Tab[];
  activeTabId?: string | number;
  onTabChange?: (tabId: string | number) => void;
  variant?: 'default' | 'pills' | 'underline';
  className?: string;
  style?: React.CSSProperties;
  /** 숨길 탭 ID 목록 (동적 조건부 탭 노출용) */
  hiddenTabIds?: (string | number)[];
  /** 모바일 전환 임계값 (px). 기본값 768 — G7 ResponsiveContext mobile 프리셋과 동일 */
  mobileBreakpoint?: number;
  /**
   * 탭 주소(`tabs[].href`)에 현재 주소의 쿼리를 합친다(탭 주소 자신의 키가 이긴다).
   * 같은 화면 안에서 쿼리만 바꾸는 탭이 목록 문맥(쪽·정렬·검색어)을 이고 갈 때 — 보통 클릭과 새 탭이 같은 주소가 된다.
   */
  tabHrefMergeQuery?: boolean;
    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
/**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
   */
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
 *       {"id": 2, "label": "설정", "iconName": "cog", "badge": 3},
 *       {"id": 3, "label": "문의내역", "iconName": "comment"}
 *     ],
 *     "hiddenTabIds": "{{_global.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug ? [] : ['inquiries']}}"
 *   }
 * }
 */
export const TabNavigation: React.FC<TabNavigationProps> = ({
  tabs,
  activeTabId,
  onTabChange,
  variant = 'underline',
  className = '',
  style,
  hiddenTabIds = [],
  mobileBreakpoint = 768,
  tabHrefMergeQuery = false,
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

  const handleTabClick = (tab: Tab) => {
    if (!tab.disabled && tab.id !== activeTabId) {
      onTabChange?.(tab.id);
    }
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

  const visibleTabs = hiddenTabIds.length > 0
    ? tabs.filter((tab) => !hiddenTabIds.includes(tab.id))
    : tabs;

  // 모바일: Select 드롭다운 단일 렌더
  // 데스크톱 분기와 동일하게 id/editorAttrs 를 루트에 spread 해야 레이아웃 편집기가
  // 모바일 디바이스 미리보기에서도 이 노드를 선택/측정할 수 있다(모든 렌더 분기 패리티 —
  // 누락 시 모바일 프리뷰에서 data-editor-* 가 사라져 캔버스에서 선택 불가).
  if (isMobile) {
    return (
      <Div className={`relative ${className}`} style={style} id={id} {...editorAttrs}>
        <Select
          value={activeTabId !== undefined ? String(activeTabId) : ''}
          onChange={handleSelectChange}
          options={visibleTabs.map((tab) => ({
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
    const baseClasses =
      'flex items-center gap-2 px-3 py-2 font-medium text-sm transition-all shrink-0 whitespace-nowrap';

    if (tab.disabled) {
      return `${baseClasses} opacity-50 cursor-not-allowed text-gray-400 dark:text-gray-600`;
    }

    switch (variant) {
      case 'pills':
        return isActive
          ? `${baseClasses} bg-blue-600 dark:bg-blue-600 text-white rounded-lg`
          : `${baseClasses} text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg`;

      case 'underline':
        return isActive
          ? `${baseClasses} text-gray-900 dark:text-white border-b-2 border-gray-900 dark:border-white`
          : `${baseClasses} text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 border-b-2 border-transparent hover:border-gray-300 dark:hover:border-gray-600`;

      case 'default':
      default:
        return isActive
          ? `${baseClasses} text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 border-b-2 border-blue-600 dark:border-blue-400`
          : `${baseClasses} text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 border-b-2 border-transparent`;
    }
  };

  const navClasses =
    variant === 'underline'
      ? 'flex gap-0 border-b border-gray-200 dark:border-gray-700'
      : 'flex gap-2';

  /** 탭 링크 주소 — `tabHrefMergeQuery` 면 현재 쿼리를 합친다 */
  const tabLink = (tab: Tab) =>
    resolveLinkHref(tabHrefMergeQuery && typeof tab.href === 'string' && tab.href !== '' ? mergeQueryIntoHref(tab.href) : tab.href);

  // 주소를 가진 탭이 하나라도 있으면 탭 목록 역할을 단다 — 링크 탭은 `role="tab"`·`aria-selected` 로 읽힌다
  const hasLinkTabs = visibleTabs.some((tab) => !tab.disabled && tabLink(tab)?.external === false);

  // 데스크톱: 탭 버튼 단일 렌더
  return (
    <Div className={`relative ${className}`} style={style} id={id} {...editorAttrs}>
      <Nav className={navClasses} role={hasLinkTabs ? 'tablist' : undefined}>
        {visibleTabs.map((tab) => {
          const tabContent = (
            <>
              {tab.iconName && (
                <Icon name={tab.iconName} size="sm" />
              )}

              <Span>{tab.label}</Span>

              {tab.badge !== undefined && (
                <Div className="flex items-center justify-center min-w-5 h-5 px-1.5 bg-red-600 dark:bg-red-600 text-white dark:text-white text-xs font-bold rounded-full">
                  <Span>{tab.badge}</Span>
                </Div>
              )}
            </>
          );
          const link = tab.disabled ? null : tabLink(tab);
          if (link && !link.external) {
            return (
              <a
                key={tab.id}
                href={link.href}
                role="tab"
                aria-selected={tab.id === activeTabId}
                onClick={(event) => handleLinkClick(event, () => handleTabClick(tab))}
                className={getTabClasses(tab)}
              >
                {tabContent}
              </a>
            );
          }

          return (
            <Button
              key={tab.id}
              type="button"
              onClick={() => handleTabClick(tab)}
              disabled={tab.disabled}
              className={getTabClasses(tab)}
              role={hasLinkTabs ? 'tab' : undefined}
              aria-selected={hasLinkTabs ? tab.id === activeTabId : undefined}
            >
              {tabContent}
            </Button>
          );
        })}
      </Nav>
    </Div>
  );
};
