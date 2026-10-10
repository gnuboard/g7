import { RefObject } from 'react';
/** 초점을 받는 업로더 요소의 구실 */
export type UploaderFocusAction = 'pick' | 'add' | 'primary' | 'drag' | 'download' | 'retry' | 'remove';
/**
 * 업로더 초점 되찾기를 건다. 업로더가 다시 그려질 때마다(항목이 빠지거나 드롭존이 목록으로 바뀐 뒤) 확인한다.
 *
 * @param rootRef 업로더 루트 요소 ref
 * @returns void
 */
export declare function useFocusRecovery(rootRef: RefObject<HTMLElement | null>): void;
export default useFocusRecovery;
