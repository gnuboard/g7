import { default as React } from 'react';
/**
 * 슬롯에 주입된 컴포넌트의 root id 를 컨테이너 id 로 스코프해 고유화한다.
 *
 * 같은 슬롯 컴포넌트가 여러 SlotContainer(예: 헤더 데스크톱/모바일)에서 렌더되면 주입
 * 컴포넌트의 정적 root id 가 컨테이너마다 같은 값으로 중복 출력되어 HTML id 유일성을
 * 위반한다. 컨테이너 고유 id 가 있고 컴포넌트가 root id 를 가지면 `{id}__{containerId}`
 * 로 스코프한다. 둘 중 하나라도 없으면 원본 그대로(무영향).
 *
 * @param componentDef 주입 컴포넌트 정의
 * @param containerId 이 SlotContainer 의 DOM id (없으면 스코프 안 함)
 * @returns 스코프된(또는 원본) 컴포넌트 정의
 */
export declare function scopeSlotChildDef<T extends {
    id?: unknown;
}>(componentDef: T, containerId: string | undefined): T;
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
