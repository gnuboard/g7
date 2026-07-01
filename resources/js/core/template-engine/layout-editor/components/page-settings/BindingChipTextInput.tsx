// e2e:allow 평문+데이터칩 인라인 칩 편집기 — contentEditable/포인터 드래그/
// 합성 이벤트 의존으로 Playwright 자동화 부적합. 순수 파싱·재조립 유틸은 단위(BindingChipTextInput.test)로
// 전수 검증, 라이브는 Chrome MCP 매트릭스(데이터 탭2~T3)로 검증. PlaceholderChipInput L1 과 동일 정책.
/**
 * BindingChipTextInput.tsx — 평문 + 데이터칩(`{{...}}`) 혼합 값 인라인 칩 편집기
 *
 * 엔드포인트 URL(`/api/.../products/{{route.id}}`)·errorHandling 메시지/경로 등 **다국어 키화 없이**
 * node.text 문자열 자체에 평문과 데이터 바인딩이 섞인 값을, `{{...}}` 바인딩(과 `$..._settings:` 설정
 * 참조)은 **드래그 가능한 원자 칩**으로, 그 사이 평문만 편집 가능한 합성 입력으로 렌더한다.
 *
 * `PlaceholderChipInput`(다국어 키 + `{pN}` 자리표시)과 **동일한 검증된 아키텍처**(슬롯 정규화 /
 * 비제어 contentEditable / 포인터 글자단위 드롭 / 칩 원자성 / X 해제)를 따르되, 토큰화 단위가 다르다:
 *
 *  - `PlaceholderChipInput`: `{pN}` 자리표시 토큰(키 값 문장) — 다국어 키 시스템 전용.
 *  - `BindingChipTextInput`: `{{...}}` 데이터 바인딩 + `$..._settings:` 설정 참조 — **키화 0**, node.text
 *    문자열을 직접 편집(서버 키 fetch/put 없음).
 *
 * 동작:
 *  - **평문 편집**: 칩 사이 평문만 타이핑. 칩은 원자 토큰(키 누르기로 안 지워짐).
 *  - **칩 이동**: 칩을 드래그해 같은 입력 안 다른 글자 위치로 drop(글자단위 정밀).
 *  - **새 칩 삽입**: [+데이터] → ScalarPicker → **커서 위치**에 `{{src?.path}}` 삽입(폴백 없는 순수 바인딩).
 *  - **칩 삭제(X)**: 그 `{{...}}` 토큰만 문자열에서 제거.
 *  - **완료**: [✓ 완료] → 칩 시각화(읽기) 모드로 복귀(부모가 editing 끔).
 *
 * 자료구조 SSoT: `value`(node.text 문자열) → InlineSegment(평문/바인딩) 으로 파싱(렌더) → 사용자 조작 →
 * 순수 재조립 → `onChange(next)`. DOM 은 표시·입력 채널일 뿐 진실은 `value` 문자열(비제어 stale 회피).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(feedback_layout_editor_no_css_lib_dependency).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { InlineBindingScalarPicker } from '../property-controls/InlineBindingScalarPicker';
import { FloatingDropdown } from '../shared/FloatingDropdown';
import { bindingChipLabel } from '../../spec/inlineBindingUtils';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** `$core_settings:`/`$module_settings:`/`$plugin_settings:` 설정 참조 토큰 — 보간(`{{}}`) 아님(칩 표시 전용). */
const SETTINGS_REF_RE = /\$(?:core|module|plugin)_settings:[a-zA-Z0-9._:-]+/;

/** 렌더 슬롯 — 칩 양옆·사이·양끝에 항상 편집 평문 슬롯을 보장한 정규화 목록. */
export interface BindingChipSlot {
  kind: 'text' | 'chip';
  /** (text) 평문 / (chip) 칩 친화 라벨(표시용) */
  text?: string;
  /** (chip) 원문 토큰(`{{...}}` 또는 `$..._settings:...`) — 재조립 SSoT */
  raw?: string;
  /** (chip) 데이터 연결 해제(X) 가능 여부 — `{{...}}` 바인딩만 true(설정 참조는 코드 편집 전용) */
  removable?: boolean;
}

/** 값 문자열을 [text, chip, text, …, text] 교차 슬롯으로 정규화한다(칩 앞·사이·끝에 빈 평문 슬롯 보장). */
export function buildBindingChipSlots(value: string): BindingChipSlot[] {
  const slots: BindingChipSlot[] = [];
  let lastWasText = false;
  const pushText = (txt: string): void => { slots.push({ kind: 'text', text: txt }); lastWasText = true; };
  const pushChip = (raw: string, label: string, removable: boolean): void => {
    if (!lastWasText) pushText(''); // 칩 앞에 평문 슬롯 보장(칩끼리 붙거나 맨 앞 칩).
    slots.push({ kind: 'chip', raw, text: label, removable });
    lastWasText = false;
  };
  for (const seg of tokenizeBindingSegments(value)) {
    if (seg.kind === 'text') {
      pushText(seg.raw);
    } else {
      pushChip(seg.raw, seg.label, seg.removable);
    }
  }
  if (!lastWasText) pushText(''); // 마지막이 칩이거나 빈 값이면 끝에 편집 슬롯 보장.
  return slots;
}

interface BindingToken {
  kind: 'text' | 'chip';
  raw: string;
  /** (chip) 친화 라벨 */
  label: string;
  /** (chip) X 해제 가능(바인딩만) */
  removable: boolean;
}

/**
 * 값 문자열을 평문/칩(바인딩·설정참조) 토큰으로 분해한다(무손실 — raw 를 이으면 원문 동일).
 * `{{...}}` 는 바인딩 칩(해제 가능), `$..._settings:` 는 설정 칩(해제 불가 — 코드 편집 전용), 그 외 평문.
 */
export function tokenizeBindingSegments(value: string): BindingToken[] {
  const out: BindingToken[] = [];
  if (typeof value !== 'string' || value.length === 0) return out;
  const n = value.length;
  let i = 0;
  let last = 0;
  const flushText = (end: number): void => {
    if (end > last) out.push({ kind: 'text', raw: value.slice(last, end), label: '', removable: false });
  };
  while (i < n) {
    // `{{...}}` 바인딩 — 짝 닫는 `}}` 까지(단일 `}` 허용, 런타임 보간 규칙과 동일).
    if (value[i] === '{' && value[i + 1] === '{') {
      let j = i + 2;
      while (j < n && !(value[j] === '}' && value[j + 1] === '}')) j++;
      if (j >= n) { i++; continue; } // 미닫힘 — 평문으로 흡수(flushText 전이라 평문에 머문다).
      flushText(i); // 닫힘 확인 후에만 평문 선행 조각 flush(미닫힘이 평문에 흡수되게).
      const raw = value.slice(i, j + 2);
      out.push({ kind: 'chip', raw, label: bindingChipLabel(raw), removable: true });
      i = j + 2;
      last = i;
      continue;
    }
    // `$..._settings:` 설정 참조 — 한 토큰(해제 불가, 표시만).
    if (value[i] === '$') {
      const m = SETTINGS_REF_RE.exec(value.slice(i));
      if (m && m.index === 0) {
        flushText(i);
        const raw = m[0];
        const label = raw.split(':').pop() || raw;
        out.push({ kind: 'chip', raw, label, removable: false });
        i += raw.length;
        last = i;
        continue;
      }
    }
    i++;
  }
  flushText(n);
  return out;
}

/**
 * 칩 이동 재조립(순수) — 현재 슬롯 배열에서 `dragRaw` 칩을 제거하고, `dropSlotIndex` 평문 슬롯의
 * `charOffset` 글자 위치에 다시 삽입한 새 value 를 만든다(글자 사이 정밀). DOM 의존 분리(단위 테스트 가능).
 */
export function recomposeBindingChipMove(
  slotState: BindingChipSlot[],
  dragRaw: string,
  dropSlotIndex: number,
  charOffset: number,
): string {
  const parts: string[] = [];
  let removed = false;
  slotState.forEach((slot, idx) => {
    if (slot.kind === 'chip') {
      if (!removed && slot.raw === dragRaw) { removed = true; return; } // 이동 칩 원위치 제거(첫 일치 1개).
      parts.push(slot.raw ?? '');
    } else {
      const txt = slot.text ?? '';
      if (idx === dropSlotIndex) {
        const cut = Math.max(0, Math.min(charOffset, txt.length));
        parts.push(txt.slice(0, cut), dragRaw, txt.slice(cut));
      } else {
        parts.push(txt);
      }
    }
  });
  return parts.join('');
}

/** 값 문자열의 `charIndex` 위치에 `{{...}}` 바인딩 토큰을 삽입한 새 값(순수). */
export function insertBindingAt(value: string, charIndex: number, bindingRaw: string): string {
  const at = Math.max(0, Math.min(charIndex, value.length));
  return value.slice(0, at) + bindingRaw + value.slice(at);
}

/** 값 문자열에서 첫 번째 일치하는 `{{...}}` 칩 토큰(raw)을 제거한 새 값(순수). */
export function removeBindingChip(value: string, bindingRaw: string): string {
  const at = value.indexOf(bindingRaw);
  if (at < 0) return value;
  return value.slice(0, at) + value.slice(at + bindingRaw.length);
}

export interface BindingChipTextInputProps {
  /** 현재 값(node.text — 평문 + `{{...}}` 칩 혼합) */
  value: string;
  /** 값 변경 콜백(평문 편집/칩 이동/삽입/해제 결과) */
  onChange: (next: string) => void;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 데이터 바인딩 후보 풀 — [+데이터] 커서 삽입용. 미전달/빈 배열이면 [+데이터] 숨김(삽입 입구 없음). */
  candidates?: BindingCandidate[];
  /** [✓ 완료] — 칩 시각화(읽기) 모드로 복귀(부모가 editing 끔). 미전달 시 [완료] 숨김. */
  onDone?: () => void;
  /** data-testid 접두 */
  testidPrefix?: string;
  /** 읽기전용 */
  disabled?: boolean;
}

/**
 * 평문+데이터칩 인라인 칩 편집기.
 *
 * @param props BindingChipTextInputProps
 * @return 합성 칩 입력 엘리먼트
 */
export function BindingChipTextInput({
  value,
  onChange,
  t,
  candidates,
  onDone,
  testidPrefix = 'g7le-binding-chip-text',
  disabled = false,
}: BindingChipTextInputProps): React.ReactElement {
  const slots = useMemo(() => buildBindingChipSlots(value), [value]);
  const rootRef = useRef<HTMLDivElement | null>(null);
  const hasCandidates = !!candidates && candidates.length > 0;

  const [dragRaw, setDragRaw] = useState<string | null>(null);
  const [dropHint, setDropHint] = useState<{ slotIdx: number; offset: number } | null>(null);
  const [caretPos, setCaretPos] = useState<{ left: number; top: number; height: number } | null>(null);
  const [insertOpen, setInsertOpen] = useState(false);
  const insertAtRef = useRef<number>(0);
  const insertBtnRef = useRef<HTMLButtonElement | null>(null);

  // 비제어 contentEditable — 슬롯 텍스트를 ref 로 주입, DOM 을 진실로 둔다(타이핑 중 커서 튐 방지).
  // structureSignature(칩 개수·순서·raw) 가 같으면(평문만 타이핑) DOM 재주입 skip, 구조 변경 시만 재주입.
  const structureSignature = useMemo(
    () => slots.map((s) => (s.kind === 'chip' ? `c:${s.raw}` : 't')).join('|'),
    [slots],
  );
  const lastSigRef = useRef<string | null>(null);
  const textRefs = useRef<Array<HTMLSpanElement | null>>([]);
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

  /** 슬롯(평문 DOM + 칩 raw)을 순서대로 재조립해 새 value 를 만든다. */
  const recompose = useCallback((): string => {
    const root = rootRef.current;
    if (!root) return value;
    const parts: string[] = [];
    for (const el of Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[]) {
      if (el.dataset.seg === 'chip') parts.push(el.dataset.raw ?? '');
      else parts.push(el.textContent ?? '');
    }
    return parts.join('');
  }, [value]);

  const handleInput = useCallback((): void => {
    if (disabled) return;
    onChange(recompose());
  }, [disabled, onChange, recompose]);

  // 포인터 기반 칩 드래그(contentEditable native drag 간섭 회피 — PlaceholderChipInput 와 동일).
  const pointerDragRef = useRef<{ raw: string; pointerId: number } | null>(null);
  const draggedRecentlyRef = useRef(false);

  const handleChipPointerDown = (e: React.PointerEvent, raw: string): void => {
    if (disabled) return;
    e.preventDefault();
    e.stopPropagation();
    pointerDragRef.current = { raw, pointerId: e.pointerId };
    draggedRecentlyRef.current = false;
    setDragRaw(raw);
    try { (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); } catch { /* 무시 */ }
  };

  const handleChipPointerMove = (e: React.PointerEvent): void => {
    const drag = pointerDragRef.current;
    if (!drag || e.pointerId !== drag.pointerId) return;
    draggedRecentlyRef.current = true;
    const target = locateSlotAtPoint(e.clientX, e.clientY);
    if (target) {
      setDropHint({ slotIdx: target.slotIdx, offset: target.offset });
      setCaretPos(measureCaretPos(rootRef.current, target.slotIdx, target.offset));
    }
  };

  const handleChipRemoveClick = (e: React.MouseEvent, raw: string): void => {
    e.stopPropagation();
    if (draggedRecentlyRef.current) { draggedRecentlyRef.current = false; return; } // 드래그 직후 합성 click(오발화) 1회 무시.
    forceResyncRef.current = true;
    onChange(removeBindingChip(value, raw));
  };

  const handleChipPointerUp = (e: React.PointerEvent): void => {
    const drag = pointerDragRef.current;
    if (!drag || e.pointerId !== drag.pointerId) return;
    try { (e.currentTarget as HTMLElement).releasePointerCapture(e.pointerId); } catch { /* 무시 */ }
    pointerDragRef.current = null;
    if (draggedRecentlyRef.current && typeof setTimeout === 'function') {
      setTimeout(() => { draggedRecentlyRef.current = false; }, 0);
    }
    const target = locateSlotAtPoint(e.clientX, e.clientY);
    setDragRaw(null);
    setDropHint(null);
    setCaretPos(null);
    if (!target) return;
    const root = rootRef.current;
    if (!root) return;
    const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
    const slotState: BindingChipSlot[] = segEls.map((el) =>
      el.dataset.seg === 'chip'
        ? { kind: 'chip', raw: el.dataset.raw ?? '' }
        : { kind: 'text', text: el.textContent ?? '' },
    );
    const next = recomposeBindingChipMove(slotState, drag.raw, target.slotIdx, target.offset);
    if (next !== value) {
      forceResyncRef.current = true; // 칩 이동은 구조 변경 — DOM 강제 재주입.
      onChange(next);
    }
  };

  /** 화면 좌표 아래의 평문 슬롯 + 글자 offset(글자 사이 정밀). 슬롯 밖이면 인접/끝 폴백. */
  const locateSlotAtPoint = (x: number, y: number): { slotIdx: number; offset: number } | null => {
    const root = rootRef.current;
    if (!root) return null;
    const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
    const doc = root.ownerDocument;
    const el = doc.elementFromPoint(x, y) as HTMLElement | null;
    const slotEl = el?.closest('[data-seg="text"]') as HTMLElement | null;
    if (slotEl) {
      const idx = segEls.indexOf(slotEl);
      return { slotIdx: idx, offset: caretOffsetInSlot(doc, slotEl, x, y) };
    }
    const chipEl = el?.closest('[data-seg="chip"]') as HTMLElement | null;
    if (chipEl) {
      const idx = segEls.indexOf(chipEl);
      const cr = chipEl.getBoundingClientRect();
      if (x < cr.left + cr.width / 2 && idx - 1 >= 0) {
        const prev = segEls[idx - 1];
        return { slotIdx: idx - 1, offset: (prev.textContent ?? '').length };
      }
      if (idx + 1 < segEls.length) return { slotIdx: idx + 1, offset: 0 };
    }
    const lastText = segEls.map((s, i) => ({ s, i })).filter((o) => o.s.dataset.seg === 'text').pop();
    if (lastText) return { slotIdx: lastText.i, offset: (lastText.s.textContent ?? '').length };
    return null;
  };

  /** [+데이터] — 현재 caret 의 value 절대 위치를 계산해 피커를 연다(없으면 끝). */
  const handleRequestInsert = (): void => {
    if (disabled || !hasCandidates) return;
    insertAtRef.current = currentCaretAbsIndex(rootRef.current, value.length);
    setInsertOpen((v) => !v);
  };

  const handlePickInsert = (c: BindingCandidate): void => {
    // 폴백 없는 순수 바인딩(`{{src?.path}}`) — 리프 컨텍스트(중첩 0). PlaceholderChipInput/DataChipBindingField 동일.
    const segs = c.path ? c.path.split('.').filter(Boolean) : [];
    const chain = [c.sourceId, ...segs].join('?.');
    forceResyncRef.current = true;
    onChange(insertBindingAt(value, insertAtRef.current, `{{${chain}}}`));
    setInsertOpen(false);
  };

  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <div ref={rootRef} data-testid={`${testidPrefix}-box`} className="g7le-binding-chip-input" style={chipInputBox}>
        {slots.map((slot, i) =>
          slot.kind === 'chip' ? (
            <span
              key={`chip-${slot.raw}-${i}`}
              data-seg="chip"
              data-raw={slot.raw}
              data-testid={`${testidPrefix}-chip-${i}`}
              contentEditable={false}
              onPointerDown={(e) => handleChipPointerDown(e, slot.raw ?? '')}
              onPointerMove={handleChipPointerMove}
              onPointerUp={handleChipPointerUp}
              title={slot.removable ? t('layout_editor.value_tree.change_data') : t('layout_editor.prop_i18n.bound_code_only')}
              style={{ ...chipStyle, ...(dragRaw === slot.raw ? chipDragging : null), touchAction: 'none' }}
            >
              <span aria-hidden="true" style={chipGrip}>⠿</span>
              <span aria-hidden="true" style={{ marginRight: 3 }}>🔗</span>
              <span>{slot.text}</span>
              {slot.removable && !disabled && (
                <button
                  type="button"
                  data-testid={`${testidPrefix}-chip-remove-${i}`}
                  aria-label={t('layout_editor.inline_binding.clear')}
                  title={t('layout_editor.inline_binding.clear')}
                  onPointerDown={(e) => { e.stopPropagation(); e.preventDefault(); }}
                  onClick={(e) => handleChipRemoveClick(e, slot.raw ?? '')}
                  style={chipRemoveBtn}
                >
                  ✕
                </button>
              )}
            </span>
          ) : (
            <span
              key={`text-slot-${i}`}
              ref={(el) => { textRefs.current[i] = el; }}
              data-seg="text"
              contentEditable={!disabled}
              suppressContentEditableWarning
              onInput={handleInput}
              data-testid={`${testidPrefix}-text-${i}`}
              style={{ ...textSeg, ...(dragRaw ? textSegDropTarget : null), ...(dropHint?.slotIdx === i ? textSegDropActive : null) }}
            />
          ),
        )}
        {caretPos && (
          <span
            aria-hidden="true"
            data-testid={`${testidPrefix}-caret`}
            style={{ position: 'absolute', left: caretPos.left, top: caretPos.top, width: 2, height: caretPos.height, background: '#2563eb', borderRadius: 1, pointerEvents: 'none', boxShadow: '0 0 0 1px rgba(37,99,235,0.3)' }}
          />
        )}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
        {onDone && (
          <button
            type="button"
            data-testid={`${testidPrefix}-done`}
            onClick={onDone}
            style={smallBtn}
            title={t('layout_editor.value_tree.done')}
            aria-label={t('layout_editor.value_tree.done')}
          >
            ✓ {t('layout_editor.value_tree.done')}
          </button>
        )}
        {hasCandidates && (
          <div style={{ position: 'relative' }}>
            <button
              ref={insertBtnRef}
              type="button"
              data-testid={`${testidPrefix}-insert`}
              onClick={handleRequestInsert}
              disabled={disabled}
              style={smallBtn}
              title={t('layout_editor.inline_binding.insert_data')}
              aria-label={t('layout_editor.inline_binding.insert_data')}
            >
              + {t('layout_editor.inline_binding.insert_data')}
            </button>
            <FloatingDropdown anchorRef={insertBtnRef} open={insertOpen} onClose={() => setInsertOpen(false)}>
              {/* 외부 FloatingDropdown 으로 직접 부유 — 이중 부유 방지 위해 picker 는 인라인 렌더. */}
              <InlineBindingScalarPicker
                candidates={candidates!}
                t={t}
                onSelect={handlePickInsert}
                testIdSuffix={`${testidPrefix}-insert`}
                defaultOpen
                floating={false}
              />
            </FloatingDropdown>
          </div>
        )}
        <span style={chipHint}>{t('layout_editor.translation.placeholder_locked')}</span>
      </div>
    </div>
  );
}

/** 화면 좌표(x,y)를 슬롯 내 글자 offset 으로 변환(caretRangeFromPoint). 슬롯 밖이면 중앙 기준 0/끝. */
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
  } catch { /* 폴백 아래 */ }
  const rect = slotEl.getBoundingClientRect();
  return x > rect.left + rect.width / 2 ? len : 0;
}

/** 삽입 캐럿(슬롯+offset)의 root 기준 상대 화면 좌표(세로 막대용). */
function measureCaretPos(root: HTMLElement | null, slotIdx: number, offset: number): { left: number; top: number; height: number } | null {
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
  } catch { /* 폴백 아래 */ }
  const sr = slotEl.getBoundingClientRect();
  const atEnd = offset > 0;
  return { left: (atEnd ? sr.right : sr.left) - rootRect.left, top: sr.top - rootRect.top, height: sr.height || 16 };
}

/** 현재 selection caret 의 value 전체 기준 절대 문자 인덱스(평문 span 안이면 앞 세그 길이 합 + offset, 아니면 끝). */
function currentCaretAbsIndex(root: HTMLElement | null, fallbackEnd: number): number {
  if (!root) return fallbackEnd;
  try {
    const sel = root.ownerDocument.defaultView?.getSelection();
    if (!sel || sel.rangeCount === 0) return fallbackEnd;
    const range = sel.getRangeAt(0);
    let node: Node | null = range.startContainer;
    let spanEl: HTMLElement | null = null;
    while (node && node !== root) {
      if (node instanceof HTMLElement && node.dataset.seg === 'text') { spanEl = node; break; }
      node = node.parentNode;
    }
    if (!spanEl || !root.contains(spanEl)) return fallbackEnd;
    const segEls = Array.from(root.querySelectorAll('[data-seg]')) as HTMLElement[];
    let base = 0;
    for (const el of segEls) {
      if (el === spanEl) break;
      base += el.dataset.seg === 'chip' ? (el.dataset.raw ?? '').length : (el.textContent ?? '').length;
    }
    return base + range.startOffset;
  } catch {
    return fallbackEnd;
  }
}

const chipInputBox: React.CSSProperties = {
  position: 'relative',
  display: 'flex',
  flexWrap: 'wrap',
  alignItems: 'center',
  gap: 2,
  minHeight: 30,
  padding: '4px 8px',
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  background: '#fff',
  fontSize: 12,
  lineHeight: 1.6,
  minWidth: 0,
  width: '100%',
  boxSizing: 'border-box',
};
const textSeg: React.CSSProperties = { outline: 'none', minWidth: 8, minHeight: '1.2em', whiteSpace: 'pre-wrap', color: '#0f172a', display: 'inline-block', wordBreak: 'break-all' };
const textSegDropTarget: React.CSSProperties = { boxShadow: 'inset 0 -2px 0 #93c5fd', background: '#eff6ff', borderRadius: 2 };
const textSegDropActive: React.CSSProperties = { background: '#dbeafe', boxShadow: 'inset 0 -2px 0 #2563eb' };
const chipStyle: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: 1,
  background: 'linear-gradient(180deg,#eff6ff,#dbeafe)',
  color: '#1d4ed8',
  border: '1px dashed #60a5fa',
  borderRadius: 9999,
  padding: '1px 8px 1px 4px',
  margin: '0 1px',
  fontSize: 11,
  fontWeight: 600,
  cursor: 'grab',
  userSelect: 'none',
  whiteSpace: 'nowrap',
  boxShadow: '0 1px 2px rgba(37,99,235,0.18)',
  verticalAlign: 'middle',
};
const chipDragging: React.CSSProperties = { opacity: 0.5, borderStyle: 'solid', cursor: 'grabbing' };
const chipGrip: React.CSSProperties = { color: '#2563eb', opacity: 0.7, fontSize: 11, marginRight: 1, cursor: 'grab' };
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
const smallBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
const chipHint: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
