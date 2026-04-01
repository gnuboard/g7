import { default as React } from 'react';
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
export declare const ThreeColumnLayout: React.FC<ThreeColumnLayoutProps>;
