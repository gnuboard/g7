import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
/**
 * 탭 정의 인터페이스
 */
export interface Tab {
    id: string | number;
    label: string;
    /** 탭 아이콘 (Font Awesome 아이콘명) */
    iconName?: IconName;
    disabled?: boolean;
    /** 개별 탭의 스크롤 오프셋 (우선순위: 개별 > 공통 > 기본값) */
    scrollOffset?: number;
    /** 개별 탭의 스크롤 딜레이 (우선순위: 개별 > 공통 > 기본값) */
    scrollDelay?: number;
    /** 개별 탭 클릭 시 실행될 콜백 (우선순위: 개별 > 공통 > 기본) */
    onClick?: (tabId: string | number) => void;
}
/**
 * TabNavigationScroll Props
 */
export interface TabNavigationScrollProps {
    /** 탭 목록 */
    tabs: Tab[];
    /** 활성 탭 ID (초기값으로만 사용) */
    activeTabId?: string | number;
    /** 컨테이너 className */
    className?: string;
    /** 컨테이너 스타일 */
    style?: React.CSSProperties;
    /** Active 탭 className */
    activeClassName?: string;
    /** Inactive 탭 className */
    inactiveClassName?: string;
    /** 섹션 ID 접두사 */
    sectionIdPrefix?: string;
    /** 스크롤 오프셋 */
    scrollOffset?: number;
    /** 스크롤 딜레이 */
    scrollDelay?: number;
    /** 탭 변경 시 실행될 공통 콜백 (우선순위: 개별 탭 onClick > onTabChange > 기본) */
    onTabChange?: (tabId: string | number) => void;
    /** Scroll Spy 활성화 여부 (스크롤 시 자동으로 탭 활성화) */
    enableScrollSpy?: boolean;
    /** Scroll Spy 감지 오프셋 (기본값: 80) */
    scrollSpyOffset?: number;
    /** 스크롤 컨테이너 ID (IntersectionObserver root 및 scrollToSection 대상, 미지정 시 window) */
    scrollContainerId?: string;
}
/**
 * 탭 네비게이션 컴포넌트 (자동 스크롤 기능 내장)
 *
 * 특징:
 * - 내부 상태로 활성 탭 관리 (React useState)
 * - scrollToSectionHandler를 사용하여 자동 스크롤
 * - 탭 클릭 시 즉시 UI 업데이트 + 스크롤 이동
 *
 * @example
 * ```json
 * {
 *   "type": "composite",
 *   "name": "TabNavigationScroll",
 *   "props": {
 *     "tabs": [
 *       {"id": "basic", "label": "$t:admin.tabs.basic"},
 *       {"id": "permissions", "label": "$t:admin.tabs.permissions"}
 *     ]
 *   }
 * }
 * ```
 */
export declare const TabNavigationScroll: React.FC<TabNavigationScrollProps>;
