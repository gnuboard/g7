import React, { useState, useEffect } from 'react';
import { Nav } from '../basic/Nav';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';

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
  /** 모바일에서 그리드 레이아웃 사용 (기본: true) */
  mobileGrid?: boolean;
  /** 모바일 그리드 컬럼 수 (기본: 3) */
  mobileColumns?: number;
  /** 숨길 탭 ID 목록 (동적 조건부 탭 노출용) */
  hiddenTabIds?: (string | number)[];
}

/**
 * TabNavigation 집합 컴포넌트
 *
 * 탭 네비게이션을 제공하는 컴포넌트입니다.
 * 여러 탭을 전환할 수 있으며, 아이콘과 뱃지를 지원합니다.
 * 모바일에서는 그리드 레이아웃으로 표시합니다.
 *
 * **주의**: 이 컴포넌트는 순수 네비게이션 UI만 제공하며,
 * 실제 탭 컨텐츠는 부모 컴포넌트에서 activeTabId를 기반으로 조건부 렌더링해야 합니다.
 *
 * 기본 컴포넌트 조합: Nav + Button + Icon + Div + Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "TabNavigation",
 *   "props": {
 *     "activeTabId": 1,
 *     "mobileColumns": 3,
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
  mobileGrid = true,
  mobileColumns = 3,
  hiddenTabIds = [],
}) => {
  const [isMobile, setIsMobile] = useState(false);

  // 모바일 감지 (768px 기준)
  useEffect(() => {
    const checkMobile = () => {
      setIsMobile(window.innerWidth < 768);
    };

    checkMobile();
    window.addEventListener('resize', checkMobile);
    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  const handleTabClick = (tab: Tab) => {
    if (!tab.disabled && tab.id !== activeTabId) {
      onTabChange?.(tab.id);
    }
  };

  const getTabClasses = (tab: Tab) => {
    const isActive = tab.id === activeTabId;
    const baseClasses = isMobile && mobileGrid
      ? 'flex flex-col items-center justify-center gap-3 pt-4 pb-2 font-medium text-sm transition-all'
      : 'flex items-center gap-2 px-3 py-2 font-medium text-sm transition-all shrink-0 whitespace-nowrap';

    if (tab.disabled) {
      return `${baseClasses} opacity-50 cursor-not-allowed text-gray-400 dark:text-gray-600`;
    }

    // 모바일 그리드 스타일
    if (isMobile && mobileGrid) {
      return isActive
        ? `${baseClasses} text-gray-900 dark:text-white bg-gray-100 dark:bg-gray-700 rounded-lg`
        : `${baseClasses} text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg`;
    }

    switch (variant) {
      case 'pills':
        return isActive
          ? `${baseClasses} bg-blue-600 dark:bg-blue-500 text-white rounded-lg`
          : `${baseClasses} text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg`;

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

  // 모바일 그리드 컬럼 클래스
  const getGridColumnsClass = () => {
    switch (mobileColumns) {
      case 2:
        return 'grid-cols-2';
      case 4:
        return 'grid-cols-4';
      case 3:
      default:
        return 'grid-cols-3';
    }
  };

  const navClasses = isMobile && mobileGrid
    ? `grid ${getGridColumnsClass()} gap-1 p-1 bg-gray-50 dark:bg-gray-800 rounded-lg`
    : variant === 'underline'
      ? 'flex gap-0 border-b border-gray-200 dark:border-gray-700'
      : 'flex gap-2';

  const renderTabButton = (tab: Tab) => (
    <Button
      key={tab.id}
      type="button"
      onClick={() => handleTabClick(tab)}
      disabled={tab.disabled}
      className={getTabClasses(tab)}
    >
      {tab.iconName && (
        <Icon name={tab.iconName} size="sm"/>
      )}

      {isMobile && mobileGrid && tab.badge !== undefined ? (
        <Div className="flex items-center gap-1">
          <Span className="text-xs">{tab.label}</Span>
          <Div className="flex items-center justify-center min-w-5 h-5 px-1.5 bg-red-500 text-white text-xs font-bold rounded-full">
            <Span>{tab.badge}</Span>
          </Div>
        </Div>
      ) : (
        <>
          <Span className={isMobile && mobileGrid ? 'text-xs' : ''}>{tab.label}</Span>
          {tab.badge !== undefined && (
            <Div className="flex items-center justify-center min-w-5 h-5 px-1.5 bg-red-500 text-white text-xs font-bold rounded-full">
              <Span>{tab.badge}</Span>
            </Div>
          )}
        </>
      )}
    </Button>
  );

  const visibleTabs = hiddenTabIds.length > 0
    ? tabs.filter((tab) => !hiddenTabIds.includes(tab.id))
    : tabs;

  return (
    <Div className={`relative ${className}`} style={style}>
      <Nav className={navClasses}>
        {visibleTabs.map((tab) => renderTabButton(tab))}
      </Nav>
    </Div>
  );
};
