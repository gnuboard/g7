import { default as React } from 'react';
/** 코어가 주입하는 오버레이 박스(프레임 기준 좌표). */
interface OverlayBox {
    top: number;
    left: number;
    width: number;
    height: number;
}
/** 코어 `CanvasOverlayProps` 의 본 오버레이 사용 부분집합(코어 타입과 구조 호환). */
interface TabNavOverlayProps {
    node: {
        props?: Record<string, unknown>;
    } & Record<string, unknown>;
    params?: Record<string, unknown>;
    nodeBox: OverlayBox;
    cellBoxes?: Array<OverlayBox & {
        path: string;
    }>;
    colorScheme?: 'light' | 'dark';
    t: (key: string, params?: Record<string, string | number>) => string;
    onPatchNode: (patched: TabNavOverlayProps['node']) => void;
}
export declare function TabNavInplaceOverlay({ node, params, cellBoxes, t, onPatchNode, }: TabNavOverlayProps): React.ReactElement | null;
export {};
