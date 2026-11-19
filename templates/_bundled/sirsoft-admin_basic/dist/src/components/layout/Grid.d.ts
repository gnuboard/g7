import { default as React } from 'react';
import { EditorAttrs } from '../../types';
import { GridResponsiveCols } from './gridColumns';
export interface GridProps {
    /**
     * Grid 열 개수
     */
    cols?: number;
    /**
     * 반응형 열 개수 (구간별: sm 640px·md 768px·lg 1024px·xl 1280px·2xl 1536px 이상)
     *
     * 엔진 폭(`G7Core.useResponsive().width`, 편집기 미리보기에서는 기기 폭)으로 열 수를 골라
     * 접두 없는 `grid-cols-N` 하나만 낸다. 폭 이상인 가장 넓은 구간 값을 쓰고, 값이 없는 구간은
     * 아래 구간 값을 이어받으며, 맞는 구간이 없으면 `cols` 를 쓴다.
     */
    responsive?: GridResponsiveCols;
    /**
     * Gap 크기 (Tailwind 스케일: 0, 1, 2, 3, 4, 5, 6, 8, 10, 12, 16, 20, 24)
     */
    gap?: number;
    /**
     * Row gap (행 간격)
     */
    rowGap?: number;
    /**
     * Column gap (열 간격)
     */
    colGap?: number;
    /**
     * 자동 행 크기 조정
     */
    autoRows?: 'auto' | 'min' | 'max' | 'fr';
    /**
     * 자동 열 크기 조정
     */
    autoCols?: 'auto' | 'min' | 'max' | 'fr';
    /**
     * Grid auto-flow 설정
     */
    flow?: 'row' | 'col' | 'dense' | 'row-dense' | 'col-dense';
    /**
     * 사용자 정의 클래스
     */
    className?: string;
    /**
     * 인라인 스타일
     */
    style?: React.CSSProperties;
    /**
     * 자식 요소
     */
    children?: React.ReactNode;
    /**
     * 클릭 이벤트 핸들러
     */
    onClick?: () => void;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /**
     * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
     */
    editorAttrs?: EditorAttrs;
}
/**
 * Grid 레이아웃 컴포넌트
 *
 * CSS Grid를 활용한 반응형 그리드 시스템을 제공합니다.
 * 반응형 열 수는 Tailwind 접두가 아니라 엔진 폭으로 판정합니다(편집기 미리보기 폭을 따름).
 * gap, span, auto-flow 등의 그리드 속성을 제어할 수 있습니다.
 *
 * @example
 * // 기본 3열 그리드
 * <Grid cols={3} gap={4}>
 *   <div>Item 1</div>
 *   <div>Item 2</div>
 *   <div>Item 3</div>
 * </Grid>
 *
 * @example
 * // 반응형 그리드 — 폭 700px 은 grid-cols-2, 900px 은 grid-cols-3, 1100px 이상은 grid-cols-4
 * <Grid
 *   cols={1}
 *   responsive={{ sm: 2, md: 3, lg: 4 }}
 *   gap={6}
 * >
 *   <div>Item 1</div>
 *   <div>Item 2</div>
 * </Grid>
 */
export declare const Grid: React.FC<GridProps>;
