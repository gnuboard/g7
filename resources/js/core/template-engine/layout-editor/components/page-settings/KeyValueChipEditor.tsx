/**
 * KeyValueChipEditor.tsx — og.extra/twitter.extra 공용 키–값 에디터
 *
 * Open Graph/Twitter 의 "그 밖의 속성"(SSoT 스키마 = **배열** of `{property,content}` 또는
 * `{name,content}`)을 키–값 행으로 편집한다. og.extra 는 `property` 키, twitter.extra 는 `name`
 * 키를 쓴다(직렬화 키는 `keyField` prop 으로 결정). 값(content)은 데이터칩 입력(DataChipValueInput).
 *
 *  - 행 추가/삭제, 키(property/name) 텍스트 + 값(content) 데이터칩.
 *  - 빈 키 행은 직렬화에서 제외(무손실 — 입력 중 빈 행 허용, onChange 는 키 있는 행만 방출).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import { DataChipValueInput } from './DataChipValueInput';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** extra 항목 1건 — `{property|name, content}` */
export type KeyValueExtraItem = Record<string, string>;

export interface KeyValueChipEditorProps {
  /** 편집 대상 배열(`{[keyField], [valueField]}[]`) */
  value: KeyValueExtraItem[] | null | undefined;
  /** 변경 콜백 — 키 있는 행만 방출(빈 키 행 제외) */
  onChange: (next: KeyValueExtraItem[]) => void;
  /**
   * 직렬화 키 필드 — og=`property`, twitter=`name`, 일반 키-값(데이터소스 params 등)=`key`.
   *  일반화: 임의 문자열 허용(종전 'property'|'name' 고정 → 범용). 기본 placeholder 는
   * `layout_editor.page_settings.seo.extra_${keyField}` 키이므로, 그 키가 없는 일반 용도는
   * `keyPlaceholder` 로 직접 라벨을 준다.
   */
  keyField: string;
  /** 직렬화 값 필드 — 기본 `content`(og/twitter). 일반 키-값은 `value` 등. */
  valueField?: string;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 값 데이터칩 후보 풀 */
  candidates?: BindingCandidate[];
  /** data-testid 접두 */
  testidPrefix?: string;
  /** 키 입력 placeholder(미전달 시 `extra_${keyField}` 키). 일반 용도(params)에서 직접 라벨 지정. */
  keyPlaceholder?: string;
  /** 값 입력 placeholder(미전달 시 `extra_content` 키). */
  valuePlaceholder?: string;
  /** 행 추가 버튼 라벨(미전달 시 `add_extra` 키). */
  addLabel?: string;
  /**
   * 키 입력칸 렌더 주입(일반화) — 미주입 시 기본 평문 `<input>`(og/twitter 회귀 0).
   * setState 상태 payload 처럼 키가 "상태 키 검색"이어야 하는 용도에서 state-key-picker 를
   * 주입한다. 주입 시 그 렌더로 키를 편집한다(값측은 항상 DataChipValueInput).
   */
  renderKeyInput?: (key: {
    value: string;
    onChange: (v: string) => void;
    testid: string;
    placeholder: string;
  }) => React.ReactNode;
}

/** 내부 편집 행(빈 키 허용) */
interface EditRow {
  /** 고유 행 키(React 키 — 입력 중 안정) */
  id: number;
  /** property/name */
  k: string;
  /** content */
  v: string;
}

let rowSeq = 0;

/**
 * og.extra/twitter.extra 키–값 에디터.
 *
 * @param props KeyValueChipEditorProps
 * @return 키–값 행 목록 엘리먼트
 */
export function KeyValueChipEditor({
  value,
  onChange,
  keyField,
  valueField = 'content',
  t,
  candidates,
  testidPrefix = 'g7le-kv-chip-editor',
  keyPlaceholder,
  valuePlaceholder,
  addLabel,
  renderKeyInput,
}: KeyValueChipEditorProps): React.ReactElement {
  // 외부 value → 내부 행(빈 키 행을 입력 중에도 유지하기 위해 로컬 상태로 변환).
  const initialRows = useMemo<EditRow[]>(
    () =>
      (Array.isArray(value) ? value : []).map((item) => ({
        id: rowSeq++,
        k: typeof item[keyField] === 'string' ? item[keyField] : '',
        v: typeof item[valueField] === 'string' ? item[valueField] : '',
      })),
    // value/keyField 변경 시에만 재동기(편집 중 keystroke 마다 재생성 방지).
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );
  const [rows, setRows] = useState<EditRow[]>(initialRows);

  const emit = useCallback(
    (next: EditRow[]): void => {
      setRows(next);
      // 키가 있는 행만 직렬화 — 빈 키 행은 무손실 입력 중 보조.
      const serialized = next
        .filter((r) => r.k.trim() !== '')
        .map<KeyValueExtraItem>((r) => ({ [keyField]: r.k, [valueField]: r.v }));
      onChange(serialized);
    },
    [keyField, valueField, onChange],
  );

  const addRow = useCallback((): void => {
    emit([...rows, { id: rowSeq++, k: '', v: '' }]);
  }, [rows, emit]);

  const removeRow = useCallback(
    (id: number): void => {
      emit(rows.filter((r) => r.id !== id));
    },
    [rows, emit],
  );

  const patchKey = useCallback(
    (id: number, k: string): void => {
      emit(rows.map((r) => (r.id === id ? { ...r, k } : r)));
    },
    [rows, emit],
  );

  const patchValue = useCallback(
    (id: number, v: string): void => {
      emit(rows.map((r) => (r.id === id ? { ...r, v } : r)));
    },
    [rows, emit],
  );

  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row) => (
        <div
          key={row.id}
          data-testid={`${testidPrefix}-row-${row.id}`}
          style={{ display: 'flex', gap: 6, alignItems: 'flex-start', border: '1px solid #e2e8f0', borderRadius: 8, padding: 8 }}
        >
          {/* 키·값을 세로 스택으로 — 각 입력기가 데이터칩/표현식 affordance(🔍·ƒx·??·≡)를 자기
 full-width 줄에서 펼쳐 가로 겹침 0.
              종전 key(160px) | value(flex) 가로 배치는 좁은 키 칸에서 버튼이 넘쳐 ≡ 와 충돌했다. */}
          <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
              {renderKeyInput ? (
                <div style={{ minWidth: 0 }}>
                  {renderKeyInput({
                    value: row.k,
                    onChange: (v) => patchKey(row.id, v),
                    testid: `${testidPrefix}-key-${row.id}`,
                    placeholder: keyPlaceholder ?? t(`layout_editor.page_settings.seo.extra_${keyField}`),
                  })}
                </div>
              ) : (
                <input
                  type="text"
                  data-testid={`${testidPrefix}-key-${row.id}`}
                  value={row.k}
                  placeholder={keyPlaceholder ?? t(`layout_editor.page_settings.seo.extra_${keyField}`)}
                  onChange={(e) => patchKey(row.id, e.target.value)}
                  style={{ width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 0, boxSizing: 'border-box' }}
                />
              )}
            </div>
            <div style={{ minWidth: 0 }}>
              <DataChipValueInput
                value={row.v}
                onChange={(v) => patchValue(row.id, v)}
                t={t}
                candidates={candidates}
                placeholder={valuePlaceholder ?? t('layout_editor.page_settings.seo.extra_content')}
                testidPrefix={`${testidPrefix}-value-${row.id}`}
              />
            </div>
          </div>
          <button
            type="button"
            data-testid={`${testidPrefix}-remove-${row.id}`}
            onClick={() => removeRow(row.id)}
            title={t('layout_editor.page_settings.seo.remove')}
            aria-label={t('layout_editor.page_settings.seo.remove')}
            style={{ flex: '0 0 auto', padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer' }}
          >
            ✕
          </button>
        </div>
      ))}
      <button
        type="button"
        data-testid={`${testidPrefix}-add`}
        onClick={addRow}
        style={{ alignSelf: 'flex-start', padding: '5px 10px', fontSize: 12, border: '1px dashed #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer' }}
      >
        + {addLabel ?? t('layout_editor.page_settings.seo.add_extra')}
      </button>
    </div>
  );
}
