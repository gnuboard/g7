import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
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
export declare const TabNavigation: React.FC<TabNavigationProps>;
