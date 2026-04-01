import React from 'react';
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
}

/**
 * TabNavigation 집합 컴포넌트
 *
 * 탭 네비게이션을 제공하는 컴포넌트입니다.
 * 여러 탭을 전환할 수 있으며, 아이콘과 뱃지를 지원합니다.
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
 *     "tabs": [
 *       {"id": 1, "label": "프로필", "iconName": "user"},
 *       {"id": 2, "label": "설정", "iconName": "cog", "badge": 3}
 *     ]
 *   }
 * }
 *
 * // 부모 컴포넌트에서 컨텐츠 렌더링 예시:
 * const [activeTab, setActiveTab] = useState(1);
 * return (
 *   <>
 *     <TabNavigation tabs={tabs} activeTabId={activeTab} onTabChange={setActiveTab} />
 *     {activeTab === 1 && <ProfileContent />}
 *     {activeTab === 2 && <SettingsContent />}
 *   </>
 * );
 */
export const TabNavigation: React.FC<TabNavigationProps> = ({
  tabs,
  activeTabId,
  onTabChange,
  variant = 'default',
  className = '',
  style,
}) => {
  const handleTabClick = (tab: Tab) => {
    if (!tab.disabled && tab.id !== activeTabId) {
      onTabChange?.(tab.id);
    }
  };

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
