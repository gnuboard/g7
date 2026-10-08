// e2e:allow 이커머스 전수 실측 후속 B7 — 전용 Chrome 접근성 트리로 배송정책 등록 화면 재실측(이력 「Chrome MCP 정밀 점검 매트릭스」), 단위 회귀는 LabelAutoLink.test.tsx·shippingPolicyFormLabelNames.test.tsx. 커밋 단위 E2E spec 은 후속 판단으로 이월
import { useLayoutEffect, useState, type RefObject } from 'react';

/** 「입력칸」 으로 세는 요소 — 선택 상자(Select)의 버튼은 목록을 여는 버튼(`aria-haspopup="listbox"`)이다 */
const CONTROL_SELECTOR = 'input:not([type="hidden"]), select, textarea, button[aria-haspopup="listbox"]';

/**
 * 칸에서 몇 단계 위 상자까지 앞 라벨을 찾는가.
 *
 * 레이아웃 JSON 전수 집계(관리자 528파일)로는 한 단계로 충분했지만, FormField 는 라벨 칸·입력 칸을 자기 상자로 감싸고
 * 레이아웃이 그 안에 칸을 다시 상자로 넣어 라벨이 두 단계 위에 놓인다(쿠폰 등록 「최소주문금액」, 2026-10-08 실측).
 * 단계마다 「그 상자에 든 칸이 이 칸 하나뿐」 이어야 올라가므로 넓혀도 다른 칸의 라벨을 잡지 않는다.
 */
const MAX_LEVELS_UP = 2;

/** 자동으로 다는 라벨 id 의 접두 */
const AUTO_LABEL_ID_PREFIX = 'g7-auto-label-';

/** 자동 라벨 id 일련번호 — 번들이 여러 번 평가돼도 겹치지 않게 window 에 둔다 */
function nextAutoLabelId(doc: Document): string {
  const w = window as unknown as { __g7AutoLabelSeq?: number };
  let id: string;
  do {
    w.__g7AutoLabelSeq = (w.__g7AutoLabelSeq ?? 0) + 1;
    id = `${AUTO_LABEL_ID_PREFIX}${w.__g7AutoLabelSeq}`;
  } while (doc.getElementById(id));

  return id;
}

/**
 * 요소가 라벨 자리(라벨, 또는 칸 없이 라벨 하나만 든 상자)면 그 라벨을 돌려준다 — 쓸 수 있는지는 따지지 않는다.
 *
 * @param el 살펴볼 요소
 * @returns 라벨, 라벨 자리가 아니면 null
 */
function labelInSlot(el: Element | null): HTMLLabelElement | null {
  if (!el) return null;
  if (el.tagName === 'LABEL') return el as HTMLLabelElement;
  if (el.children.length === 1 && el.firstElementChild?.tagName === 'LABEL' && !el.querySelector(CONTROL_SELECTOR)) {
    return el.firstElementChild as HTMLLabelElement;
  }

  return null;
}

/**
 * 라벨 자리 뒤 형제들 가운데 다음 라벨 자리가 나오기 전까지의 칸 수.
 *
 * 「주소」 처럼 우편번호·기본주소·상세주소 여러 줄을 묶는 **그룹 제목** 라벨은 뒤 형제에 칸이 여럿이다 — 첫 줄 상자에
 * 칸이 하나뿐이라는 이유로 첫 칸에 그룹 제목을 이으면 「우편번호」 로 읽히던 칸이 「주소」 로 바뀐다(회원 등록, 2026-10-08
 * 실측). 라벨·칸이 번갈아 놓인 한 줄(그리드)은 다음 라벨에서 끊기므로 각 칸이 자기 라벨만 받는다.
 *
 * @param slot 라벨 자리
 * @returns 칸 수
 */
function controlsInLabelScope(slot: Element): number {
  let count = 0;
  for (let sib = slot.nextElementSibling; sib; sib = sib.nextElementSibling) {
    if (labelInSlot(sib)) break;
    count += (sib.matches(CONTROL_SELECTOR) ? 1 : 0) + sib.querySelectorAll(CONTROL_SELECTOR).length;
  }

  return count;
}

/**
 * 요소가 「아직 아무 칸에도 매이지 않은 보이는 라벨」 이면 그 라벨을, 라벨 하나만 든 상자면 그 안의 라벨을 돌려준다.
 *
 * - 라벨 뒤 범위(다음 라벨 전까지)에 칸이 둘 이상이면 그룹 제목으로 보고 쓰지 않는다.
 * - `for` 가 있거나 안에 칸이 든 라벨은 이미 다른 칸의 이름이므로 쓰지 않는다.
 * - 레이아웃이 준 id 가 문서에서 이 라벨을 가리키지 않으면(반복 안의 고정 id 처럼 겹친 경우) 쓰지 않는다
 *   — `aria-labelledby` 는 id 로 찾으므로 겹치면 엉뚱한 행의 라벨로 읽힌다.
 * - id 가 없는 라벨에는 겹치지 않는 id 를 단다. Label 부품은 id prop 이 없으면 id 속성을 그리지 않으므로 React 가
 *   이 속성을 덮어쓰지 않는다. Label 에 훅(useId)을 두지 않는 이유: Label 은 FormField 등 여러 composite 안에서
 *   훅 없는 부품으로 쓰이고, 확장 시험은 엔진(루트 React)과 템플릿 부품(템플릿 React)을 함께 그려 훅이 깨진다.
 *
 * @param el 칸(또는 칸을 든 상자)의 바로 앞 형제
 * @returns 쓸 수 있는 라벨, 없으면 null
 */
function asFreeLabel(el: Element | null): HTMLLabelElement | null {
  const label = labelInSlot(el);
  if (!el || !label) return null;
  if (label.htmlFor || label.querySelector(CONTROL_SELECTOR)) return null;
  if (!(label.textContent ?? '').trim()) return null;
  if (controlsInLabelScope(el) !== 1) return null;
  if (label.id) {
    return label.ownerDocument.getElementById(label.id) === label ? label : null;
  }
  label.id = nextAutoLabelId(label.ownerDocument);

  return label;
}

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
export function findPrecedingLabelId(root: HTMLElement, control: HTMLElement = root): string | undefined {
  const labels = (control as HTMLInputElement).labels;
  if (labels && labels.length > 0) return undefined;

  let node: HTMLElement = root;
  for (let level = 0; ; level++) {
    const label = asFreeLabel(node.previousElementSibling);
    if (label) return label.id;
    if (level >= MAX_LEVELS_UP) return undefined;

    const parent = node.parentElement;
    if (!parent || parent.querySelectorAll(CONTROL_SELECTOR).length !== 1) return undefined;
    node = parent;
  }
}

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
export function useLabelledByPrecedingLabel(
  rootRef: RefObject<HTMLElement | null>,
  enabled: boolean,
  controlRef?: RefObject<HTMLElement | null>,
): string | undefined {
  const [labelId, setLabelId] = useState<string | undefined>(undefined);

  // 의존성 없이 매 렌더 뒤에 본다 — 형제 라벨의 등장·사라짐은 이 칸의 props 로 드러나지 않는다
  useLayoutEffect(() => {
    const root = rootRef.current;
    const control = controlRef?.current ?? root;
    const next = enabled && root && control ? findPrecedingLabelId(root, control) : undefined;
    setLabelId((prev) => (prev === next ? prev : next));
  });

  return labelId;
}
