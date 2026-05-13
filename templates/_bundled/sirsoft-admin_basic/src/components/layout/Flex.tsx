import React from 'react';
import { Div } from '../basic/Div';

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
export const Flex: React.FC<FlexProps> = ({
  children,
  direction = 'row',
  justify = 'start',
  align = 'stretch',
  wrap = 'nowrap',
  gap = 0,
  grow = false,
  shrink = true,
  className = '',
  style,
  onClick,
}) => {
  // Tailwind CSS 클래스 생성
  const classes: string[] = ['flex'];

  // Flex direction
  const directionMap: Record<string, string> = {
    row: 'flex-row',
    'row-reverse': 'flex-row-reverse',
    col: 'flex-col',
    'col-reverse': 'flex-col-reverse',
  };
  classes.push(directionMap[direction]);

  // Flex wrap
  const wrapMap: Record<string, string> = {
    wrap: 'flex-wrap',
    nowrap: 'flex-nowrap',
    'wrap-reverse': 'flex-wrap-reverse',
  };
  classes.push(wrapMap[wrap]);

  // Justify content
  const justifyMap: Record<string, string> = {
    start: 'justify-start',
    end: 'justify-end',
    center: 'justify-center',
    between: 'justify-between',
    around: 'justify-around',
    evenly: 'justify-evenly',
  };
  classes.push(justifyMap[justify]);

  // Align items
  const alignMap: Record<string, string> = {
    start: 'items-start',
    end: 'items-end',
    center: 'items-center',
    baseline: 'items-baseline',
    stretch: 'items-stretch',
  };
  classes.push(alignMap[align]);

  // Gap
  if (gap > 0) {
    classes.push(`gap-${gap}`);
  }

  // Flex grow
  if (grow === true) {
    classes.push('flex-grow');
  } else if (typeof grow === 'number' && grow > 0) {
    classes.push(`flex-grow-${grow}`);
  }

  // Flex shrink
  if (shrink === false) {
    classes.push('shrink-0');
  } else if (shrink === true) {
    classes.push('flex-shrink');
  } else if (typeof shrink === 'number' && shrink > 0) {
    classes.push(`flex-shrink-${shrink}`);
  }

  // 사용자 정의 클래스 추가
  if (className) {
    classes.push(className);
  }

  return (
    <Div className={classes.join(' ')} style={style} onClick={onClick}>
      {children}
    </Div>
  );
};
