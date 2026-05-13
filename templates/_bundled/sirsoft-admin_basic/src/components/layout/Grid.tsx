import React from 'react';
import { Div } from '../basic/Div';

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
export const Grid: React.FC<GridProps> = ({
  children,
  cols = 1,
  responsive,
  gap,
  rowGap,
  colGap,
  autoRows,
  autoCols,
  flow = 'row',
  className = '',
  style,
  onClick,
}) => {
  // Tailwind CSS 클래스 생성
  const classes: string[] = ['grid'];

  // Grid 열 개수
  if (cols) {
    classes.push(`grid-cols-${cols}`);
  }

  // 반응형 그리드
  if (responsive) {
    if (responsive.sm) classes.push(`sm:grid-cols-${responsive.sm}`);
    if (responsive.md) classes.push(`md:grid-cols-${responsive.md}`);
    if (responsive.lg) classes.push(`lg:grid-cols-${responsive.lg}`);
    if (responsive.xl) classes.push(`xl:grid-cols-${responsive.xl}`);
    if (responsive['2xl']) classes.push(`2xl:grid-cols-${responsive['2xl']}`);
  }

  // Gap
  if (gap !== undefined && gap > 0) {
    classes.push(`gap-${gap}`);
  }

  // Row gap
  if (rowGap !== undefined && rowGap > 0) {
    classes.push(`gap-y-${rowGap}`);
  }

  // Column gap
  if (colGap !== undefined && colGap > 0) {
    classes.push(`gap-x-${colGap}`);
  }

  // Auto rows
  if (autoRows) {
    const autoRowsMap: Record<string, string> = {
      auto: 'auto-rows-auto',
      min: 'auto-rows-min',
      max: 'auto-rows-max',
      fr: 'auto-rows-fr',
    };
    classes.push(autoRowsMap[autoRows]);
  }

  // Auto columns
  if (autoCols) {
    const autoColsMap: Record<string, string> = {
      auto: 'auto-cols-auto',
      min: 'auto-cols-min',
      max: 'auto-cols-max',
      fr: 'auto-cols-fr',
    };
    classes.push(autoColsMap[autoCols]);
  }

  // Grid flow
  const flowMap: Record<string, string> = {
    row: 'grid-flow-row',
    col: 'grid-flow-col',
    dense: 'grid-flow-dense',
    'row-dense': 'grid-flow-row-dense',
    'col-dense': 'grid-flow-col-dense',
  };
  classes.push(flowMap[flow]);

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
