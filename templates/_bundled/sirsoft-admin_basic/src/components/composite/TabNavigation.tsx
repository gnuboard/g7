import React from 'react';
import { Nav } from '../basic/Nav';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Select } from '../basic/Select';

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

  // 모바일: Select 드롭다운 단일 렌더
  if (isMobile) {
    return (
      <Div className={className} style={style}>
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
    const baseClasses = 'flex items-center gap-2 px-4 py-2 font-medium text-sm transition-all';

    if (tab.disabled) {
      return `${baseClasses} opacity-50 cursor-not-allowed text-gray-400 dark:text-gray-600`;
    }

    switch (variant) {
      case 'pills':
        return isActive
          ? `${baseClasses} bg-blue-600 dark:bg-blue-500 text-white rounded-lg`
          : `${baseClasses} text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg`;

      case 'underline':
        return isActive
          ? `${baseClasses} text-blue-600 dark:text-blue-400 border-b-2 border-blue-600 dark:border-blue-400`
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

  // 데스크톱: 탭 버튼 단일 렌더
  return (
    <Nav
      className={`${navClasses} ${className}`}
      style={style}
    >
      {tabs.map((tab) => (
        <Button
          key={tab.id}
          type="button"
          onClick={() => handleTabClick(tab)}
          disabled={tab.disabled}
          className={getTabClasses(tab)}
        >
          {tab.iconName && (
            <Icon name={tab.iconName} className="w-4 h-4" />
          )}

          <Span>{tab.label}</Span>

          {tab.badge !== undefined && (
            <Div className="flex items-center justify-center min-w-[20px] h-5 px-1.5 bg-red-500 text-white text-xs font-bold rounded-full">
              <Span>{tab.badge}</Span>
            </Div>
          )}
        </Button>
      ))}
    </Nav>
  );
};
