// e2e:allow 레이아웃 편집기 속성패널 배열 항목 에디터 UI — Chrome MCP 매트릭스(T1~T7) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * ArrayItemsEditor.tsx — `array` 노드 에디터
 *
 * 컴포넌트의 **배열 prop**(`node.props[arrayProp]` — 예 TabNavigation `tabs`,
 * Breadcrumb `items`, DataGrid `columns`, ActionMenu `items` …)을 항목 단위로
 * 구조 편집한다. `children` 노드 에디터(ChildrenListControl)가 실제 자식 노드
 * 트리를 다루는 것과 달리, 본 에디터는 **props 안의 정적 데이터 배열**(객체/문자열
 * 항목)을 추가/삭제/정렬/필드편집한다.
 *
 * 역할/스키마는 capability `nodeEditor: { kind: "array", params }` 가 공급한다
 * (컴포넌트명 가정 0 — 부록4-ter 중립성):
 *  - `arrayProp`: 편집 대상 prop 키(예 "tabs"/"items"/"columns").
 *  - `itemLabel`(선택): "추가" 버튼에 쓸 친화 단수 명사(`$t:` 키 또는 평문).
 *  - `fields`: 항목 1건의 편집 필드 스키마 — 각 `{ key, widget, label, ... }`.
 *      widget = `text` | `i18n-text` | `select`(options) | `boolean` | `icon`.
 *      `primary: true` 인 필드는 행 라벨/주 입력(미지정 시 첫 필드).
 *  - `newItem`(선택): "추가" 시 새 항목 골격(미지정 시 fields 기반 빈 객체 합성).
 *
 * 정적-바인딩 가드: `node.props[arrayProp]` 가 `{{...}}` 데이터바인딩
 * 문자열이거나 비-배열이면 편집 비대상 → "바인딩됨(코드 편집)" 디그레이드 표시
 * (덮어쓰기 위험 차단 — 메모리 feedback_dont_modify_stable_infrastructure_without_evidence).
 *
 * 항목 텍스트 다국어(`i18n-text` 필드): 현재 값이 `$t:` 키면 현재 로케일 해석값을
 * 입력칸 시작값으로 보이고, blur 시 평문이면 커스텀 키(`$t:custom.*`)를 생성해
 * 그 필드에 기록(인라인 편집과 동일 모델 — createCustomKey). 이미 커스텀 키면 현재
 * 로케일 값만 갱신(updateCustomKeyValue). children/table 항목 텍스트와 동일 SSoT.
 *
 * 모든 조작은 `onPatchNode({ ...node, props })` 로 노드 전체를 교체 → PATCH_LAYOUT
 * 으로 캔버스 즉시 반영 + history. 캔버스 인플레이스(부록4-bis TabNavigation
 * 레퍼런스 — 단계 4-b)는 동일 `node.props[arrayProp]` 패치 경로를 공유한다(SSoT 1벌).
 *
 * 노드 파생 무상태: 선택 노드 prop 이 바뀌면(다른 항목 선택/외부 패치) drafts 로컬
 * 버퍼를 useEffect 로 비운다(stale 회피 — 3-b CellPadding 결함 정합).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만, CSS 라이브러리 토큰 비종속.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback } from 'react';
import type { NodeEditorProps } from '../../spec/nodeEditorRegistry';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { I18nTextField } from './I18nTextField';

/** 바인딩식 토큰. */
const BINDING_RE = /\{\{.*?\}\}/;

/**
 * 위젯 종류 — params.fields[].widget.
 *
 * `number`(차트 값 등 단일 수치), `color`(차트 색상 등 HEX 색),
 * `number-list`(BarChart datasets[].data 처럼 라벨 정렬 수치 배열 — 콤마 구분 입력).
 */
type FieldWidget =
  | 'text'
  | 'i18n-text'
  | 'select'
  | 'boolean'
  | 'icon'
  | 'number'
  | 'color'
  | 'number-list';

/** 항목 1건 필드 스키마 (capability nodeEditor.params.fields[]). */
interface ItemFieldSpec {
  /** 항목 객체의 키(예 "label"/"id"/"iconName") */
  key: string;
  /** 입력 위젯 — 미지정 시 text */
  widget?: FieldWidget | string;
  /** 친화 라벨 — `$t:` 키 또는 평문 */
  label?: string;
  /** select 위젯 선택지 */
  options?: Array<{ value: unknown; label?: string }>;
  /** 행 라벨/주 입력 필드 여부(미지정 시 첫 필드가 primary) */
  primary?: boolean;
  /** 자유 필드 — 보존만 */
  [k: string]: unknown;
}

/** 배열 항목 — 객체(필드 맵) 또는 원시 문자열(string[] 옵션 등). */
type ArrayItem = Record<string, unknown> | string | number;

/**
 * node.props[arrayProp] 안전 추출 — prop 미정의 시 스펙 선언 기본 항목(defaultItems) 폴백.
 *
 * 컴포넌트가 prop 미지정 시 **내장 기본 목록**으로 렌더하는 경우(IconSelect 기본 아이콘
 * 22종 등), 빈 목록에서 항목을 1개 추가하면 prop 이 `[추가분]` 으로 기록되어 내장 목록
 * 전체가 교체되는 함정이 있었다. 스펙이
 * `params.defaultItems` 로 내장 목록을 그대로 선언하면 에디터가 그 목록을 시작 상태로
 * 보여주고, 첫 변경 커밋 시 전체 목록+변경분이 함께 저장된다. 코어는 의미를 모른다 —
 * 시드 데이터일 뿐(스펙 주도).
 */
function readArray(node: EditorNode, arrayProp: string, defaultItems: ArrayItem[] | null): ArrayItem[] {
  const props = (node.props ?? {}) as Record<string, unknown>;
  const raw = props[arrayProp];
  if (Array.isArray(raw)) return raw as ArrayItem[];
  // prop 미정의(undefined)일 때만 기본 시드 — 명시적 빈 배열([])은 작성자 의도로 존중.
  if (raw === undefined && defaultItems) {
    return JSON.parse(JSON.stringify(defaultItems)) as ArrayItem[];
  }
  return [];
}

/** 값이 `{{...}}` 바인딩 표현식 문자열인지. */
function isBindingExpression(value: unknown): boolean {
  return typeof value === 'string' && BINDING_RE.test(value);
}

/** 친화 라벨 해석 — `$t:` 키면 t(), 아니면 평문. */
function resolveLabel(
  label: string | undefined,
  t: (k: string, p?: Record<string, string | number>) => string,
  fallback: string,
): string {
  if (!label) return fallback;
  return label.startsWith('$t:') ? t(label.slice(3)) : label;
}

/** 항목에서 특정 필드값 읽기(객체면 키, 문자열 항목이면 primary 필드에 한해 그 자체). */
function readField(item: ArrayItem, field: ItemFieldSpec, isPrimary: boolean): unknown {
  if (typeof item === 'object' && item !== null) return (item as Record<string, unknown>)[field.key];
  // 원시 문자열/숫자 항목 — primary 필드에 매핑(예 string[] 옵션).
  return isPrimary ? item : undefined;
}

/** 항목의 특정 필드값을 교체한 새 항목 반환(immutable). */
function writeField(
  item: ArrayItem,
  field: ItemFieldSpec,
  isPrimary: boolean,
  value: unknown,
): ArrayItem {
  if (typeof item === 'object' && item !== null) {
    const next = { ...(item as Record<string, unknown>) };
    if (value === undefined) delete next[field.key];
    else next[field.key] = value;
    return next;
  }
  // 원시 항목 — primary 면 값 자체 교체, 아니면 객체로 승격.
  if (isPrimary) return value as ArrayItem;
  return { [field.key]: value } as Record<string, unknown>;
}

export function ArrayItemsEditor({
  node,
  params,
  t,
  onPatchNode,
  templateIdentifier,
  candidates,
}: NodeEditorProps): React.ReactElement {
  // templateIdentifier 는 NodeEditorProps 시그니처 호환용(미사용 — i18n-text 필드 편집은
  // I18nTextField 가 useCustomTranslation 으로 로케일/식별자/레이아웃명을 자체 해석).
  void templateIdentifier;

  const arrayProp = typeof params?.arrayProp === 'string' ? (params.arrayProp as string) : null;
  const fields: ItemFieldSpec[] = Array.isArray(params?.fields)
    ? (params!.fields as ItemFieldSpec[])
    : [];
  const itemLabel = resolveLabel(
    typeof params?.itemLabel === 'string' ? (params.itemLabel as string) : undefined,
    t,
    t('layout_editor.array_editor.item'),
  );
  // 새 항목 골격. 객체(필드 맵) 또는 원시값(string/number — string[] 등 원시 배열용).
  // 원시 배열은 항목이 객체가 아니므로 newItem 도 원시여야 add 시 배열 형태가 보존된다
  // (미지정 시 fields 기반 빈 객체로 합성 → 원시 배열에 객체가 섞이는 회귀). 빈 문자열도
  // 유효한 원시 골격이므로 `=== 'object'` 만으로 판정하면 falsy 로 떨어지는 함정을 피한다.
  const newItemRaw = params?.newItem;
  const newItemSkeleton =
    newItemRaw && typeof newItemRaw === 'object' ? (newItemRaw as Record<string, unknown>) : null;
  const newItemPrimitive =
    typeof newItemRaw === 'string' || typeof newItemRaw === 'number'
      ? (newItemRaw as string | number)
      : null;

  // primary 필드 결정(명시 우선, 없으면 첫 필드).
  const primaryKey = (fields.find((f) => f.primary) ?? fields[0])?.key;

  const rawValue = (node.props ?? ({} as Record<string, unknown>))[arrayProp ?? ''];

  // 스펙 선언 기본 항목(prop 미정의 시 시드 — 내장 기본 목록 교체 함정 차단, readArray 참조).
  const defaultItems = Array.isArray(params?.defaultItems)
    ? (params!.defaultItems as ArrayItem[])
    : null;

  const items = arrayProp ? readArray(node, arrayProp, defaultItems) : [];

  /** props 배열을 통째 교체해 노드 패치(캔버스 PATCH_LAYOUT 반영 + history). */
  const commit = useCallback(
    (next: ArrayItem[]): void => {
      if (!arrayProp) return;
      const props = { ...(node.props ?? {}) } as Record<string, unknown>;
      props[arrayProp] = next;
      onPatchNode({ ...node, props });
    },
    [arrayProp, node, onPatchNode],
  );

  /** params.newItem(객체/원시) 또는 fields 기반 빈 항목 골격. */
  const buildNewItem = useCallback((): ArrayItem => {
    if (newItemSkeleton) return JSON.parse(JSON.stringify(newItemSkeleton)) as ArrayItem;
    // 원시 골격(string[]/number[] 배열) — 객체 합성하지 않고 원시값을 그대로 추가.
    if (newItemPrimitive !== null) return newItemPrimitive;
    const obj: Record<string, unknown> = {};
    for (const f of fields) {
      if ((f.widget ?? 'text') === 'boolean') obj[f.key] = false;
      else obj[f.key] = '';
    }
    return obj;
  }, [newItemSkeleton, newItemPrimitive, fields]);

  const addItem = useCallback((): void => {
    commit([...items, buildNewItem()]);
  }, [commit, items, buildNewItem]);

  const removeAt = useCallback(
    (idx: number): void => {
      commit(items.filter((_, i) => i !== idx));
    },
    [commit, items],
  );

  const move = useCallback(
    (idx: number, dir: -1 | 1): void => {
      const target = idx + dir;
      if (target < 0 || target >= items.length) return;
      const next = [...items];
      [next[idx], next[target]] = [next[target]!, next[idx]!];
      commit(next);
    },
    [commit, items],
  );

  /** 비-i18n 필드 즉시 반영(text/select/boolean/icon). */
  const updateField = useCallback(
    (idx: number, field: ItemFieldSpec, value: unknown): void => {
      const isPrimary = field.key === primaryKey;
      const next = items.map((it, i) => (i === idx ? writeField(it, field, isPrimary, value) : it));
      commit(next);
    },
    [items, commit, primaryKey],
  );

  // i18n-text 필드는 공통 위젯 `I18nTextField`(useCustomTranslation SSoT)가 평문→키 생성·
  // ko/en/ja 일괄 편집·바인딩 디그레이드를 모두 처리한다(7-b 통일). 생성 토큰은 updateField 로
  // 그 필드에 기록 — 별도 commit 로직 불요.

  // arrayProp 미선언(잘못된 capability) — 안전 안내.
  if (!arrayProp) {
    return (
      <div
        className="g7le-node-editor g7le-node-editor--array"
        data-testid="g7le-array-editor-misconfigured"
        style={emptyHint}
      >
        {t('layout_editor.array_editor.no_array_prop')}
      </div>
    );
  }

  // 정적-바인딩 가드 — props[arrayProp] 가 바인딩식이거나 비-배열이면 디그레이드.
  if (isBindingExpression(rawValue) || (rawValue !== undefined && !Array.isArray(rawValue))) {
    return (
      <div
        className="g7le-node-editor g7le-node-editor--array"
        data-testid="g7le-array-editor-bound"
        style={boundHint}
      >
        {t('layout_editor.array_editor.bound_degraded')}
      </div>
    );
  }

  return (
    <div
      className="g7le-node-editor g7le-node-editor--array"
      data-testid="g7le-array-editor"
      style={wrap}
    >
      <div style={sectionTitle}>{t('layout_editor.array_editor.items_title')}</div>

      {items.length === 0 && (
        <div data-testid="g7le-array-empty" style={emptyHint}>
          {t('layout_editor.array_editor.empty')}
        </div>
      )}

      {items.map((item, idx) => (
        <div key={idx} data-testid={`g7le-array-row-${idx}`} style={itemBox}>
          <div style={itemHeader}>
            <span style={itemIndex}>#{idx + 1}</span>
            <div style={{ flex: 1 }} />
            <button
              type="button"
              data-testid={`g7le-array-up-${idx}`}
              title={t('layout_editor.array_editor.move_up')}
              disabled={idx === 0}
              onClick={() => move(idx, -1)}
              style={iconBtn}
            >
              ↑
            </button>
            <button
              type="button"
              data-testid={`g7le-array-down-${idx}`}
              title={t('layout_editor.array_editor.move_down')}
              disabled={idx === items.length - 1}
              onClick={() => move(idx, 1)}
              style={iconBtn}
            >
              ↓
            </button>
            <button
              type="button"
              data-testid={`g7le-array-remove-${idx}`}
              title={t('layout_editor.array_editor.remove')}
              onClick={() => removeAt(idx)}
              style={removeBtn}
            >
              ✕
            </button>
          </div>

          {fields.map((field) => {
            const widget = (field.widget ?? 'text') as FieldWidget;
            const isPrimary = field.key === primaryKey;
            const fieldLabel = resolveLabel(field.label, t, field.key);
            const raw = readField(item, field, isPrimary);
            const testid = `g7le-array-field-${idx}-${field.key}`;

            if (widget === 'boolean') {
              return (
                <label key={field.key} style={fieldRow}>
                  <span style={fieldLabelStyle}>{fieldLabel}</span>
                  <input
                    type="checkbox"
                    data-testid={testid}
                    checked={raw === true}
                    onChange={(e) => updateField(idx, field, e.target.checked)}
                  />
                </label>
              );
            }

            if (widget === 'select') {
              const opts = Array.isArray(field.options) ? field.options : [];
              return (
                <label key={field.key} style={fieldRow}>
                  <span style={fieldLabelStyle}>{fieldLabel}</span>
                  <select
                    data-testid={testid}
                    value={raw === undefined || raw === null ? '' : String(raw)}
                    onChange={(e) => updateField(idx, field, e.target.value === '' ? undefined : e.target.value)}
                    style={cellInput}
                  >
                    <option value="">—</option>
                    {opts.map((o, oi) => (
                      <option key={oi} value={String(o.value)}>
                        {resolveLabel(o.label, t, String(o.value))}
                      </option>
                    ))}
                  </select>
                </label>
              );
            }

            if (widget === 'i18n-text') {
              // 항목 i18n-text 필드도 속성 패널·옵션 라벨·children·표 셀과 **동일
              // 공통 위젯 `I18nTextField`**(useCustomTranslation SSoT). 평문→`$t:custom.*` 자동
              // 생성 토큰을 그 필드에 기록, 🌐 ko/en/ja 일괄 편집, `{{...}}` 바인딩 읽기전용.
              // (이전: 자체 input+blur 라 펼침 폼/바인딩 디그레이드 없던 패리티 결함.)
              const rawStr = typeof raw === 'string' ? raw : raw == null ? '' : String(raw);
              return (
                <label key={field.key} style={fieldRow}>
                  <span style={fieldLabelStyle}>{fieldLabel}</span>
                  <div data-testid={testid} style={{ flex: 1, minWidth: 0 }}>
                    <I18nTextField
                      value={rawStr}
                      onChange={(token) => updateField(idx, field, token === '' ? undefined : token)}
                      t={t}
                      placeholder={fieldLabel}
                      testidPrefix={`${testid}-i18n`}
                      // 항목 텍스트도 `+데이터` 칩 삽입(키화)에 후보 풀이 닿도록 전달.
                      candidates={candidates}
                      // 배열 항목 텍스트도 표현식 분해
                      // 트리(접힌 미리보기 + [수정]) + 데이터 칩. 평문/단일키/칩은 종전 경로(회귀 0).
                      enableExpressionTree
                      expressionTreeCollapsible
                    />
                  </div>
                </label>
              );
            }

            if (widget === 'number') {
              // 단일 수치(차트 값/슬라이스 값 등). 빈 입력은 undefined, 그 외 Number 변환.
              const numValue = raw === undefined || raw === null ? '' : String(raw);
              return (
                <label key={field.key} style={fieldRow}>
                  <span style={fieldLabelStyle}>{fieldLabel}</span>
                  <input
                    type="number"
                    data-testid={testid}
                    value={numValue}
                    onChange={(e) => {
                      const v = e.target.value;
                      updateField(idx, field, v === '' ? undefined : Number(v));
                    }}
                    style={cellInput}
                  />
                </label>
              );
            }

            if (widget === 'color') {
              // HEX 색(차트 색상 등). 색 피커 + 텍스트 입력 병행(자유 HEX/토큰 허용).
              const colorValue = typeof raw === 'string' ? raw : '';
              const swatch = /^#[0-9a-fA-F]{6}$/.test(colorValue) ? colorValue : '#000000';
              return (
                <label key={field.key} style={fieldRow}>
                  <span style={fieldLabelStyle}>{fieldLabel}</span>
                  <input
                    type="color"
                    data-testid={`${testid}-swatch`}
                    value={swatch}
                    onChange={(e) => updateField(idx, field, e.target.value)}
                    style={colorSwatch}
                  />
                  <input
                    type="text"
                    data-testid={testid}
                    value={colorValue}
                    placeholder="#7C3AED"
                    onChange={(e) => updateField(idx, field, e.target.value === '' ? undefined : e.target.value)}
                    style={cellInput}
                  />
                </label>
              );
            }

            if (widget === 'number-list') {
              // 수치 배열(BarChart datasets[].data — 라벨 정렬 값). 콤마 구분 입력 ↔ number[].
              // 비-수치 토큰은 0 으로 정규화하지 않고 그대로 거르며(빈 항목 제거), 빈 입력은 [].
              const listValue = Array.isArray(raw) ? (raw as unknown[]).join(', ') : '';
              return (
                <label key={field.key} style={fieldRow}>
                  <span style={fieldLabelStyle}>{fieldLabel}</span>
                  <input
                    type="text"
                    data-testid={testid}
                    value={listValue}
                    placeholder={t('layout_editor.array_editor.number_list_placeholder')}
                    onChange={(e) => {
                      const parts = e.target.value
                        .split(',')
                        .map((s) => s.trim())
                        .filter((s) => s !== '')
                        .map((s) => Number(s))
                        .filter((n) => !Number.isNaN(n));
                      updateField(idx, field, parts);
                    }}
                    style={cellInput}
                  />
                </label>
              );
            }

            // text / icon — 평문 즉시 반영(icon 은 아이콘명 문자열).
            const textValue = raw === undefined || raw === null ? '' : String(raw);
            return (
              <label key={field.key} style={fieldRow}>
                <span style={fieldLabelStyle}>{fieldLabel}</span>
                <input
                  type="text"
                  data-testid={testid}
                  value={textValue}
                  placeholder={widget === 'icon' ? t('layout_editor.array_editor.icon_placeholder') : fieldLabel}
                  onChange={(e) => updateField(idx, field, e.target.value === '' ? undefined : e.target.value)}
                  style={cellInput}
                />
              </label>
            );
          })}
        </div>
      ))}

      <button
        type="button"
        data-testid="g7le-array-add"
        onClick={addItem}
        style={addBtn}
      >
        {t('layout_editor.array_editor.add', { item: itemLabel })}
      </button>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, width: '100%', marginBottom: 12 };
const sectionTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 2 };
const itemBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: 8, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' };
const itemHeader: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center' };
const itemIndex: React.CSSProperties = { fontSize: 11, fontWeight: 700, color: '#64748b' };
// flexWrap — 라벨+입력(특히 🌐 다국어 위젯)이 좁은 폭에서 가로 스크롤 대신 줄바꿈되도록.
const fieldRow: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' };
const fieldLabelStyle: React.CSSProperties = { fontSize: 11, color: '#475569', minWidth: 64, flexShrink: 0 };
const cellInput: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const colorSwatch: React.CSSProperties = { width: 32, height: 28, padding: 0, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', cursor: 'pointer', flexShrink: 0 };
const iconBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const removeBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const addBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', alignSelf: 'flex-start' };
const emptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
const boundHint: React.CSSProperties = { fontSize: 11, color: '#b45309', fontStyle: 'italic', padding: '4px 2px' };
