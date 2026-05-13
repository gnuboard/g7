import React from 'react';
import { Div } from '../basic/Div';

export interface ContainerProps {
  /**
   * DOM id 속성
   */
  id?: string;

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
}

/**
 * Container 컴포넌트
 *
 * 단순한 div 래퍼입니다. id와 className을 지정할 수 있습니다.
 */
export const Container: React.FC<ContainerProps> = ({
  id,
  className,
  style,
  children,
}) => {
  return (
    <Div id={id} className={className} style={style}>
      {children}
    </Div>
  );
};
