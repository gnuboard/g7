// e2e:allow 레이아웃 편집기 페이지 설정 모달 — 캔버스 dnd/합성 이벤트 비의존이나, 편집기 UI 일관 정책으로 Chrome MCP 매트릭스 + 단위 테스트로 검증
/**
 * DataSourcesPanel.tsx — 페이지 설정 · 데이터 소스 CRUD 모달.
 *
 * 현재 레이아웃의 `data_sources` 배열을 목록으로 보여 주고 추가/편집/삭제를
 * 제공한다. 툴바 ⚙데이터 버튼으로 진입(🖼이미지·🌐다국어 모달과 동형).
 *
 * SSoT 분리:
 *  - **자체 소스**(편집 대상) = `document.raw.__editor.original.data_sources`.
 *    편집기가 저장할 때 마스킹 골격(`stripInheritedFromLayoutContent`)이 original
 *    을 쓰므로, 영속 대상은 original 의 data_sources 다. `__editor` 부재(레거시)면
 *    최상위 `raw.data_sources` 로 폴백.
 *  - **상속 소스**(extends 레이아웃의 부모 소스) = 최상위 merged 목록 중 자체 id 에
 *    없는 항목. 읽기전용으로 표시(편집/삭제 불가) — ValidDataSourceMerge 가 부모
 *    id 중복을 거부하므로 자체 목록에 섞지 않는다.
 *
 * 패치는 `patchDocumentRaw('data_sources', mergedList, ownList)` 한 번으로:
 *  - 최상위 raw.data_sources = [상속 ∪ 자체] (검색 후보·오버레이가 즉시 읽음)
 *  - __editor.original.data_sources = 자체만 (저장 골격에 들어감)
 *
 * label_key 는 `$t:` 다국어 키 — 검색형 데이터 피커(6-a/6-b)가 현재 로케일로
 * 해석해 친화 명칭으로 표시한다. 편집기 코어 위젯이므로 `g7le-*`
 * BEM + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import { I18nTextField } from './I18nTextField';
import { ActionListBuilder, type ActionItem } from '../page-settings/ActionListBuilder';
import { ErrorHandlingRows, type ErrorHandlingMap } from '../page-settings/ErrorHandlingRows';
import { ParamFieldList, type ActionParamCandidatePools } from '../page-settings/ActionParamFields';
import { normalizeActionRecipes, resolveActionCard, buildAction } from '../../spec/actionRecipeEngine';
import { ConditionBuilder } from './ConditionBuilder';
import { KeyValueChipEditor } from '../page-settings/KeyValueChipEditor';
import { InitialStateValueEditor } from '../page-settings/InitialStateValueEditor';
import { DataChipValueInput } from '../page-settings/DataChipValueInput';
import { JsonBlockField } from '../JsonBlockField';
import {
  paramsToRows,
  rowsToParams,
  hasNestedParamValue,
  type ParamRow,
} from '../../spec/dataSourceParamsUtils';
import {
  dataSourceToConditionNode,
  applyConditionNodeToDataSource,
} from '../../spec/dataSourceConditionAdapter';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { ActionRecipeSpec } from '../../spec/specTypes';
import {
  friendlyDataSourceName,
  dataSourceExtensionBadge,
} from '../../spec/candidatePools';
import { buildActionContextCandidates } from '../../spec/bindingCandidates';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** data_source 한 건(편집기 표시용 — 임의 추가 필드 보존). */
export type DataSourceEntry = Record<string, unknown> & { id?: unknown };

const DS_TYPES = ['api', 'static', 'route_params', 'query_params', 'websocket'] as const;
const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const;
const AUTH_MODES = ['none', 'optional', 'required'] as const;
const LOADING_STRATEGIES = ['progressive', 'blocking', 'background'] as const;
const CONTENT_TYPES = ['application/json', 'multipart/form-data'] as const;
const CHANNEL_TYPES = ['public', 'private', 'presence'] as const;

export interface DataSourcesPanelProps {
  /** 현재 레이아웃 문서 raw (병합 + __editor.original 포함) */
  raw: Record<string, unknown> | null | undefined;
  /**
   * 자체 data_sources 변경 콜백.
   * @param merged 최상위 raw.data_sources 로 쓸 값(상속 ∪ 자체)
   * @param own    __editor.original.data_sources 로 쓸 값(자체만)
   */
  onChange: (merged: DataSourceEntry[], own: DataSourceEntry[]) => void;
  /** 다국어 해석 함수 (편집기 chrome 키) */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * `label_key`($t: 키) → 친화 명칭 해석 함수 (편집 대상 템플릿 사전 우선, editorAwareT 식).
   * 미전달 시 `t` 폴백. data_source label_key 는 편집 대상 템플릿의 `editor.data_source.*`
   * 키라 chrome `t` 로는 해석되지 않으므로 호출자(chrome)가 editorAwareT 를 전달한다.
   */
  resolveLabel?: (key: string, params?: Record<string, string | number>) => string;
  /** 모달 닫기 */
  onClose: () => void;
  /**
   * 액션 친화 레시피(onSuccess/onError/onReceive 다중 액션 빌더).
   * 코어 시드 + 확장 병합본. 미전달 시 빌더는 핸들러명 폴백.
   */
  actionRecipes?: Record<string, ActionRecipeSpec | string>;
  /**
   * 에러 동작 친화 레시피(errorHandling). 미전달 시 코드별 동작 JSON 요약만.
   */
  errorRecipes?: Record<string, ActionRecipeSpec | string>;
  /** 표시조건 빌더용 병합 editor-spec(conditionRecipes) — 조건부 로딩 */
  conditionSpec?: import('../../spec/specTypes').EditorSpec | null;
  /** 데이터칩 후보 풀(params/fallback·동작 param 칩 해석). 미전달 시 칩 피커 숨김. */
  bindingCandidates?: import('../../spec/bindingCandidates').BindingCandidate[];
  /** 라우트 후보(동작 page-picker). */
  pageCandidates?: Array<{ value: string; label: string }>;
  /** 데이터소스 후보(동작 datasource-picker). */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /** 상태 키 후보(동작 state-key-picker). */
  stateKeyCandidates?: Array<{ value: string; label: string }>;
}

// 친화 명칭(friendlyDataSourceName)·확장 출처 배지(dataSourceExtensionBadge)는 멀티선택 칩
// ([로딩 화면] wait_for / [검색엔진] SEO 연동 데이터)과 같은 도출을 쓰도록 candidatePools 로
// 추출해 공유한다(SSoT — 패널과 칩의 친화명·출처 표기 불일치 0).

/** raw 에서 자체/상속 소스를 분리한다. */
function splitSources(raw: Record<string, unknown> | null | undefined): {
  own: DataSourceEntry[];
  inherited: DataSourceEntry[];
} {
  if (!raw || typeof raw !== 'object') return { own: [], inherited: [] };
  const editorMeta = raw.__editor;
  const original =
    editorMeta && typeof editorMeta === 'object' && !Array.isArray(editorMeta)
      ? (editorMeta as Record<string, unknown>).original
      : undefined;
  const ownRaw =
    original && typeof original === 'object' && !Array.isArray(original)
      ? (original as Record<string, unknown>).data_sources
      : raw.data_sources;
  const own: DataSourceEntry[] = Array.isArray(ownRaw)
    ? (ownRaw as DataSourceEntry[]).filter((d) => d && typeof d === 'object')
    : [];
  const ownIds = new Set(
    own.map((d) => (typeof d.id === 'string' ? d.id : '')).filter(Boolean),
  );
  const mergedRaw = raw.data_sources;
  const merged: DataSourceEntry[] = Array.isArray(mergedRaw)
    ? (mergedRaw as DataSourceEntry[]).filter((d) => d && typeof d === 'object')
    : [];
  // 상속 = merged 중 자체 id 에 없는 항목.
  const inherited = merged.filter(
    (d) => typeof d.id === 'string' && !ownIds.has(d.id),
  );
  return { own, inherited };
}

/** 편집 폼 초안 — 문자열 필드는 string, params 는 JSON 텍스트로 관리. */
interface DraftState {
  /** 편집 중인 자체 소스의 배열 인덱스. -1 = 신규 추가. */
  index: number;
  id: string;
  type: string;
  label_key: string;
  endpoint: string;
  method: string;
  auth_mode: string;
  loading_strategy: string;
  auto_fetch: boolean;
  /** 요청 파라미터 행(키–값 블럭 — 값은 표현식/평문/숫자). ① 블럭 편집. */
  paramRows: ParamRow[];
  /**
   * params 를 raw JSON(코드) 으로 편집 중인지(중첩 객체/배열 값 — 칩 에디터로 평탄화 불가). true 면
   * paramsRawValue 를 직렬화하고, false 면 paramRows 를 직렬화한다. 코드 편집기는 공용 부품
   * JsonBlockField 가 담당한다.
   */
  paramsRaw: boolean;
  /** raw 모드 params 값(마지막 유효 JSON 객체 — JsonBlockField 가 파싱해 흘린 값). */
  paramsRawValue: Record<string, unknown>;
  /** raw 모드 JSON 문법 유효 여부(false 면 저장 차단 — JsonBlockField onValidityChange). */
  paramsRawValid: boolean;
  /**
   * 기본값(fallback) — **재귀 블럭 편집기**(InitialStateValueEditor)로 임의 JSON 구조(문자/숫자/
   * 예아니오/없음/목록/묶음)를 중첩까지 블럭으로 편집한다("중첩이라도 표현식
   * 편집기처럼 블럭 지원", 레이아웃 편집기 블럭 전면 도입 맥락). 종전 키–값 평면 블럭 + raw JSON
   * 토글(중첩 객체 평탄화 불가 → raw 폴백, [블럭으로] 무반응 결함)을 제거하고 초기 상태 탭과 동일한
   * 순수 블럭 UX 로 통일. 문자열 리프는 DataChipValueInput 주입으로 데이터칩/표현식 1급.
   */
  fallbackEnabled: boolean;
  /** 기본값 JSON 값 트리(스칼라/객체/배열/중첩 — InitialStateValueEditor 가 재귀 편집). */
  fallbackValue: unknown;
  /** 불러오기 조건(if 식 — 최상위 node.if). 빈 문자열/미정 = 무조건 로드. ⑧. */
  if: string;
  // ── 보완 필드 ──
  /** 요청 본문 타입(api). 빈 문자열 = 미지정(기본 application/json). */
  contentType: string;
  /** 재진입 시 재fetch. */
  refetchOnMount: boolean;
  /** 불러오기 성공 후속 액션 배열. */
  onSuccess: ActionItem[];
  /** 불러오기 실패 후속 액션 배열. */
  onError: ActionItem[];
  /** 상태코드별 동작. */
  errorHandling: ErrorHandlingMap;
  /** websocket — 채널/이벤트/채널타입/타겟소스. */
  channel: string;
  event: string;
  channel_type: string;
  target_source: string;
  /** websocket 수신 후속 액션. */
  onReceive: ActionItem[];
}

function entryToDraft(entry: DataSourceEntry, index: number): DraftState {
  const str = (v: unknown, d = ''): string => (typeof v === 'string' ? v : d);
  return {
    index,
    id: str(entry.id),
    type: str(entry.type, 'api'),
    label_key: str(entry.label_key),
    endpoint: str(entry.endpoint),
    method: str(entry.method, 'GET'),
    auth_mode: str(entry.auth_mode, 'none'),
    loading_strategy: str(entry.loading_strategy, 'progressive'),
    auto_fetch: entry.auto_fetch !== false,
    paramRows: paramsToRows(entry.params),
    // 중첩 객체/배열 값이 있는 params 는 칩 에디터로 평탄화하면 손실 → raw(코드) JSON 폴백으로 시작.
    paramsRaw: hasNestedParamValue(entry.params),
    paramsRawValue:
      entry.params && typeof entry.params === 'object' && !Array.isArray(entry.params)
        ? (entry.params as Record<string, unknown>)
        : {},
    paramsRawValid: true,
    // 기본값(fallback) — JSON 값 트리 그대로(재귀 블럭 편집기가 스칼라/객체/배열/중첩 모두 편집).
    fallbackEnabled: entry.fallback !== undefined,
    fallbackValue: entry.fallback,
    if: str(entry.if),
    contentType: str(entry.contentType),
    refetchOnMount: entry.refetchOnMount === true,
    onSuccess: Array.isArray(entry.onSuccess)
      ? (entry.onSuccess as unknown[]).filter((a): a is ActionItem => !!a && typeof a === 'object')
      : [],
    onError: Array.isArray(entry.onError)
      ? (entry.onError as unknown[]).filter((a): a is ActionItem => !!a && typeof a === 'object')
      : [],
    errorHandling:
      entry.errorHandling && typeof entry.errorHandling === 'object' && !Array.isArray(entry.errorHandling)
        ? (entry.errorHandling as ErrorHandlingMap)
        : {},
    channel: str(entry.channel),
    event: str(entry.event),
    channel_type: str(entry.channel_type, 'public'),
    target_source: str(entry.target_source),
    onReceive: Array.isArray(entry.onReceive)
      ? (entry.onReceive as unknown[]).filter((a): a is ActionItem => !!a && typeof a === 'object')
      : [],
  };
}

function emptyDraft(): DraftState {
  return {
    index: -1,
    id: '',
    type: 'api',
    label_key: '',
    endpoint: '',
    method: 'GET',
    auth_mode: 'none',
    loading_strategy: 'progressive',
    auto_fetch: true,
    paramRows: [],
    paramsRaw: false,
    paramsRawValue: {},
    paramsRawValid: true,
    fallbackEnabled: false,
    fallbackValue: undefined,
    if: '',
    contentType: '',
    refetchOnMount: false,
    onSuccess: [],
    onError: [],
    errorHandling: {},
    channel: '',
    event: '',
    channel_type: 'public',
    target_source: '',
    onReceive: [],
  };
}

/**
 * 초안을 data_source 객체로 변환. 기존 엔트리의 미편집 필드(__source 등)는 보존한다.
 * 검증 실패(중복 id, JSON 파싱 오류)면 error 메시지 반환.
 */
function draftToEntry(
  draft: DraftState,
  base: DataSourceEntry | null,
  existingIds: Set<string>,
  t: DataSourcesPanelProps['t'],
): { entry: DataSourceEntry } | { error: string } {
  const id = draft.id.trim();
  if (!id) return { error: t('layout_editor.data_sources.error.id_required') };
  if (!/^[A-Za-z_][\w]*$/.test(id)) {
    return { error: t('layout_editor.data_sources.error.id_invalid') };
  }
  if (existingIds.has(id)) {
    return { error: t('layout_editor.data_sources.error.id_duplicate', { id }) };
  }

  // 기존 엔트리의 보존 필드(예 __source, initLocal 등)에서 출발 — 편집 가능 필드만 덮어쓴다.
  const next: DataSourceEntry = base ? { ...base } : {};
  next.id = id;
  next.type = draft.type;
  if (draft.label_key.trim()) next.label_key = draft.label_key.trim();
  else delete next.label_key;

  // api/websocket 류만 endpoint/method 의미 있음 — static 은 data 로 운용(코드 편집).
  if (draft.type === 'api' || draft.type === 'websocket') {
    if (draft.endpoint.trim()) next.endpoint = draft.endpoint.trim();
    else delete next.endpoint;
    next.method = draft.method;
    next.auth_mode = draft.auth_mode;
    next.loading_strategy = draft.loading_strategy;
  } else {
    delete next.endpoint;
    delete next.method;
  }
  next.auto_fetch = draft.auto_fetch;

  // 요청 파라미터 — raw(코드) 모드면 JsonBlockField 가 이미 파싱·검증한 객체를 쓴다(저장 시점
  // 재파싱 불필요). 문법이 깨진 상태(paramsRawValid=false)면 저장 차단. 아니면 키–값 행을 객체로.
  if (draft.paramsRaw) {
    if (!draft.paramsRawValid) {
      return { error: t('layout_editor.data_sources.error.params_json') };
    }
    const obj = draft.paramsRawValue;
    if (obj && typeof obj === 'object' && Object.keys(obj).length > 0) next.params = obj;
    else delete next.params;
  } else {
    const obj = rowsToParams(draft.paramRows);
    if (Object.keys(obj).length > 0) next.params = obj;
    else delete next.params;
  }

  // 기본값(fallback) — 재귀 블럭 편집기의 JSON 값 트리 그대로. 비활성 = 미지정.
  if (!draft.fallbackEnabled) {
    delete next.fallback;
  } else {
    // 활성인데 값이 undefined 면 빈 객체로 시작(블럭 편집 시작점). 그 외는 트리 값 그대로.
    next.fallback = draft.fallbackValue === undefined ? {} : draft.fallbackValue;
  }

  // 불러오기 조건(if) — 빈 식이면 제거(무조건 로드). 신규/편집 모두 저장(⑧).
  if (draft.if.trim()) next.if = draft.if.trim();
  else delete next.if;

  // ── 보완 필드 ── (빈 값/빈 배열은 키 제거 — 깔끔한 JSON, 무손실 라운드트립)
  if (draft.type === 'api') {
    if (draft.contentType) next.contentType = draft.contentType;
    else delete next.contentType;
  } else {
    delete next.contentType;
  }

  if (draft.refetchOnMount) next.refetchOnMount = true;
  else delete next.refetchOnMount;

  if (draft.onSuccess.length > 0) next.onSuccess = draft.onSuccess;
  else delete next.onSuccess;
  if (draft.onError.length > 0) next.onError = draft.onError;
  else delete next.onError;

  if (Object.keys(draft.errorHandling).length > 0) next.errorHandling = draft.errorHandling;
  else delete next.errorHandling;

  // websocket 전용 필드.
  if (draft.type === 'websocket') {
    if (draft.channel) next.channel = draft.channel; else delete next.channel;
    if (draft.event) next.event = draft.event; else delete next.event;
    next.channel_type = draft.channel_type;
    if (draft.target_source) next.target_source = draft.target_source; else delete next.target_source;
    if (draft.onReceive.length > 0) next.onReceive = draft.onReceive; else delete next.onReceive;
  } else {
    delete next.channel;
    delete next.event;
    delete next.channel_type;
    delete next.target_source;
    delete next.onReceive;
  }

  return { entry: next };
}

const inputStyle: React.CSSProperties = {
  width: '100%',
  padding: '6px 8px',
  fontSize: 13,
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  outline: 'none',
  boxSizing: 'border-box',
  fontFamily: 'inherit',
  color: '#0f172a',
  background: '#ffffff',
};

const labelStyle: React.CSSProperties = {
  display: 'block',
  fontSize: 12,
  fontWeight: 600,
  color: '#475569',
  marginBottom: 4,
};

const fieldWrap: React.CSSProperties = { marginBottom: 12 };

const btnBase: React.CSSProperties = {
  padding: '6px 12px',
  fontSize: 13,
  fontWeight: 500,
  borderRadius: 6,
  border: '1px solid #cbd5e1',
  background: '#ffffff',
  color: '#0f172a',
  cursor: 'pointer',
};

const btnPrimary: React.CSSProperties = {
  ...btnBase,
  background: '#2563eb',
  borderColor: '#2563eb',
  color: '#ffffff',
  fontWeight: 600,
};

const sectionBox: React.CSSProperties = {
  marginTop: 12,
  padding: 10,
  border: '1px solid #e2e8f0',
  borderRadius: 8,
  background: '#f8fafc',
};

const sectionTitle: React.CSSProperties = {
  fontSize: 12,
  fontWeight: 700,
  color: '#475569',
  marginBottom: 8,
};

// 요청 파라미터 블럭 — 빈 상태 안내 + 결과 JSON 프리뷰.
const paramEmptyHint: React.CSSProperties = { margin: '0 0 6px', fontSize: 11, color: '#94a3b8' };
const paramPreviewWrap: React.CSSProperties = { marginTop: 8 };
// [미리보기 ▾/▴] 토글 — 가벼운 텍스트 링크(테두리·배경 없음). 보조 "펼쳐보기"라 박스 버튼은 과함
// 작은 회색 글자 + 화살표만으로 부담 없이 일관.
const previewToggleBtn: React.CSSProperties = { alignSelf: 'flex-start', padding: '2px 0', fontSize: 11, border: 'none', background: 'none', color: '#64748b', cursor: 'pointer' };
const paramPreviewCode: React.CSSProperties = { margin: 0, padding: 8, background: '#0f172a', color: '#e2e8f0', borderRadius: 6, fontSize: 11, lineHeight: 1.5, overflow: 'auto', fontFamily: 'ui-monospace, monospace' };

/**
 * 데이터소스 errorHandling 한 코드 행의 동작 편집.
 *
 * 동작 종류 select + 동작별 입력(공용 ParamFieldList). 종전엔 핸들러별 input/select 를 자작했는데
 * (setState/openModal 분기가 아예 없어 입력 불가, 데이터칩 부재, 코드 미리보기 부재), 동작 종류
 * select 는 두되 그 아래 입력칸은 [에러 처리] 탭·[화면 동작]과 동일한 공용 부품(`ParamFieldList` +
 * `DataChipValueInput`)으로 일원화한다(패리티). 모든 자유값 칸 에러 컨텍스트 데이터칩, setState
 * 상태 키–값/모달 후보 select/표시 위치 select 정상 작동 + 항목별 코드 미리보기(`</>`).
 */
function DsErrorActionEditor({
  code,
  action,
  recipes,
  pools,
  t,
  onPatch,
}: {
  code: string;
  action: Record<string, unknown> | Record<string, unknown>[] | undefined;
  recipes?: Record<string, ActionRecipeSpec | string>;
  pools: ActionParamCandidatePools;
  t: DataSourcesPanelProps['t'];
  onPatch: (next: Record<string, unknown> | Record<string, unknown>[]) => void;
}): React.ReactElement {
  const [codeOpen, setCodeOpen] = React.useState(false);
  const normalized = React.useMemo(() => normalizeActionRecipes(recipes), [recipes]);

  const single = Array.isArray(action) ? (action[0] as Record<string, unknown> | undefined) : action;
  const handler = typeof single?.handler === 'string' ? single.handler : '';
  const recipe = normalized.find((r) => r.id === handler) ?? null;
  const card = single ? resolveActionCard(single, normalized) : null;
  const values = card?.kind === 'preset' ? card.values : {};
  const isSeq = handler === 'sequence' || handler === 'parallel';
  const HANDLER_OPTIONS = ['', 'showErrorPage', 'navigate', 'openModal', 'toast', 'setState', 'sequence', 'parallel'];

  const setHandler = (h: string): void => {
    if (h === '') { onPatch({}); return; }
    const r = normalized.find((x) => x.id === h);
    onPatch(r ? buildAction(r, {}) : { handler: h });
  };

  const seqActions = isSeq && single
    ? (Array.isArray((single.params as Record<string, unknown> | undefined)?.actions)
        ? ((single.params as Record<string, unknown>).actions as Array<Record<string, unknown>>)
        : [])
    : [];

  return (
    <div data-testid={`g7le-ds-errorhandling-${code}`} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        <select
          data-testid={`g7le-ds-eh-handler-${code}`}
          style={{ ...inputStyle, flex: 1 }}
          value={handler}
          onChange={(e) => setHandler(e.target.value)}
        >
          {HANDLER_OPTIONS.map((h) => (
            <option key={h} value={h}>
              {h === '' ? t('layout_editor.error.handler_none') : t(`layout_editor.error.handler_${h}`)}
            </option>
          ))}
        </select>
        {handler !== '' ? (
          <button
            type="button"
            data-testid={`g7le-ds-eh-code-${code}`}
            onClick={() => setCodeOpen((v) => !v)}
            aria-label={t('layout_editor.action_list.view_code')}
            title={t('layout_editor.action_list.view_code')}
            style={codeOpen ? dsEhCodeBtnActive : dsEhCodeBtn}
          >
            {'</>'}
          </button>
        ) : null}
      </div>

      {isSeq ? (
        <div data-testid={`g7le-ds-eh-actions-${code}`}>
          <ActionListBuilder
            actions={seqActions}
            onChange={(next) => onPatch({ handler, params: { actions: next } })}
            t={t}
            recipes={recipes}
            candidatePools={pools}
            chipContext="error"
            testIdPrefix={`g7le-ds-eh-seq-${code}`}
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
          testIdPrefix={`g7le-ds-eh-action-${code}-edit`}
        />
      ) : null}

      {codeOpen && single ? (
        <pre data-testid={`g7le-ds-eh-code-view-${code}`} style={dsEhCodeView}>
          {JSON.stringify(action, null, 2)}
        </pre>
      ) : null}
    </div>
  );
}

const dsEhCodeBtn: React.CSSProperties = { flex: '0 0 auto', width: 26, height: 26, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer', fontFamily: 'ui-monospace, monospace' };
const dsEhCodeBtnActive: React.CSSProperties = { ...dsEhCodeBtn, background: '#0f172a', borderColor: '#0f172a', color: '#e2e8f0' };
const dsEhCodeView: React.CSSProperties = { margin: '4px 0 0', padding: 8, background: '#0f172a', color: '#e2e8f0', borderRadius: 6, fontSize: 12, overflow: 'auto', fontFamily: 'ui-monospace, monospace' };

export function DataSourcesPanel({
  raw,
  onChange,
  t,
  resolveLabel,
  onClose,
  actionRecipes,
  errorRecipes,
  conditionSpec,
  bindingCandidates,
  pageCandidates,
  dataSourceCandidates,
  stateKeyCandidates,
}: DataSourcesPanelProps): React.ReactElement {
  // label_key 해석기 — 전달되면 편집 대상 템플릿 사전 우선, 미전달 시 chrome t 폴백.
  const resolve = resolveLabel ?? t;
  // 모달은 단일 편집 세션 — raw 를 마운트 시 1회 분해해 작업 사본(own)을 로컬 상태로
  // 보유한다. 이후 모든 변형은 로컬 상태 + onChange 통지로 진행(모달이 Provider 밖에
  // 마운트돼 live raw 재구독 불가하므로 작업 사본 SSoT). inherited 는 불변(읽기전용).
  const initial = useMemo(() => splitSources(raw), [raw]);
  const inherited = initial.inherited;
  const [own, setOwn] = useState<DataSourceEntry[]>(initial.own);
  const [draft, setDraft] = useState<DraftState | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  // 요청 파라미터·기본값 JSON 미리보기 펼침 상태 — [미리보기 ▾/▴] 토글(표현식
  // 편집기 [원본 식 보기] 토글과 일관). 기본 접힘(상시 표시로 폼이 길어지던 것 → 필요 시 펼침).
  const [showParamsPreview, setShowParamsPreview] = useState(false);
  const [showFallbackPreview, setShowFallbackPreview] = useState(false);
  // 상속(읽기전용) 소스의 펼침 상태 — id 별 토글. 펼치면 endpoint/method/type 등 정보 노출.
  const [expandedInherited, setExpandedInherited] = useState<Set<string>>(() => new Set());
  const toggleInherited = useCallback((id: string): void => {
    setExpandedInherited((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  // 동작(onSuccess/onError/onReceive) params 위젯 후보 풀 — 공용 ActionListBuilder 기본 편집 폼용.
  const actionPools = useMemo(
    () => ({ pageCandidates, dataSourceCandidates, stateKeyCandidates, bindingCandidates }),
    [pageCandidates, dataSourceCandidates, stateKeyCandidates, bindingCandidates],
  );

  // 자체 소스 변경 — 로컬 상태 갱신 + hook 통지(merged 상속 ∪ 자체 / own 분리).
  const commit = useCallback(
    (nextOwn: DataSourceEntry[]): void => {
      setOwn(nextOwn);
      onChange([...inherited, ...nextOwn], nextOwn);
    },
    [inherited, onChange],
  );

  const startAdd = useCallback(() => {
    setFormError(null);
    setDraft(emptyDraft());
  }, []);

  const startEdit = useCallback((entry: DataSourceEntry, index: number) => {
    setFormError(null);
    setDraft(entryToDraft(entry, index));
  }, []);

  const remove = useCallback(
    (index: number) => {
      const next = own.filter((_, i) => i !== index);
      commit(next);
    },
    [own, commit],
  );

  const cancelForm = useCallback(() => {
    setDraft(null);
    setFormError(null);
  }, []);

  const submitForm = useCallback(() => {
    if (!draft) return;
    // 중복 검사 집합 — 자기 자신(편집 중 index)과 상속 id 는 제외하지 않는다(상속과의
    // 중복은 백엔드 ValidDataSourceMerge 가 거부하므로 여기서도 차단).
    const existing = new Set<string>();
    own.forEach((d, i) => {
      if (i !== draft.index && typeof d.id === 'string') existing.add(d.id);
    });
    inherited.forEach((d) => {
      if (typeof d.id === 'string') existing.add(d.id);
    });
    const base = draft.index >= 0 ? own[draft.index] ?? null : null;
    const result = draftToEntry(draft, base, existing, t);
    if ('error' in result) {
      setFormError(result.error);
      return;
    }
    const next =
      draft.index >= 0
        ? own.map((d, i) => (i === draft.index ? result.entry : d))
        : [...own, result.entry];
    commit(next);
    setDraft(null);
    setFormError(null);
  }, [draft, own, inherited, commit, t]);

  const isApiLike = draft?.type === 'api' || draft?.type === 'websocket';

  return (
    <div
      className="g7le-data-sources"
      data-testid="g7le-data-sources-panel"
      style={{
        // 모달(.g7le-modal)은 flex column + maxHeight + overflow:hidden 로 콘텐츠를 클리핑한다.
        // 본 패널을 헤더(고정)/본문(스크롤)/푸터(고정) 3존 flex column 으로 구성해, 폼이
        // 길어도 상단 타이틀·설명과 하단 취소/저장 버튼은 고정되고 가운데 본문만 스크롤한다
        // flex:1 1 auto + minHeight:0 로 모달
        // flex 높이에 맞춰 줄어든다.
        flex: '1 1 auto',
        minHeight: 0,
        display: 'flex',
        flexDirection: 'column',
        fontSize: 13,
        color: '#0f172a',
        boxSizing: 'border-box',
      }}
    >
      {/* ── 헤더 (상단 고정) ── 타이틀 + 닫기 + 설명 */}
      <div
        className="g7le-data-sources__header"
        style={{ flex: '0 0 auto', padding: '16px 16px 12px', borderBottom: '1px solid #e2e8f0' }}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            marginBottom: 8,
          }}
        >
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>
            {t('layout_editor.data_sources.title')}
          </h2>
          <button
            type="button"
            data-testid="g7le-data-sources-close"
            onClick={onClose}
            aria-label={t('layout_editor.data_sources.close')}
            style={{ ...btnBase, padding: '4px 10px' }}
          >
            ✕
          </button>
        </div>
        <p style={{ margin: 0, fontSize: 12, color: '#64748b', lineHeight: 1.5 }}>
          {t('layout_editor.data_sources.description')}
        </p>
      </div>

      {/* ── 본문 (스크롤) ── 폼 필드 또는 추가 버튼 + 소스 목록 */}
      <div
        className="g7le-data-sources__body"
        style={{ flex: '1 1 auto', minHeight: 0, overflowY: 'auto', padding: 16 }}
      >
      {/* 폼 (추가/편집) — 필드만(액션은 푸터 고정) */}
      {draft && (
        <div
          className="g7le-data-sources__form"
          data-testid="g7le-data-sources-form"
        >
          <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 12 }}>
            {draft.index >= 0
              ? t('layout_editor.data_sources.form.edit_title')
              : t('layout_editor.data_sources.form.add_title')}
          </div>

          <div style={fieldWrap}>
            <label style={labelStyle} htmlFor="g7le-ds-id">
              {t('layout_editor.data_sources.field.id')}
            </label>
            <input
              id="g7le-ds-id"
              data-testid="g7le-data-sources-field-id"
              style={inputStyle}
              value={draft.id}
              onChange={(e) => setDraft({ ...draft, id: e.target.value })}
              placeholder="products"
            />
          </div>

          <div style={fieldWrap}>
            <label style={labelStyle} htmlFor="g7le-ds-label">
              {t('layout_editor.data_sources.field.label_key')}
            </label>
            {/* 부록7 7-a — raw `$t:` 키 직접 입력을 평문 입력 + `$t:custom.*` 자동 생성/언어별
                편집으로 승격(인라인 편집과 동일 모델). 입력한 평문이 키 생성 후 토큰으로 label_key
                에 기록되고, 🌐 로 ko/en/ja 일괄 편집한다. 기존 raw 키는 평문 동격(시작값=해석값). */}
            <I18nTextField
              value={draft.label_key}
              onChange={(v) => setDraft({ ...draft, label_key: v ?? '' })}
              t={t}
              placeholder={t('layout_editor.data_sources.field.label_key_placeholder')}
              testidPrefix="g7le-data-sources-field-label"
              // label_key 도 데이터 칩 + 표현식 분해
              // 트리(접힌 미리보기 + [수정]). 평문/단일 다국어키는 종전 키화 경로 그대로(회귀 0).
              candidates={bindingCandidates}
              enableExpressionTree
              expressionTreeCollapsible
            />
            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 4 }}>
              {t('layout_editor.data_sources.field.label_key_hint')}
            </div>
          </div>

          <div style={fieldWrap}>
            <label style={labelStyle} htmlFor="g7le-ds-type">
              {t('layout_editor.data_sources.field.type')}
            </label>
            <select
              id="g7le-ds-type"
              data-testid="g7le-data-sources-field-type"
              style={inputStyle}
              value={draft.type}
              onChange={(e) => setDraft({ ...draft, type: e.target.value })}
            >
              {DS_TYPES.map((ty) => (
                <option key={ty} value={ty}>
                  {t(`layout_editor.data_sources.ds_type.${ty}`)}
                </option>
              ))}
            </select>
          </div>

          {isApiLike && (
            <>
              <div style={fieldWrap}>
                <label style={labelStyle}>
                  {t('layout_editor.data_sources.field.endpoint')}
                </label>
                {/* 엔드포인트는 URL 경로 + 표현식(`{{route.id}}`) 혼합 — 데이터 칩 입력(검색 피커
 포함)으로 통일. 표현식 닿는 칸은 전부 평문 input 금지. */}
                <div data-testid="g7le-data-sources-field-endpoint">
                  <DataChipValueInput
                    value={draft.endpoint}
                    onChange={(v) => setDraft({ ...draft, endpoint: v })}
                    t={t}
                    candidates={bindingCandidates}
                    placeholder="/api/shop/products/{{route.id}}"
                    testidPrefix="g7le-ds-endpoint"
                  />
                </div>
              </div>

              <div style={{ display: 'flex', gap: 12 }}>
                <div style={{ ...fieldWrap, flex: 1 }}>
                  <label style={labelStyle} htmlFor="g7le-ds-method">
                    {t('layout_editor.data_sources.field.method')}
                  </label>
                  <select
                    id="g7le-ds-method"
                    data-testid="g7le-data-sources-field-method"
                    style={inputStyle}
                    value={draft.method}
                    onChange={(e) => setDraft({ ...draft, method: e.target.value })}
                  >
                    {HTTP_METHODS.map((m) => (
                      <option key={m} value={m}>
                        {m}
                      </option>
                    ))}
                  </select>
                </div>
                <div style={{ ...fieldWrap, flex: 1 }}>
                  <label style={labelStyle} htmlFor="g7le-ds-auth">
                    {t('layout_editor.data_sources.field.auth_mode')}
                  </label>
                  <select
                    id="g7le-ds-auth"
                    data-testid="g7le-data-sources-field-auth"
                    style={inputStyle}
                    value={draft.auth_mode}
                    onChange={(e) => setDraft({ ...draft, auth_mode: e.target.value })}
                  >
                    {AUTH_MODES.map((a) => (
                      <option key={a} value={a}>
                        {t(`layout_editor.data_sources.auth_mode.${a}`)}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div style={fieldWrap}>
                <label style={labelStyle} htmlFor="g7le-ds-loading">
                  {t('layout_editor.data_sources.field.loading_strategy')}
                </label>
                <select
                  id="g7le-ds-loading"
                  data-testid="g7le-data-sources-field-loading"
                  style={inputStyle}
                  value={draft.loading_strategy}
                  onChange={(e) => setDraft({ ...draft, loading_strategy: e.target.value })}
                >
                  {LOADING_STRATEGIES.map((s) => (
                    <option key={s} value={s}>
                      {t(`layout_editor.data_sources.loading_strategy.${s}`)}
                    </option>
                  ))}
                </select>
              </div>

              {/* contentType */}
              {draft.type === 'api' && (
                <div style={fieldWrap}>
                  <label style={labelStyle} htmlFor="g7le-ds-contenttype">
                    {t('layout_editor.data_sources.field.content_type')}
                  </label>
                  <select
                    id="g7le-ds-contenttype"
                    data-testid="g7le-ds-contenttype"
                    style={inputStyle}
                    value={draft.contentType || 'application/json'}
                    onChange={(e) => setDraft({ ...draft, contentType: e.target.value })}
                  >
                    {CONTENT_TYPES.map((ct) => (
                      <option key={ct} value={ct}>{ct}</option>
                    ))}
                  </select>
                </div>
              )}
            </>
          )}

          <div style={fieldWrap}>
            <label style={{ ...labelStyle, display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
              <input
                type="checkbox"
                data-testid="g7le-data-sources-field-autofetch"
                checked={draft.auto_fetch}
                onChange={(e) => setDraft({ ...draft, auto_fetch: e.target.checked })}
              />
              {t('layout_editor.data_sources.field.auto_fetch')}
            </label>
          </div>

          {/* refetchOnMount  */}
          <div style={fieldWrap}>
            <label style={{ ...labelStyle, display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
              <input
                type="checkbox"
                data-testid="g7le-ds-refetch"
                checked={draft.refetchOnMount}
                onChange={(e) => setDraft({ ...draft, refetchOnMount: e.target.checked })}
              />
              {t('layout_editor.data_sources.field.refetch_on_mount')}
            </label>
          </div>

          {/* 요청 파라미터 — 키–값 블럭(값은 표현식 칩) + 중첩값 raw JSON 폴백 토글 (①) */}
          <div style={fieldWrap}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <label style={labelStyle} htmlFor="g7le-ds-params">
                {t('layout_editor.data_sources.field.params')}
              </label>
              <button
                type="button"
                data-testid="g7le-ds-params-mode-toggle"
                onClick={() => {
                  // 블럭 → 코드 전환 시 현재 행을 JSON 객체로 시드. 코드 → 블럭 전환 시 그 객체를
                  // 행으로 복원(중첩값이면 평탄화 불가 → 코드 유지, 무손실). 깨진 코드면 블럭 전환 차단.
                  if (!draft.paramsRaw) {
                    const obj = rowsToParams(draft.paramRows);
                    setDraft({ ...draft, paramsRaw: true, paramsRawValue: obj, paramsRawValid: true });
                  } else if (draft.paramsRawValid) {
                    const obj = draft.paramsRawValue;
                    if (obj && typeof obj === 'object' && !Array.isArray(obj) && !hasNestedParamValue(obj)) {
                      setDraft({ ...draft, paramsRaw: false, paramRows: paramsToRows(obj) });
                    }
                  }
                }}
                style={{ ...btnBase, padding: '2px 8px', fontSize: 11 }}
              >
                {draft.paramsRaw
                  ? t('layout_editor.data_sources.field.params_mode_block')
                  : t('layout_editor.data_sources.field.params_mode_code')}
              </button>
            </div>
            {draft.paramsRaw ? (
              <JsonBlockField
                id="g7le-ds-params"
                value={draft.paramsRawValue}
                shape="object"
                emptyValue={{}}
                minHeight={64}
                testidPrefix="g7le-data-sources-field-params"
                placeholder={'{\n  "page": "{{query.page ?? 1}}"\n}'}
                shapeErrorKey="layout_editor.data_sources.error.params_object"
                invalidErrorKey="layout_editor.data_sources.error.params_json"
                t={t}
                onChange={(next) => setDraft((d) => (d ? { ...d, paramsRawValue: (next ?? {}) as Record<string, unknown>, paramsRawValid: true } : d))}
                onValidityChange={(valid) => setDraft((d) => (d ? { ...d, paramsRawValid: valid } : d))}
              />
            ) : (
              <div data-testid="g7le-data-sources-field-params">
                {/* 빈 상태 안내 — 행 0개면 "어떤 값을 보낼지" 설명(휑한 추가 버튼만 노출 방지). */}
                {draft.paramRows.length === 0 && (
                  <p data-testid="g7le-ds-params-empty" style={paramEmptyHint}>
                    {t('layout_editor.data_sources.field.params_empty')}
                  </p>
                )}
                <KeyValueChipEditor
                  value={draft.paramRows.map((r) => ({ key: r.key, value: r.value }))}
                  onChange={(next) =>
                    setDraft({
                      ...draft,
                      paramRows: next.map((it) => ({ key: it.key ?? '', value: it.value ?? '' })),
                    })
                  }
                  keyField="key"
                  valueField="value"
                  t={t}
                  candidates={bindingCandidates}
                  testidPrefix="g7le-ds-params-kv"
                  keyPlaceholder={t('layout_editor.data_sources.field.param_key_placeholder')}
                  valuePlaceholder="{{query.page ?? 1}}"
                  addLabel={t('layout_editor.data_sources.field.param_add')}
                />
                {/* 결과 JSON 프리뷰 — 입력한 키–값이 실제로 어떤 요청 본문이 되는지 [미리보기 ▾/▴]
 토글로 펼쳐 본다(표현식 편집기 [원본 식 보기] 토글과 일관, 상시 표시
                    제거). 입력값이 있을 때만 토글 노출. */}
                {(() => {
                  const obj = rowsToParams(draft.paramRows);
                  if (Object.keys(obj).length === 0) return null;
                  return (
                    <div style={paramPreviewWrap}>
                      <button
                        type="button"
                        data-testid="g7le-ds-params-preview-toggle"
                        aria-expanded={showParamsPreview}
                        onClick={() => setShowParamsPreview((v) => !v)}
                        style={previewToggleBtn}
                      >
                        {showParamsPreview ? '▴' : '▾'} {t('layout_editor.data_sources.field.params_preview')}
                      </button>
                      {showParamsPreview && (
                        <pre data-testid="g7le-ds-params-preview" style={{ ...paramPreviewCode, marginTop: 4 }}>
                          {JSON.stringify(obj, null, 2)}
                        </pre>
                      )}
                    </div>
                  );
                })()}
              </div>
            )}
            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 4 }}>
              {t('layout_editor.data_sources.field.params_hint')}
            </div>
          </div>

          {/* 기본값(fallback) — 재귀 블럭 편집기(InitialStateValueEditor). 임의 JSON 구조(스칼라/객체/
 배열/중첩)를 초기 상태 탭과 동일한 블럭 UX 로 편집한다(중첩도 블럭 지원,
              레이아웃 편집기 블럭 전면 도입). 문자열 리프는 DataChipValueInput 주입으로 표현식·데이터칩
              1급. 미활성이면 추가 버튼만. raw JSON 모드/평면 키–값 블럭은 제거(중복·평탄화 결함 해소). */}
          <div style={fieldWrap}>
            <label style={labelStyle}>{t('layout_editor.data_sources.field.fallback')}</label>
            {!draft.fallbackEnabled ? (
              <button
                type="button"
                data-testid="g7le-data-sources-field-fallback-add"
                onClick={() => setDraft({ ...draft, fallbackEnabled: true, fallbackValue: {} })}
                style={{ ...btnBase, padding: '4px 10px', display: 'block' }}
              >
                ＋ {t('layout_editor.data_sources.field.fallback_add')}
              </button>
            ) : (
              <div data-testid="g7le-data-sources-field-fallback" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                <InitialStateValueEditor
                  value={draft.fallbackValue === undefined ? {} : draft.fallbackValue}
                  onChange={(v) => setDraft({ ...draft, fallbackValue: v })}
                  t={t}
                  path="fallback"
                  scope="ds-fallback"
                  // 문자열 리프 = 데이터칩/표현식 입력(키화 없음). 초기 상태 탭은 평문이지만 fallback 은
                  // `{{...}}`·`$..._settings:` 표현식이 1급이라 DataChipValueInput 주입.
                  renderStringLeaf={(leaf) => (
                    <DataChipValueInput
                      value={leaf.value}
                      onChange={leaf.onChange}
                      t={t}
                      candidates={bindingCandidates}
                      testidPrefix={leaf.testidPrefix}
                    />
                  )}
                />
                {/* 결과 JSON 프리뷰 — [미리보기 ▾/▴] 토글(요청 파라미터와 일관). */}
                {(() => {
                  const obj = draft.fallbackValue === undefined ? {} : draft.fallbackValue;
                  return (
                    <div style={paramPreviewWrap}>
                      <button
                        type="button"
                        data-testid="g7le-ds-fallback-preview-toggle"
                        aria-expanded={showFallbackPreview}
                        onClick={() => setShowFallbackPreview((v) => !v)}
                        style={previewToggleBtn}
                      >
                        {showFallbackPreview ? '▴' : '▾'} {t('layout_editor.data_sources.field.fallback_preview')}
                      </button>
                      {showFallbackPreview && (
                        <pre data-testid="g7le-ds-fallback-preview" style={{ ...paramPreviewCode, marginTop: 4 }}>
                          {JSON.stringify(obj, null, 2)}
                        </pre>
                      )}
                    </div>
                  );
                })()}
                <button
                  type="button"
                  data-testid="g7le-data-sources-field-fallback-clear"
                  onClick={() => setDraft({ ...draft, fallbackEnabled: false, fallbackValue: undefined })}
                  style={{ ...btnBase, padding: '2px 8px', fontSize: 11, alignSelf: 'flex-start', color: '#dc2626', borderColor: '#fecaca' }}
                >
                  {t('layout_editor.data_sources.field.fallback_clear')}
                </button>
              </div>
            )}
          </div>

          {/* websocket 전용  */}
          {draft.type === 'websocket' && (
            <div data-testid="g7le-ds-ws-section" style={sectionBox}>
              <div style={sectionTitle}>{t('layout_editor.data_sources.section.websocket')}</div>
              <div style={fieldWrap}>
                <label style={labelStyle}>{t('layout_editor.data_sources.field.channel')}</label>
                <input data-testid="g7le-ds-ws-channel" style={inputStyle} value={draft.channel} onChange={(e) => setDraft({ ...draft, channel: e.target.value })} placeholder="core.admin.dashboard" />
              </div>
              <div style={fieldWrap}>
                <label style={labelStyle}>{t('layout_editor.data_sources.field.event')}</label>
                <input data-testid="g7le-ds-ws-event" style={inputStyle} value={draft.event} onChange={(e) => setDraft({ ...draft, event: e.target.value })} placeholder="dashboard.stats.updated" />
              </div>
              <div style={fieldWrap}>
                <label style={labelStyle}>{t('layout_editor.data_sources.field.channel_type')}</label>
                <select data-testid="g7le-ds-ws-channeltype" style={inputStyle} value={draft.channel_type} onChange={(e) => setDraft({ ...draft, channel_type: e.target.value })}>
                  {CHANNEL_TYPES.map((ct) => <option key={ct} value={ct}>{ct}</option>)}
                </select>
              </div>
              <div style={fieldWrap}>
                <label style={labelStyle}>{t('layout_editor.data_sources.field.target_source')}</label>
                <input data-testid="g7le-ds-ws-target" style={inputStyle} value={draft.target_source} onChange={(e) => setDraft({ ...draft, target_source: e.target.value })} list="g7le-ds-ws-target-list" />
                <datalist id="g7le-ds-ws-target-list">
                  {own.filter((d) => d.id !== draft.id).map((d) => (typeof d.id === 'string' ? <option key={d.id} value={d.id} /> : null))}
                </datalist>
              </div>
              <div style={fieldWrap}>
                <label style={labelStyle}>{t('layout_editor.data_sources.field.on_receive')}</label>
                <div data-testid="g7le-ds-ws-onreceive">
                  <ActionListBuilder actions={draft.onReceive} onChange={(next) => setDraft({ ...draft, onReceive: next })} t={t} recipes={actionRecipes} candidatePools={actionPools} chipContext="payload" actionChipCandidates={conditionSpec?.actionChipCandidates ?? null} testIdPrefix="g7le-ds-onreceive-list" />
                </div>
              </div>
            </div>
          )}

          {/* onSuccess / onError 다중 액션 빌더  */}
          <div style={sectionBox}>
            <div style={sectionTitle}>{t('layout_editor.data_sources.section.on_success')}</div>
            <div data-testid="g7le-ds-onsuccess">
              <ActionListBuilder actions={draft.onSuccess} onChange={(next) => setDraft({ ...draft, onSuccess: next })} t={t} recipes={actionRecipes} candidatePools={actionPools} chipContext="response" actionChipCandidates={conditionSpec?.actionChipCandidates ?? null} testIdPrefix="g7le-ds-onsuccess-list" />
            </div>
          </div>
          <div style={sectionBox}>
            <div style={sectionTitle}>{t('layout_editor.data_sources.section.on_error')}</div>
            <div data-testid="g7le-ds-onerror">
              <ActionListBuilder actions={draft.onError} onChange={(next) => setDraft({ ...draft, onError: next })} t={t} recipes={actionRecipes} candidatePools={actionPools} chipContext="error" actionChipCandidates={conditionSpec?.actionChipCandidates ?? null} testIdPrefix="g7le-ds-onerror-list" />
            </div>
          </div>

          {/* errorHandling 코드별 행  */}
          <div style={sectionBox}>
            <div style={sectionTitle}>{t('layout_editor.data_sources.section.error_handling')}</div>
            <div data-testid="g7le-ds-errorhandling">
              <ErrorHandlingRows
                value={draft.errorHandling}
                onChange={(next) => setDraft({ ...draft, errorHandling: next })}
                t={t}
                mode="local"
                testIdPrefix="g7le-ds-eh"
                renderActionList={(code, action, onPatch) => (
                  <DsErrorActionEditor code={code} action={action} recipes={errorRecipes} pools={actionPools} t={t} onPatch={onPatch} />
                )}
              />
              <button
                type="button"
                data-testid="g7le-ds-errorhandling-add"
                onClick={() => {
                  const used = Object.keys(draft.errorHandling);
                  const candidate = ['403', '404', '500', '422', 'default'].find((c) => !used.includes(c)) ?? 'default';
                  setDraft({ ...draft, errorHandling: { ...draft.errorHandling, [candidate]: { handler: 'toast' } } });
                }}
                style={{ ...btnBase, padding: '4px 10px', marginTop: 6 }}
              >
                ＋ {t('layout_editor.data_sources.add_error_code')}
              </button>
            </div>
          </div>

          {/* 조건부 로딩 — 표시조건 빌더 재사용 + 어댑터  */}
          <div style={sectionBox}>
            <div style={sectionTitle}>{t('layout_editor.data_sources.section.load_condition')}</div>
            <div data-testid="g7le-ds-loadcondition">
              <ConditionBuilder
                // 불러오기 조건은 draft.if 가 정본 — 신규/편집 모두 폼 저장 시 함께 영속(⑧).
                // 종전엔 own[draft.index].if 를 직접 읽고 draft.index<0 이면 return 해 신규 추가 시
                // 조건을 저장하지 못했다. draft.if 로 일원화해 결함 해소.
                node={dataSourceToConditionNode({ if: draft.if || undefined }) as EditorNode}
                spec={conditionSpec ?? null}
                t={t}
                dataSourceCandidates={dataSourceCandidates}
                stateKeyCandidates={stateKeyCandidates}
                onPatchNode={(patched) => {
                  // 어댑터로 if 만 추출해 draft.if 에 반영(submitForm 에서 entry 로 영속).
                  const applied = applyConditionNodeToDataSource(patched as never, {} as never) as { if?: unknown };
                  setDraft({ ...draft, if: typeof applied.if === 'string' ? applied.if : '' });
                }}
              />
            </div>
          </div>

        </div>
      )}

      {/* 추가 버튼 (폼 미열림 시) */}
      {!draft && (
        <button
          type="button"
          data-testid="g7le-data-sources-add"
          onClick={startAdd}
          style={{ ...btnPrimary, marginBottom: 16 }}
        >
          ＋ {t('layout_editor.data_sources.add')}
        </button>
      )}

      {/* 자체 소스 목록 */}
      <div
        className="g7le-data-sources__list"
        data-testid="g7le-data-sources-list"
        style={{ display: 'flex', flexDirection: 'column', gap: 8 }}
      >
        {own.length === 0 && !draft && (
          <div
            data-testid="g7le-data-sources-empty"
            style={{ fontSize: 12, color: '#94a3b8', padding: '12px 0', textAlign: 'center' }}
          >
            {t('layout_editor.data_sources.empty')}
          </div>
        )}
        {own.map((entry, index) => {
          const id = typeof entry.id === 'string' ? entry.id : `#${index}`;
          const endpoint = typeof entry.endpoint === 'string' ? entry.endpoint : '';
          const labelKey = typeof entry.label_key === 'string' ? entry.label_key : '';
          const friendly = friendlyDataSourceName(entry, resolve);
          return (
            <div
              key={`${id}-${index}`}
              data-testid="g7le-data-sources-item"
              data-ds-id={id}
              style={{
                border: '1px solid #e2e8f0',
                borderRadius: 8,
                padding: '10px 12px',
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                background: '#ffffff',
              }}
            >
              <div style={{ flex: 1, minWidth: 0 }}>
                {/* 친화 명칭(label_key 해석) 우선 노출, id 는 보조. 친화 명칭 없으면 id 가 제목. */}
                <div
                  data-testid="g7le-data-sources-item-title"
                  style={{ fontWeight: 600, fontSize: 13, display: 'flex', alignItems: 'baseline', gap: 6, flexWrap: 'wrap' }}
                >
                  <span>{friendly ?? id}</span>
                  {friendly && (
                    <span data-testid="g7le-data-sources-item-id" style={{ fontSize: 11, fontWeight: 400, color: '#94a3b8' }}>
                      {id}
                    </span>
                  )}
                  {(() => {
                    const badge = dataSourceExtensionBadge(entry, t);
                    return badge ? (
                      <span
                        data-testid="g7le-data-sources-ext-badge"
                        style={{
                          fontSize: 10,
                          fontWeight: 600,
                          color: '#7c3aed',
                          background: '#f5f3ff',
                          border: '1px solid #ddd6fe',
                          borderRadius: 4,
                          padding: '1px 6px',
                        }}
                      >
                        {badge}
                      </span>
                    ) : null;
                  })()}
                </div>
                {endpoint && (
                  <div
                    style={{
                      fontSize: 11,
                      color: '#94a3b8',
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    {endpoint}
                  </div>
                )}
                {!labelKey && (
                  <div data-testid="g7le-data-sources-nolabel" style={{ fontSize: 11, color: '#f59e0b' }}>
                    {t('layout_editor.data_sources.no_label')}
                  </div>
                )}
              </div>
              <button
                type="button"
                data-testid="g7le-data-sources-edit"
                onClick={() => startEdit(entry, index)}
                style={{ ...btnBase, padding: '4px 10px' }}
              >
                {t('layout_editor.data_sources.edit')}
              </button>
              <button
                type="button"
                data-testid="g7le-data-sources-remove"
                onClick={() => remove(index)}
                style={{ ...btnBase, padding: '4px 10px', color: '#dc2626', borderColor: '#fecaca' }}
              >
                {t('layout_editor.data_sources.remove')}
              </button>
            </div>
          );
        })}
      </div>

      {/* 상속 소스 (읽기전용) — 토글로 endpoint/method/type 등 정보 펼침 */}
      {inherited.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <div
            style={{
              fontSize: 12,
              fontWeight: 600,
              color: '#64748b',
              textTransform: 'uppercase',
              letterSpacing: 0.4,
              marginBottom: 8,
            }}
          >
            {t('layout_editor.data_sources.inherited_title')}
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {inherited.map((entry, index) => {
              const id = typeof entry.id === 'string' ? entry.id : `#${index}`;
              const expanded = expandedInherited.has(id);
              // 표시 가능한 읽기전용 정보 — 편집기 메타(__source) 제외한 주요 필드.
              const infoRows: Array<[string, string]> = [];
              const pushIf = (key: string, label: string): void => {
                const v = (entry as Record<string, unknown>)[key];
                if (v === undefined || v === null) return;
                infoRows.push([label, typeof v === 'object' ? JSON.stringify(v) : String(v)]);
              };
              pushIf('type', t('layout_editor.data_sources.field.type'));
              pushIf('endpoint', t('layout_editor.data_sources.field.endpoint'));
              pushIf('method', t('layout_editor.data_sources.field.method'));
              pushIf('auth_mode', t('layout_editor.data_sources.field.auth_mode'));
              pushIf('loading_strategy', t('layout_editor.data_sources.field.loading_strategy'));
              pushIf('auto_fetch', t('layout_editor.data_sources.field.auto_fetch'));
              pushIf('params', t('layout_editor.data_sources.field.params'));
              return (
                <div
                  key={`inh-${id}-${index}`}
                  data-testid="g7le-data-sources-inherited-item"
                  data-ds-id={id}
                  data-expanded={expanded ? 'true' : 'false'}
                  style={{
                    border: '1px dashed #e2e8f0',
                    borderRadius: 6,
                    fontSize: 12,
                    color: '#64748b',
                    background: '#f8fafc',
                    overflow: 'hidden',
                  }}
                >
                  <button
                    type="button"
                    data-testid="g7le-data-sources-inherited-toggle"
                    onClick={() => toggleInherited(id)}
                    aria-expanded={expanded}
                    style={{
                      width: '100%',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                      padding: '8px 12px',
                      background: 'transparent',
                      border: 'none',
                      cursor: 'pointer',
                      color: 'inherit',
                      fontSize: 12,
                      textAlign: 'left',
                      outline: 'none',
                    }}
                  >
                    <span style={{ flex: '0 0 auto', width: 12, transition: 'transform 120ms', transform: expanded ? 'rotate(90deg)' : 'none' }}>
                      ▶
                    </span>
                    {(() => {
                      const inhFriendly = friendlyDataSourceName(entry, resolve);
                      const badge = dataSourceExtensionBadge(entry, t);
                      return (
                        <span style={{ flex: 1, display: 'inline-flex', alignItems: 'baseline', gap: 6, flexWrap: 'wrap' }}>
                          <span style={{ fontWeight: 600 }}>{inhFriendly ?? id}</span>
                          {inhFriendly && (
                            <span style={{ fontSize: 11, fontWeight: 400, color: '#94a3b8' }}>{id}</span>
                          )}
                          {badge && (
                            <span
                              data-testid="g7le-data-sources-ext-badge"
                              style={{
                                fontSize: 10,
                                fontWeight: 600,
                                color: '#7c3aed',
                                background: '#f5f3ff',
                                border: '1px solid #ddd6fe',
                                borderRadius: 4,
                                padding: '1px 6px',
                              }}
                            >
                              {badge}
                            </span>
                          )}
                        </span>
                      );
                    })()}
                  </button>
                  {expanded && (
                    <div
                      data-testid="g7le-data-sources-inherited-info"
                      style={{ padding: '0 12px 10px 32px', display: 'flex', flexDirection: 'column', gap: 3 }}
                    >
                      {infoRows.length === 0 && (
                        <span style={{ color: '#94a3b8' }}>—</span>
                      )}
                      {infoRows.map(([label, value]) => (
                        <div key={label} style={{ display: 'flex', gap: 6 }}>
                          <span style={{ flex: '0 0 auto', color: '#94a3b8' }}>{label}:</span>
                          <span style={{ flex: 1, minWidth: 0, wordBreak: 'break-all', color: '#475569' }}>{value}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
      </div>

      {/* ── 푸터 (하단 고정) ── 폼 진입 시 에러 + 취소/저장 */}
      {draft && (
        <div
          className="g7le-data-sources__footer"
          style={{ flex: '0 0 auto', padding: '12px 16px', borderTop: '1px solid #e2e8f0', background: '#ffffff' }}
        >
          {formError && (
            <div
              data-testid="g7le-data-sources-form-error"
              role="alert"
              style={{ fontSize: 12, color: '#dc2626', marginBottom: 10 }}
            >
              {formError}
            </div>
          )}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button type="button" data-testid="g7le-data-sources-form-cancel" onClick={cancelForm} style={btnBase}>
              {t('layout_editor.data_sources.form.cancel')}
            </button>
            <button type="button" data-testid="g7le-data-sources-form-submit" onClick={submitForm} style={btnPrimary}>
              {t('layout_editor.data_sources.form.save')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
