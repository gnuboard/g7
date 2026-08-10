import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
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
export declare const TabNavigation: React.FC<TabNavigationProps>;
