import { RefObject } from 'react';
/**
 * 칸의 바로 앞 라벨 id 를 찾는다.
 *
 * 레이아웃은 `<label class="form-label">우편번호</label><input …>` 처럼 라벨과 칸을 형제로만 두고 `for` 로 잇지 않는다.
 * 칸 바로 앞 형제를 보고, 없으면 **칸이 그 하나뿐인** 상자로 올라가(최대 `MAX_LEVELS_UP` 단계) 그 상자의 앞 형제를 본다
 * (`<label/><div class="relative"><input/><span>KRW</span></div>`, FormField 의 라벨 칸·입력 칸).
 * 상자에 칸이 둘 이상이면(기간 시작·끝처럼) 어느 칸의 이름인지 알 수 없어 잇지 않는다.
 *
 * @param root 칸 요소(선택 상자는 바깥 상자)
 * @param control 이름을 받을 요소 — 이미 라벨이 매인 칸(라벨 안의 칸·`for` 로 이어진 칸)이면 잇지 않는다
 * @returns 라벨 id, 없으면 undefined
 */
export declare function findPrecedingLabelId(root: HTMLElement, control?: HTMLElement): string | undefined;
/**
 * 바로 앞의 보이는 라벨을 칸의 `aria-labelledby` 로 잇는다.
 *
 * 렌더가 끝난 뒤(레이아웃 효과)에 DOM 형제를 보고 라벨 id 를 상태로 돌려준다 — 라벨은 형제라 같은 커밋에 이미
 * 그려져 있다. 라벨이 조건부로 나타나거나 사라져도 칸이 다시 그려질 때 다시 찾는다(값이 같으면 다시 그리지 않는다).
 *
 * @param rootRef 칸 요소(선택 상자는 바깥 상자) ref
 * @param enabled 호출처가 `aria-label`·`aria-labelledby` 로 이미 이름을 줬으면 false
 * @param controlRef 이름을 받을 요소 ref (생략하면 rootRef)
 * @returns 이을 라벨 id
 */
export declare function useLabelledByPrecedingLabel(rootRef: RefObject<HTMLElement | null>, enabled: boolean, controlRef?: RefObject<HTMLElement | null>): string | undefined;
