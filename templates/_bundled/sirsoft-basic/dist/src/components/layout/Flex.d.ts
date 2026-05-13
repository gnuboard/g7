import { default as React } from 'react';
export interface FlexProps {
    /**
     * Flex 방향
     */
    direction?: 'row' | 'row-reverse' | 'col' | 'col-reverse';
    /**
     * Justify content (주축 정렬)
     */
    justify?: 'start' | 'end' | 'center' | 'between' | 'around' | 'evenly';
    /**
     * Align items (교차축 정렬)
     */
    align?: 'start' | 'end' | 'center' | 'baseline' | 'stretch';
    /**
     * Flex wrap 설정
     */
    wrap?: 'wrap' | 'nowrap' | 'wrap-reverse';
    /**
     * Gap 크기 (Tailwind 스케일: 0, 1, 2, 3, 4, 5, 6, 8, 10, 12, 16, 20, 24)
     */
    gap?: number;
    /**
     * Flex grow (자식 요소 확장)
     */
    grow?: boolean | number;
    /**
     * Flex shrink (자식 요소 축소)
     */
    shrink?: boolean | number;
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
 * Flex 레이아웃 컴포넌트
 *
 * Flexbox를 활용한 유연한 레이아웃을 제공합니다.
 * justify, align, wrap 등의 옵션을 통해 다양한 정렬을 구현할 수 있습니다.
 *
 * @example
 * // 가로 정렬 (가운데 정렬)
 * <Flex direction="row" justify="center" align="center" gap={4}>
 *   <div>Item 1</div>
 *   <div>Item 2</div>
 * </Flex>
 *
 * @example
 * // 세로 정렬 (양 끝 정렬)
 * <Flex direction="col" justify="between" gap={6}>
 *   <div>Top</div>
 *   <div>Bottom</div>
 * </Flex>
 */
export declare const Flex: React.FC<FlexProps>;
