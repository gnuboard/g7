/**
 * ErrorHandlingForm.tsx — [에러 처리] 탭 본체
 *
 * 레이아웃 최상위 `errorHandling`(코드→동작)을 코드별 행으로 편집한다. 행 생성 = 편집 대상
 * 템플릿 `error_config.layouts` 키 − `maintenance` + `default` 행. 출처 도출:
 * own(`__editor.original`) ∋ code → 자체 / merged ∋ code(own ∌) → 부모 상속 / template
 * errorHandling ∋ code → 템플릿 / 없음 → 설정 없음.
 *
 * 코드별 동작 편집은 `ErrorHandlingRows`(A 산출 호스트) + 동작별 입력 분기(showErrorPage/
 * navigate/openModal/toast/setState/sequence·parallel)를 `renderActionList` 렌더 프롭으로
 * 주입한다. 에러 컨텍스트 데이터칩 5종(error.status/message/errors/data/statusText)은
 * `chipContext='error'` 로. maintenance 제외.
 *
 * 본 폼은 prop 주도 — 셸이 errorHandling·own·codes·templateCodes·recipes·후보를 주입한다.
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import { ErrorHandlingRows, type ErrorAction, type ErrorRowSource } from './ErrorHandlingRows';
import { ActionListBuilder } from './ActionListBuilder';
import { ParamFieldList, type ActionParamCandidatePools } from './ActionParamFields';
import {
  normalizeActionRecipes,
  buildAction,
  resolveActionCard,
} from '../../spec/actionRecipeEngine';
import type { ActionRecipeSpec } from '../../spec/specTypes';
import { buildActionContextCandidates, type BindingCandidate } from '../../spec/bindingCandidates';

export interface ErrorHandlingFormProps {
  /** 편집 중 errorHandling 맵(병합본) */
  value: Record<string, ErrorAction>;
  /** 변경 콜백 — 호스트가 patchDocumentRaw('errorHandling', …) */
  onChange: (next: Record<string, ErrorAction>) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** error_config.layouts 코드(maintenance 제외 전) — 편집 대상 템플릿 */
  errorConfigCodes?: string[];
  /** 자기 선언분(`__editor.original.errorHandling`) — 출처 판정 */
  ownCodes?: string[];
  /** 템플릿 errorHandling 코드(🏷 판정) */
  templateCodes?: string[];
  /** 에러 동작 친화 레시피(getErrorRecipes — 코어 시드 + 확장) */
  recipes?: Record<string, ActionRecipeSpec | string>;
  /** 라우트 후보(navigate page-picker) */
  pageCandidates?: Array<{ value: string; label: string }>;
  /** 레이아웃 modals 후보(openModal) */
  modalCandidates?: Array<{ value: string; label: string }>;
  /** 데이터소스 후보(refetch 등 데이터 동작 param) */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /** 상태 키 후보(setState 상태 키 검색) */
  stateKeyCandidates?: Array<{ value: string; label: string }>;
}

/** maintenance 제외 + default 행 부착 */
function buildRowCodes(errorConfigCodes: string[]): string[] {
  const codes = errorConfigCodes.filter((c) => c !== 'maintenance');
  return [...codes, 'default'];
}

/**
 * [에러 처리] 탭 폼.
 *
 * @param props ErrorHandlingFormProps
 * @return 에러 처리 폼 엘리먼트
 */
export function ErrorHandlingForm({
  value,
  onChange,
  t,
  errorConfigCodes = [],
  ownCodes = [],
  templateCodes = [],
  recipes,
  pageCandidates,
  modalCandidates,
  dataSourceCandidates,
  stateKeyCandidates,
}: ErrorHandlingFormProps): React.ReactElement {
  const normalized = useMemo(() => normalizeActionRecipes(recipes), [recipes]);
  const codes = useMemo(() => buildRowCodes(errorConfigCodes), [errorConfigCodes]);
  const hasRecipes = normalized.length > 0;

  // 에러 컨텍스트 데이터칩 후보(error.status/message/errors/data/statusText) — 모든 동작 입력칸이
  // `{{error.message}}` 등을 평문 타이핑하지 않고 칩으로 검색·삽입한다(데이터칩).
  const errorCands = useMemo<BindingCandidate[]>(() => buildActionContextCandidates('error', t), [t]);

  // params 위젯 후보 풀 — 공용 ParamFieldList 기본 편집 폼용. bindingCandidates = 에러 컨텍스트 칩.
  const pools = useMemo<ActionParamCandidatePools>(
    () => ({ pageCandidates, dataSourceCandidates, stateKeyCandidates, modalCandidates, bindingCandidates: errorCands }),
    [pageCandidates, dataSourceCandidates, stateKeyCandidates, modalCandidates, errorCands],
  );

  // 출처 도출.
  const sourceOf = useCallback(
    (code: string): ErrorRowSource => {
      if (ownCodes.includes(code)) return 'self';
      if (code in value) return 'inherited'; // merged 에 있으나 own 아님.
      if (templateCodes.includes(code)) return 'template';
      return 'none';
    },
    [ownCodes, value, templateCodes],
  );

  if (!hasRecipes) {
    // 디그레이드 — 코드 편집 안내 + 읽기전용 배지.
    return (
      <div className="g7le-error-form" data-testid="g7le-error-form" style={form}>
        <p style={heading}>{t('layout_editor.page_settings.error.heading')}</p>
        <p data-testid="g7le-error-degrade" style={degrade}>
          ⓘ {t('layout_editor.error.degrade')}
        </p>
        <ErrorHandlingRows value={value} onChange={onChange} t={t} codes={codes} mode="badge" sourceOf={sourceOf} testIdPrefix="g7le-error-rows" />
      </div>
    );
  }

  return (
    <div className="g7le-error-form" data-testid="g7le-error-form" style={form}>
      <p style={heading}>{t('layout_editor.page_settings.error.heading')}</p>
      <p style={hint}>ⓘ {t('layout_editor.error.intro')}</p>
      <ErrorHandlingRows
        value={value}
        onChange={onChange}
        t={t}
        codes={codes}
        mode="badge"
        sourceOf={sourceOf}
        testIdPrefix="g7le-error-rows"
        renderActionList={(code, action, onPatch) => (
          <ErrorActionEditor
            code={code}
            action={action}
            recipes={recipes}
            normalized={normalized}
            t={t}
            pools={pools}
            onPatch={onPatch}
          />
        )}
      />
    </div>
  );
}

/**
 * 한 코드 행의 동작 편집 — 7종 핸들러 select + 동작별 입력(공용 ParamFieldList).
 *
 * 종전엔 핸들러별 input/select 를 자작했는데(setState 상태값이 스프레드 build 와 어긋나 소실,
 * 모달 후보 미연동, 데이터칩 부재, 코드 미리보기 부재), 동작 종류 select 는 그대로 두되 그 아래
 * 입력칸은 [화면 동작]·데이터소스 동작과 동일한 공용 부품(`ParamFieldList` + `DataChipValueInput`)
 * 으로 일원화한다. 모든 자유값 칸에 에러 컨텍스트 데이터칩(error.*), setState 상태 키–값/모달
 * 후보 select/표시 위치 select 가 정상 작동하고, 항목별 코드 미리보기(`</>`)를 제공한다.
 * sequence/parallel 은 중첩 `ActionListBuilder`(카드 목록)로 여러 하위 동작을 편집한다.
 */
function ErrorActionEditor({
  code,
  action,
  recipes,
  normalized,
  t,
  pools,
  onPatch,
}: {
  code: string;
  action: ErrorAction | undefined;
  recipes?: Record<string, ActionRecipeSpec | string>;
  normalized: ReturnType<typeof normalizeActionRecipes>;
  t: ErrorHandlingFormProps['t'];
  pools: ActionParamCandidatePools;
  onPatch: (next: ErrorAction) => void;
}): React.ReactElement {
  const [codeOpen, setCodeOpen] = useState(false);

  // 단일 액션(배열 아님)으로 현재 핸들러 추출. 배열(레거시)이면 첫 동작을 기준으로 본다.
  const single = Array.isArray(action) ? (action[0] as Record<string, unknown> | undefined) : action;
  const handler = typeof single?.handler === 'string' ? single.handler : '';
  const recipe = normalized.find((r) => r.id === handler) ?? null;
  const card = single ? resolveActionCard(single, normalized) : null;
  const values = card?.kind === 'preset' ? card.values : {};
  const isSeq = handler === 'sequence' || handler === 'parallel';

  const setHandler = (h: string): void => {
    if (h === '') {
      // "설정 안 함" — ErrorHandlingRows 가 clear 로 키 제거하므로 여기선 빈 액션 회피.
      onPatch({});
      return;
    }
    const r = normalized.find((x) => x.id === h);
    if (r) onPatch(buildAction(r, {}));
  };

  // 7종 + 설정안함.
  const HANDLER_OPTIONS = ['', 'showErrorPage', 'navigate', 'openModal', 'toast', 'setState', 'sequence', 'parallel'];

  // sequence/parallel 의 하위 동작 배열.
  const seqActions = isSeq && single
    ? (Array.isArray((single.params as Record<string, unknown> | undefined)?.actions)
        ? ((single.params as Record<string, unknown>).actions as Array<Record<string, unknown>>)
        : [])
    : [];

  return (
    <div data-testid={`g7le-error-action-${code}`} style={editor}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        <select
          data-testid={`g7le-error-handler-${code}`}
          value={handler}
          onChange={(e) => setHandler(e.target.value)}
          style={{ ...select, flex: 1 }}
        >
          {HANDLER_OPTIONS.map((h) => (
            <option key={h} value={h}>
              {h === '' ? t('layout_editor.error.handler_none') : t(`layout_editor.error.handler_${h}`)}
            </option>
          ))}
        </select>
        {/* 항목별 코드 미리보기(`</>`) — 지정한 값이 코드로 어떻게 표현되는지 확인(다른 UI 와 일관). */}
        {handler !== '' ? (
          <button
            type="button"
            data-testid={`g7le-error-code-${code}`}
            onClick={() => setCodeOpen((v) => !v)}
            aria-label={t('layout_editor.action_list.view_code')}
            title={t('layout_editor.action_list.view_code')}
            style={codeOpen ? codeBtnActive : codeBtn}
          >
            {'</>'}
          </button>
        ) : null}
      </div>

      {/* 동작별 추가 입력 — 단일 동작은 공용 ParamFieldList(모든 칸 데이터칩·후보 select·상태 키–값),
          sequence/parallel 은 중첩 카드 목록. */}
      {isSeq ? (
        <div data-testid={`g7le-error-actions-${code}`}>
          <ActionListBuilder
            actions={seqActions}
            onChange={(next) => onPatch({ handler, params: { actions: next } })}
            t={t}
            recipes={recipes}
            candidatePools={pools}
            chipContext="error"
            testIdPrefix={`g7le-error-seq-${code}`}
          />
        </div>
      ) : recipe && single ? (
        <ParamFieldList
          raw={single}
          recipe={recipe}
          values={values}
          t={t}
          pools={pools}
          onChange={(next) => onPatch(next)}
          testIdPrefix={`g7le-error-action-${code}-edit`}
        />
      ) : null}

      {/* 코드 미리보기 패널 — 현재 동작 JSON. */}
      {codeOpen && single ? (
        <pre
          data-testid={`g7le-error-code-view-${code}`}
          style={codeView}
        >
          {JSON.stringify(action, null, 2)}
        </pre>
      ) : null}
    </div>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 12, minWidth: 0 };
const heading: React.CSSProperties = { margin: 0, fontSize: 13, fontWeight: 700, color: '#0f172a' };
const hint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const degrade: React.CSSProperties = { margin: 0, fontSize: 12, color: '#b45309', background: '#fef3c7', padding: '8px 10px', borderRadius: 6 };
const editor: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 };
const select: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
// 코드 미리보기(`</>`) 버튼 — ActionListBuilder 카드와 동일 시각(아이콘 버튼, 활성 시 강조).
const codeBtn: React.CSSProperties = { flex: '0 0 auto', width: 26, height: 26, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer', fontFamily: 'ui-monospace, monospace' };
const codeBtnActive: React.CSSProperties = { ...codeBtn, background: '#0f172a', borderColor: '#0f172a', color: '#e2e8f0' };
const codeView: React.CSSProperties = { margin: '4px 0 0', padding: 8, background: '#0f172a', color: '#e2e8f0', borderRadius: 6, fontSize: 12, overflow: 'auto', fontFamily: 'ui-monospace, monospace' };
