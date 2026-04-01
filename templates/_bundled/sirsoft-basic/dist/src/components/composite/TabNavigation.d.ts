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
export declare const TabNavigation: React.FC<TabNavigationProps>;
