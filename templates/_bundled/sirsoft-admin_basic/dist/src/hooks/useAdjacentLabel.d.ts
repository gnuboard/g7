import { RefObject } from 'react';
/**
 * 스위치 행의 제목 — 스위치 바로 앞 형제 상자(입력 부품 없음) 안 첫 글자 요소.
 *
 * `<div class="flex-between"><div><span>중복 실행 방지</span><p>설명</p></div><toggle/></div>` 처럼
 * 제목에 라벨 표식 클래스가 없는 배치를 위한 마지막 수단이다.
 *
 * @param anchor 부품 루트
 * @returns 제목 요소 또는 null
 */
export declare function findRowTitle(anchor: HTMLElement): HTMLElement | null;
/**
 * 요소 앞에 놓인 가장 가까운 라벨을 찾습니다.
 *
 * @param el 대상 요소
 * @returns 라벨 요소 또는 null
 */
export declare function findAdjacentLabel(el: HTMLElement): HTMLElement | null;
/**
 * 요소에 이름이 없으면 곁 라벨을 `aria-labelledby` 로 잇습니다.
 *
 * @param el 대상 요소
 * @param includeSelf true 면 요소 자신의 글자(선택 상자의 현재 값)도 이름 뒤에 잇는다
 * @param anchor 라벨을 찾기 시작할 요소(부품 깊숙이 든 입력칸이면 부품 루트) — 생략하면 el
 * @param rowTitleFallback true 면 라벨 표식이 없을 때 바로 앞 형제 상자의 첫 글자 요소를 쓴다(스위치 행)
 * @returns 이은 라벨 id 또는 null
 */
export declare function linkAdjacentLabel(el: HTMLElement | null, includeSelf?: boolean, anchor?: HTMLElement | null, rowTitleFallback?: boolean): string | null;
/**
 * 부품이 그려진 뒤 곁 라벨을 이름으로 잇는 훅.
 *
 * @param ref 이름을 붙일 요소 ref
 * @param enabled false 면 아무것도 하지 않는다(부품이 이미 이름 prop 을 받은 경우)
 * @param includeSelf 요소 자신의 글자도 이름 뒤에 이을지
 */
export declare function useAdjacentLabel(ref: RefObject<HTMLElement | null>, enabled?: boolean, includeSelf?: boolean): void;
