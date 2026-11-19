import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
/**
 * 탭 정의 인터페이스
 */
export interface Tab {
    id: string | number;
    label: string;
    /** 탭 아이콘 (Font Awesome 아이콘명) */
    iconName?: IconName;
    disabled?: boolean;
    /**
     * 표시 조건. 레이아웃의 `if="{{...}}"` 가 평가된 값이며, 키가 있고 값이 거짓(false·undefined·null·빈 문자열·0)이면
     * 탭을 그리지 않는다 — 노드의 `if` 와 같은 뜻이다(`{{route?.id}}` 처럼 거짓이 undefined 로 오는 식도 숨는다).
     * 확장이 끼워 넣는 탭은 이동할 본문 카드와 같은 조건을 달아, 카드가 없을 때 갈 곳 없는 빈 탭이 남지 않게 한다.
     */
    if?: unknown;
    /** 개별 탭의 스크롤 오프셋 (우선순위: 개별 > 공통 > 기본값) */
    scrollOffset?: number;
    /** 개별 탭의 스크롤 딜레이 (우선순위: 개별 > 공통 > 기본값) */
    scrollDelay?: number;
    /** 개별 탭 클릭 시 실행될 콜백 (우선순위: 개별 > 공통 > 기본) */
    onClick?: (tabId: string | number) => void;
    /**
     * 탭이 가리키는 주소 — 주면 `<a role="tab" href>` 로 그려 가운데 클릭·Ctrl+클릭으로 새 탭에서 열 수 있다.
     * 보통 클릭은 지금처럼 탭 전환(onTabChange — 저장 안 한 변경 확인 흐름 유지)(#757).
     */
    href?: string;
    /**
     * 탭 이름 옆에 빨간 점을 붙일지 — 그 탭이 가리키는 구획에 확인할 것(검증 오류가 난 칸 등)이 있음을 알린다.
     * 레이아웃의 `errorDot="{{...}}"` 가 평가된 값이다. 점의 읽기 이름은 컴포넌트의 `errorDotLabel` 이 준다.
     */
    errorDot?: boolean | string;
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
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
    /**
     * 좁은 화면에서 탭 대신 그리는 선택 상자의 이름(「{이름} {현재 탭}」으로 읽힌다).
     * 없으면 공용 문구 「섹션 이동」 — 종전에는 이름이 없어 현재 탭 글자(「기본정보」)만 읽혔다(#757)
     */
    'aria-label'?: string;
    /** 탭의 빨간 점(`errorDot`)을 보조기기가 읽을 이름 (예: 「오류가 있는 칸이 있습니다」). 없으면 공용 문구 */
    errorDotLabel?: string;
}
export declare const TabNavigationScroll: React.FC<TabNavigationScrollProps>;
