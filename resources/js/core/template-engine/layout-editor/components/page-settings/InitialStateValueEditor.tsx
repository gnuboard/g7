// e2e:allow 초기 상태 재귀 값 편집기 — 페이지 설정 모달 내부(캔버스 dnd 비의존), 편집기 UI 일관 정책으로 Chrome MCP 매트릭스 + 단위(InitialStateForm.test)로 검증.
/**
 * InitialStateValueEditor.tsx — 초기 상태 재귀 값 편집기
 *
 * `initLocal`/`initGlobal`/`initIsolated` 의 한 값(문자/숫자/예아니오/없음/목록/묶음)을 재귀
 * 편집한다. 값 종류 select + 타입별 위젯(text/number/toggle/null 고정). 묶음→하위 키 재귀
 * (`+ 하위 키 추가`), 목록→요소 재귀(`+ 항목 추가`). depth 들여쓰기. 로컬·전역·하위 공용.
 *
 * 표현식/고급 배지 분기 없음(최상위 init 은 정적값만, 손작성 `{{}}` 도 문자열로 무손실).
 * 값 변경은 onChange(새 값) — 호스트(InitialStateForm)가 setAtPath 로 루트에 반영한다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import {
  inferValueKind,
  defaultForKind,
  isValidStateKey,
  type ValueKind,
} from '../../spec/initialStateValueUtils';
import { ToggleSwitch } from './FormPrimitives';

export interface InitialStateValueEditorProps {
  /** 현재 값 */
  value: unknown;
  /** 값 변경(새 값 전체) */
  onChange: (value: unknown) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** testid 경로(점/인덱스) — `g7le-initstate-{scope}-item-{path}` 의 path 부분 */
  path: string;
  /** scope 접두(local/global/isolated) — testid 네임스페이스 */
  scope: string;
  /** 들여쓰기 depth */
  depth?: number;
  /**
   * 문자열(string) 리프 입력 렌더 주입(① — fallback 구조형 블럭에서 표현식 칩 허용).
   * 미주입 시 기존 평문 `<input>`(초기 상태 편집 — 표현식 금지). 주입 시 그 렌더로 문자열 값을
   * 편집한다(데이터소스 fallback 은 DataChipValueInput 주입으로 표현식/평문 모두 1급). 재귀
   * 전파되어 깊이 무관하게 모든 문자열 리프에 동일 입력기가 적용된다.
   */
  renderStringLeaf?: (leaf: {
    value: string;
    onChange: (v: string) => void;
    testidPrefix: string;
  }) => React.ReactNode;
}

const KIND_OPTIONS: Array<{ kind: ValueKind; labelKey: string }> = [
  { kind: 'string', labelKey: 'layout_editor.initstate.kind_string' },
  { kind: 'number', labelKey: 'layout_editor.initstate.kind_number' },
  { kind: 'boolean', labelKey: 'layout_editor.initstate.kind_boolean' },
  { kind: 'null', labelKey: 'layout_editor.initstate.kind_null' },
  { kind: 'list', labelKey: 'layout_editor.initstate.kind_list' },
  { kind: 'object', labelKey: 'layout_editor.initstate.kind_object' },
];

/**
 * 재귀 값 편집기.
 *
 * @param props InitialStateValueEditorProps
 * @return 값 편집 엘리먼트
 */
export function InitialStateValueEditor({
  value,
  onChange,
  t,
  path,
  scope,
  depth = 0,
  renderStringLeaf,
}: InitialStateValueEditorProps): React.ReactElement {
  const kind = inferValueKind(value);

  const changeKind = (newKind: ValueKind): void => {
    onChange(defaultForKind(newKind));
  };

  return (
    <div className="g7le-initstate-value" style={{ paddingLeft: depth > 0 ? 12 : 0, minWidth: 0 }}>
      <div style={row}>
        <select
          data-testid={`g7le-initstate-type-${path}`}
          value={kind}
          onChange={(e) => changeKind(e.target.value as ValueKind)}
          style={typeSelect}
        >
          {KIND_OPTIONS.map(({ kind: k, labelKey }) => (
            <option key={k} value={k}>
              {t(labelKey)}
            </option>
          ))}
        </select>

        {/* 타입별 값 위젯 */}
        {kind === 'string' ? (
          renderStringLeaf ? (
            <div style={{ flex: 1, minWidth: 0 }}>
              {renderStringLeaf({
                value: String(value ?? ''),
                onChange: (v) => onChange(v),
                testidPrefix: `g7le-initstate-value-${path}`,
              })}
            </div>
          ) : (
            <input type="text" data-testid={`g7le-initstate-value-${path}`} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} style={valueInput} />
          )
        ) : kind === 'number' ? (
          <input
            type="number"
            data-testid={`g7le-initstate-value-${path}`}
            value={typeof value === 'number' ? value : ''}
            onChange={(e) => {
              const v = parseFloat(e.target.value);
              onChange(Number.isNaN(v) ? 0 : v);
            }}
            style={valueInput}
          />
        ) : kind === 'boolean' ? (
          <ToggleSwitch
            checked={!!value}
            onChange={onChange}
            testid={`g7le-initstate-value-${path}`}
            label={value ? t('layout_editor.overlay.on') : t('layout_editor.overlay.off')}
          />
        ) : kind === 'null' ? (
          <span data-testid={`g7le-initstate-value-${path}`} style={nullFixed}>null</span>
        ) : null}
      </div>

      {/* 목록 — 요소 재귀 */}
      {kind === 'list' ? (
        <div style={nested}>
          {(value as unknown[]).map((item, i) => (
            <div key={i} style={listItemRow}>
              <span style={indexLabel}>[{i}]</span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <InitialStateValueEditor
                  value={item}
                  onChange={(v) => {
                    const next = (value as unknown[]).slice();
                    next[i] = v;
                    onChange(next);
                  }}
                  t={t}
                  path={`${path}.${i}`}
                  scope={scope}
                  depth={depth + 1}
                  renderStringLeaf={renderStringLeaf}
                />
              </div>
              <button
                type="button"
                data-testid={`g7le-initstate-item-remove-${path}.${i}`}
                onClick={() => onChange((value as unknown[]).filter((_, idx) => idx !== i))}
                style={removeBtn}
              >
                ✕
              </button>
            </div>
          ))}
          <button
            type="button"
            data-testid={`g7le-initstate-item-add-${path}`}
            onClick={() => onChange([...(value as unknown[]), ''])}
            style={addBtn}
          >
            + {t('layout_editor.initstate.add_item')}
          </button>
        </div>
      ) : null}

      {/* 묶음 — 하위 키 재귀 */}
      {kind === 'object' ? (
        <ObjectChildren value={value as Record<string, unknown>} onChange={onChange} t={t} path={path} scope={scope} depth={depth + 1} renderStringLeaf={renderStringLeaf} />
      ) : null}
    </div>
  );
}

/** 묶음 하위 키 편집(+ 하위 키 추가) */
function ObjectChildren({
  value,
  onChange,
  t,
  path,
  scope,
  depth,
  renderStringLeaf,
}: {
  value: Record<string, unknown>;
  onChange: (v: unknown) => void;
  t: InitialStateValueEditorProps['t'];
  path: string;
  scope: string;
  depth: number;
  renderStringLeaf?: InitialStateValueEditorProps['renderStringLeaf'];
}): React.ReactElement {
  const [newKey, setNewKey] = useState('');
  // 하위 값 종류 — 1뎁스 추가행(InitialStateForm.Section.addRow)과 동일하게 하위 키도
  // [이름]+[종류]+[추가] 3요소로 추가한다. 종전엔 종류
  // select 가 없어 항상 문자(`''`)로만 추가됐다(묶음/숫자 하위 값을 한 번에 만들 길이 없음).
  const [newKind, setNewKind] = useState<ValueKind>('string');
  const [keyError, setKeyError] = useState<string | null>(null);
  const entries = Object.entries(value);

  const addKey = (): void => {
    const k = newKey.trim();
    if (!k) { setKeyError(t('layout_editor.initstate.name_required')); return; }
    // 하위 키도 점 접근(`{{a.키}}`) 식별자로 쓰이므로 동일 검증.
    if (!isValidStateKey(k)) { setKeyError(t('layout_editor.initstate.name_invalid')); return; }
    if (k in value) { setKeyError(t('layout_editor.initstate.name_duplicate')); return; }
    // 선택한 종류의 기본값으로 추가(1뎁스 addRow 와 동일 — defaultForKind).
    onChange({ ...value, [k]: defaultForKind(newKind) });
    setNewKey('');
    setKeyError(null);
  };

  return (
    <div style={nested}>
      {entries.map(([k, v]) => (
        <div key={k} style={objKeyRow}>
          <code style={keyLabel}>{k}</code>
          <div style={{ flex: 1, minWidth: 0 }}>
            <InitialStateValueEditor
              value={v}
              onChange={(nv) => onChange({ ...value, [k]: nv })}
              t={t}
              path={`${path}.${k}`}
              scope={scope}
              depth={depth}
              renderStringLeaf={renderStringLeaf}
            />
          </div>
          <button
            type="button"
            data-testid={`g7le-initstate-subremove-${path}.${k}`}
            onClick={() => {
              const next = { ...value };
              delete next[k];
              onChange(next);
            }}
            style={removeBtn}
          >
            ✕
          </button>
        </div>
      ))}
      <div style={addKeyRow}>
        <input
          type="text"
          data-testid={`g7le-initstate-subkey-input-${path}`}
          value={newKey}
          placeholder={t('layout_editor.initstate.subkey_name')}
          onChange={(e) => { setNewKey(e.target.value); if (keyError) setKeyError(null); }}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addKey(); } }}
          aria-invalid={keyError ? true : undefined}
          style={{ ...subKeyInput, ...(keyError ? subKeyInputError : null) }}
        />
        {/* 종류 select — 1뎁스 addRow 와 동일하게 [이름]과 [추가] 사이. */}
        <select
          data-testid={`g7le-initstate-subkey-kind-${path}`}
          value={newKind}
          onChange={(e) => setNewKind(e.target.value as ValueKind)}
          aria-label={t('layout_editor.initstate.value_kind')}
          style={typeSelect}
        >
          {KIND_OPTIONS.map(({ kind: k, labelKey }) => (
            <option key={k} value={k}>
              {t(labelKey)}
            </option>
          ))}
        </select>
        <button type="button" data-testid={`g7le-initstate-subkey-add-${path}`} onClick={addKey} style={addBtn}>
          + {t('layout_editor.initstate.add_subkey')}
        </button>
      </div>
      {keyError ? (
        <p data-testid={`g7le-initstate-subkey-error-${path}`} style={subKeyErrorStyle}>{keyError}</p>
      ) : null}
    </div>
  );
}

const row: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'center', minWidth: 0 };
const typeSelect: React.CSSProperties = { padding: '4px 6px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, color: '#64748b' };
const valueInput: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const nullFixed: React.CSSProperties = { fontSize: 12, color: '#94a3b8', fontFamily: 'monospace' };
const nested: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, marginTop: 6, paddingLeft: 8, borderLeft: '2px solid #e2e8f0' };
const listItemRow: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'flex-start', minWidth: 0 };
const indexLabel: React.CSSProperties = { fontSize: 11, color: '#94a3b8', minWidth: 28, paddingTop: 4 };
const objKeyRow: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'flex-start', minWidth: 0 };
const keyLabel: React.CSSProperties = { fontSize: 11, color: '#475569', fontFamily: 'monospace', minWidth: 72, paddingTop: 4 };
const removeBtn: React.CSSProperties = { border: 'none', background: 'transparent', color: '#94a3b8', cursor: 'pointer', fontSize: 12 };
const addBtn: React.CSSProperties = { alignSelf: 'flex-start', padding: '2px 8px', fontSize: 11, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const addKeyRow: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'center' };
const subKeyInput: React.CSSProperties = { padding: '4px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6 };
const subKeyInputError: React.CSSProperties = { border: '1px solid #dc2626' };
const subKeyErrorStyle: React.CSSProperties = { margin: '2px 0 0', fontSize: 11, color: '#dc2626' };
