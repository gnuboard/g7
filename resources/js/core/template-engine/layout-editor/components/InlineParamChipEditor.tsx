// e2e:allow 레이아웃 편집기 인라인 칩 편집 오버레이 — contentEditable/드래그 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위(PlaceholderChipInput/inlineBindingUtils)로 검증 (InlineTextEditor.tsx 와 동일 정책)
/**
 * InlineParamChipEditor.tsx — param 키 노드 인라인 편집 오버레이
 *
 * 평문+보간을 키화한 노드(`$t:custom.X|pN={{}}`)를 캔버스에서 더블클릭해 편집할 때, 평문은
 * 타이핑 편집하고 보간은 **드래그 가능한 원자 칩(뱃지)**으로 보여 주는 오버레이다. 일반 평문
 * 노드의 `InlineTextEditor`(단일 contentEditable)와 달리, 이 노드는 키 값에 `{pN}` 자리표시가
 * 박혀 있어 칩 합성 위젯(`PlaceholderChipInput`)이 필요하다.
 *
 * 동작:
 *  - 현재 로케일 키 값(`{p0} 작성` 등)을 fetch 해 칩+평문으로 렌더.
 *  - 평문 편집/칩 드래그 이동 → 해당 **로케일 키 값만** PUT(다른 로케일 어순 불변).
 *  - 칩은 `contenteditable=false` 원자 — 키 누르기로 안 지워짐(데이터 연결 해제는 [속성] 탭).
 *  - Enter/blur 로 확정(저장), Escape 로 취소.
 *
 * 번역 탭(`TranslationField`)의 칩 위젯과 동일 위젯·동일 UX(인라인은 현재 로케일 1개, 번역 탭은
 * 전 로케일 N행). 저장은 단일 로케일 키 값 PUT(`putSingleLocaleKeyValue`).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useMemo, useRef, useState } from 'react';
import type { OverlayBox } from '../utils/overlayGeometry';
import { PlaceholderChipInput } from './property-controls/PlaceholderChipInput';
import { findCustomKeyRow, putSingleLocaleKeyValue } from './property-controls/inlineBindingApi';
import { InlineBindingScalarPicker } from './property-controls/InlineBindingScalarPicker';
import { extractParamBindings, bindingChipLabel } from '../spec/inlineBindingUtils';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import { getPendingValue } from '../hooks/pendingCustomTranslations';
import type { BindingCandidate } from '../spec/bindingCandidates';
import type { EditorNode } from '../utils/layoutTreeUtils';

export interface InlineParamChipEditorProps {
  /** 편집 대상 박스(frame-local) — 오버레이 배치 */
  box: OverlayBox;
  /** 편집 대상 노드(param 키 텍스트 보유) */
  node: EditorNode;
  /**
   * 커스텀 키(`custom.*`). **미키화 데이터 노드**는 아직 키가
   * 없으므로 `null`. 이때 `initialChipValue`/`chipParamLabels` 로 칩을 렌더하고, 내용 변경 시
   * `onKeyify` 로 키를 생성한다.
   */
  customKey: string | null;
  /**
   * (미키화 전용) 칩 편집기 시작 값 — lang 평문화 + param 정규화한 `{pN}` 자리표시 문장
   * (`"발행일: {p0}"`). `customKey` 가 null 일 때만 사용(키가 있으면 서버/버퍼 값 fetch).
   */
  initialChipValue?: string;
  /**
   * (미키화 전용) 칩 라벨 오버라이드 — `customKey` 가 null 일 때 node.text 의 `|pN=` 가 없으므로
   * 분류가 derive 한 `{pN}` → 데이터 친화 라벨 맵을 직접 받는다.
   */
  chipParamLabels?: Record<string, string>;
  /**
   * (미키화 전용) 내용 변경 시 키화 — 편집된 `{pN}` 자리표시 키 값을 받아 커스텀 키를 생성하고
   * 노드 text 를 param 형태로 치환한다. 생성된 customKey 를 돌려주면 이후 일반 param 키 경로로
   * 전환한다. `customKey` 가 null 이고 baseline 과 달라질 때만 호출(키 생성=내용 변경 시).
   */
  onKeyify?: (editedKeyValue: string) => Promise<string | null>;
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 편집 로케일 */
  locale: string;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 연결 가능 데이터 후보 풀(scalar 포함) — '+데이터' 커서 위치 삽입 피커용. 미전달 시 '+데이터'
   * 버튼을 숨긴다(삽입 입구 없음 — 후보 빌드 전/불가). EditorCanvasOverlay 가 `bindingCandidates` 주입.
   */
  candidates?: BindingCandidate[];
  /**
   * '+데이터' 커서 위치 삽입 — 현재 로케일 키 값의 `charIndex` 위치에 새
   * 데이터 칩을 끼우라는 요청. 호출자(오버레이)가 node.text 에 `|pN=` 추가 + 전 로케일 키 값
   * 자리표시(편집 로케일=커서, 그 외=끝)를 버퍼에 기록한 뒤, **편집 로케일 갱신 키 값**을 돌려준다.
   * 반환값으로 본 위젯의 칩 문장을 즉시 갱신한다(라이브). 미전달 시 '+데이터' 버튼 숨김.
   */
  onInsertBinding?: (charIndex: number, candidate: BindingCandidate) => Promise<string | null>;
  /**
   * 칩 우측 X = 데이터 연결 '해제' — 호출자(오버레이)가 node.text 의
   * `|pN=` 제거 + 전 로케일 키 값 `{pN}` 제거(custom_translations) + 캔버스/다국어 동기화를 수행하고,
   * **편집 로케일 갱신 키 값**(해당 칩 제거 후)을 돌려준다. 반환값으로 칩 문장을 즉시 갱신(라이브).
   * 미전달 시 칩 X 미노출(키화 전/해제 불가 컨텍스트). */
  onRemoveBinding?: (paramName: string) => Promise<string | null>;
  /** 확정(저장 완료/변경 없음) */
  onCommit: () => void;
  /** 취소(Escape) */
  onCancel: () => void;
}

export function InlineParamChipEditor({
  box,
  node,
  customKey,
  initialChipValue,
  chipParamLabels,
  onKeyify,
  templateIdentifier,
  locale,
  t,
  candidates,
  onInsertBinding,
  onRemoveBinding,
  onCommit,
  onCancel,
}: InlineParamChipEditorProps): React.ReactElement {
  const [value, setValue] = useState<string | null>(null); // 현재 로케일 키 값(자리표시 문장)
  const baselineRef = useRef<string>(''); // 변경 감지 기준
  const [saving, setSaving] = useState(false);
  const committedRef = useRef(false);
  const rootRef = useRef<HTMLDivElement | null>(null);
  const docCtx = useLayoutDocumentContext();
  // commit 을 ref 로 노출 — document 리스너(외부 클릭)가 최신 commit 을 호출.
  const commitRef = useRef<() => void>(() => {});
  // '+데이터' 클릭 시 커서 위치를 보관 → 피커에서 후보 선택 시 그 위치로 삽입. null 이면 피커 닫힘.
  const [insertAt, setInsertAt] = useState<number | null>(null);
  const [inserting, setInserting] = useState(false);

  // 키화 후 전환된 customKey 보관 — 미키화 진입(`customKey===null`)에서 onKeyify 로 키가 생성되면
  // 그 키로 이후 putSingleLocaleKeyValue 경로를 탄다.
  const activeKeyRef = useRef<string | null>(customKey ?? null);
  // G-2 충돌 차단 — 인라인 '+데이터'(onInsertBinding=keyifyWithNewBinding)가
  // 미키화 노드를 키화하면 호출자(오버레이)가 inlineEditing.customKey 를 새 키로 갱신 → 이 위젯이
  // customKey prop 으로 그 키를 받는다. activeKeyRef 는 useRef 초기값만 받으므로 prop 변화를
  // effect 로 동기화해야 commit 이 keyifyChipValue(재키화) 대신 putSingleLocaleKeyValue 를 탄다
  // (둘째 키 생성 방지). null → 키 전환만 반영(키 → null 역전환은 없음 — 키화는 비가역).
  useEffect(() => {
    if (customKey && activeKeyRef.current !== customKey) {
      activeKeyRef.current = customKey;
    }
  }, [customKey]);

  // param 친화 라벨 — 키화된 노드는 node.text 의 `|pN=` 보간 경로, 미키화 노드는 분류가 derive 한
  // chipParamLabels(`{pN}` → 데이터 경로)를 쓴다.
  const paramLabels = useMemo<Record<string, string>>(() => {
    if (!customKey && chipParamLabels) return chipParamLabels;
    const out: Record<string, string> = {};
    const parsed = extractParamBindings(typeof node.text === 'string' ? node.text : '');
    for (const p of parsed?.params ?? []) {
      // 칩 친화 라벨 — bindingChipLabel 이 파이프 필터(`| date`) 보간도 경로로 추출(
      // 종전 `p.parsed ? path : p.expression` 은 파이프 보간(parsed=null)에서 raw 표현식 전체를
      // 칩 라벨로 박아 `{{termsContent?.data?.published_at | date}}` 가 깨져 보이던 결함).
      out[p.name] = bindingChipLabel(p.expression);
    }
    return out;
  }, [node.text, customKey, chipParamLabels]);

  // 현재 로케일 키 값 — 키화된 노드는 버퍼/서버 fetch, **미키화 노드**(customKey===null)는 분류가
  // derive 한 파생 칩 값(`"발행일: {p0}"`)을 그대로 시작값으로 쓴다(키 없음 → fetch 불가).
  useEffect(() => {
    let cancelled = false;
    if (!customKey) {
      const seed = initialChipValue ?? '';
      baselineRef.current = seed;
      setValue(seed);
      return () => { cancelled = true; };
    }
    (async () => {
      const pending = getPendingValue(customKey, locale);
      if (pending !== undefined) { baselineRef.current = pending; setValue(pending); return; }
      const row = await findCustomKeyRow(templateIdentifier, customKey);
      if (cancelled) return;
      const v = row?.values?.[locale] ?? '';
      baselineRef.current = v;
      setValue(v);
    })();
    return () => { cancelled = true; };
  }, [templateIdentifier, customKey, locale, initialChipValue]);

  const commit = async (): Promise<void> => {
    if (committedRef.current) return;
    committedRef.current = true;
    const v = value ?? '';
    if (v === baselineRef.current) { onCommit(); return; } // 내용 변경 없음 → 키 생성 안 함.
    setSaving(true);
    // 미키화 노드(customKey===null) 첫 변경 → 키화(onKeyify). 키 생성 + node.text param 치환 + 키 값
    // 버퍼(저장-지연)까지 onKeyify 가 수행. 이후 같은 키로 전환(activeKeyRef)된다.
    let key = activeKeyRef.current;
    if (!key) {
      if (!onKeyify) { setSaving(false); committedRef.current = false; return; }
      key = await onKeyify(v);
      setSaving(false);
      if (!key) { committedRef.current = false; return; } // 키화 실패 — 재시도 가능하게 플래그 복구.
      activeKeyRef.current = key;
      onCommit();
      return;
    }
    // 이미 키화된 노드 — 현재 로케일 키 값만 버퍼 기록(저장-지연). 레이아웃 [저장] 시 flush.
    const res = await putSingleLocaleKeyValue(templateIdentifier, key, locale, v);
    setSaving(false);
    if (res.kind === 'error') {
      committedRef.current = false;
      return;
    }
    docCtx?.markDirty?.();
    onCommit();
  };

  const cancel = (): void => {
    if (committedRef.current) return;
    committedRef.current = true;
    onCancel();
  };

  // '+데이터'(커서 위치 삽입) — PlaceholderChipInput 이 현재 caret 의 키 값 절대 인덱스를 넘긴다.
  // 그 위치를 보관하고 scalar 피커를 연다(아래 렌더). 후보 선택 시 onInsertBinding 으로 위임.
  const handleRequestInsert = (charIndex: number): void => {
    setInsertAt(charIndex);
  };

  // 피커 후보 선택 — 호출자(오버레이)가 node.text `|pN=` 추가 + 전 로케일 키 값 자리표시(편집
  // 로케일=커서 위치, 그 외=끝)를 버퍼에 기록하고, 편집 로케일 갱신 키 값을 돌려준다. 그 값으로
  // 칩 문장을 즉시 갱신(라이브) + baseline 동기화(추가 삽입은 이미 버퍼라 재커밋 불필요).
  const handlePickCandidate = async (c: BindingCandidate): Promise<void> => {
    if (insertAt === null || !onInsertBinding || inserting) return;
    setInserting(true);
    try {
      // S9-N2 — 미커밋 로컬 변경(칩 드래그 이동/평문 수정)을 **삽입 전에 선커밋**한다.
      // 삽입(insertBindingIntoParamKey)은 pending/서버 값 기준으로 키 값을 재구성하므로, 위젯
      // 로컬 state 에만 있는 드래그 결과는 선커밋 없이는 통째로 소실된다(라이브 4칩 실측 —
      // 드래그 직후 '+데이터' 시 이동이 DB 저장값 순서로 되돌아가던 결함).
      const activeKey = activeKeyRef.current;
      if (activeKey && value !== null && value !== baselineRef.current) {
        await putSingleLocaleKeyValue(templateIdentifier, activeKey, locale, value);
        baselineRef.current = value;
      }
      const nextValue = await onInsertBinding(insertAt, c);
      if (nextValue !== null) {
        setValue(nextValue);
        baselineRef.current = nextValue; // 삽입은 버퍼 기록 완료 — 재PUT 방지(커밋 시 변경 없음).
        docCtx?.markDirty?.();
      }
    } finally {
      setInserting(false);
      setInsertAt(null);
    }
  };

  // 칩 우측 X = 데이터 연결 '해제' — 호출자(오버레이)가 node.text
  // `|pN=` 제거 + 전 로케일 키 값 `{pN}` 제거 + 캔버스/다국어 동기화 후 편집 로케일 갱신 값 반환.
  // 삽입(handlePickCandidate)과 대칭. 미커밋 로컬 변경 선커밋(소실 방지) 후 위임.
  const handleRemoveChip = async (paramName: string): Promise<void> => {
    if (!onRemoveBinding || inserting) return;
    setInserting(true);
    try {
      const activeKey = activeKeyRef.current;
      if (activeKey && value !== null && value !== baselineRef.current) {
        await putSingleLocaleKeyValue(templateIdentifier, activeKey, locale, value);
        baselineRef.current = value;
      }
      const nextValue = await onRemoveBinding(paramName);
      if (nextValue !== null) {
        setValue(nextValue);
        baselineRef.current = nextValue; // 해제는 버퍼 기록 완료 — 재PUT 방지.
        docCtx?.markDirty?.();
      }
    } finally {
      setInserting(false);
    }
  };

  const onKeyDown = (e: React.KeyboardEvent): void => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void commit(); }
    else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
  };

  // 외부 클릭 시 닫기(저장 커밋) — 편집 중 다른 엘리먼트를 클릭하면 칩 편집기가 안 닫히던 결함
  // 일반 InlineTextEditor 의 blur 닫힘과 동일 UX. document pointerdown 으로
  // 편집기 바깥 클릭을 감지해 commit(저장 후 닫기). commitRef 로 최신 핸들러 호출(stale 회피).
  commitRef.current = () => { void commit(); };
  useEffect(() => {
    const onDocPointerDown = (ev: PointerEvent): void => {
      const root = rootRef.current;
      if (!root) return;
      const tgt = ev.target as Node | null;
      if (tgt && root.contains(tgt)) return; // 편집기 내부 클릭 — 무시.
      // 서식 툴바(InlineTextToolbar)는 편집기 밖에 별도 마운트되므로 그 클릭은 닫지 않는다.
      const el = tgt as HTMLElement | null;
      if (el && el.closest && el.closest('[data-testid="g7le-inline-toolbar"], .g7le-inline-toolbar')) return;
      commitRef.current();
    };
    const doc = rootRef.current?.ownerDocument ?? document;
    // 캡처 단계 — 다른 노드 선택 핸들러보다 먼저 닫기 커밋이 돌도록.
    doc.addEventListener('pointerdown', onDocPointerDown, true);
    return () => doc.removeEventListener('pointerdown', onDocPointerDown, true);
  }, []);

  return (
    <div
      ref={rootRef}
      className="g7le-inline-param-chip-editor"
      data-testid="g7le-inline-param-chip-editor"
      onKeyDown={onKeyDown}
      style={{
        position: 'absolute',
        left: box.left,
        top: box.top,
        minWidth: Math.max(box.width, 120),
        pointerEvents: 'auto',
        zIndex: 10000,
        background: '#fff',
        border: '2px solid #2563eb',
        borderRadius: 4,
        boxShadow: '0 2px 8px rgba(37,99,235,0.25)',
        padding: 4,
      }}
    >
      {value === null ? (
        <div data-testid="g7le-inline-param-chip-loading" style={{ fontSize: 12, color: '#94a3b8', padding: 4 }}>
          {t('layout_editor.translation.loading')}
        </div>
      ) : (
        <PlaceholderChipInput
          value={value}
          onChange={setValue}
          t={t}
          paramLabels={paramLabels}
          testIdSuffix="inline"
          onRequestInsert={onInsertBinding ? handleRequestInsert : undefined}
          onRemoveChip={onRemoveBinding ? (p) => void handleRemoveChip(p) : undefined}
        />
      )}
      {/* '+데이터' 커서 위치 삽입 피커 — 칩 위젯에서 '+데이터' 클릭 시
          커서 절대 인덱스를 보관(insertAt)하고 scalar 피커를 연다. 후보 선택 → handlePickCandidate
          → onInsertBinding(오버레이) → node.text `|pN=` + 전 로케일 키 값 자리표시 버퍼. [속성]탭
          "+데이터"(끝 추가)와 동일 피커(InlineBindingScalarPicker, SSoT)지만 위치만 커서. */}
      {insertAt !== null && onInsertBinding && (
        <div data-testid="g7le-inline-param-chip-insert-picker" style={{ marginTop: 4 }}>
          {/* 이 편집기는 캔버스 위 `position:absolute; z-index:10000` 오버레이다. picker 를 부유
              (FloatingDropdown, z-index:1000)시키면 오버레이 뒤로 숨어 오히려 가려진다. 또 오버레이
              자체가 이미 떠 있어 인라인 펼침이 화면 흐름을 밀어내지 않는다(페이지 설정 모달 폼 행과
              다른 컨텍스트). 따라서 공용 기본값(floating=true)을 명시적으로 끄고 인라인 유지한다. */}
          <InlineBindingScalarPicker
            candidates={candidates ?? []}
            t={t}
            onSelect={(c) => void handlePickCandidate(c)}
            testIdSuffix="inline-insert"
            defaultOpen
            floating={false}
          />
        </div>
      )}
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 4 }}>
        <button
          type="button"
          data-testid="g7le-inline-param-chip-save"
          disabled={saving}
          onMouseDown={(e) => e.preventDefault()}
          onClick={() => void commit()}
          style={{ fontSize: 11, border: 'none', borderRadius: 6, background: '#2563eb', color: '#fff', padding: '4px 12px', cursor: 'pointer' }}
        >
          {saving ? t('layout_editor.translation.saving') : t('layout_editor.translation.save')}
        </button>
        <span style={{ fontSize: 10, color: '#94a3b8' }}>
          {t('layout_editor.translation.placeholder_locked')}
        </span>
      </div>
    </div>
  );
}
