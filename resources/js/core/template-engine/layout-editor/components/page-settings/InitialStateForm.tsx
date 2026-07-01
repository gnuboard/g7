/**
 * InitialStateForm.tsx — [초기 상태] 탭 본체
 *
 * 레이아웃 최상위 `initLocal`/`initGlobal`/`initIsolated` 정적 시작값을 3섹션으로 편집한다:
 *  - 로컬 초기값(`initLocal`/`state` 병합 읽기, `initLocal` 정규화 저장).
 *  - 전역 초기값(`initGlobal`).
 *  - 컴포넌트 격리 상태 초기값(`initIsolated` 짝 검증 orphan 경고).
 *
 * 각 값은 `InitialStateValueEditor` 재귀 편집기로(전타입·중첩·목록). 출처(자기 선언 vs 부모
 * 상속)는 own/merged 비교로 도출 — 상속 행 🔗 배지 + [되돌림], 자기 행 [✕]. legacy
 * `state` 보유 시 정규화 안내 + 첫 편집 시 initLocal 이관. 표현식/고급 배지 분기 없음.
 *
 * 본 폼은 prop 주도 — 셸이 raw·own(original)·orphanMap·patch 를 주입한다.
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import { InitialStateValueEditor } from './InitialStateValueEditor';
import { JsonBlockField } from '../JsonBlockField';
import {
  classifyKeyOrigin,
  normalizeLegacyState,
  isValidStateKey,
  type ValueKind,
} from '../../spec/initialStateValueUtils';

/** 값 추가 폼 종류 select 후보(문자/숫자/예아니오/없음/목록/묶음) */
const ADD_KIND_OPTIONS: Array<{ kind: ValueKind; labelKey: string }> = [
  { kind: 'string', labelKey: 'layout_editor.initstate.kind_string' },
  { kind: 'number', labelKey: 'layout_editor.initstate.kind_number' },
  { kind: 'boolean', labelKey: 'layout_editor.initstate.kind_boolean' },
  { kind: 'null', labelKey: 'layout_editor.initstate.kind_null' },
  { kind: 'list', labelKey: 'layout_editor.initstate.kind_list' },
  { kind: 'object', labelKey: 'layout_editor.initstate.kind_object' },
];

/** 한 섹션 식별자 */
type Scope = 'local' | 'global' | 'isolated';

export interface InitialStateFormProps {
  /** 병합본 raw(initLocal/initGlobal/initIsolated/state 보유) — usePageSettings.raw */
  raw: Record<string, unknown> | null;
  /**
   * 자기 선언분(`__editor.original`) — 출처 판정용. legacy `state`(initLocal 의 옛 이름)도 자기
   * 선언이므로 함께 받는다. 서버는 자기 state 를 `__editor.original.state` 에 두고 병합본은
   * `raw.initLocal` 로 내려보내므로(top-level `raw.state` 부재), own.state 가 없으면 자기 선언
   * legacy 값이 전부 "부모 상속"으로 오분류된다.
   */
  own?: {
    initLocal?: Record<string, unknown>;
    initGlobal?: Record<string, unknown>;
    initIsolated?: Record<string, unknown>;
    state?: Record<string, unknown>;
  };
  /** 최상위 키 패치(usePageSettings.patch) */
  patch: (key: string, value: unknown) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 격리 키 orphan 맵(classifyIsolatedOrphan 결과) — key→orphan */
  isolatedOrphan?: Record<string, boolean>;
}

const SCOPE_KEY: Record<Scope, string> = {
  local: 'initLocal',
  global: 'initGlobal',
  isolated: 'initIsolated',
};

/**
 * [초기 상태] 탭 폼.
 *
 * @param props InitialStateFormProps
 * @return 초기 상태 폼 엘리먼트
 */
export function InitialStateForm({
  raw,
  own,
  patch,
  t,
  isolatedOrphan = {},
}: InitialStateFormProps): React.ReactElement {
  // legacy state 정규화 — 자기 선언 legacy state 는 두 경로로 올 수 있다:
  //  ① 편집기 셸: 병합본은 raw.initLocal, 자기 state 는 __editor.original.state(=own.state).
  //     서버 응답 top-level 엔 raw.state 가 없다 → own.state 를 봐야 자기 키로 분류된다(라이브 경로).
  //  ② 독립/직접 주입(RTL·테스트): raw.state 가 top-level 로 올 수 있다 → 이것도 자기 선언으로 본다.
  // 둘을 합쳐 migrated/표시/출처를 판정한다.
  const ownState = useMemo<Record<string, unknown> | undefined>(() => {
    const fromOwn = own?.state && typeof own.state === 'object' ? (own.state as Record<string, unknown>) : undefined;
    const fromRaw = raw?.state && typeof raw.state === 'object' ? (raw.state as Record<string, unknown>) : undefined;
    if (!fromOwn && !fromRaw) return undefined;
    return { ...(fromRaw ?? {}), ...(fromOwn ?? {}) };
  }, [own, raw]);
  const { initLocal: normalizedLocal, migrated } = useMemo(
    () => normalizeLegacyState({
      initLocal: raw?.initLocal as Record<string, unknown> | undefined,
      state: ownState,
    }),
    [raw, ownState],
  );

  const sectionValue = (scope: Scope): Record<string, unknown> => {
    if (scope === 'local') return normalizedLocal;
    const v = raw?.[SCOPE_KEY[scope]];
    return v && typeof v === 'object' ? (v as Record<string, unknown>) : {};
  };

  const ownKeys = (scope: Scope): string[] => {
    if (scope === 'local') {
      // 자기 선언 = own.initLocal + own.state(legacy 별칭). 둘 다 __editor.original 에서 온다.
      // 종전엔 raw.state(서버 미노출)를 봐서 legacy state 화면의 자기 키가 전부 빈 ownKeys →
      // 부모 상속(🔗)으로 오분류됐다.
      const ownLocal = own?.initLocal ?? {};
      const stateKeys = ownState ? Object.keys(ownState) : [];
      return Array.from(new Set([...Object.keys(ownLocal), ...stateKeys]));
    }
    return Object.keys(own?.[SCOPE_KEY[scope] as 'initGlobal' | 'initIsolated'] ?? {});
  };

  const patchSection = useCallback(
    (scope: Scope, next: Record<string, unknown>): void => {
      const key = SCOPE_KEY[scope];
      if (scope === 'local' && migrated) {
        // 정규화 이관 — legacy state 키 제거 + initLocal 기록(dirty). migrated 는 own.state 보유로
        // 판정(서버 top-level raw.state 부재 → raw.state 직접 검사는 항상 false 였다).
        patch('state', undefined);
      }
      patch(key, Object.keys(next).length === 0 ? undefined : next);
    },
    [patch, migrated],
  );

  return (
    <div className="g7le-initstate-form" data-testid="g7le-initstate-form" style={form}>
      <p style={heading}>{t('layout_editor.page_settings.initstate.heading')}</p>

      {migrated ? (
        <p data-testid="g7le-initstate-legacy-state" style={legacyHint}>
          ⓘ {t('layout_editor.initstate.legacy_state')}
        </p>
      ) : null}

      <Section
        scope="local"
        label={t('layout_editor.initstate.section_local')}
        value={sectionValue('local')}
        ownKeys={ownKeys('local')}
        patchSection={patchSection}
        t={t}
      />
      <Section
        scope="global"
        label={t('layout_editor.initstate.section_global')}
        value={sectionValue('global')}
        ownKeys={ownKeys('global')}
        patchSection={patchSection}
        t={t}
      />
      <Section
        scope="isolated"
        label={t('layout_editor.initstate.section_isolated')}
        description={t('layout_editor.initstate.section_isolated_desc')}
        value={sectionValue('isolated')}
        ownKeys={ownKeys('isolated')}
        patchSection={patchSection}
        t={t}
        orphan={isolatedOrphan}
      />

      <p style={hint}>ⓘ {t('layout_editor.initstate.isolated_dynamic_hint')}</p>
    </div>
  );
}

/** 한 섹션(로컬/전역/격리) */
function Section({
  scope,
  label,
  description,
  value,
  ownKeys,
  patchSection,
  t,
  orphan = {},
}: {
  scope: Scope;
  label: string;
  description?: string;
  value: Record<string, unknown>;
  ownKeys: string[];
  patchSection: (scope: Scope, next: Record<string, unknown>) => void;
  t: InitialStateFormProps['t'];
  orphan?: Record<string, boolean>;
}): React.ReactElement {
  const [newName, setNewName] = useState('');
  const [newKind, setNewKind] = useState<ValueKind>('string');
  // 추가 시도했는데 이름이 비어 무시된 경우의 안내 표시(종전엔 silent return → "버튼이 죽은" 듯 보임).
  const [nameError, setNameError] = useState<string | null>(null);
  // "코드로" 모드 — 데이터 탭 params 와 동일(블럭 편집 ↔ JSON 텍스트 편집 토글).
  const [codeMode, setCodeMode] = useState(false);
  const keys = Object.keys(value);
  const mergedKeys = keys;

  const setKeyValue = (key: string, v: unknown): void => {
    patchSection(scope, { ...value, [key]: v });
  };
  const removeKey = (key: string): void => {
    const next = { ...value };
    delete next[key];
    patchSection(scope, next);
  };
  const addRow = (): void => {
    const name = newName.trim();
    // 이름 빈 칸 → 무반응 대신 안내(값 추가 무반응 버그 수정).
    if (!name) {
      setNameError(t('layout_editor.initstate.name_required'));
      return;
    }
    // 식별자 검증 — 한글·공백·하이픈·숫자 시작 등은 _local.키/{{키}} 식별자로 못 쓰임.
    if (!isValidStateKey(name)) {
      setNameError(t('layout_editor.initstate.name_invalid'));
      return;
    }
    if (name in value) {
      setNameError(t('layout_editor.initstate.name_duplicate'));
      return;
    }
    const defaults: Record<ValueKind, unknown> = { string: '', number: 0, boolean: false, null: null, list: [], object: {} };
    patchSection(scope, { ...value, [name]: defaults[newKind] });
    setNewName('');
    setNameError(null);
  };

  return (
    <div data-testid={`g7le-initstate-section-${scope}`} style={section}>
      <div style={sectionHeadRow}>
        <div style={sectionHead}>{label}</div>
        <button
          type="button"
          data-testid={`g7le-initstate-${scope}-mode-toggle`}
          onClick={() => setCodeMode((v) => !v)}
          style={modeToggleBtn}
        >
          {codeMode ? t('layout_editor.json_block.mode_block') : t('layout_editor.json_block.mode_code')}
        </button>
      </div>
      {description ? <p style={sectionDesc}>ⓘ {description}</p> : null}

      {codeMode ? (
        // 코드 모드 — 섹션 전체를 JSON 객체 텍스트로 편집(객체 가드 + 유효일 때만 반영=저장 차단).
        <JsonBlockField
          value={value}
          shape="object"
          emptyValue={{}}
          onChange={(next) => patchSection(scope, (next ?? {}) as Record<string, unknown>)}
          t={t}
          testidPrefix={`g7le-initstate-${scope}-code`}
          placeholder={'{\n  "keyword": "",\n  "page": 1\n}'}
          shapeErrorKey="layout_editor.json_block.must_be_object"
          validate={(parsed) => {
            // 코드 모드에서도 최상위 키는 유효 식별자여야(블럭 모드 addRow 와 동일 규칙).
            const bad = Object.keys((parsed ?? {}) as Record<string, unknown>).filter((k) => !isValidStateKey(k));
            return bad.length ? t('layout_editor.json_block.invalid_keys', { keys: bad.join(', ') }) : null;
          }}
        />
      ) : (
        <>
          {keys.length === 0 ? (
            <p data-testid={`g7le-initstate-${scope}-empty`} style={emptyHint}>{t('layout_editor.initstate.no_values')}</p>
          ) : (
            keys.map((key) => {
              const origin = classifyKeyOrigin(mergedKeys, ownKeys, key);
              const inherited = origin === 'inherited';
              const isOrphan = scope === 'isolated' && orphan[key];
              return (
                <ValueItem
                  key={key}
                  scope={scope}
                  itemKey={key}
                  value={value[key]}
                  inherited={inherited}
                  isOrphan={!!isOrphan}
                  onChangeValue={(v) => setKeyValue(key, v)}
                  onRemove={() => removeKey(key)}
                  t={t}
                />
              );
            })
          )}

          <div style={addRowStyle}>
            <input
              type="text"
              data-testid={`g7le-initstate-${scope}-add-name`}
              value={newName}
              placeholder={t('layout_editor.initstate.value_name')}
              onChange={(e) => {
                setNewName(e.target.value);
                if (nameError) setNameError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  addRow();
                }
              }}
              aria-invalid={nameError ? true : undefined}
              style={{ ...addNameInput, ...(nameError ? addNameInputError : null) }}
            />
            {/* 값 종류 선택(와이어프레임 L2619 — 이름+종류+값). 종전엔 종류 select 미노출로 항상 string 생성. */}
            <select
              data-testid={`g7le-initstate-${scope}-add-kind`}
              value={newKind}
              onChange={(e) => setNewKind(e.target.value as ValueKind)}
              aria-label={t('layout_editor.initstate.value_kind')}
              style={addKindSelect}
            >
              {ADD_KIND_OPTIONS.map((opt) => (
                <option key={opt.kind} value={opt.kind}>
                  {t(opt.labelKey)}
                </option>
              ))}
            </select>
            <button type="button" data-testid={`g7le-initstate-${scope}-add`} onClick={addRow} style={addBtn}>
              + {t('layout_editor.initstate.add_value')}
            </button>
          </div>
          {nameError ? (
            <p data-testid={`g7le-initstate-${scope}-add-error`} style={addErrorStyle}>
              {nameError}
            </p>
          ) : null}
        </>
      )}
    </div>
  );
}

/**
 * 한 초기값 행 — 키 헤더(상속/되돌리기/삭제 + 코드 보기) + 값 편집기 + 코드 미리보기.
 *
 * 코드 보기([</>])는 그 값의 JSON 직렬화를 monospace 로 표시한다(각 값 코드
 * 미리보기). 값별 토글 상태가 필요해 인라인 map 에서 별도 컴포넌트로 분리(useState 훅 사용).
 */
function ValueItem({
  scope,
  itemKey,
  value,
  inherited,
  isOrphan,
  onChangeValue,
  onRemove,
  t,
}: {
  scope: Scope;
  itemKey: string;
  value: unknown;
  inherited: boolean;
  isOrphan: boolean;
  onChangeValue: (v: unknown) => void;
  onRemove: () => void;
  t: InitialStateFormProps['t'];
}): React.ReactElement {
  const [showCode, setShowCode] = useState(false);
  // 값 JSON 직렬화 — 순환/직렬화 불가 시 String 폴백(미리보기는 손상 0).
  let code: string;
  try {
    code = JSON.stringify(value, null, 2) ?? String(value);
  } catch {
    code = String(value);
  }
  return (
    <div data-testid={`g7le-initstate-${scope}-item-${itemKey}`} style={itemRow}>
      <div style={itemHead}>
        {inherited ? <span data-testid={`g7le-initstate-inherited-${itemKey}`} style={inheritBadge}>🔗</span> : null}
        <code style={keyName}>{itemKey}</code>
        <span style={{ flex: 1 }} />
        <button
          type="button"
          data-testid={`g7le-initstate-code-${itemKey}`}
          onClick={() => setShowCode((v) => !v)}
          style={iconBtn}
          aria-label={t('layout_editor.value_tree.show_source')}
          title={t('layout_editor.value_tree.show_source')}
        >
          {'</>'}
        </button>
        {inherited ? (
          <button type="button" data-testid={`g7le-initstate-revert-${itemKey}`} onClick={onRemove} style={iconBtn}>
            {t('layout_editor.initstate.revert')}
          </button>
        ) : (
          <button type="button" data-testid={`g7le-initstate-remove-${itemKey}`} onClick={onRemove} style={iconBtn} aria-label={t('layout_editor.init_actions.remove')}>✕</button>
        )}
      </div>
      {showCode ? (
        <pre data-testid={`g7le-initstate-code-block-${itemKey}`} style={codeBlock}>{code}</pre>
      ) : null}
      <InitialStateValueEditor value={value} onChange={onChangeValue} t={t} path={itemKey} scope={scope} />
      {isOrphan ? (
        <p data-testid={`g7le-initstate-isolated-orphan-${itemKey}`} style={orphanWarn}>
          ⚠ {t('layout_editor.initstate.isolated_orphan')}
        </p>
      ) : null}
    </div>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 14, minWidth: 0 };
const heading: React.CSSProperties = { margin: 0, fontSize: 13, fontWeight: 700, color: '#0f172a' };
const legacyHint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#1d4ed8', background: '#eff6ff', padding: '6px 8px', borderRadius: 6 };
const section: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, padding: 10, border: '1px solid #e2e8f0', borderRadius: 10, minWidth: 0 };
const sectionHeadRow: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 };
const sectionHead: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#475569' };
const sectionDesc: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const modeToggleBtn: React.CSSProperties = { padding: '2px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const itemRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: '6px 8px', border: '1px solid #f1f5f9', borderRadius: 6, minWidth: 0 };
const itemHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const inheritBadge: React.CSSProperties = { fontSize: 12 };
const keyName: React.CSSProperties = { fontSize: 12, color: '#0f172a', fontFamily: 'monospace' };
const iconBtn: React.CSSProperties = { border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', fontSize: 11, padding: '2px 6px' };
const orphanWarn: React.CSSProperties = { margin: 0, fontSize: 11, color: '#b45309', background: '#fef3c7', padding: '4px 6px', borderRadius: 6 };
const addRowStyle: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'center' };
const addNameInput: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const addNameInputError: React.CSSProperties = { border: '1px solid #dc2626' };
const addErrorStyle: React.CSSProperties = { margin: 0, fontSize: 11, color: '#dc2626' };
const addKindSelect: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#0f172a' };
const addBtn: React.CSSProperties = { padding: '2px 10px', fontSize: 11, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const emptyHint: React.CSSProperties = { margin: 0, fontSize: 12, color: '#94a3b8' };
const hint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const codeBlock: React.CSSProperties = { margin: '2px 0', padding: '6px 8px', fontSize: 11, fontFamily: 'monospace', color: '#0f172a', background: '#f1f5f9', border: '1px solid #e2e8f0', borderRadius: 6, whiteSpace: 'pre-wrap', wordBreak: 'break-all', overflowX: 'auto' };
