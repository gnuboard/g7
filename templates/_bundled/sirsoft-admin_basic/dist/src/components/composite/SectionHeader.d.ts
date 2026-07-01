import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface SectionHeaderProps {
    title?: string;
    description?: string;
    className?: string;
    style?: React.CSSProperties;
    children?: React.ReactNode;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * SectionHeader 집합 컴포넌트
 *
 * 페이지 내부의 섹션 (카드 / 탭 / 폼 그룹 등) 머리 부분을 표준화하는
 * 가벼운 헤더 시맨틱. 제목 (좌측) + 액션 자식 (우측) 의 균형 배치.
 *
 * PageHeader 와 다른 점:
 *  - PageHeader 는 페이지 단위 머리 (제목 + description + actions array
 *    + tabs + breadcrumb) 등 풍부한 구조.
 *  - SectionHeader 는 카드/탭/폼 그룹 안의 섹션 머리 — 제목과 우측
 *    children 슬롯만. 인라인 `flex items-center justify-between` 패턴
 *    중 "제목 + 액션" 묶음을 흡수.
 *
 * 기본 컴포넌트 조합: Div + H2 + P
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "SectionHeader",
 *   "props": { "title": "최근 활동", "className": "mb-4" },
 *   "children": [
 *     { "type": "basic", "name": "Button", ... }
 *   ]
 * }
 */
export declare const SectionHeader: React.FC<SectionHeaderProps>;
