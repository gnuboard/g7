import React from 'react';
import { Div } from '../basic/Div';

export interface ThreeColumnLayoutProps {
  /**
   * 왼쪽 영역 너비
   */
  leftWidth?: string;

  /**
   * 오른쪽 영역 너비
   */
  rightWidth?: string;

  /**
   * 왼쪽 슬롯 컨텐츠
   */
  leftSlot?: React.ReactNode;

  /**
   * 가운데 슬롯 컨텐츠
   */
  centerSlot?: React.ReactNode;

  /**
   * 오른쪽 슬롯 컨텐츠
   */
  rightSlot?: React.ReactNode;

  /**
   * 사용자 정의 클래스
   */
  className?: string;

  /**
   * 인라인 스타일
   */
  style?: React.CSSProperties;
}

/**
 * ThreeColumnLayout 레이아웃 컴포넌트
 *
 * 3단 레이아웃 구조를 제공하는 기본 layout 컴포넌트입니다.
 * leftWidth, rightWidth를 조절할 수 있으며 각 영역에 슬롯을 제공합니다.
 *
 * @example
 * <ThreeColumnLayout
 *   leftWidth="250px"
 *   rightWidth="300px"
 *   leftSlot={<div>Left Content</div>}
 *   centerSlot={<div>Center Content</div>}
 *   rightSlot={<div>Right Content</div>}
 * />
 */
export const ThreeColumnLayout: React.FC<ThreeColumnLayoutProps> = ({
  leftWidth = '250px',
  rightWidth = '300px',
  leftSlot,
  centerSlot,
  rightSlot,
  className = '',
  style,
}) => {
  // 컨테이너 스타일
  const containerClasses = `flex flex-row w-full h-full ${className}`.trim();

  // 왼쪽 영역 스타일
  const leftStyle: React.CSSProperties = {
    width: leftWidth,
    flexShrink: 0,
  };

  // 가운데 영역 스타일 (남은 공간 차지)
  const centerStyle: React.CSSProperties = {
    flex: 1,
    minWidth: 0, // flex 아이템 오버플로우 방지
  };

  // 오른쪽 영역 스타일
  const rightStyle: React.CSSProperties = {
    width: rightWidth,
    flexShrink: 0,
  };

  return (
    <Div className={containerClasses} style={style}>
      {/* 왼쪽 영역 */}
      <Div className="flex flex-col" style={leftStyle}>
        {leftSlot}
      </Div>

      {/* 가운데 영역 */}
      <Div className="flex flex-col" style={centerStyle}>
        {centerSlot}
      </Div>

      {/* 오른쪽 영역 */}
      <Div className="flex flex-col" style={rightStyle}>
        {rightSlot}
      </Div>
    </Div>
  );
};
