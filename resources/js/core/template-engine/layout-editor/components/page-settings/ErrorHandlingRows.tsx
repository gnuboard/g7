/**
 * ErrorHandlingRows.tsx — 코드별 에러 동작 행
 *
 * 상태 코드별(403/404/500/422/default …) 에러 동작을 행으로 편집하는 공용 부품. 두 곳에서
 * 재사용:
 *  - [에러 처리] 탭(출처 배지 모드) — 자체/부모 상속/템플릿 출처 배지 + 오버라이드 안내.
 *  - 데이터소스 errorHandling(로컬 모드) — 그 데이터소스의 errorHandling 만 단순 편집.
 *
 * 각 행의 동작은 `ActionListBuilder`(다중 액션) 또는 단일 동작으로 편집한다. 에러 컨텍스트
 * 5종(status/message/errors/data/statusText) 데이터칩은 `chipContext='error'` 로 흘려보낸다.
 * 동작 편집 폼(ActionAddPicker/recipe 선택)은 세션 B 가 `renderActionList` 렌더 프롭으로 주입.
 *
 * errorHandling 은 `{ [code]: action | action[] }` 객체 — array_replace 병합 정합(코드 키 정수
 * 변환 회피 위해 문자열 키 유지). 본 컴포넌트는 코드 행 add/remove + 행별 동작 위임만 한다.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback } from 'react';

/** 한 코드 행의 동작 — 단일 또는 배열 */
export type ErrorAction = Record<string, unknown> | Record<string, unknown>[];

/** errorHandling 맵 — `{ [code]: action }` */
export type ErrorHandlingMap = Record<string, ErrorAction>;

/** 행 출처(에러 처리 탭 배지) */
export type ErrorRowSource = 'self' | 'inherited' | 'template' | 'none';

/** ErrorHandlingRows props */
export interface ErrorHandlingRowsProps {
  /** 편집 대상 errorHandling 맵 */
  value: ErrorHandlingMap;
  /** 맵 변경 콜백 */
  onChange: (next: ErrorHandlingMap) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 행으로 노출할 코드 목록(예 `['403','404','500','default']`). 미지정 시 value 의 키.
   * [에러 처리] 탭은 error_config.layouts − maintenance 로 코드 행 생성.
   */
  codes?: string[];
  /** 모드 — 'badge'(에러 처리 탭, 출처 배지) / 'local'(데이터소스, 단순 편집) */
  mode?: 'badge' | 'local';
  /** 코드별 출처 도출(badge 모드) — 자체/부모상속/템플릿/없음 */
  sourceOf?: (code: string) => ErrorRowSource;
  /** 행별 동작 편집 폼 렌더 프롭(세션 B). 미주입 시 동작 JSON 요약만. */
  renderActionList?: (
    code: string,
    action: ErrorAction | undefined,
    onPatch: (next: ErrorAction) => void,
  ) => React.ReactNode;
  /** testid 접두사 */
  testIdPrefix?: string;
}

/** 출처 → 배지 라벨 키 */
const SOURCE_LABEL: Record<ErrorRowSource, string> = {
  self: 'layout_editor.error_rows.source_self',
  inherited: 'layout_editor.error_rows.source_inherited',
  template: 'layout_editor.error_rows.source_template',
  none: 'layout_editor.error_rows.source_none',
};

/**
 * 코드별 에러 동작 행.
 *
 * @param props ErrorHandlingRowsProps
 * @return 에러 동작 행 목록 엘리먼트
 */
export function ErrorHandlingRows({
  value,
  onChange,
  t,
  codes,
  mode = 'local',
  sourceOf,
  renderActionList,
  testIdPrefix = 'g7le-error-rows',
}: ErrorHandlingRowsProps): React.ReactElement {
  const rowCodes = codes ?? Object.keys(value);

  const patchCode = useCallback(
    (code: string, action: ErrorAction): void => {
      onChange({ ...value, [code]: action });
    },
    [value, onChange],
  );

  const clearCode = useCallback(
    (code: string): void => {
      const next = { ...value };
      delete next[code];
      onChange(next);
    },
    [value, onChange],
  );

  return (
    <div className={testIdPrefix} data-testid={testIdPrefix}>
      {rowCodes.map((code) => {
        const action = value[code];
        const hasAction = action !== undefined;
        const source = mode === 'badge' && sourceOf ? sourceOf(code) : null;
        return (
          <div
            key={code}
            data-testid={`${testIdPrefix}-row-${code}`}
            style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: '8px 10px', marginBottom: 6 }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <strong data-testid={`${testIdPrefix}-code-${code}`} style={{ fontSize: 13 }}>
                {code === 'default'
                  ? t('layout_editor.error_rows.default')
                  : code}
              </strong>
              {source ? (
                <span
                  data-testid={`${testIdPrefix}-source-${code}`}
                  style={{
                    fontSize: 11,
                    padding: '2px 6px',
                    borderRadius: 4,
                    background: '#f1f5f9',
                    color: '#475569',
                  }}
                >
                  〔{t(SOURCE_LABEL[source])}〕
                </span>
              ) : null}
              {hasAction ? (
                <button
                  type="button"
                  data-testid={`${testIdPrefix}-clear-${code}`}
                  onClick={() => clearCode(code)}
                  style={{ marginLeft: 'auto', fontSize: 12 }}
                >
                  {t('layout_editor.error_rows.clear')}
                </button>
              ) : (
                <span style={{ marginLeft: 'auto', fontSize: 12, color: '#94a3b8' }}>
                  {t('layout_editor.error_rows.no_action')}
                </span>
              )}
            </div>
            {/* 상속/템플릿 오버라이드 안내 + [되돌림]/[덮기] (W7 L2829). */}
            {(source === 'inherited' || source === 'template') ? (
              <div data-testid={`${testIdPrefix}-override-${code}`} style={{ marginTop: 6, display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                <p style={{ margin: 0, flex: 1, fontSize: 12, color: '#64748b' }}>
                  ⓘ {t(source === 'inherited' ? 'layout_editor.error_rows.override_inherited' : 'layout_editor.error_rows.override_template')}
                </p>
                {hasAction ? (
                  <button
                    type="button"
                    data-testid={`${testIdPrefix}-revert-${code}`}
                    onClick={() => clearCode(code)}
                    style={{ fontSize: 12, padding: '2px 8px', border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' }}
                  >
                    {t('layout_editor.error_rows.revert')}
                  </button>
                ) : (
                  <button
                    type="button"
                    data-testid={`${testIdPrefix}-override-here-${code}`}
                    onClick={() => patchCode(code, {})}
                    style={{ fontSize: 12, padding: '2px 8px', border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' }}
                  >
                    {t('layout_editor.error_rows.override_here')}
                  </button>
                )}
              </div>
            ) : null}
            {code === 'default' ? (
              <p
                data-testid={`${testIdPrefix}-default-warn-${code}`}
                style={{ margin: '6px 0 0', fontSize: 12, color: '#b45309' }}
              >
                ⚠ {t('layout_editor.error_rows.default_warning')}
              </p>
            ) : null}
            <div style={{ marginTop: 6 }}>
              {renderActionList ? (
                renderActionList(code, action, (next) => patchCode(code, next))
              ) : (
                <pre
                  data-testid={`${testIdPrefix}-json-${code}`}
                  style={{ margin: 0, fontSize: 12, color: '#475569' }}
                >
                  {hasAction ? JSON.stringify(action) : ''}
                </pre>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
