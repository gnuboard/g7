/**
 * 목록에서 항목을 지운 직후 포커스를 알맞은 곳으로 옮긴다 (#869 키보드 접근).
 *
 * 지우기 단추를 누르면 그 단추가 사라져 포커스가 body 로 빠진다. 지운 자리를 기록해 두었다가 다시 그린 직후
 * 같은 자리의 다음 대상(지운 뒤에는 같은 위치에 서는 다음 항목)으로 옮기고, 없으면 `fallback` 이 고른 요소로 옮긴다.
 *
 * @param containerRef 대상들을 품은 상자
 * @param selector 포커스 대상(지우기 단추 등) 선택자
 * @param fallback 같은 자리에 대상이 없을 때 고를 요소 — 남은 대상 목록과 지운 위치를 받는다
 * @returns 지운 위치를 기록하는 함수 — 지우기 처리에서 부른다
 */
export declare function useRemovalFocus(containerRef: React.RefObject<HTMLElement | null>, selector: string, fallback: (remaining: HTMLElement[], index: number) => HTMLElement | null | undefined): (index: number) => void;
