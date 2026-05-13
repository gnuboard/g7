import { default as React } from 'react';
/**
 * SlotContainer Props
 */
export interface SlotContainerProps {
    /**
     * 슬롯 ID
     *
     * 이 슬롯에 등록된 컴포넌트들을 렌더링합니다.
     */
    slotId: string;
    /**
     * 컨테이너 CSS 클래스
     */
    className?: string;
    /**
     * 빈 슬롯일 때 표시할 내용
     *
     * React 엘리먼트 또는 문자열을 전달할 수 있습니다.
     */
    emptyContent?: React.ReactNode;
    /**
     * 인라인 스타일
     */
    style?: React.CSSProperties;
    /**
     * 컴포넌트 ID (DOM id)
     */
    id?: string;
    /**
     * 자식 컴포넌트 (children을 통한 fallback 또는 slot 컴포넌트가 아닌 정적 컨텐츠)
     */
    children?: React.ReactNode;
}
/**
 * SlotContainer 컴포넌트
 *
 * SlotContext에서 지정된 슬롯 ID에 등록된 컴포넌트들을 수집하여 렌더링합니다.
 * 컴포넌트는 slotOrder 기준으로 정렬됩니다.
 *
 * 이 컴포넌트는 SlotContext를 사용하므로 SlotProvider 내부에서만 동작합니다.
 * SlotProvider는 DynamicRenderer의 루트에서 자동으로 래핑됩니다.
 *
 * @param props SlotContainerProps
 */
export declare const SlotContainer: React.FC<SlotContainerProps>;
export default SlotContainer;
