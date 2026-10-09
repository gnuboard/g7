/**
 * 곁에 놓인 라벨을 입력 부품의 접근 가능한 이름으로 잇는 훅
 *
 * 관리자 화면 레이아웃은 라벨과 입력을 형제로 늘어놓는다(`<label class="form-label">` 다음 `<input>`,
 * 스위치는 `<span class="text-heading">` 옆 `<toggle>`). 라벨에 `for` 가 없어 화면에는 붙어 보여도
 * 접근성 트리에서는 서로 모르는 사이라, 입력칸은 placeholder 값(「30」)으로, 스위치는 필드 키
 * (「security.force_https」)로, 선택 상자는 고른 값(「Artisan」)으로 읽혔다(7.1.0 영역 J D-J7, 관측 O-G2·O-H2).
 *
 * 부품이 스스로 이름(aria-label·aria-labelledby·`<label for>`)을 갖지 않을 때만, 가까운 조상 안에서
 * **앞에 놓인 가장 가까운 라벨**을 찾아 `aria-labelledby` 로 잇는다. 레이아웃을 한 줄도 고치지 않고
 * 라벨 문구(다국어)가 그대로 이름이 된다.
 *
 * 어느 칸의 이름인지 알 수 없는 라벨은 잇지 않는다(이커머스 전수 실측 B7, 2026-10-08):
 * - 라벨 범위(라벨 뒤 형제들 가운데 다음 라벨 전까지)에 이 칸 말고 다른 칸이 있으면 — 기간 시작·끝 두 칸을 든 상자,
 *   우편번호·기본주소·상세주소를 묶는 「주소」 같은 그룹 제목. 첫 칸에 그룹 제목을 이으면 안내 글자 이름이 덮인다.
 * - 레이아웃이 준 id 가 문서에서 이 라벨을 가리키지 않으면(반복 안의 고정 id) — `aria-labelledby` 는 id 로 찾으므로
 *   겹친 id 를 이으면 엉뚱한 행의 라벨로 읽힌다.
 *
 * id 없는 라벨에는 문서에 없는 id 를 골라 단다(`window.__g7AutoLabelSeq`). Label 부품은 id prop 이 없으면 id 속성을
 * 그리지 않으므로 React 가 이 속성을 덮어쓰지 않는다.
 */

import { useEffect, type RefObject } from 'react';

/** 이름표로 쓸 수 있는 요소 — 폼 라벨과 스위치 행의 제목 */
const LABEL_SELECTOR = 'label, .form-label, .filter-label, .text-heading';

/** 이름이 필요한 입력 부품 */
const CONTROL_SELECTOR =
  'input:not([type="hidden"]):not(.sr-only), select, textarea, [role="switch"], [role="combobox"], button[aria-haspopup="listbox"]';

/** 행 제목이 될 수 없는 조작 요소 — 단추·링크 글자는 그 단추의 이름이지 곁 스위치의 이름이 아니다 */
const INTERACTIVE_SELECTOR = 'button, a[href], [role="button"], [role="link"]';

/** 조상을 몇 단계까지 올라가며 찾을지 — 필드 한 칸을 넘어 다른 구획의 라벨을 집지 않도록 짧게 둔다 */
const MAX_DEPTH = 3;

/** 라벨을 못 찾은 요소를 다시 살펴보는 최대 횟수(라벨이 늦게 그려지는 경우 대비) */
const MAX_TRIES = 3;

/**
 * 문서에 아직 없는 자동 id 를 만듭니다 — 일련번호는 window 에 두어 번들이 여러 번 평가돼도 겹치지 않는다.
 *
 * @param doc 대상 문서
 * @param prefix id 접두(`g7-auto-label-`·`g7-auto-control-`)
 * @returns 새 id
 */
function nextAutoId(doc: Document, prefix: string): string {
  const w = window as unknown as { __g7AutoLabelSeq?: number };
  let id: string;
  do {
    w.__g7AutoLabelSeq = (w.__g7AutoLabelSeq ?? 0) + 1;
    id = `${prefix}${w.__g7AutoLabelSeq}`;
  } while (doc.getElementById(id));

  return id;
}

/**
 * 레이아웃이 준 id 가 문서에서 이 요소 하나만 가리키는지 판정합니다(id 가 없으면 true).
 *
 * @param el 대상 요소
 * @returns 겹치지 않으면 true
 */
function hasUniqueIdOrNone(el: HTMLElement): boolean {
  return !el.id || el.ownerDocument.getElementById(el.id) === el;
}

/**
 * 요소가 이미 접근 가능한 이름을 갖는지 판정합니다.
 *
 * @param el 대상 요소
 * @returns 이름 지정이 있으면 true
 */
function hasOwnName(el: HTMLElement): boolean {
  if (el.getAttribute('aria-label')?.trim()) return true;
  if (el.getAttribute('aria-labelledby')?.trim()) return true;
  const labels = (el as HTMLInputElement).labels;
  return !!labels && labels.length > 0;
}

/**
 * 라벨 후보가 이름표로 쓸 만한지 판정합니다(자기 안에 입력을 품은 래퍼 라벨·`for` 가 다른 곳을 가리키는 라벨 제외).
 *
 * @param label 후보
 * @param el 대상 요소
 * @param following true 면 요소 뒤에 놓인 라벨을 본다
 * @param scope 사이에 낀 입력을 셀 범위(라벨을 찾은 조상)
 * @returns 쓸 수 있으면 true
 */
function isUsableLabel(label: HTMLElement, el: HTMLElement, following = false, scope?: HTMLElement): boolean {
  if (label.contains(el) || el.contains(label)) return false;
  if (label instanceof HTMLLabelElement && label.htmlFor) return false;
  if (label.querySelector(CONTROL_SELECTOR)) return false;
  const position = label.compareDocumentPosition(el);
  const ok = following ? position & Node.DOCUMENT_POSITION_PRECEDING : position & Node.DOCUMENT_POSITION_FOLLOWING;
  if (!ok) return false;
  if ((label.textContent ?? '').trim() === '') return false;
  if (!hasUniqueIdOrNone(label)) return false;

  // 라벨과 이 요소 사이에 다른 입력 부품이 끼어 있으면 그 라벨은 앞 칸의 것이다 — 집지 않는다.
  // (스케줄 추가 창: 「타임아웃 (초)」 라벨 → 숫자칸 → 스위치 3개가 모두 「타임아웃 (초)」 로 읽혔다)
  return !hasControlBetween(label, el, following, scope);
}

/**
 * 문서 순서에서 두 요소 사이에 다른 입력 부품이 있는지 판정합니다.
 *
 * @param label 라벨 후보
 * @param el 대상 요소
 * @param following true 면 라벨이 요소 뒤에 있다
 * @param scope 검사 범위(라벨을 찾은 조상) — 생략하면 문서 전체
 * @returns 끼어 있으면 true
 */
function hasControlBetween(label: HTMLElement, el: HTMLElement, following: boolean, scope?: HTMLElement): boolean {
  const [first, last] = following ? [el, label] : [label, el];
  const root = scope ?? first.ownerDocument?.body ?? document.body;
  for (const control of Array.from(root.querySelectorAll<HTMLElement>(CONTROL_SELECTOR))) {
    if (control === el || el.contains(control) || control.contains(el)) continue;
    const afterFirst = first.compareDocumentPosition(control) & Node.DOCUMENT_POSITION_FOLLOWING;
    const beforeLast = control.compareDocumentPosition(last) & Node.DOCUMENT_POSITION_FOLLOWING;
    if (afterFirst && beforeLast && !first.contains(control)) return true;
  }

  return false;
}

/**
 * `ancestor` 의 자식 가운데 `node` 를 품은 것(자기 자신 포함)을 찾습니다.
 *
 * @param ancestor 조상
 * @param node 자손
 * @returns 자식 요소 또는 null
 */
function childContaining(ancestor: HTMLElement, node: HTMLElement): HTMLElement | null {
  let cur: HTMLElement | null = node;
  while (cur && cur.parentElement !== ancestor) cur = cur.parentElement;

  return cur;
}

/**
 * 형제 하나가 라벨 자리(라벨 자체, 또는 칸 없이 라벨을 품은 상자)인지 — 라벨 범위의 끝을 정한다.
 *
 * @param node 형제 요소
 * @returns 라벨 자리면 true
 */
function isLabelSlot(node: Element): boolean {
  if (node.matches(LABEL_SELECTOR)) return true;

  return !node.querySelector(CONTROL_SELECTOR) && !!node.querySelector(LABEL_SELECTOR);
}

/**
 * 요소(또는 그 안)에 이 부품 밖의 입력 부품이 있는지 판정합니다.
 *
 * @param node 살펴볼 요소
 * @param el 이름을 받을 부품(라벨 탐색 시작점)
 * @returns 다른 칸이 있으면 true
 */
function hasForeignControl(node: HTMLElement, el: HTMLElement): boolean {
  const controls = Array.from(node.querySelectorAll<HTMLElement>(CONTROL_SELECTOR));
  if (node.matches(CONTROL_SELECTOR)) controls.push(node);

  return controls.some((control) => !el.contains(control) && !control.contains(el));
}

/**
 * 라벨 범위(라벨 자리에서 칸 쪽으로, 다음 라벨 자리 전까지)에 든 칸이 이 부품 하나뿐인지 판정합니다.
 *
 * 범위에 다른 칸이 있으면 라벨은 여러 칸을 묶는 그룹 제목(「주소」)이거나 칸이 둘인 상자(「기간」 시작·끝)의 제목이라
 * 어느 칸의 이름인지 알 수 없다. 라벨·칸이 번갈아 놓인 한 줄(그리드)은 다음 라벨에서 끊기므로 각 칸이 자기 라벨만 받는다.
 *
 * @param label 라벨
 * @param el 이름을 받을 부품
 * @param ancestor 라벨을 찾은 조상
 * @param following true 면 라벨이 부품 뒤에 있다
 * @returns 이 부품만의 라벨이면 true
 */
function labelOwnsOnly(label: HTMLElement, el: HTMLElement, ancestor: HTMLElement, following: boolean): boolean {
  const labelSlot = childContaining(ancestor, label);
  const elSlot = childContaining(ancestor, el);
  if (!labelSlot || !elSlot || labelSlot === elSlot) return false;

  let seen = false;
  const step = (node: Element) => (following ? node.previousElementSibling : node.nextElementSibling);
  for (let sib = step(labelSlot) as HTMLElement | null; sib; sib = step(sib) as HTMLElement | null) {
    if (sib === elSlot) {
      seen = true;
    } else if (isLabelSlot(sib)) {
      break;
    }
    if (hasForeignControl(sib, el)) return false;
  }

  return seen;
}

/**
 * 스위치 행의 제목 — 스위치 바로 앞 형제 상자(입력 부품 없음) 안 첫 글자 요소.
 *
 * `<div class="flex-between"><div><span>중복 실행 방지</span><p>설명</p></div><toggle/></div>` 처럼
 * 제목에 라벨 표식 클래스가 없는 배치를 위한 마지막 수단이다.
 *
 * @param anchor 부품 루트
 * @returns 제목 요소 또는 null
 */
export function findRowTitle(anchor: HTMLElement): HTMLElement | null {
  const prev = anchor.previousElementSibling as HTMLElement | null;
  if (!prev || prev.querySelector(CONTROL_SELECTOR)) return null;
  const walker = [prev, ...Array.from(prev.querySelectorAll<HTMLElement>('*'))];
  for (const node of walker) {
    // 단추·링크 안 글자는 건너뛴다 — 확장 카드에서 스위치 앞 「업데이트」·「레이아웃 편집」 단추 글자가
    // 스위치 이름이 되어 화면 읽기가 확장 이름 대신 단추 글자를 읽었다(O-Q2-1).
    // 앞 형제(prev) 안의 단추·링크만 본다 — 행 전체를 감싼 클릭 카드(role=button)까지 단추로 보면 모든 글자를 건너뛴다.
    const interactive = node.closest(INTERACTIVE_SELECTOR);
    if (interactive && prev.contains(interactive)) continue;
    const ownText = Array.from(node.childNodes)
      .filter((n) => n.nodeType === Node.TEXT_NODE)
      .map((n) => n.textContent ?? '')
      .join('')
      .trim();
    if (ownText !== '') return node;
  }

  return null;
}

/**
 * 요소 앞에 놓인 가장 가까운 라벨을 찾습니다.
 *
 * @param el 대상 요소
 * @returns 라벨 요소 또는 null
 */
export function findAdjacentLabel(el: HTMLElement): HTMLElement | null {
  let ancestor = el.parentElement;
  for (let depth = 0; ancestor && depth < MAX_DEPTH; depth++, ancestor = ancestor.parentElement) {
    const all = Array.from(ancestor.querySelectorAll<HTMLElement>(LABEL_SELECTOR));
    const scope = ancestor;
    const before = all.filter((label) => isUsableLabel(label, el, false, scope));
    if (before.length > 0) {
      // 가장 가까운 라벨이 여러 칸의 제목이면 잇지 않는다 — 더 먼 라벨은 사이에 칸이 끼어 어차피 쓸 수 없다
      const nearest = before[before.length - 1];

      return labelOwnsOnly(nearest, el, ancestor, false) ? nearest : null;
    }
    // 바로 위 상자 안에서만, 스위치 뒤에 제목을 두는 배치(「[스위치] 레이아웃 캐시」)도 받는다
    if (depth === 0) {
      const after = all.filter((label) => isUsableLabel(label, el, true, scope));
      if (after.length > 0) {
        return labelOwnsOnly(after[0], el, ancestor, true) ? after[0] : null;
      }
    }
  }

  return null;
}

/**
 * 요소에 이름이 없으면 곁 라벨을 `aria-labelledby` 로 잇습니다.
 *
 * @param el 대상 요소
 * @param includeSelf true 면 요소 자신의 글자(선택 상자의 현재 값)도 이름 뒤에 잇는다
 * @param anchor 라벨을 찾기 시작할 요소(부품 깊숙이 든 입력칸이면 부품 루트) — 생략하면 el
 * @param rowTitleFallback true 면 라벨 표식이 없을 때 바로 앞 형제 상자의 첫 글자 요소를 쓴다(스위치 행)
 * @returns 이은 라벨 id 또는 null
 */
export function linkAdjacentLabel(
  el: HTMLElement | null,
  includeSelf = false,
  anchor?: HTMLElement | null,
  rowTitleFallback = false
): string | null {
  if (!el || el.dataset.g7AutoLabel === '1' || hasOwnName(el)) return null;
  // 라벨을 못 찾은 요소는 몇 번만 다시 본다 — 입력마다 다시 그려질 때 문서를 거듭 훑지 않게 한다
  const tries = Number(el.dataset.g7AutoLabelTries ?? '0');
  if (tries >= MAX_TRIES) return null;
  el.dataset.g7AutoLabelTries = String(tries + 1);
  const start = anchor ?? el;
  const label = findAdjacentLabel(start) ?? (rowTitleFallback ? findRowTitle(start) : null);
  if (!label || !hasUniqueIdOrNone(label)) return null;

  if (!label.id) {
    label.id = nextAutoId(label.ownerDocument, 'g7-auto-label-');
  }
  let ids = label.id;
  if (includeSelf) {
    if (!el.id) {
      el.id = nextAutoId(el.ownerDocument, 'g7-auto-control-');
    }
    ids = `${label.id} ${el.id}`;
  }
  el.setAttribute('aria-labelledby', ids);
  el.dataset.g7AutoLabel = '1';

  return label.id;
}

/**
 * 부품이 그려진 뒤 곁 라벨을 이름으로 잇는 훅.
 *
 * @param ref 이름을 붙일 요소 ref
 * @param enabled false 면 아무것도 하지 않는다(부품이 이미 이름 prop 을 받은 경우)
 * @param includeSelf 요소 자신의 글자도 이름 뒤에 이을지
 */
export function useAdjacentLabel(ref: RefObject<HTMLElement | null>, enabled = true, includeSelf = false): void {
  useEffect(() => {
    if (!enabled) return;
    linkAdjacentLabel(ref.current, includeSelf);
  });
}
