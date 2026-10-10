import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { ActionMenuItem } from './ActionMenu';
import { EditorAttrs } from '../../types';
/** 보기 방식 — 갤러리(카드)와 목록(행)은 서로 다른 DOM 을 그린다. */
export type ExtensionViewMode = 'gallery' | 'list';
/** 확장 의존성 한 건. */
export interface ExtensionDependency {
    type?: string;
    name?: string;
}
/** 확장 배지 한 건 — 상태·호환성처럼 이름 옆에 붙는 표식. */
export interface ExtensionBadgeSpec {
    label: string;
    tone?: 'neutral' | 'success' | 'info' | 'warning' | 'danger' | 'accent';
    title?: string;
    srText?: string;
    icon?: IconName;
}
/** 확장 카드 하나가 그리는 데이터. 레이아웃이 아니라 이 컴포넌트가 형태를 정한다. */
export interface ExtensionCardItem {
    id: string;
    /** 원본 행 — 콜백이 그대로 되돌려 준다. 레이아웃이 다시 찾아 오지 않게 한다. */
    row?: any;
    name: string;
    version?: string;
    description?: string;
    vendor?: string;
    iconUrl?: string;
    fallbackIcon?: IconName;
    /**
     * 아이콘 오른쪽 아래에 얹는 짧은 표식(언어팩의 로케일). 언어팩은 대개 자기 그림이 없어
     * 대상 확장·코어의 아이콘을 빌려 쓰므로, 무엇의 어떤 언어인지 이 표식으로 구분한다.
     */
    iconBadge?: {
        text: string;
        srLabel?: string;
    };
    dependencies?: ExtensionDependency[];
    badges?: ExtensionBadgeSpec[];
    toggle?: {
        checked: boolean;
        disabled?: boolean;
        label?: string;
    };
    primaryAction?: {
        label: string;
        icon?: IconName;
        variant?: 'solid' | 'outline';
        disabled?: boolean;
        title?: string;
    };
    secondaryAction?: {
        label: string;
        icon?: IconName;
        disabled?: boolean;
        title?: string;
    };
    actions?: ActionMenuItem[];
}
export interface ExtensionCardGridProps {
    items?: ExtensionCardItem[];
    viewMode?: ExtensionViewMode;
    /** 카드 최소 폭(px). 열 수는 컨테이너 폭 나누기 이 값으로 정해진다. */
    minCardWidth?: number;
    /** 열 수 상한 — 초대형 화면에서 카드가 끝없이 잘게 쪼개지는 것을 막는다. */
    maxColumns?: number;
    emptyMessage?: string;
    pagination?: boolean;
    currentPage?: number;
    /**
     * 전체 페이지 수. **null 이면 '모름'** 이다 — 총 건수를 상한까지만 센 목록에서
     * 서버가 null 을 주며, 1 로 접으면 화면이 "1페이지뿐" 이라고 잘못 말한다.
     * 이때는 `hasMorePages` 가 '다음' 이동을 연다.
     */
    totalPages?: number | null;
    hasMorePages?: boolean;
    alwaysShowPagination?: boolean;
    paginationInfoText?: string;
    onPageChange?: (page: number) => void;
    onItemClick?: (row: any) => void;
    onToggle?: (checked: boolean, row: any) => void;
    onPrimaryAction?: (row: any) => void;
    onSecondaryAction?: (row: any) => void;
    onAction?: (actionId: string | number, row: any) => void;
    /**
     * 스위치 재동기 신호 — 값이 바뀌면 카드마다 누른 채 기다리던 스위치 값을 버리고 서버 값을 따른다.
     * 서버가 토글을 거절해 원래 값이 그대로 돌아오는 경로(실패·의존 충돌 창 취소)에서 화면이 올려 준다.
     */
    resyncKey?: string | number;
    loading?: boolean;
    skeletonCount?: number;
    className?: string;
    style?: React.CSSProperties;
    id?: string;
    editorAttrs?: EditorAttrs;
}
/**
 * ExtensionCardGrid — 확장 목록 전용 그리드.
 *
 * 갤러리와 목록을 각각 독립한 DOM 으로 그린다. 한 트리에 두 모드를 겹쳐 놓고 클래스만
 * 바꾸던 방식은 카드 안쪽에서 보기 상태를 볼 수 없어 성립하지 않았다 — 카드 셀의 렌더
 * 컨텍스트에는 전역 상태가 없기 때문이다. 이 컴포넌트는 `viewMode` 를 prop 으로 직접
 * 받아 그 분기를 React 안에서 끝낸다.
 *
 * 열 수는 중단점이 아니라 컨테이너 실측 폭으로 정한다 — 사이드바 개폐까지 따라간다.
 */
export declare const ExtensionCardGrid: React.FC<ExtensionCardGridProps>;
export default ExtensionCardGrid;
