import { default as React } from 'react';
export interface GridProps {
    /**
     * Grid 열 개수
     */
    cols?: number;
    /**
     * 반응형 열 개수 (브레이크포인트별)
     */
    responsive?: {
        sm?: number;
        md?: number;
        lg?: number;
        xl?: number;
        '2xl'?: number;
    };
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
}
/**
 * Grid 레이아웃 컴포넌트
 *
 * CSS Grid를 활용한 반응형 그리드 시스템을 제공합니다.
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
 * // 반응형 그리드
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
