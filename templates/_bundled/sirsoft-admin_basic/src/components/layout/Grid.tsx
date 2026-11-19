import React from 'react';
import { Div } from '../basic/Div';
import type { EditorAttrs } from '../../types';
import { useRenderWidth } from '../../hooks/useRenderWidth';
import { gridColsClass, resolveGridCols, type GridResponsiveCols } from './gridColumns';
import { keyboardActivationProps, FOCUS_VISIBLE_RING } from '../../support/keyboardActivation';

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
  id,
  editorAttrs,
}) => {
  // 엔진 폭 구독 (편집기 미리보기 overrideWidth 반영, 훅이 없는 환경은 창 폭 + resize)
  const width = useRenderWidth();

  // Tailwind CSS 클래스 생성
  const classes: string[] = ['grid'];

  // Grid 열 개수 — 반응형 구간은 엔진 폭으로 골라 접두 없는 클래스 하나만 낸다
  const colsClass = gridColsClass(resolveGridCols(cols, responsive, width));
  if (colsClass) {
    classes.push(colsClass);
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

  // 클릭을 받으면 손 모양 커서 — 눌러 쓰는 요소는 손 모양 커서를 준다(#869 κ). 레이아웃 className 이 뒤에 와서 다른 커서를 주면 그 값이 남는다
  if (onClick) {
    classes.push('cursor-pointer', FOCUS_VISIBLE_RING);
  }

  // 사용자 정의 클래스 추가
  if (className) {
    classes.push(className);
  }

  return (
    <Div className={classes.join(' ')} style={style} onClick={onClick} {...keyboardActivationProps(onClick)} id={id} {...editorAttrs}>
      {children}
    </Div>
  );
};
