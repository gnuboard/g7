// e2e:allow 트리 UI 는 I18nTextField(다국어/칩 위젯) 재귀 — contentEditable/합성 칩 드래그 의존으로
// Playwright 부적합(정책, I18nTextField 와 동일). 분해/직렬화는 단위(expressionValueTree.test
// 골든)로, 트리 렌더/분기 편집은 RTL(ConditionalValueEditor.test)로, 라이브는 Chrome MCP 매트릭스로.
/**
 * ConditionalValueEditor.tsx — 표현식 분해 트리 UI
 *
 * 표현식+다국어 값(`{{route.id ? '$t:edit' : '$t:create'}}`)을 raw `{{...}}` 코드로 노출하지 않고,
 * **조건 노드 + 분기(참/거짓)별 리프**로 분해한 트리를 그린다. 리프는 기존 `I18nTextField` 를
 * **재귀 재사용**(다국어 번역탭 + +데이터 칩 그대로) — 신규 입력기 0.
 *
 * 구조(ValueNode 재귀):
 *  - conditional `cond ? then : else` — 조건 빌더(단순 비교만 편집, 복잡 조건은 readonly "코드에서
 *    수정") + [참일 때]/[거짓일 때] 분기(각각 재귀).
 *  - fallback `a ?? b` / `a || b` — [기본값]/[비었을 때] 분기(재귀).
 *  - concat `a + b` — 순서대로 조각(재귀).
 *  - leaf — `I18nTextField` 위임(다국어/칩).
 *  - raw — readonly 코드(분해 불가식, 손상 0 폴백).
 *
 * 편집 모델: 트리의 한 노드를 바꾸면 루트 트리를 갱신해 `serializeValueNode` 로 **새 node.text
 * 문자열**을 만들고 `onChange` 로 흘린다(round-trip). `[</> 원본 식 보기]` 가 그 직렬화 결과를
 * 상시 표시해 의미 동일을 시각 검증한다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useState } from 'react';
import { SegmentedValueEditor, type LeafInputRenderer } from './SegmentedValueEditor';
import { InlineBindingScalarPicker } from '../property-controls/InlineBindingScalarPicker';
import {
  serializeValueNode,
  type ValueNode,
  type ConditionalNode,
  type FallbackNode,
  type ConcatNode,
  type Condition,
  type SimpleCondition,
} from '../../spec/expressionValueTree';
import { buildBindingExpression, type BindingCandidate } from '../../spec/bindingCandidates';
import { DropLine } from '../DropLine';
import { useListDragReorder } from '../../hooks/useListDragReorder';

export interface ConditionalValueEditorProps {
  /** 분해 트리 루트 */
  node: ValueNode;
  /** 트리 변경 — 갱신된 루트 트리를 직렬화해 새 node.text 를 흘린다(루트에서만 onChange 호출) */
  onChange: (next: ValueNode) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 데이터 칩 후보 풀 — 리프 I18nTextField +데이터 */
  candidates?: BindingCandidate[];
  /** data-testid 접두(위젯별 격리) */
  testidPrefix?: string;
  /** 재귀 깊이(들여쓰기·키 격리) — 내부용 */
  depth?: number;
  /**
   * 루트에 [원본 식 보기] 토글을 그릴지 ("원본 식 보기는 조각 편집기당 하나"
   * 2026-06-13). 기본 true(단독 트리). SegmentedValueEditor 안의 조각으로 쓰일 때는 false —
   * 세그먼트 편집기가 전체 식 하나의 [원본 식 보기] 를 통합 제공한다(카드마다 중복 방지).
   */
  showSourceToggle?: boolean;
  /**
   * 리프 입력기 렌더러. 미전달 시 종전대로 리프를 SegmentedValueEditor
   * (→ I18nTextField 키화)로 그린다(키 모드 회귀 0). 값 전용 칸은 키화 없는 입력기를 주입한다.
   * 본 prop 은 조건/폴백/이어붙이기 모든 분기·중첩 리프로 전파돼 한 칸도 키화로 새지 않게 한다.
   */
  renderLeafInput?: LeafInputRenderer;
}

/** 단순 비교 연산 목록(드롭다운) */
const SIMPLE_OPS: Array<{ value: SimpleCondition['op']; labelKey: string }> = [
  { value: 'truthy', labelKey: 'layout_editor.value_tree.cond.op_truthy' },
  { value: 'falsy', labelKey: 'layout_editor.value_tree.cond.op_falsy' },
  { value: '===', labelKey: 'layout_editor.value_tree.cond.op_eq' },
  { value: '!==', labelKey: 'layout_editor.value_tree.cond.op_neq' },
  { value: '>', labelKey: 'layout_editor.value_tree.cond.op_gt' },
  { value: '<', labelKey: 'layout_editor.value_tree.cond.op_lt' },
  { value: '>=', labelKey: 'layout_editor.value_tree.cond.op_gte' },
  { value: '<=', labelKey: 'layout_editor.value_tree.cond.op_lte' },
];

/**
 * 표현식 분해 트리 편집기(재귀). 루트에서 한 번 마운트되고, 자식 노드는 내부에서 재귀 렌더한다.
 *
 * @param props ConditionalValueEditorProps
 * @returns 트리 UI
 */
export function ConditionalValueEditor({
  node,
  onChange,
  t,
  candidates,
  testidPrefix = 'g7le-value-tree',
  depth = 0,
  showSourceToggle = true,
  renderLeafInput,
}: ConditionalValueEditorProps): React.ReactElement {
  const [showSource, setShowSource] = useState(false);

  // 루트에서만 [</> 원본 식 보기] 토글 + 외곽을 그린다. 자식은 본문만.
  const isRoot = depth === 0;
  const body = (
    <NodeView
      node={node}
      onChange={onChange}
      t={t}
      candidates={candidates}
      testidPrefix={testidPrefix}
      depth={depth}
      renderLeafInput={renderLeafInput}
    />
  );

  if (!isRoot) return body;
  // 세그먼트 조각으로 쓰일 때(showSourceToggle=false)는 [원본 식 보기] 없이 본문만(세그먼트
  // 편집기가 전체 식 하나의 토글을 통합 제공 — "조각 편집기당 하나" 2026-06-13).
  if (!showSourceToggle) return <div data-testid={testidPrefix} style={rootWrap}>{body}</div>;

  return (
    <div data-testid={testidPrefix} style={rootWrap}>
      {body}
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
            {serializeValueNode(node, false)}
          </code>
        )}
      </div>
    </div>
  );
}

/** 한 노드를 종류별로 렌더(재귀 디스패치) */
function NodeView({
  node,
  onChange,
  t,
  candidates,
  testidPrefix,
  depth,
  renderLeafInput,
}: {
  node: ValueNode;
  onChange: (next: ValueNode) => void;
  t: ConditionalValueEditorProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  depth: number;
  renderLeafInput?: LeafInputRenderer;
}): React.ReactElement {
  switch (node.kind) {
    case 'leaf':
      // 분기 리프(then/else/기본값/대신/이어붙이기 조각)도 **조각 편집기**(SegmentedValueEditor)로
      // 렌더한다 — "유저는 표현식 편집기에서 가능한 모든 조합과 양식을 정의할 수 있어야"(2026-06-13).
      // 그래야 모든 분기 안에서 `[+고정글자]/[+조건분기]/[+값이 없을 때 대신]/[+데이터]` 추가·중첩이
      // 가능하다(종전엔 분기가 단일 I18nTextField 라 조각 추가 입구가 없었다). 조각 편집기가 단일
      // 평문/키/식을 1조각으로 분해하고, 조각 내부 I18nTextField 가 다시 분기 트리로 재귀한다(단일식).
      // leaf.text 에 `{{식}}` 문자열을 그대로 담아도 부모 직렬화(serializeChild→leafToExpr)가 보간을
      // 벗겨 의미를 보존하므로 round-trip 안전.
      return (
        <SegmentedValueEditor
          value={node.text}
          onChange={(v) => onChange({ kind: 'leaf', text: v })}
          t={t}
          candidates={candidates}
          testidPrefix={`${testidPrefix}-leaf`}
          renderLeafInput={renderLeafInput}
        />
      );
    case 'conditional':
      return (
        <ConditionalView
          node={node}
          onChange={onChange}
          t={t}
          candidates={candidates}
          testidPrefix={testidPrefix}
          depth={depth}
          renderLeafInput={renderLeafInput}
        />
      );
    case 'fallback':
      return (
        <FallbackView
          node={node}
          onChange={onChange}
          t={t}
          candidates={candidates}
          testidPrefix={testidPrefix}
          depth={depth}
          renderLeafInput={renderLeafInput}
        />
      );
    case 'concat':
      return (
        <ConcatView
          node={node}
          onChange={onChange}
          t={t}
          candidates={candidates}
          testidPrefix={testidPrefix}
          depth={depth}
          renderLeafInput={renderLeafInput}
        />
      );
    case 'raw':
    default:
      return (
        <div data-testid={`${testidPrefix}-raw`} style={rawWrap}>
          <code style={rawCode}>{(node as { source?: string }).source ?? ''}</code>
          <span style={rawBadge}>🔒 {t('layout_editor.value_tree.raw_code_only')}</span>
        </div>
      );
  }
}

/** 조건 분기 노드 — 조건 빌더 + 참/거짓 분기 재귀 */
function ConditionalView({
  node,
  onChange,
  t,
  candidates,
  testidPrefix,
  depth,
  renderLeafInput,
}: {
  node: ConditionalNode;
  onChange: (next: ValueNode) => void;
  t: ConditionalValueEditorProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  depth: number;
  renderLeafInput?: LeafInputRenderer;
}): React.ReactElement {
  const setCondition = useCallback(
    (condition: Condition) => onChange({ ...node, condition }),
    [node, onChange],
  );
  const setThen = useCallback((then: ValueNode) => onChange({ ...node, then }), [node, onChange]);
  const setElse = useCallback((els: ValueNode) => onChange({ ...node, else: els }), [node, onChange]);

  return (
    <div data-testid={`${testidPrefix}-conditional`} style={branchBox(depth)}>
      <ConditionBuilder condition={node.condition} onChange={setCondition} t={t} candidates={candidates} testidPrefix={testidPrefix} />
      <BranchRow label={t('layout_editor.value_tree.cond.when_true')} testid={`${testidPrefix}-then`}>
        <NodeView node={node.then} onChange={setThen} t={t} candidates={candidates} testidPrefix={`${testidPrefix}-then`} depth={depth + 1} renderLeafInput={renderLeafInput} />
      </BranchRow>
      <BranchRow label={t('layout_editor.value_tree.cond.when_false')} testid={`${testidPrefix}-else`}>
        <NodeView node={node.else} onChange={setElse} t={t} candidates={candidates} testidPrefix={`${testidPrefix}-else`} depth={depth + 1} renderLeafInput={renderLeafInput} />
      </BranchRow>
    </div>
  );
}

/** 폴백 노드 — 기본값/비었을 때 분기 재귀 */
function FallbackView({
  node,
  onChange,
  t,
  candidates,
  testidPrefix,
  depth,
  renderLeafInput,
}: {
  node: FallbackNode;
  onChange: (next: ValueNode) => void;
  t: ConditionalValueEditorProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  depth: number;
  renderLeafInput?: LeafInputRenderer;
}): React.ReactElement {
  const setPrimary = useCallback((primary: ValueNode) => onChange({ ...node, primary }), [node, onChange]);
  const setFallback = useCallback((fallback: ValueNode) => onChange({ ...node, fallback }), [node, onChange]);
  return (
    <div data-testid={`${testidPrefix}-fallback`} style={branchBox(depth)}>
      <BranchRow label={t('layout_editor.value_tree.fallback.primary')} testid={`${testidPrefix}-primary`}>
        <NodeView node={node.primary} onChange={setPrimary} t={t} candidates={candidates} testidPrefix={`${testidPrefix}-primary`} depth={depth + 1} renderLeafInput={renderLeafInput} />
      </BranchRow>
      <BranchRow
        label={t(node.op === '??' ? 'layout_editor.value_tree.fallback.when_empty' : 'layout_editor.value_tree.fallback.when_falsy')}
        testid={`${testidPrefix}-fallback-branch`}
      >
        <NodeView node={node.fallback} onChange={setFallback} t={t} candidates={candidates} testidPrefix={`${testidPrefix}-fallbackb`} depth={depth + 1} renderLeafInput={renderLeafInput} />
      </BranchRow>
    </div>
  );
}

/** 새 이어붙이기 조각 시드(ValueNode). 고정글자/데이터=빈 리프, 조건분기/폴백=빈 식 노드. */
const PART_SEED: Record<'text' | 'expression' | 'fallback' | 'data', () => ValueNode> = {
  text: () => ({ kind: 'leaf', text: '' }),
  data: () => ({ kind: 'leaf', text: '' }), // 리프가 SegmentedValueEditor 로 렌더되며 빈 값 → 데이터 피커.
  expression: () => ({
    kind: 'conditional',
    condition: { kind: 'simple', left: '', op: 'truthy', right: '' },
    then: { kind: 'leaf', text: '' },
    else: { kind: 'leaf', text: '' },
  }),
  fallback: () => ({ kind: 'fallback', op: '??', primary: { kind: 'leaf', text: '' }, fallback: { kind: 'leaf', text: '' } }),
};

/**
 * 이어붙이기 노드 — 순서대로 조각. 각 조각은 **드래그 손잡이(⠿)로 순서변경 + ✕ 삭제**가 가능하고,
 * 하단 [+조각] 버튼으로 조각을 추가한다(-06-14 — "여러 조각을 하나로 묶어 순서를
 * 바꿀 수 있어야"). 종전엔 "손잡이로 순서를 바꾸세요" 안내만 있고 실제 손잡이·재배치가 없었다.
 * SegmentedValueEditor 와 동일한 useListDragReorder + DropLine 삽입선으로 일관된 감각을 준다.
 *
 * 조각 1개로 줄면 concat 을 벗고 그 단일 노드로 환원(불필요한 1항 이어붙이기 제거), 0개면 빈 리프.
 */
function ConcatView({
  node,
  onChange,
  t,
  candidates,
  testidPrefix,
  depth,
  renderLeafInput,
}: {
  node: ConcatNode;
  onChange: (next: ValueNode) => void;
  t: ConditionalValueEditorProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  depth: number;
  renderLeafInput?: LeafInputRenderer;
}): React.ReactElement {
  const setPart = useCallback(
    (i: number, part: ValueNode) => {
      const parts = node.parts.slice();
      parts[i] = part;
      onChange({ ...node, parts });
    },
    [node, onChange],
  );

  // 조각 리스트가 1개로 줄면 단일 노드로 환원(2개 미만은 concat 의미 없음), 0개면 빈 리프.
  const commitParts = useCallback(
    (parts: ValueNode[]) => {
      if (parts.length === 0) onChange({ kind: 'leaf', text: '' });
      else if (parts.length === 1) onChange(parts[0]);
      else onChange({ ...node, parts });
    },
    [node, onChange],
  );

  const removePart = useCallback(
    (i: number) => commitParts(node.parts.filter((_, idx) => idx !== i)),
    [node.parts, commitParts],
  );

  const addPart = useCallback(
    (kind: 'text' | 'expression' | 'fallback' | 'data') => commitParts([...node.parts, PART_SEED[kind]()]),
    [node.parts, commitParts],
  );

  const movePart = useCallback(
    (from: number, to: number) => {
      if (from === to || from < 0 || to < 0 || from >= node.parts.length || to >= node.parts.length) return;
      const parts = node.parts.slice();
      const [moved] = parts.splice(from, 1);
      parts.splice(to, 0, moved);
      commitParts(parts);
    },
    [node.parts, commitParts],
  );

  const dnd = useListDragReorder({ length: node.parts.length, onMove: movePart });

  return (
    <div data-testid={`${testidPrefix}-concat`} style={branchBox(depth)}>
      <div style={concatHint}>{t('layout_editor.value_tree.concat.hint')}</div>
      {node.parts.map((p, i) => (
        <React.Fragment key={i}>
          <DropLine active={dnd.isDropTarget(i)} testid={`${testidPrefix}-part-dropline-${i}`} />
          <div
            data-testid={`${testidPrefix}-part-card-${i}`}
            style={dnd.dragIndex === i ? { ...concatPartCard, opacity: 0.5 } : concatPartCard}
            onDragOver={(e) => {
              e.preventDefault();
              const rect = e.currentTarget.getBoundingClientRect();
              dnd.onDragOverItem(i, e.clientY < rect.top + rect.height / 2 ? 'before' : 'after');
            }}
            onDrop={(e) => { e.preventDefault(); dnd.onDrop(); }}
          >
            <div style={concatPartHead}>
              <span
                data-testid={`${testidPrefix}-part-handle-${i}`}
                style={dragHandle}
                draggable
                onDragStart={() => dnd.onDragStart(i)}
                onDragEnd={dnd.onDragEnd}
                title={t('layout_editor.value_tree.drag_reorder')}
                aria-label={t('layout_editor.value_tree.drag_reorder')}
                role="button"
              >
                ⠿
              </span>
              <span style={concatPartLabel}>{t('layout_editor.value_tree.concat.part', { n: i + 1 })}</span>
              <button
                type="button"
                data-testid={`${testidPrefix}-part-remove-${i}`}
                onClick={() => removePart(i)}
                title={t('layout_editor.value_tree.segment.remove')}
                aria-label={t('layout_editor.value_tree.segment.remove')}
                style={removeBtn}
              >
                ✕
              </button>
            </div>
            <div style={{ minWidth: 0 }}>
              <NodeView node={p} onChange={(np) => setPart(i, np)} t={t} candidates={candidates} testidPrefix={`${testidPrefix}-part-${i}`} depth={depth + 1} renderLeafInput={renderLeafInput} />
            </div>
          </div>
        </React.Fragment>
      ))}
      <DropLine active={dnd.isDropTarget(node.parts.length)} testid={`${testidPrefix}-part-dropline-end`} />
      <div style={concatAddRow} data-testid={`${testidPrefix}-part-add-row`}>
        <button type="button" data-testid={`${testidPrefix}-part-add-text`} onClick={() => addPart('text')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.text')}
        </button>
        <button type="button" data-testid={`${testidPrefix}-part-add-expression`} onClick={() => addPart('expression')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.expression')}
        </button>
        <button type="button" data-testid={`${testidPrefix}-part-add-fallback`} onClick={() => addPart('fallback')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.fallback')}
        </button>
        <button type="button" data-testid={`${testidPrefix}-part-add-data`} onClick={() => addPart('data')} style={addBtn}>
          + {t('layout_editor.value_tree.segment.data')}
        </button>
      </div>
    </div>
  );
}

/**
 * 조건 빌더 — 단순 비교(필드 + 연산 + 값)만 편집. 복잡 조건은 readonly "코드에서 수정"
 * (조건 로직은 비개발자 영역 밖, 손상 회피).
 */
function ConditionBuilder({
  condition,
  onChange,
  t,
  candidates,
  testidPrefix,
}: {
  condition: Condition;
  onChange: (next: Condition) => void;
  t: ConditionalValueEditorProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
}): React.ReactElement {
  if (condition.kind === 'raw') {
    return (
      <div data-testid={`${testidPrefix}-cond-raw`} style={condRawWrap}>
        <span style={condLabel}>{t('layout_editor.value_tree.cond.label')}</span>
        <code style={rawCode}>{condition.source}</code>
        <span style={rawBadge}>🔒 {t('layout_editor.value_tree.cond.code_only')}</span>
      </div>
    );
  }
  const c = condition;
  const needsValue = c.op !== 'truthy' && c.op !== 'falsy';
  return (
    <div data-testid={`${testidPrefix}-cond`} style={condWrap}>
      <span style={condLabel}>{t('layout_editor.value_tree.cond.label')}</span>
      {/* 기준 값(left)·비교값(right)은 **로컬 버퍼링 + blur 커밋**(BufferedCondInput). 매 글자 입력마다
          상위 onChange→직렬화→재파싱→트리 재구성을 하면, 불완전 경로(`route.`)가 RawCondition(코드에서
 수정 잠금)으로 떨어지고 입력칸이 리마운트되어 타이핑이 끊긴다(
          조건 입력 중 잠금). blur 에서만 커밋해 타이핑 중에는 재파싱하지 않는다. */}
      <BufferedCondInput
        testid={`${testidPrefix}-cond-left`}
        value={c.left}
        onCommit={(v) => onChange({ ...c, left: v })}
        placeholder={t('layout_editor.value_tree.cond.field_placeholder')}
        // 기준 값은 데이터 경로(route.id, form_meta.data...) — 직접 입력 + 데이터 검색 둘 다.
        // 조건 식은 `{{}}` 래핑 없는 순수 경로가 들어가므로 후보 선택 시 경로만 넣는다.
        candidates={candidates}
        t={t}
      />
      <select
        data-testid={`${testidPrefix}-cond-op`}
        value={c.op}
        onChange={(e) => onChange({ ...c, op: e.target.value as SimpleCondition['op'] })}
        style={condOp}
      >
        {SIMPLE_OPS.map((op) => (
          <option key={op.value} value={op.value}>
            {t(op.labelKey)}
          </option>
        ))}
      </select>
      {needsValue && (
        <BufferedCondInput
          testid={`${testidPrefix}-cond-right`}
          value={c.right}
          onCommit={(v) => onChange({ ...c, right: v })}
          placeholder={t('layout_editor.value_tree.cond.value_placeholder')}
          // 비교할 값도 직접 입력(고정 문자열) + 데이터 검색 둘 다("직접 입력 +
          // 데이터 검색 둘 다 제공"). 데이터 후보 선택 시 경로를, 직접 입력 시 리터럴을 그대로 둔다.
          candidates={candidates}
          t={t}
          quoteDataPick
        />
      )}
    </div>
  );
}

/**
 * 조건 기준 값/비교값 입력칸 — **로컬 버퍼링 + blur 커밋**. 타이핑 중에는 상위로 전파하지 않아
 * (직렬화→재파싱→리마운트로 인한 커서 소실·불완전 경로 잠금을 피한다), blur/Enter 에서만 커밋한다.
 * 외부 value 가 바뀌면(다른 노드/연산자 전환) 버퍼를 동기화한다.
 *
 * @since engine-v1.74.x
 */
function BufferedCondInput({
  testid,
  value,
  onCommit,
  placeholder,
  candidates,
  t,
  quoteDataPick = false,
}: {
  testid: string;
  value: string;
  onCommit: (v: string) => void;
  placeholder: string;
  /** 데이터 검색 후보 풀 — 있으면 입력칸 옆 🔍 데이터 검색 피커를 노출(직접 입력과 공존). */
  candidates?: BindingCandidate[];
  t?: ConditionalValueEditorProps['t'];
  /**
   * 데이터 선택 시 따옴표 리터럴로 감쌀지(비교값=문자열 비교 시). 기준 값(left)은 경로 그대로(false),
   * 비교값(right)도 데이터 경로 비교가 가능하므로 경로 그대로 둔다(현재 false 고정 — 따옴표는 직접
   * 입력으로). quoteDataPick 은 향후 "문자열 값 고정" UX 분기용 예약 플래그.
   */
  quoteDataPick?: boolean;
}): React.ReactElement {
  const [draft, setDraft] = useState<string | null>(null);
  // 외부 value 변경(연산자 전환/노드 교체)을 버퍼에 반영 — 편집 중(draft!=null)이 아닐 때만.
  const shown = draft !== null ? draft : value;
  const commit = (): void => {
    if (draft === null) return;
    if (draft !== value) onCommit(draft);
    setDraft(null);
  };
  // 데이터 후보 선택 — 조건 식은 `{{}}` 래핑 없는 순수 경로(`form_meta?.data?.board?.name`)를 쓴다.
  // buildBindingExpression 의 `{{...}}` 래핑/폴백을 벗겨 경로만 추출해 넣는다(직접 입력값을 즉시 교체).
  const pickData = (sourceId: string, path: string): void => {
    const wrapped = buildBindingExpression(sourceId, path, 'scalar'); // `{{src?.path ?? ''}}`
    const inner = /^\{\{\s*([\s\S]*?)\s*\}\}$/.exec(wrapped.trim());
    let pathOnly = inner ? inner[1] : wrapped;
    pathOnly = pathOnly.replace(/\s*\?\?\s*''$/, '').trim(); // 폴백 제거 → 순수 경로.
    onCommit(quoteDataPick ? `'${pathOnly}'` : pathOnly);
    setDraft(null);
  };
  return (
    <span style={condInputWrap}>
      <input
        type="text"
        data-testid={testid}
        value={shown}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            commit();
          }
        }}
        placeholder={placeholder}
        style={condField}
      />
      {candidates && candidates.length > 0 && t && (
        <InlineBindingScalarPicker
          candidates={candidates}
          t={t}
          onSelect={(c) => pickData(c.sourceId, c.path)}
          testIdSuffix={`${testid}-data`}
          // 좁은 조건 행 — 검색을 부유 드롭다운으로 띄워 행을 밀어내지 않는다.
          floating
        />
      )}
    </span>
  );
}

/** 분기 라벨 + 본문 한 행 */
function BranchRow({
  label,
  testid,
  children,
}: {
  label: string;
  testid: string;
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <div data-testid={testid} style={branchRow}>
      <span style={branchLabel}>{label}</span>
      <div style={branchBody}>{children}</div>
    </div>
  );
}

/* ── 스타일(g7le-* 인라인, CSS 라이브러리 비종속) ── */
const rootWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, width: '100%', minWidth: 0 };
const branchBox = (depth: number): React.CSSProperties => ({
  display: 'flex',
  flexDirection: 'column',
  gap: 6,
  padding: '8px',
  border: '1px solid #e2e8f0',
  borderLeft: `3px solid ${depth % 2 === 0 ? '#6366f1' : '#0ea5e9'}`,
  borderRadius: 6,
  background: depth % 2 === 0 ? '#f8fafc' : '#fefefe',
  minWidth: 0,
});
const branchRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 3, minWidth: 0 };
const branchLabel: React.CSSProperties = { fontSize: 11, fontWeight: 600, color: '#475569' };
const branchBody: React.CSSProperties = { paddingLeft: 8, minWidth: 0 };
const condWrap: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center', minWidth: 0 };
const condRawWrap: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center', minWidth: 0 };
const condLabel: React.CSSProperties = { fontSize: 11, fontWeight: 600, color: '#0f172a', whiteSpace: 'nowrap' };
// 기준값/비교값 입력칸 + 🔍 데이터 검색 피커 묶음 — 좁은 조건 행에서 함께 줄어들도록 flex.
const condInputWrap: React.CSSProperties = { display: 'inline-flex', alignItems: 'flex-start', gap: 2, flex: '1 1 80px', minWidth: 60 };
const condField: React.CSSProperties = { flex: '1 1 80px', minWidth: 60, padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const condOp: React.CSSProperties = { padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff' };
const concatHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
// 이어붙이기 조각 카드 — SegmentedValueEditor 조각 카드와 동일 시각(손잡이+라벨+삭제).
const concatPartCard: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: '6px 8px', border: '1px solid #e2e8f0', borderLeft: '3px solid #6366f1', borderRadius: 6, background: '#fff', minWidth: 0 };
const concatPartHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 };
const concatPartLabel: React.CSSProperties = { fontSize: 10, fontWeight: 600, color: '#6366f1', flex: 1, minWidth: 0 };
const concatAddRow: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 2 };
const dragHandle: React.CSSProperties = { color: '#94a3b8', cursor: 'grab', fontSize: 14, userSelect: 'none' };
const removeBtn: React.CSSProperties = { border: 'none', background: 'transparent', color: '#94a3b8', cursor: 'pointer', fontSize: 12, padding: 2, flexShrink: 0 };
const addBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 11, border: '1px dashed #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
const sourceRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, marginTop: 2 };
const sourceToggle: React.CSSProperties = { alignSelf: 'flex-start', padding: '3px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', fontFamily: 'monospace' };
const sourceCode: React.CSSProperties = { fontSize: 11, background: '#0f172a', color: '#e2e8f0', padding: '6px 8px', borderRadius: 4, wordBreak: 'break-all', fontFamily: 'monospace' };
const rawWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 };
const rawCode: React.CSSProperties = { fontSize: 11, background: '#f1f5f9', padding: '3px 6px', borderRadius: 4, color: '#0f172a', wordBreak: 'break-all', fontFamily: 'monospace' };
const rawBadge: React.CSSProperties = { fontSize: 10, color: '#64748b' };
