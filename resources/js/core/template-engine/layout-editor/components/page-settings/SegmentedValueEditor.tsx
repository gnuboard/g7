// e2e:allow 조각 CRUD 편집기 — 조각마다 I18nTextField(다국어 자동생성·번역탭·데이터 칩) 재사용 +
// ⠿ 드래그(HTML5)·✕·추가. contentEditable/합성 칩 드래그 의존이라 Playwright 부적합 — RTL
// (SegmentedValueEditor.test 추가/삭제/순서/편집) + Chrome MCP 매트릭스로 검증.
/**
 * SegmentedValueEditor.tsx — 다중 세그먼트 값 조각 편집기
 *
 * `{{route.id ? '$t:edit' : '$t:new'}} - {{form_meta?.data?.board?.name || ''}}` 처럼 **여러 조각이
 * 이어붙은 값**(조건 분기 + 고정 글자 + 데이터)을, 조각 단위로 **추가·삭제·순서변경·편집**하는
 * 상품 수준 편집기다. 1차 "표시 위주" 버전을 "상품성 없음"으로 지적 → 조각 CRUD 로 재설계.
 *
 * 조각 종류(추가 시 시드 + 카드 라벨만 다름 — 편집기는 동일):
 *  - text(고정 글자): 평문 — `I18nTextField`(입력 시 다국어 키 자동 생성, 번역탭, `+데이터` 칩 내장).
 *  - expression(조건 분기): `{{식}}` — `I18nTextField`(enableExpressionTree)가 ConditionalValueEditor
 *    분해 트리로 그린다(분기 리프도 다국어 입력칸).
 *  - data(데이터): `{{바인딩}}` — `I18nTextField` 데이터 칩 입력(데이터 피커로 선택·변경).
 *
 * **모든 조각의 텍스트/분기/칩 입력 = 기존 `I18nTextField` 재사용** —
 * 신규 입력기 0. 세그먼트 편집기는 그 위에 조각 추가/삭제/순서변경(⠿ 드래그) 컨테이너만 얹는다.
 *
 * 값 모델: 조각 리스트(id/kind/value, value=각 조각의 I18nTextField 값). 전체 값 = 조각 value 순서
 * 결합(무손실 — 평문은 그대로, `{{...}}` 는 그대로). 한 조각만 바뀌면 그 조각 value 만 갱신 후 재결합.
 * 각 조각 value 는 **단일**(하나의 `{{식}}` 또는 평문)이라 I18nTextField 가 재귀 세그먼트화하지 않는다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useRef, useState } from 'react';
import { I18nTextField } from '../property-controls/I18nTextField';
import { InlineBindingScalarPicker } from '../property-controls/InlineBindingScalarPicker';
import { splitInlineSegments } from '../../spec/inlineBindingUtils';
import { buildBindingExpression, type BindingCandidate } from '../../spec/bindingCandidates';
import { DropLine } from '../DropLine';
import { useListDragReorder } from '../../hooks/useListDragReorder';

/**
 * 리프 입력기 렌더러 계약.
 *
 * 세그먼트 조각/트리 분기의 **리프 입력기**를 주입식으로 교체하기 위한 공통 계약이다. 미주입 시
 * (`renderLeafInput` 없음) 세그먼트/조건 편집기는 종전대로 `I18nTextField`(키화 — 평문 입력 시
 * `$t:custom.*` 다국어 키 생성)를 그린다. 키화가 부적합한 **값 전용 칸**(SEO og.image·구조화
 * 속성값·추가속성 content = URL/숫자/데이터연결)은 키화 없는 입력기(DataChipValueInput)를
 * 주입해 평문 입력이 번역키로 새지 않게 한다("칩 분해로 보이되 번역키는
 * 만들지 않음 + 범용화").
 *
 * 시그니처는 두 편집기가 리프에 넘기는 공통 props 계약이다. `onChange` 는 `string | undefined`
 * (빈 값 = 조각 삭제 신호 — 입력기가 빈 값 정리를 흡수).
 */
export type LeafInputRenderer = (props: {
  value: string;
  onChange: (v: string | undefined) => void;
  t: (key: string, params?: Record<string, string | number>) => string;
  candidates?: BindingCandidate[];
  testidPrefix: string;
}) => React.ReactElement;

export interface SegmentedValueEditorProps {
  /** 현재 값(여러 조각이 이어진 문자열) */
  value: string;
  /** 값 변경 — 재결합된 전체 문자열을 흘린다 */
  onChange: (value: string) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 데이터 칩 후보 풀 — 조각 I18nTextField +데이터/데이터 피커 */
  candidates?: BindingCandidate[];
  /** data-testid 접두 */
  testidPrefix?: string;
  /**
   * 리프 입력기 렌더러. 미전달 시 종전대로 `I18nTextField`(키화)를
   * 그린다(키 모드 회귀 0). 값 전용 칸은 키화 없는 입력기를 주입한다. 본 prop 은 트리 전체로
   * 전파돼(조각·분기·중첩 리프 모두) 한 칸이라도 키화로 새지 않게 한다.
   */
  renderLeafInput?: LeafInputRenderer;
}

/** 조각 종류 — 시드/라벨만 다름(편집기는 동일 I18nTextField, data 만 전용 피커) */
type SegmentKind = 'text' | 'expression' | 'fallback' | 'data';

interface Segment {
  /** 안정 id(리스트 키·드래그 추적). 신규는 단조 증가. */
  id: number;
  kind: SegmentKind;
  /** 조각 값(I18nTextField 값 — 평문/`$t:키`/`{{식}}`/`{{바인딩}}`) */
  value: string;
}

/**
 * 새 조각 시드. `[+조건분기]` 는 편집 가능한 기본 삼항 식 — 분기는 **빈 평문**(`''`)으로 둔다.
 *
 * 종전엔 then/else 를 `$t:custom.then`/`$t:custom.else` 더미 키로 시드했는데, 이 값은 **실재하지
 * 않는 custom 키**라 classify 가 "기존 커스텀 키"로 인식했다. 그러면 사용자가 그 빈 분기 칸에
 * 입력해도 commitText 가 update 경로(존재하지 않는 키 PUT, token 없음)를 타 onChange 가 발화하지
 * 않고 입력값이 버려졌다. 빈
 * 평문으로 시드하면 입력 시 created 경로(새 키 생성→token→onChange)를 정상으로 탄다.
 */
const SEED: Record<SegmentKind, string> = {
  text: '',
  // 기준 값(조건)은 빈 채로 시작 — 특정 경로(route.id 등) 하드코딩 금지. 빈 조건은
  // 중립 토큰 `false` 로 직렬화되고(seedExpressionFromPlain 과 동일 규약), parseConditionFromExpr 가
  // 빈 SimpleCondition 으로 복원해 "기준 값" 입력칸이 빈 채로 노출된다(사용자가 직접 채움).
  expression: "{{false ? '' : ''}}",
  // 폴백(값이 없을 때 대신) — primary/fallback 모두 빈 칸으로 시작. `{{'' ?? ''}}` 로 직렬화되고
  // parseExpressionValue 가 fallback 노드로 복원한다(사용자가 기본값/대신 칸을 채움).
  fallback: "{{'' ?? ''}}",
  // 데이터 조각 — 빈 값으로 시작하되 SegmentCard 가 data kind 를 감지해 데이터 검색 피커를 즉시
  // 노출한다(빈 평문 입력이 아니라 — "고정 글자와 뭐가 다르냐" 우려 해소). 데이터를 고르면
  // `{{src?.path ?? ''}}` 바인딩으로 채워진다.
  data: '',
};

/** 초기 문자열을 조각 리스트로 분해 — 평문=text, 보간=expression/data(여기선 보간 일괄 표시, 편집은 I18nTextField 자체 라우팅). */
function toSegments(value: string, seqStart: number): { segs: Segment[]; nextSeq: number } {
  const parts = splitInlineSegments(value);
  let seq = seqStart;
  const segs: Segment[] = parts.map((p) => ({
    id: seq++,
    // 보간 조각은 'expression'(I18nTextField 가 분해 가능하면 트리, 아니면 데이터/읽기전용으로 자체 라우팅).
    kind: p.kind === 'literal' ? 'text' : 'expression',
    value: p.raw,
  }));
  // 빈 값 → 빈 text 조각 1개(추가 시작점).
  if (segs.length === 0) segs.push({ id: seq++, kind: 'text', value: '' });
  return { segs, nextSeq: seq };
}

/**
 * 조각 CRUD 편집기.
 *
 * @param props SegmentedValueEditorProps
 * @returns 조각 카드 리스트 + 추가 버튼
 */
export function SegmentedValueEditor({
  value,
  onChange,
  t,
  candidates,
  testidPrefix = 'g7le-seg-value',
  renderLeafInput,
}: SegmentedValueEditorProps): React.ReactElement {
  // 조각 리스트 — 초기 1회 분해 후 로컬 편집. value prop 재동기는 lastEmittedRef 로 가드.
  const seqRef = useRef(0);
  // 우리가 마지막으로 onChange 로 흘린 결합값(자기 변경 회신은 재동기 안 함 — 루프/커서 점프 방지).
  const lastEmittedRef = useRef<string>(value);
  const initial = useMemo(() => {
    const r = toSegments(value, seqRef.current);
    seqRef.current = r.nextSeq;
    return r.segs;
    // 최초 1회만(value 재동기는 아래에서 명시 처리).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const [segments, setSegments] = useState<Segment[]>(initial);
  // 통합 [원본 식 보기] — 카드마다가 아니라 세그먼트 편집기당 하나(전체 결합 식).
  const [showSource, setShowSource] = useState(false);

  // 외부에서 value 가 바뀌면(다른 노드/언어 전환 등) 조각을 재동기. 우리가 방금 흘린 값이면 무시
  // (자기 변경 회신 — 편집 루프/조각 리마운트로 인한 커서 점프 방지). 렌더 중 setState 는 React 가
  // 즉시 재렌더로 흡수하는 표준 "파생 상태 동기화" 패턴.
  if (value !== lastEmittedRef.current) {
    lastEmittedRef.current = value;
    const cur = segments.map((s) => s.value).join('');
    if (value !== cur) {
      const r = toSegments(value, seqRef.current);
      seqRef.current = r.nextSeq;
      setSegments(r.segs);
    }
  }

  // 조각 리스트 변경 → 결합값 onChange.
  const commit = useCallback(
    (next: Segment[]): void => {
      setSegments(next);
      const out = next.map((s) => s.value).join('');
      lastEmittedRef.current = out;
      onChange(out);
    },
    [onChange],
  );

  const setSegValue = useCallback(
    (id: number, v: string | undefined): void => {
      commit(segments.map((s) => (s.id === id ? { ...s, value: v ?? '' } : s)));
    },
    [segments, commit],
  );

  const removeSeg = useCallback(
    (id: number): void => {
      const next = segments.filter((s) => s.id !== id);
      // 전부 지우면 빈 text 조각 1개 유지(편집 시작점).
      commit(next.length > 0 ? next : [{ id: seqRef.current++, kind: 'text', value: '' }]);
    },
    [segments, commit],
  );

  const addSeg = useCallback(
    (kind: SegmentKind): void => {
      commit([...segments, { id: seqRef.current++, kind, value: SEED[kind] }]);
    },
    [segments, commit],
  );

  const moveTo = useCallback(
    (from: number, to: number): void => {
      if (from === to || from < 0 || to < 0 || from >= segments.length || to >= segments.length) return;
      const next = segments.slice();
      const [moved] = next.splice(from, 1);
      next.splice(to, 0, moved);
      commit(next);
    },
    [segments, commit],
  );

  // 드래그 재배치 + 드롭 위치 표시 — 공용 훅(useListDragReorder)에 위임. 모든 조각 드래그 가능
  // (잠금 없음). 캔버스 DnD 와 동일한 삽입선(DropLine)으로 드롭 지점 표시.
  const dnd = useListDragReorder({ length: segments.length, onMove: moveTo });

  return (
    <div data-testid={testidPrefix} style={wrap}>
      <div style={intro}>{t('layout_editor.value_tree.segmented_hint')}</div>
      {segments.map((seg, index) => (
        <React.Fragment key={seg.id}>
          {/* 삽입선 — 드롭 예정 지점(index)에 캔버스 DnD 와 동일한 파란 줄 표시. */}
          <DropLine active={dnd.isDropTarget(index)} testid={`${testidPrefix}-dropline-${index}`} />
          <SegmentCard
            seg={seg}
            index={index}
            t={t}
            candidates={candidates}
            testidPrefix={testidPrefix}
            renderLeafInput={renderLeafInput}
            onValueChange={(v) => setSegValue(seg.id, v)}
            onRemove={() => removeSeg(seg.id)}
            dragging={dnd.dragIndex === index}
            onDragStartCard={() => dnd.onDragStart(index)}
            onDragEndCard={dnd.onDragEnd}
            // 드래그 오버 — 포인터가 카드 위/아래 절반 중 어디인지로 삽입 지점(index/index+1) 결정.
            onDragOverCard={(half) => dnd.onDragOverItem(index, half)}
            onDropCard={dnd.onDrop}
          />
        </React.Fragment>
      ))}
      {/* 마지막 삽입선 — 리스트 끝(length)에 떨어뜨릴 때. */}
      <DropLine active={dnd.isDropTarget(segments.length)} testid={`${testidPrefix}-dropline-end`} />
      <div style={addRow} data-testid={`${testidPrefix}-add-row`}>
        <button type="button" data-testid={`${testidPrefix}-add-text`} onClick={() => addSeg('text')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.text')}
        </button>
        <button type="button" data-testid={`${testidPrefix}-add-expression`} onClick={() => addSeg('expression')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.expression')}
        </button>
        {/* [+값이 없을 때 대신](폴백) — 사용자가 폴백 양식도 직접 추가. */}
        <button type="button" data-testid={`${testidPrefix}-add-fallback`} onClick={() => addSeg('fallback')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.fallback')}
        </button>
        <button type="button" data-testid={`${testidPrefix}-add-data`} onClick={() => addSeg('data')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.data')}
        </button>
      </div>
      {/* 통합 [원본 식 보기] — 카드마다가 아니라 세그먼트 편집기당 하나. 전체 결합 식. */}
      <div style={sourceRow}>
        <button
          type="button"
          data-testid={`${testidPrefix}-source-toggle`}
          aria-expanded={showSource}
          onClick={() => setShowSource((v) => !v)}
          style={sourceToggle}
        >
          {'</>'} {t('layout_editor.value_tree.show_source')} {showSource ? '▴' : '▾'}
        </button>
        {showSource && (
          <code data-testid={`${testidPrefix}-source-code`} style={sourceCode}>
            {segments.map((s) => s.value).join('')}
          </code>
        )}
      </div>
    </div>
  );
}

/**
 * 데이터 조각 전용 피커 — 빈 데이터 조각에 즉시 데이터 검색 자동완성을 노출한다("데이터 조각은
 * 당연히 데이터 자동완성 검색이 되어야" 2026-06-13). 후보를 고르면 shape 안전 바인딩
 * (`{{src?.path ?? ''}}`)을 만들어 onPick 으로 흘린다 — 그 뒤로 조각은 바인딩 값을 가지므로
 * SegmentCard 가 일반 I18nTextField(BindingDataField=데이터 바꾸기) 분기로 넘어간다.
 *
 * 후보 풀(candidates) 미전달 시 — 데이터 검색을 못 하므로 평문 입력으로 폴백(디그레이드, 안내).
 */
function DataSegmentPicker({
  t,
  candidates,
  onPick,
  testidPrefix,
}: {
  t: SegmentedValueEditorProps['t'];
  candidates?: BindingCandidate[];
  onPick: (value: string) => void;
  testidPrefix: string;
}): React.ReactElement {
  if (!candidates || candidates.length === 0) {
    return (
      <div data-testid={`${testidPrefix}-data-empty`} style={dataEmptyHint}>
        {t('layout_editor.value_tree.segment.data_no_candidates')}
      </div>
    );
  }
  return (
    <div data-testid={`${testidPrefix}-data-picker`} style={{ minWidth: 0 }}>
      <div style={dataPickerHint}>{t('layout_editor.value_tree.segment.data_pick_hint')}</div>
      <InlineBindingScalarPicker
        candidates={candidates}
        t={t}
        onSelect={(c) => {
          // shape 안전 바인딩(`{{src?.path ?? ''}}`) — 데이터 미도착 시 런타임 에러 방지(CLAUDE.md
          // fallback 필수). buildBindingExpression 이 scalar 폴백(`?? ''`)을 붙인다.
          onPick(buildBindingExpression(c.sourceId, c.path, 'scalar'));
        }}
        testIdSuffix={`${testidPrefix}-data`}
        defaultOpen
        // 좁은 조각 카드 — 검색을 부유 드롭다운으로(행/카드 높이 폭발 방지).
        floating
      />
    </div>
  );
}

/** 한 조각 카드 — 손잡이(⠿ 드래그) + 종류 라벨 + I18nTextField + ✕ 삭제 */
function SegmentCard({
  seg,
  index,
  t,
  candidates,
  testidPrefix,
  renderLeafInput,
  onValueChange,
  onRemove,
  dragging,
  onDragStartCard,
  onDragEndCard,
  onDragOverCard,
  onDropCard,
}: {
  seg: Segment;
  index: number;
  t: SegmentedValueEditorProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  renderLeafInput?: LeafInputRenderer;
  onValueChange: (v: string | undefined) => void;
  onRemove: () => void;
  dragging: boolean;
  onDragStartCard: () => void;
  onDragEndCard: () => void;
  onDragOverCard: (half: 'before' | 'after') => void;
  onDropCard: () => void;
}): React.ReactElement {
  const labelKey =
    seg.kind === 'text'
      ? 'layout_editor.value_tree.segment.text'
      : seg.kind === 'data'
        ? 'layout_editor.value_tree.segment.data'
        : seg.kind === 'fallback'
          ? 'layout_editor.value_tree.segment.fallback'
          : 'layout_editor.value_tree.segment.expression';
  return (
    <div
      data-testid={`${testidPrefix}-card-${index}`}
      data-seg-kind={seg.kind}
      style={dragging ? { ...card, opacity: 0.5 } : card}
      onDragOver={(e) => {
        e.preventDefault();
        // 포인터가 카드 위/아래 절반 중 어디인지 → 삽입 지점 결정(캔버스 DnD 와 동일 감각).
        const rect = e.currentTarget.getBoundingClientRect();
        onDragOverCard(e.clientY < rect.top + rect.height / 2 ? 'before' : 'after');
      }}
      onDrop={(e) => {
        e.preventDefault();
        onDropCard();
      }}
    >
      <div style={cardHead}>
        <span
          data-testid={`${testidPrefix}-drag-${index}`}
          style={dragHandle}
          draggable
          onDragStart={onDragStartCard}
          onDragEnd={onDragEndCard}
          title={t('layout_editor.value_tree.drag_reorder')}
          aria-label={t('layout_editor.value_tree.drag_reorder')}
          role="button"
        >
          ⠿
        </span>
        <span style={cardLabel}>{t(labelKey)}</span>
        <button
          type="button"
          data-testid={`${testidPrefix}-remove-${index}`}
          onClick={onRemove}
          title={t('layout_editor.value_tree.segment.remove')}
          aria-label={t('layout_editor.value_tree.segment.remove')}
          style={removeBtn}
        >
          ✕
        </button>
      </div>
      <div style={cardBody}>
        {seg.kind === 'data' && seg.value.trim() === '' ? (
          // 데이터 조각이 비었으면 — 평문 입력이 아니라 데이터 검색 피커를 즉시 노출("데이터를
          // 표출하려는 거라면 당연히 데이터 자동완성 검색이 되어야" 2026-06-13). 선택 시 안전 바인딩
          // (`{{src?.path ?? ''}}`)으로 채워지고, 그 뒤로는 아래 리프 입력기(BindingDataField/칩)가
          // "데이터 바꾸기"를 제공한다(빈 값이 아니므로 이 분기를 벗어남). 데이터 피커는 키화가 없어
          // 값/키 모드 공통이다.
          <DataSegmentPicker
            t={t}
            candidates={candidates}
            onPick={onValueChange}
            testidPrefix={`${testidPrefix}-field-${index}`}
          />
        ) : renderLeafInput ? (
          // 값 모드 — 키화 없는 리프 입력기 주입. 평문 입력이 다국어 키로 새지 않게
          // 한다(SEO og.image·구조화 속성값·추가속성 content). renderLeafInput 은 단일식이면 다시
          // 트리(ConditionalValueEditor)로 재귀 위임하되 그 재귀에도 자신을 주입해 키화 0 을 유지한다.
          renderLeafInput({
            value: seg.value,
            onChange: onValueChange,
            t,
            candidates,
            testidPrefix: `${testidPrefix}-field-${index}`,
          })
        ) : (
          // 키 모드(기본 — 미주입) — 종전대로 I18nTextField(평문 입력 시 `$t:custom.*` 키화). 제목/
          // 설명 등 다국어 텍스트 칸. renderLeafInput 미전달 시 동작 100% 보존(회귀 0).
          <I18nTextField
            value={seg.value}
            onChange={onValueChange}
            t={t}
            candidates={candidates}
            testidPrefix={`${testidPrefix}-field-${index}`}
            enableExpressionTree
            // 카드별 [원본 식 보기] 미표시 — 세그먼트 편집기가 전체 식 하나로 통합 제공.
            expressionSourceToggle={false}
          />
        )}
      </div>
    </div>
  );
}

/* ── 스타일(g7le-* 인라인, CSS 라이브러리 비종속) ── */
const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, width: '100%', minWidth: 0 };
const intro: React.CSSProperties = { fontSize: 11, color: '#64748b' };
const card: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: '6px 8px', border: '1px solid #e2e8f0', borderLeft: '3px solid #6366f1', borderRadius: 6, background: '#f8fafc', minWidth: 0 };
const cardHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 };
const cardLabel: React.CSSProperties = { fontSize: 10, fontWeight: 600, color: '#6366f1', flex: 1, minWidth: 0 };
const cardBody: React.CSSProperties = { minWidth: 0 };
const dragHandle: React.CSSProperties = { color: '#94a3b8', cursor: 'grab', fontSize: 14, userSelect: 'none' };
const removeBtn: React.CSSProperties = { border: 'none', background: 'transparent', color: '#94a3b8', cursor: 'pointer', fontSize: 12, padding: 2, flexShrink: 0 };
const addRow: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 2 };
// 데이터 조각 피커 — 빈 데이터 조각 진입 안내 + 후보 없음 폴백.
const dataPickerHint: React.CSSProperties = { fontSize: 11, color: '#64748b', marginBottom: 2 };
const dataEmptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', padding: '4px 0' };
const addBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 11, border: '1px dashed #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
// 통합 [원본 식 보기] — ConditionalValueEditor 와 동일 시각.
const sourceRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, marginTop: 2 };
const sourceToggle: React.CSSProperties = { alignSelf: 'flex-start', padding: '3px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', fontFamily: 'monospace' };
const sourceCode: React.CSSProperties = { fontSize: 11, background: '#0f172a', color: '#e2e8f0', padding: '6px 8px', borderRadius: 4, wordBreak: 'break-all', fontFamily: 'monospace' };
