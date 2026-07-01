// e2e:allow 레이아웃 편집기 자리표시 칩 합성 입력 위젯 — contentEditable/드래그/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(§공통 검증) + 단위(순수 파싱·조작 유틸)로 검증 (InlineBindingSection.tsx L1 과 동일 정책)
/**
 * PlaceholderChipInput.tsx — `{pN}` 자리표시 칩 + 평문 합성 입력 위젯
 *
 * param 정규화된 키 값(로케일 문장, 예 `"{p0} 작성 {p1}"`)을, `{pN}` 자리표시는
 * `contenteditable=false` **원자 칩**(데이터 친화명 표시, 예 `[회원명]`)으로, 그 사이 평문만
 * 편집 가능한 합성 입력으로 렌더한다. 확정 UX:
 *
 *  - **평문 편집**: 칩 사이 평문만 타이핑. 칩은 원자 토큰으로 그 자리 고정(백스페이스로 안 지워짐).
 *  - **칩 이동**: 칩을 드래그해 같은 입력 안 다른 위치로 drop → `movePlaceholder`(해당 로케일만).
 *  - **새 칩 삽입**: '+데이터' 버튼 → ScalarPicker → 선택 시 **커서 위치**에 칩 삽입.
 *  - **칩 삭제(키 누르기)**: 불가(칩은 원자). 데이터 연결 해제는 [속성]탭 '해제' 버튼 전용.
 *  - **평문 0(칩만)**: 평문을 모두 지워 칩만 남겨도 정상(키 값 = `{pN}` 단독 = 순수 데이터 바인딩 동등).
 *
 * 본 위젯은 키 값 **단일 문자열**(편집 중인 1개 로케일)만 다룬다 — 자리표시는 로케일별 독립이므로
 * (한국어 어순 ≠ 영어 어순) 한 로케일의 칩 위치 변경이 다른 로케일을 건드리지 않는다. 부모(번역
 * 탭/인라인 편집)가 로케일별로 본 위젯을 인스턴스화한다.
 *
 * 자료구조 SSoT: `keyValue`(문자열, `{pN}` 자리표시 포함) → 칩/평문 세그먼트로 파싱(렌더) →
 * 사용자 조작 → `inlineBindingUtils` 순수 유틸로 새 `keyValue` 생성 → `onChange(next)`. DOM 은
 * 표시·입력 채널일 뿐 진실은 `keyValue` 문자열(비제어 contentEditable 의 stale 회피).
 *
 * 칩 친화명: 부모가 `paramLabels[paramName]` 으로 각 param 의 표시 라벨을 주입한다(데이터 연결의
 * 친화 명칭). 미주입 시 param 이름(`p0`)을 그대로 표시.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속). 사용자 대면 문자열은
 * `$t:layout_editor.*`.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { insertPlaceholderAt } from '../../spec/inlineBindingUtils';

/** 키 값 문장을 칩/평문 세그먼트로 분해한 결과의 한 조각. */
export interface ChipSegment {
  kind: 'text' | 'chip';
  /** (text) 평문 / (chip) `{pN}` 원문 토큰 */
  raw: string;
  /** (chip) param 이름(`p0`) */
  paramName?: string;
  /** 키 값 문자열 내 시작 인덱스(드롭 위치 계산 SSoT) */
  start: number;
  /** 끝 인덱스(exclusive) */
  end: number;
}

/** `{pN}`/`{{pN}}` 자리표시 토큰 매칭(이름 캡처). */
const PLACEHOLDER_RE = /\{\{?(p\d+)\}?\}/g;

/**
 * 키 값 문장을 칩/평문 세그먼트로 분해한다(무손실 — raw 를 이으면 원문 동일).
 *
 * @param keyValue 키 값(로케일 문장, `{pN}` 자리표시 포함)
 * @returns 세그먼트 배열
 */
export function parseChipSegments(keyValue: string): ChipSegment[] {
  const out: ChipSegment[] = [];
  if (typeof keyValue !== 'string' || keyValue.length === 0) return out;
  const re = new RegExp(PLACEHOLDER_RE.source, 'g');
  let last = 0;
  let m: RegExpExecArray | null;
  while ((m = re.exec(keyValue)) !== null) {
    const start = m.index;
    const end = start + m[0].length;
    if (start > last) {
      out.push({ kind: 'text', raw: keyValue.slice(last, start), start: last, end: start });
    }
    out.push({ kind: 'chip', raw: m[0], paramName: m[1], start, end });
    last = end;
  }
  if (last < keyValue.length) {
    out.push({ kind: 'text', raw: keyValue.slice(last), start: last, end: keyValue.length });
  }
  return out;
}

/** 렌더 슬롯 — 칩 양옆·사이·양끝에 **항상 편집 가능한 평문 슬롯**을 보장한 정규화 목록. */
export interface ChipSlot {
  kind: 'text' | 'chip';
  /** (text) 평문 / (chip) param 이름 */
  text?: string;
  paramName?: string;
}

/**
 * 키 값을 **[text, chip, text, chip, …, text]** 교차 슬롯으로 정규화한다.
 *
 * `parseChipSegments` 는 문자열에 평문이 있는 자리에만 text 세그먼트를 만들어, `{p0}{p1}` 처럼
 * 칩이 붙어 있거나 `{p0}` 로 끝나면 칩 사이/끝에 **커서를 둘 평문 슬롯이 없다**(
 * "칩 끝 커서 타이핑 불가", "칩 이동 후 재이동 불가"). 본 함수는 칩 앞·사이·끝에 빈 text 슬롯을
 * 강제 삽입해, 어디서나 타이핑·드롭이 가능하게 한다. 항상 text 로 시작·끝나고 칩은 text 로 둘러싸인다.
 *
 * @param keyValue 키 값(자리표시 문장)
 * @returns 교차 슬롯 배열(최소 `[{text:''}]`)
 */
export function buildChipSlots(keyValue: string): ChipSlot[] {
  const segs = parseChipSegments(keyValue);
  const slots: ChipSlot[] = [];
  let pendingText = '';
  let lastWasText = false;
  const pushText = (txt: string): void => { slots.push({ kind: 'text', text: txt }); lastWasText = true; };
  for (const s of segs) {
    if (s.kind === 'text') {
      pendingText = s.raw;
      pushText(pendingText);
      pendingText = '';
    } else {
      // 칩 앞에 text 슬롯이 없으면 빈 슬롯 삽입(칩끼리 붙거나 맨 앞 칩).
      if (!lastWasText) pushText('');
      slots.push({ kind: 'chip', paramName: s.paramName });
      lastWasText = false;
    }
  }
  // 마지막이 칩이거나 빈 값이면 끝에 편집 슬롯 보장.
  if (!lastWasText) pushText('');
  return slots;
}

/**
 * 칩 이동 재조립(순수) — 현재 슬롯 배열에서 `dragParam` 칩을 제거하고, `dropSlotIndex` 평문 슬롯의
 * `charOffset` 글자 위치에 다시 삽입한 새 keyValue 를 만든다.
 *
 * DOM 의존을 분리해 단위 테스트 가능하게 한다(드래그는 브라우저 전용이라 jsdom 불가 — 위치 계산
 * 로직만 순수 함수로 잠근다). 슬롯 텍스트는 호출자가 DOM 에서 읽은 현재 값을 넘긴다.
 *
 * @param slotState 현재 슬롯 상태(text 슬롯은 현재 DOM 텍스트 포함)
 * @param dragParam 이동할 칩 param 이름
 * @param dropSlotIndex 드롭 대상 슬롯 인덱스
 * @param charOffset 드롭 슬롯 텍스트 내 글자 위치(글자 사이 정밀)
 * @returns 재조립된 keyValue
 */
export function recomposeChipMove(
  slotState: ChipSlot[],
  dragParam: string,
  dropSlotIndex: number,
  charOffset: number,
): string {
  const parts: string[] = [];
  slotState.forEach((slot, i) => {
    if (slot.kind === 'chip') {
      if (slot.paramName === dragParam) return; // 이동 칩 원위치 제거.
      parts.push(`{${slot.paramName}}`);
    } else {
      const txt = slot.text ?? '';
      if (i === dropSlotIndex) {
        const cut = Math.max(0, Math.min(charOffset, txt.length));
        parts.push(txt.slice(0, cut), `{${dragParam}}`, txt.slice(cut));
      } else {
        parts.push(txt);
      }
    }
  });
  return parts.join('');
}

export interface PlaceholderChipInputProps {
  /** 편집 중 로케일의 키 값(자리표시 문장) */
  value: string;
  /** 키 값 변경 콜백(평문 편집/칩 이동/새 칩 삽입 결과) */
  onChange: (next: string) => void;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** param 이름 → 표시 라벨(데이터 친화명). 미주입 키는 param 이름 그대로. */
  paramLabels?: Record<string, string>;
  /** '+데이터' 버튼 클릭 — 커서 문자 위치를 넘긴다(부모가 ScalarPicker 열고 삽입 배선). 미전달 시 버튼 숨김. */
  onRequestInsert?: (charIndex: number) => void;
  /**
   * 칩 우측 X 클릭 — 데이터 연결 '해제'. 부모가 node.text
   * `|pN=` 제거 + 전 로케일 `{pN}` 제거 + 캔버스/다국어 실시간 동기화를 배선한다(기존 [속성]탭 '해제'
   * 버튼과 동일 동작). **미전달 시 X 미노출** — node.text 미보유 컨텍스트(키 관리 모달)는 안전하게
   * 해제 불가하므로 전달하지 않는다(`+데이터` 미노출과 동일 정책). 칩 위치만 단일 문자열로
   * 다루는 본 위젯은 전 로케일/노드 정리를 할 수 없어 부모에 위임한다. */
  onRemoveChip?: (paramName: string) => void;
  /** 테스트/식별용 접미사 */
  testIdSuffix?: string;
  /** 비활성(읽기전용) */
  disabled?: boolean;
  /**
   * 내부 '+데이터' 버튼 행 숨김(데이터 칩 모드 액션 버튼을 평문 모드와 동일하게
   * 입력칸 우측 한 줄로 통일). 부모가 외부 액션 행에서 '+데이터'를 렌더할 때 true. 이때 커서 위치
   * 삽입은 `caretRef` 로 부모가 현재 caret 절대 위치를 읽어 유지한다(기능 보존). */
  hideInsertButton?: boolean;
  /**
   * 현재 caret 의 키 값 절대 위치를 부모가 읽도록 노출하는 콜백 ref. 부모가
   * 외부 '+데이터' 버튼에서 `caretRef.current?.()` 로 삽입 위치를 얻는다(미선택 시 끝). */
  caretRef?: React.MutableRefObject<(() => number) | null>;
}

/**
 * 합성 입력 — 칩은 원자(`contenteditable=false`), 평문 span 만 편집 가능.
 *
 * 비제어 contentEditable 의 stale 회피: DOM 의 평문은 입력 채널이고, 확정값은 평문 span 의
 * 텍스트를 세그먼트 순서대로 재조립해 새 `keyValue` 로 만든다(`onInput` 마다). 칩 위치/개수는
 * `value`(prop)가 SSoT 이므로, 평문 편집은 칩 사이 텍스트만 바꾸고 칩은 그대로 둔다.
 */
export function PlaceholderChipInput({
  value,
  onChange,
  t,
  paramLabels,
  onRequestInsert,
  onRemoveChip,
  testIdSuffix = 'pc',
  disabled = false,
  hideInsertButton = false,
  caretRef,
}: PlaceholderChipInputProps): React.ReactElement {
  // 렌더는 정규화 슬롯(칩 양옆·사이·끝에 항상 편집 평문 슬롯) 기준 — 칩 끝 커서/칩 사이 타이핑·
  // 칩 이동 후 재이동을 모두 가능케 한다. value 변화 시 슬롯 재계산.
  const slots = useMemo(() => buildChipSlots(value), [value]);
  const rootRef = useRef<HTMLDivElement | null>(null);
  // 드래그 중인 칩 param 이름.
  const [dragParam, setDragParam] = useState<string | null>(null);
  // 드래그 중 삽입 지점(슬롯 + 글자 offset) — 어디로 들어갈지 실시간 강조.
  const [dropHint, setDropHint] = useState<{ slotIdx: number; offset: number } | null>(null);
  // 드래그 중 삽입 캐럿 화면 좌표(rootRef 기준 상대) — "어느 글자 사이" 세로 막대 표시.
  const [caretPos, setCaretPos] = useState<{ left: number; top: number; height: number } | null>(null);

  // 타이핑 안전성: 평문 슬롯은 **비제어 contentEditable**.
  // React 가 매 onInput 마다 텍스트를 자식으로 다시 그리면 커서가 튀므로, 슬롯 텍스트는 ref 로
  // 주입하고 DOM 을 진실로 둔다. structureSignature(칩 개수·순서·param) 가 같으면(=평문만 타이핑)
  // DOM 재주입을 건너뛴다. 칩 이동/삽입/해제로 시그니처가 바뀌면 슬롯 텍스트를 재주입한다.
  const structureSignature = useMemo(
    () => slots.map((s) => (s.kind === 'chip' ? `c:${s.paramName}` : 't')).join('|'),
    [slots],
  );
  const lastSigRef = useRef<string | null>(null);
  const textRefs = useRef<Array<HTMLSpanElement | null>>([]);
  // 칩 이동/삽입 등 구조적 변경 시 DOM 강제 재주입 플래그. structureSignature 가 같아도
  // (text-chip-text → text-chip-text) 칩 위치가 바뀌면 화면을 다시 그려야 한다 — 타이핑 보존
  // 최적화(시그니처 동일 시 skip)가 칩 이동을 가로막던 결함을 우회한다.
  const forceResyncRef = useRef(false);

  useLayoutEffect(() => {
    const force = forceResyncRef.current;
    forceResyncRef.current = false;
    if (!force && lastSigRef.current === structureSignature) return;
    lastSigRef.current = structureSignature;
    slots.forEach((slot, i) => {
      if (slot.kind !== 'text') return;
      const el = textRefs.current[i];
      if (el && el.textContent !== (slot.text ?? '')) el.textContent = slot.text ?? '';
    });
  }, [structureSignature, slots, value]);

  /** 슬롯(평문 DOM + 칩 토큰)을 순서대로 재조립해 새 keyValue 를 만든다. */
  const recompose = (): string => {
    const root = rootRef.current;
    if (!root) return value;
    const parts: string[] = [];
    for (const el of Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[]) {
      if (el.dataset.seg === 'chip') parts.push(`{${el.dataset.param ?? ''}}`);
      else parts.push(el.textContent ?? '');
    }
    return parts.join('');
  };

  const handleInput = (): void => {
    if (disabled) return;
    onChange(recompose());
  };

  // 포인터 기반 칩 드래그. contentEditable 평문 슬롯 위에서는
  // 브라우저 native drag/drop 이 텍스트 편집으로 가로채여 onDrop 이 신뢰성 없게 동작한다(
  // 글자 사이 이동 안 됨). pointerdown→pointermove→pointerup 으로 직접 제어하면 contentEditable
  // 간섭 없이 caretRangeFromPoint 로 글자 단위 정밀 드롭이 가능하다(횟수 무제한·어디로든).
  const pointerDragRef = useRef<{ param: string; pointerId: number } | null>(null);
  // 칩을 드래그(실제 이동)하면 그 칩이 커서 아래(드롭 지점)로 오므로, pointerup 직후 브라우저가 합성하는
  // click 이 (커서 아래로 따라온) **그 칩의 X 버튼**에 떨어져 onRemoveChip 이 오발화한다(
  // 결함 — 계측 확정: clickLog 에 chip-remove 버튼 click 포착). 드래그가 실제 움직였으면 직후 click 1회를
  // 무시해 X 오발화를 차단한다(의도적 X 클릭은 드래그 없이 click 단독이라 영향 없음).
  const draggedRecentlyRef = useRef(false);

  const handleChipPointerDown = (e: React.PointerEvent, paramName: string): void => {
    if (disabled) return;
    e.preventDefault(); // 텍스트 선택/네이티브 드래그 방지.
    e.stopPropagation();
    pointerDragRef.current = { param: paramName, pointerId: e.pointerId };
    draggedRecentlyRef.current = false; // 새 드래그 시작 — 이동 발생 시에만 true 로.
    setDragParam(paramName);
    try { (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); } catch { /* 캡처 실패 무시 */ }
  };

  const handleChipPointerMove = (e: React.PointerEvent): void => {
    const drag = pointerDragRef.current;
    if (!drag || e.pointerId !== drag.pointerId) return;
    draggedRecentlyRef.current = true; // 실제 이동 발생 → 직후 합성 click(X 오발화) 1회 무시.
    // 포인터 아래 평문 슬롯 + 글자 offset 계산 → drop-hint 강조 + 캐럿(삽입 지점) 좌표 갱신.
    const target = locateSlotAtPoint(e.clientX, e.clientY);
    if (target) {
      setDropHint({ slotIdx: target.slotIdx, offset: target.offset });
      setCaretPos(measureCaretPos(rootRef.current, target.slotIdx, target.offset));
    }
  };

  /** 칩 X click — 드래그 직후 합성 click(X 오발화)이면 1회 무시, 아니면 데이터 연결 해제. */
  const handleChipRemoveClick = (e: React.MouseEvent, paramName: string): void => {
    e.stopPropagation();
    if (draggedRecentlyRef.current) { draggedRecentlyRef.current = false; return; }
    onRemoveChip?.(paramName);
  };

  const handleChipPointerUp = (e: React.PointerEvent): void => {
    const drag = pointerDragRef.current;
    if (!drag || e.pointerId !== drag.pointerId) return;
    try { (e.currentTarget as HTMLElement).releasePointerCapture(e.pointerId); } catch { /* 무시 */ }
    pointerDragRef.current = null;
    // 드래그가 실제 이동했으면, 직후 합성 click(X 오발화 차단용) 가드가 켜진 상태다. 그 click 은 pointerup
    // 과 같은 마이크로/매크로 태스크 경계에서 오므로, 다음 틱에 가드를 해제해 **이후** 의도적 X 클릭이
    // 잘못 무시되지 않게 한다(가드가 영구 true 로 남는 회귀 방지).
    if (draggedRecentlyRef.current && typeof setTimeout === 'function') {
      setTimeout(() => { draggedRecentlyRef.current = false; }, 0);
    }
    const target = locateSlotAtPoint(e.clientX, e.clientY);
    setDragParam(null);
    setDropHint(null);
    setCaretPos(null);
    if (!target) return;
    const root = rootRef.current;
    if (!root) return;
    const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
    const slotState: ChipSlot[] = segEls.map((el) =>
      el.dataset.seg === 'chip'
        ? { kind: 'chip', paramName: el.dataset.param ?? '' }
        : { kind: 'text', text: el.textContent ?? '' },
    );
    const next = recomposeChipMove(slotState, drag.param, target.slotIdx, target.offset);
    if (next !== value) {
      // 칩 이동은 구조적 변경 — 다음 렌더에서 DOM(칩 위치+평문)을 강제 재주입해야 화면에 반영된다
      // (structureSignature 가 같아도 칩 위치가 바뀌었으므로 보존 최적화를 건너뛴다)..
      forceResyncRef.current = true;
      onChange(next);
    }
  };

  /**
   * 화면 좌표 아래의 평문 슬롯 + 글자 offset 을 찾는다.
   *
   * elementFromPoint 로 평문 슬롯(`[data-seg=text]`)을 찾고, caretRangeFromPoint 로 그 안 글자
   * offset 을 구한다. 슬롯 위가 아니면(칩/여백) 가장 가까운 평문 슬롯으로 폴백(좌우 경계).
   *
   * @returns { slotIdx, offset } 또는 null
   */
  const locateSlotAtPoint = (x: number, y: number): { slotIdx: number; offset: number } | null => {
    const root = rootRef.current;
    if (!root) return null;
    const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
    const doc = root.ownerDocument;
    const el = doc.elementFromPoint(x, y) as HTMLElement | null;
    // 포인터가 평문 슬롯 위 — 그 슬롯 + 글자 offset.
    let slotEl = el?.closest('[data-seg="text"]') as HTMLElement | null;
    if (slotEl) {
      const idx = segEls.indexOf(slotEl);
      return { slotIdx: idx, offset: caretOffsetInSlot(doc, slotEl, x, y) };
    }
    // 포인터가 칩 위 — 그 칩에 인접한 평문 슬롯(좌/우 중 가까운 쪽)으로.
    const chipEl = el?.closest('[data-seg="chip"]') as HTMLElement | null;
    if (chipEl) {
      const idx = segEls.indexOf(chipEl);
      const cr = chipEl.getBoundingClientRect();
      // 칩 왼쪽 절반 → 앞 슬롯 끝, 오른쪽 절반 → 뒤 슬롯 0.
      if (x < cr.left + cr.width / 2 && idx - 1 >= 0) {
        const prev = segEls[idx - 1];
        return { slotIdx: idx - 1, offset: (prev.textContent ?? '').length };
      }
      if (idx + 1 < segEls.length) return { slotIdx: idx + 1, offset: 0 };
    }
    // 그 외(여백) — 루트 안 가장 가까운 평문 슬롯(끝).
    const lastText = segEls.map((s, i) => ({ s, i })).filter((o) => o.s.dataset.seg === 'text').pop();
    if (lastText) return { slotIdx: lastText.i, offset: (lastText.s.textContent ?? '').length };
    return null;
  };

  /** '+데이터' — 현재 선택 caret 의 키 값 절대 위치를 계산해 부모에 넘긴다(없으면 끝). */
  const handleRequestInsert = (): void => {
    if (disabled || !onRequestInsert) return;
    onRequestInsert(currentCaretAbsIndex(rootRef.current, value.length));
  };

  // 외부 '+데이터' 버튼이 현재 caret 위치를 읽도록 노출(데이터 칩 모드 액션
  // 버튼을 입력칸 우측 한 줄로 통일하면서 커서 위치 삽입 보존). 부모는 caretRef.current?.() 로 호출.
  useEffect(() => {
    if (!caretRef) return;
    caretRef.current = () => currentCaretAbsIndex(rootRef.current, value.length);
    return () => { caretRef.current = null; };
  }, [caretRef, value.length]);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <div
        ref={rootRef}
        data-testid={`g7le-chip-input-${testIdSuffix}`}
        className="g7le-chip-input"
        style={chipInputBox}
      >
        {slots.map((slot, i) =>
          slot.kind === 'chip' ? (
            // 칩 — key 를 param 이름으로 고정 → 타이핑/이동으로 위치가 바뀌어도 재마운트 없이 안정.
            // 어디로든 횟수 무제한 드래그 이동 가능. 원자 토큰(키 누르기로 삭제 불가).
            <span
              key={`chip-${slot.paramName}`}
              data-seg="chip"
              data-param={slot.paramName}
              data-testid={`g7le-chip-${testIdSuffix}-${slot.paramName}`}
              contentEditable={false}
              // 포인터 기반 드래그 — native draggable 폐기(contentEditable 간섭 회피).
              // pointerdown 으로 캡처 시작 → move 로 삽입 지점 추적 → up 으로 글자 단위 드롭.
              onPointerDown={(e) => handleChipPointerDown(e, slot.paramName ?? '')}
              onPointerMove={handleChipPointerMove}
              onPointerUp={handleChipPointerUp}
              title={t('layout_editor.translation.placeholder_locked')}
              style={{ ...chipStyle, ...(dragParam === slot.paramName ? chipDragging : null), touchAction: 'none' }}
            >
              <span aria-hidden="true" style={chipGrip}>⠿</span>
              <span aria-hidden="true" style={{ marginRight: 3 }}>🔗</span>
              <span style={chipLabel}>{paramLabels?.[slot.paramName ?? ''] ?? slot.paramName}</span>
              {onRemoveChip && !disabled && (
                // 데이터 연결 '해제'. 드래그 시작과 분리하기
                // 위해 pointerdown 전파 차단(칩 onPointerDown=드래그 시작이 X 위에서 발화하지 않게).
                <button
                  type="button"
                  data-testid={`g7le-chip-remove-${testIdSuffix}-${slot.paramName}`}
                  aria-label={t('layout_editor.inline_binding.clear')}
                  title={t('layout_editor.inline_binding.clear')}
                  onPointerDown={(e) => { e.stopPropagation(); e.preventDefault(); }}
                  onClick={(e) => handleChipRemoveClick(e, slot.paramName ?? '')}
                  style={chipRemoveBtn}
                >
                  ✕
                </button>
              )}
            </span>
          ) : (
            // 평문 슬롯 — 칩 앞·사이·끝 어디나 존재(buildChipSlots). 비제어 contentEditable 로
            // 자유 타이핑 + 칩 드롭 타겟. minWidth 로 빈 슬롯도 클릭/드롭 가능한 폭 확보(칩 사이·끝
            // 커서 위치 보장). 드래그 중엔 drop-zone 강조.
            <span
              key={`text-slot-${i}`}
              ref={(el) => { textRefs.current[i] = el; }}
              data-seg="text"
              contentEditable={!disabled}
              suppressContentEditableWarning
              onInput={handleInput}
              data-testid={`g7le-chip-text-${testIdSuffix}-${i}`}
              style={{ ...textSeg, ...(dragParam ? textSegDropTarget : null), ...(dropHint?.slotIdx === i ? textSegDropActive : null) }}
            />
          ),
        )}
        {/* 드래그 중 삽입 캐럿 — 어느 글자 사이에 들어갈지 세로 막대로 명시. 절대배치. */}
        {caretPos && (
          <span
            aria-hidden="true"
            data-testid={`g7le-chip-caret-${testIdSuffix}`}
            style={{
              position: 'absolute',
              left: caretPos.left,
              top: caretPos.top,
              width: 2,
              height: caretPos.height,
              background: '#2563eb',
              borderRadius: 1,
              pointerEvents: 'none',
              boxShadow: '0 0 0 1px rgba(37,99,235,0.3)',
            }}
          />
        )}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        {/* 내부 '+데이터' 버튼 — hideInsertButton 시 숨김(부모가 입력칸 우측 액션 행에서 렌더해
 평문 모드와 위치 통일). 커서 위치 삽입은 caretRef 로 보존. */}
        {onRequestInsert && !hideInsertButton && (
          <button
            type="button"
            data-testid={`g7le-chip-insert-${testIdSuffix}`}
            onClick={handleRequestInsert}
            disabled={disabled}
            style={insertBtn}
          >
            + {t('layout_editor.inline_binding.insert_data')}
          </button>
        )}
        <span style={chipHint}>{t('layout_editor.translation.placeholder_locked')}</span>
      </div>
    </div>
  );
}

/**
 * 화면 좌표(x,y)를 **슬롯 내 글자 offset** 으로 정밀 변환한다.
 *
 * `caretRangeFromPoint` 의 결과 텍스트 노드가 슬롯(`slotEl`) 안이면 그 offset(글자 단위)을 반환한다.
 * 슬롯 밖이면 x 가 슬롯 중앙보다 오른쪽이면 끝, 왼쪽이면 0 으로 폴백(빈 슬롯·경계). 글자 한 자 한 자
 * 사이 모두에 칩을 떨어뜨릴 수 있도록 보장한다.
 *
 * @param doc 문서
 * @param slotEl 대상 평문 슬롯
 * @param x 화면 X
 * @param y 화면 Y
 * @returns 슬롯 텍스트 내 0~length 글자 offset
 */
function caretOffsetInSlot(doc: Document, slotEl: HTMLElement, x: number, y: number): number {
  const len = (slotEl.textContent ?? '').length;
  try {
    const anyDoc = doc as unknown as {
      caretRangeFromPoint?: (x: number, y: number) => Range | null;
      caretPositionFromPoint?: (x: number, y: number) => { offsetNode: Node; offset: number } | null;
    };
    let node: Node | null = null;
    let offset = 0;
    if (typeof anyDoc.caretRangeFromPoint === 'function') {
      const r = anyDoc.caretRangeFromPoint(x, y);
      if (r) { node = r.startContainer; offset = r.startOffset; }
    } else if (typeof anyDoc.caretPositionFromPoint === 'function') {
      const p = anyDoc.caretPositionFromPoint(x, y);
      if (p) { node = p.offsetNode; offset = p.offset; }
    }
    if (node && slotEl.contains(node)) return Math.max(0, Math.min(offset, len));
  } catch {
    /* 폴백 아래 */
  }
  const rect = slotEl.getBoundingClientRect();
  return x > rect.left + rect.width / 2 ? len : 0;
}

/**
 * 삽입 캐럿(슬롯 + 글자 offset)의 화면 좌표를 root 기준 상대 위치로 계산한다.
 *
 * 슬롯의 텍스트 노드에 Range 를 만들어 offset 지점의 클라이언트 사각형을 얻고, root 좌상단 기준으로
 * 환산한다. 텍스트가 비었거나 Range 실패 시 슬롯 좌/우 경계로 폴백. "어느 글자 사이" 세로 막대용.
 *
 * @param root 합성 입력 루트
 * @param slotIdx 슬롯 인덱스
 * @param offset 슬롯 텍스트 내 글자 offset
 * @returns { left, top, height } (root 상대) 또는 null
 */
function measureCaretPos(
  root: HTMLElement | null,
  slotIdx: number,
  offset: number,
): { left: number; top: number; height: number } | null {
  if (!root) return null;
  const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
  const slotEl = segEls[slotIdx];
  if (!slotEl) return null;
  const rootRect = root.getBoundingClientRect();
  try {
    const textNode = slotEl.firstChild;
    const doc = root.ownerDocument;
    if (textNode && textNode.nodeType === Node.TEXT_NODE && (textNode.textContent ?? '').length > 0) {
      const range = doc.createRange();
      const safe = Math.max(0, Math.min(offset, (textNode.textContent ?? '').length));
      range.setStart(textNode, safe);
      range.setEnd(textNode, safe);
      const rects = range.getClientRects();
      const rc = rects.length > 0 ? rects[0] : range.getBoundingClientRect();
      if (rc && (rc.height > 0 || rc.width >= 0)) {
        return { left: rc.left - rootRect.left, top: rc.top - rootRect.top, height: rc.height || slotEl.getBoundingClientRect().height };
      }
    }
  } catch {
    /* 폴백 아래 */
  }
  // 빈 슬롯/실패 — 슬롯 좌/우 경계.
  const sr = slotEl.getBoundingClientRect();
  const atEnd = offset > 0;
  return { left: (atEnd ? sr.right : sr.left) - rootRect.left, top: sr.top - rootRect.top, height: sr.height || 16 };
}

/**
 * 현재 selection caret 의 합성 입력 전체 기준 키 값 절대 문자 인덱스를 계산한다.
 * caret 이 평문 span 안이면 그 span 의 `data-seg` 시작 + 평문 offset, 칩 위/밖이면 끝.
 *
 * @param root 합성 입력 루트
 * @param fallbackEnd selection 부재 시 끝 인덱스
 * @returns 키 값 절대 인덱스
 */
function currentCaretAbsIndex(root: HTMLElement | null, fallbackEnd: number): number {
  if (!root) return fallbackEnd;
  try {
    const sel = root.ownerDocument.defaultView?.getSelection();
    if (!sel || sel.rangeCount === 0) return fallbackEnd;
    const range = sel.getRangeAt(0);
    let node: Node | null = range.startContainer;
    // caret 이 위치한 평문 span 을 찾는다.
    let spanEl: HTMLElement | null = null;
    while (node && node !== root) {
      if (node instanceof HTMLElement && node.dataset.seg === 'text') {
        spanEl = node;
        break;
      }
      node = node.parentNode;
    }
    if (!spanEl || !root.contains(spanEl)) return fallbackEnd;
    // 이 span 앞의 모든 세그먼트 길이 합 + caret offset.
    const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
    let base = 0;
    for (const el of segEls) {
      if (el === spanEl) break;
      base += el.dataset.seg === 'chip' ? `{${el.dataset.param ?? ''}}`.length : (el.textContent ?? '').length;
    }
    return base + range.startOffset;
  } catch {
    return fallbackEnd;
  }
}

/**
 * 부모(번역 탭/인라인)가 '+데이터' 삽입을 배선할 때 쓰는 키 값 변환 헬퍼 — 편집 로케일은
 * 커서 위치 삽입(`insertPlaceholderAt`), 미편집 로케일은 끝 추가(`appendPlaceholder`).
 * 본 위젯은 단일 로케일만 다루므로 편집 로케일 변환만 노출(미편집 로케일은 부모가 처리).
 *
 * @param keyValue 편집 로케일 키 값
 * @param charIndex 커서 위치
 * @param paramName 새 param 이름
 * @returns 자리표시가 삽입된 키 값
 */
export function insertChipInValue(keyValue: string, charIndex: number, paramName: string): string {
  return insertPlaceholderAt(keyValue, charIndex, paramName);
}

const chipInputBox: React.CSSProperties = {
  position: 'relative', // 드래그 캐럿(절대배치) 기준.
  display: 'flex',
  flexWrap: 'wrap',
  alignItems: 'center',
  gap: 2,
  minHeight: 30,
  padding: '4px 8px',
  border: '1px solid #cbd5e1',
  borderRadius: 4,
  background: '#fff',
  fontSize: 13,
  lineHeight: 1.6,
};
// 평문 슬롯 — 빈 슬롯도 클릭/드롭 가능하도록 최소 폭 확보(칩 사이·끝 커서 위치 보장).
const textSeg: React.CSSProperties = { outline: 'none', minWidth: 8, minHeight: '1.2em', whiteSpace: 'pre-wrap', color: '#0f172a', display: 'inline-block' };
// 드래그 중 평문 슬롯 — drop 가능 지점임을 시각화(어디에 놓일지 인지). 사용자 편의.
const textSegDropTarget: React.CSSProperties = { boxShadow: 'inset 0 -2px 0 #93c5fd', background: '#eff6ff', borderRadius: 2 };
// 현재 드래그가 머무는 슬롯 — 더 강한 강조(칩이 이 슬롯의 글자 사이로 들어감).
const textSegDropActive: React.CSSProperties = { background: '#dbeafe', boxShadow: 'inset 0 -2px 0 #2563eb' };
const chipStyle: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: 1,
  // 뱃지 — 데이터임을 한눈에(파란 알약 + 점선 테두리로 "끌 수 있는 토큰" 암시).
  background: 'linear-gradient(180deg,#eff6ff,#dbeafe)',
  color: '#1d4ed8',
  border: '1px dashed #60a5fa',
  borderRadius: 9999,
  padding: '1px 8px 1px 4px',
  margin: '0 1px',
  fontSize: 12,
  fontWeight: 600,
  cursor: 'grab',
  userSelect: 'none',
  whiteSpace: 'nowrap',
  boxShadow: '0 1px 2px rgba(37,99,235,0.18)',
  verticalAlign: 'middle',
};
/** 드래그 중 칩 강조(반투명 + 실선 테두리). */
const chipDragging: React.CSSProperties = { opacity: 0.5, borderStyle: 'solid', cursor: 'grabbing' };
/** 드래그 그립(점 그리드) — 끌 수 있음을 시각적으로 명시. */
const chipGrip: React.CSSProperties = { color: '#2563eb', opacity: 0.7, fontSize: 11, marginRight: 1, cursor: 'grab' };
/** 칩 라벨(데이터 친화명). */
const chipLabel: React.CSSProperties = { textDecoration: 'none' };
/** 칩 우측 '해제' X 버튼. 작은 원형, hover 시 적색 강조. */
const chipRemoveBtn: React.CSSProperties = {
  marginLeft: 4,
  width: 14,
  height: 14,
  lineHeight: '12px',
  padding: 0,
  border: 'none',
  borderRadius: 9999,
  background: 'rgba(29,78,216,0.12)',
  color: '#1d4ed8',
  fontSize: 9,
  fontWeight: 700,
  cursor: 'pointer',
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  flex: '0 0 auto',
};
const insertBtn: React.CSSProperties = {
  fontSize: 11,
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  background: '#f8fafc',
  color: '#334155',
  padding: '3px 10px',
  cursor: 'pointer',
};
const chipHint: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
