// e2e:allow 레이아웃 편집기 텍스트 데이터 연결 속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(§공통 검증) + 단위/레이아웃 렌더링 테스트로 검증 (DataBindingSection.tsx L1 과 동일 정책)
/**
 * InlineBindingSection.tsx — [속성] 탭 "텍스트 데이터 연결" 영역
 *
 * 텍스트 보유 컴포넌트(`isTextBindableNode`)의 `text` prop 에 박힌 `{{...}}` 보간 조각을
 * **조각 단위**로 교체/해제하고, 맨 아래 "+ 데이터 삽입" 으로 새 조각을 **끝에 추가**한다
 * (9-a 권고안 채택, 부록6 dataProps 의 text 내부 보간판).
 *
 * 부록6 `DataBindingSection` 과 차이:
 *  - dataProps = prop 한 칸 = 표현식 통째. 여기는 한 문자열 **안의 여러 토큰**을 위치
 *    보존하며 조각별 행으로 다룬다(`inlineBindingUtils`).
 *  - 라벨/구분자/평문은 보존 — `text` 전체를 덮어쓰지 않는다.
 *  - 항상 scalar 후보(텍스트에 꽂는 값은 단일 스칼라) — 검색 피커는 scalar 만 노출.
 *
 * 조각 행:
 *  - parseable(단일 경로) → 표현식 + [해제] + (피커로) 소스/경로 교체.
 *  - 복합(삼항/Math/필터/다중) → "복합 바인딩(코드 편집)" 읽기전용 디그레이드(부록6 가드).
 *
 * 검색 피커는 부록6 `bindingCandidates`(scalar 후보) + `buildBindingExpression`(안전 형태)
 * 을 그대로 재사용한다 — 저장값 형태 일관(`?.`+`?? ''`).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { useLayoutEditor } from '../../LayoutEditorContext';
import {
  type BindingCandidate,
  buildBindingExpression,
} from '../../spec/bindingCandidates';
import { InlineBindingScalarPicker } from './InlineBindingScalarPicker';
import {
  toInlineBindingRows,
  extractParamBindings,
  isParamizedKeyText,
  replaceParamBinding,
  removeParamBinding,
} from '../../spec/inlineBindingUtils';
import {
  removeParamPlaceholderAllLocales,
  keyifyWithNewBinding,
  insertBindingIntoParamKey,
} from './inlineBindingApi';
import { EDITOR_TRANSLATIONS_REFRESHED_EVENT } from '../../hooks/useInlineEdit';

export interface InlineBindingSectionProps {
  /** 편집 대상 노드 */
  node: EditorNode;
  /** 연결 가능 데이터 후보 풀(평탄) — EditorCanvasOverlay 가 빌드해 주입 */
  candidates: BindingCandidate[];
  /** 다국어 해석(편집 대상 템플릿 사전) — 친화 명칭/라벨 `$t:` 키 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 패치 */
  onPatchNode: (patched: EditorNode) => void;
  /**
   * 편집 대상 템플릿 식별자 — param 데이터 연결 '해제' 시 전 로케일 키 값의 `{pN}` 자리표시를
   * 제거하기 위해 custom-translations API 를 호출한다(node.text 의 `|pN=` 제거와 동기). 미전달
   * 시 해제는 node.text 만 정리(키 값 자리표시는 다음 저장 시 raw 노출 — 폴백).
   */
  templateIdentifier?: string;
}

export function InlineBindingSection({
  node,
  candidates,
  t,
  onPatchNode,
  templateIdentifier: templateIdProp,
}: InlineBindingSectionProps): React.ReactElement | null {
  const text = typeof node.text === 'string' ? node.text : '';
  // 키화·키 값 PUT 에 필요한 편집 컨텍스트(템플릿/레이아웃/로케일) — 편집기 컨텍스트에서 직접 조회.
  // (prop 으로도 templateIdentifier 를 받지만, layoutName/locale 은 컨텍스트가 SSoT.)
  const { state } = useLayoutEditor();
  const templateIdentifier = templateIdProp ?? state.templateIdentifier;
  const layoutName = state.selectedRoute?.layoutName ?? null;
  const locale = state.locale;
  const [busy, setBusy] = useState(false);

  // param 부착 키 노드(`$t:custom.X|p0={{a}}`)면 param 값을 행으로 노출한다.
  // 평문/문장은 [번역] 탭이 다루고, 여기서는 보간 소스(param 값) 교체/해제/추가만(쟁점 4 공존).
  // S9-N1: param 0 **단독 custom 키**(`$t:custom.X`)도 param 키 분기로 — 데이터 삽입 시
  // insertDataKeyify(재키화)가 아니라 appendParam(키 승계)을 타야 기존 en/ja 번역이 보존된다.
  const isBareCustomKey = /^\s*\$t:custom\.[A-Za-z0-9._-]+\s*$/.test(text);
  // S9-N4: "param 키 분기"는 **custom 키일 때만**. isParamizedKeyText
  // (PARAMIZED_KEY_RE)는 custom/lang 키를 구분하지 않아 **미키화 lang named-param** 노드
  // (`$t:user.identity.challenge.remaining_attempts|count={{Math.max(...)}}`)도 true 로 판정한다.
  // 그러면 속성 탭이 그 노드를 "이미 키화된 param 키"로 취급해, 데이터 추가 시 appendParam
  // (=insertBindingIntoParamKey)이 lang 키(`user.identity...`)에 `|pN=` 을 붙이려 한다 → 키화 안 됨
  // + lang 키 오염. lang named-param 은 **비키화 노드**로 취급해 insertDataKeyify(신규 custom 키화)
  // 경로로 보낸다(아래 rows 가 toInlineBindingRows 로 읽기 표시 + 삽입=키화). custom 키일 때만 param
  // 행 분기. (classify[useInlineEdit] / keyifyChipValue 의 `startsWith(custom.)` 가드와 동일 기준.)
  const isCustomParamizedKey =
    isParamizedKeyText(text) && (extractParamBindings(text)?.key.startsWith('custom.') ?? false);
  const isParamized = isCustomParamizedKey || isBareCustomKey;
  const paramized = useMemo(() => (isParamized ? extractParamBindings(text) : null), [isParamized, text]);
  // 비키화 노드는 행을 안 만든다(데이터를 넣으면 즉시 키화 → param 키로 전이). 기존 raw 보간이
  // 박혀 있던 노드는 그 보간들을 표시만 한다(읽기 — 키화 권유). 삽입=키화 통일.
  const rows = useMemo(() => (isParamized ? [] : toInlineBindingRows(text)), [isParamized, text]);

  const patchText = (next: string): void => {
    onPatchNode({ ...node, text: next });
  };

  /** 키 CRUD 후 캔버스/번역탭 즉시 반영 — 사전 재fetch 이벤트 발화(useInlineEdit 와 동일 신호). */
  const fireRefresh = (): void => {
    if (typeof window === 'undefined') return;
    try {
      window.dispatchEvent(
        new CustomEvent(EDITOR_TRANSLATIONS_REFRESHED_EVENT, { detail: { templateIdentifier, locale } }),
      );
    } catch {
      /* 무해 — 다음 진입 시 최신 사전 로드 */
    }
  };

  // === param 키 노드 param 값 교체 — `|pN=` 값만 새 표현식으로(키·자리표시 보존). ===
  const replaceParamAt = (paramName: string, c: BindingCandidate): void => {
    const expr = buildBindingExpression(c.sourceId, c.path, 'scalar');
    patchText(replaceParamBinding(text, paramName, expr));
  };

  // === param 데이터 연결 '해제' ===
  // (a) node.text 의 `|pN=` 제거 + (b) 전 로케일 키 값의 `{pN}` 자리표시 제거(custom_translations).
  const disconnectParam = (paramName: string): void => {
    const parsedBefore = extractParamBindings(text);
    patchText(removeParamBinding(text, paramName));
    const key = parsedBefore?.key;
    if (templateIdentifier && key) {
      void removeParamPlaceholderAllLocales(templateIdentifier, key, paramName).then(fireRefresh);
    }
  };

  // === 데이터 삽입 = 즉시 키화 ===
  // 비키화 노드(평문 또는 평문+raw 보간)에 데이터를 추가하면, raw `{{}}` 만 붙이지 않고 그 즉시
  // param 키화한다 → 번역 탭 즉시 활성 + 칩 노출. 끝에 추가(위치 변경은 인라인/번역 탭 칩 드래그).
  // `$t:` lang 키 → 현재 로케일 평문 해석. 노드 text 가 `$t:auth.email`(lang 키)이면 키 값에
  // raw `$t:` 토큰이 박히지 않도록 평문("이메일")으로 치환한다(중첩 키 참조 방지). 평문/보간은 그대로.
  // lang 키 해석은 prop `t`(= editorAwareT, EditorCanvasOverlay 주입)로 한다.
  // editorAwareT 는 `window.G7Core.t`(편집 대상 사전 fallback 체인으로 swap 됨)를 우선 사용하므로
  // 편집 대상 템플릿(sirsoft-basic)의 `policy.*` 를 `발행일` 로 정확히 해석한다. TranslationEngine
  // 싱글톤(getInstance)은 캔버스가 쓰는 격리 엔진과 다른 인스턴스라 그 키를 못 찾아 빈값을 반환했고,
  // 그 결과 라벨(`발행일:`)이 사라져 키 값이 깨졌다(라벨 소실/빈 value POST). prop `t` 가 SSoT.
  const resolveLangKey = (key: string): string => {
    const resolved = t(key);
    return resolved && resolved !== key && !resolved.startsWith('$t:') ? resolved : '';
  };
  // `$t:<키>` 매칭 — 키 문자 클래스에서 **콜론을 제외**한다. Shape A 노드
  // `$t:policy.published_at: {{...}}` 에서 `:` 은 키 뒤의 **라벨 구분자**(평문)다. 종전 정규식
  // `[a-zA-Z0-9._:-]+` 은 그 구분자 `:` 까지 키에 삼켜 `policy.published_at:`(끝 콜론 포함)로 해석
  // 시도 → t() 미해석 → 라벨 `발행일:` 소실(빈 value POST). 콜론을 키에서 빼면 키만 평문화되고
  // 구분자 `:` 은 평문으로 보존돼 `발행일:` 라벨이 살아난다. (lang 키 자체에 `:` 은 쓰이지 않는다.)
  const resolveLang = (s: string): string =>
    s.replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) => resolveLangKey(tok.slice(3)))
      .replace(/\s+/g, ' ')
      .trim();

  const insertDataKeyify = (c: BindingCandidate): void => {
    if (busy) return;
    setBusy(true);
    void keyifyWithNewBinding(
      templateIdentifier,
      layoutName,
      locale,
      text,
      text.length, // 끝에 추가(앞/중간은 인라인 편집 칩 '+데이터' 커서 위치가 담당)
      c.sourceId,
      c.path,
      'scalar',
      resolveLang,
      // S9-N4 — lang named-param Shape(`$t:user.*|count={{}}`)를 deriveChipModel 로 분해(lang
      // 값 `{{count}}` ↔ named param 매핑)하도록 lang 값 해석기 t 를 넘긴다. EditorCanvasOverlay 와 동일.
      (key: string) => t(key),
    )
      .then((res) => {
        if (res.kind === 'ok') {
          patchText(res.text);
          fireRefresh();
        }
      })
      .finally(() => setBusy(false));
  };

  // === param 키 노드 신규 보간 추가 — 끝에 추가(전 로케일 자리표시 끝 추가, 번역가가 드래그 이동). ===
  // S9-N1: param 0 단독 custom 키도 허용(insertBindingIntoParamKey 가 키 승계 — 번역 보존).
  const appendParam = (c: BindingCandidate): void => {
    if (busy || (!paramized && !isBareCustomKey)) return;
    setBusy(true);
    // 키 값 끝 위치 — 편집 로케일 현재 값 길이(미상이면 0). insertBindingIntoParamKey 가
    // 편집 로케일은 그 위치, 그 외는 문장 끝에 자리표시 추가.
    void insertBindingIntoParamKey(
      templateIdentifier,
      locale,
      text,
      Number.MAX_SAFE_INTEGER, // 끝(clamp)
      c.sourceId,
      c.path,
      'scalar',
    )
      .then((res) => {
        if (res.kind === 'ok') {
          patchText(res.text);
          fireRefresh();
        }
      })
      .finally(() => setBusy(false));
  };

  // param 키 노드: param 값 행만(교체) — 해제/신규추가는 키 값 자리표시 동기 필요 → 번역 탭/후속.
  if (isParamized) {
    const params = paramized?.params ?? [];
    return (
      <div data-testid="g7le-inline-binding-section" style={sectionStyle}>
        <div style={sectionHead}>🔗 {t('layout_editor.inline_binding.section_title')}</div>
        {params.length === 0 ? (
          <div data-testid="g7le-inline-binding-none" style={emptyRow}>
            {t('layout_editor.inline_binding.none')}
          </div>
        ) : (
          params.map((p) => (
            <div key={p.name} data-testid={`g7le-inline-binding-param-${p.name}`} style={rowStyle}>
              {p.parsed === null ? (
                <div data-testid={`g7le-inline-binding-param-complex-${p.name}`} style={complexBadge}>
                  {t('layout_editor.inline_binding.complex')}
                  <code style={exprCode}>{p.expression}</code>
                </div>
              ) : (
                <>
                  <div style={connectedRow}>
                    <code data-testid={`g7le-inline-binding-param-expr-${p.name}`} style={exprCode}>
                      {p.expression}
                    </code>
                    {/* 데이터 연결 '해제' — node.text `|pN=` 제거 + 전 로케일 키 값 `{pN}` 자리표시 제거.
 칩 제거는 이 버튼 전용(칩 자체는 키 누르기로 안 지워짐 — UX). */}
                    <button
                      type="button"
                      data-testid={`g7le-inline-binding-param-clear-${p.name}`}
                      onClick={() => disconnectParam(p.name)}
                      style={clearBtn}
                    >
                      {t('layout_editor.inline_binding.clear')}
                    </button>
                  </div>
                  <InlineBindingScalarPicker
                    candidates={candidates}
                    t={t}
                    onSelect={(c) => replaceParamAt(p.name, c)}
                    testIdSuffix={`param-${p.name}`}
                  />
                </>
              )}
            </div>
          ))
        )}
        {/* 신규 보간 추가 — 끝에 추가(미편집 로케일 자리표시는 문장 끝). 위치 지정(앞/중간) 삽입은
 인라인 편집 칩 위젯의 '+데이터'(커서 위치)가 담당한다. */}
        <div data-testid="g7le-inline-binding-param-append" style={appendStyle}>
          <div style={appendHint}>{t('layout_editor.inline_binding.append_hint')}</div>
          <InlineBindingScalarPicker candidates={candidates} t={t} onSelect={appendParam} testIdSuffix="param-append" />
        </div>
        <div data-testid="g7le-inline-binding-param-hint" style={appendHint}>
          {t('layout_editor.inline_binding.param_hint')}
        </div>
        <div style={divider} />
      </div>
    );
  }

  // 비키화 노드 — 데이터를 추가하면 즉시 키화(param 키 전이)되어 위 isParamized 분기로 넘어간다.
  // 기존 raw 보간(legacy)이 박힌 노드는 그 보간을 읽기 표시만 한다(교체/해제하려면 데이터를 한 번
  // 추가해 키화하거나 인라인 편집으로 키화 — "삽입=키화 통일"). 삽입 입구는 항상 제공.
  return (
    <div data-testid="g7le-inline-binding-section" style={sectionStyle}>
      <div style={sectionHead}>🔗 {t('layout_editor.inline_binding.section_title')}</div>

      {rows.length === 0 ? (
        <div data-testid="g7le-inline-binding-none" style={emptyRow}>
          {t('layout_editor.inline_binding.none')}
        </div>
      ) : (
        rows.map((row) => (
          <div
            key={row.bindingIndex}
            data-testid={`g7le-inline-binding-row-${row.bindingIndex}`}
            style={rowStyle}
          >
            {/* 비키화 raw 보간 — 읽기 표시(키화 전). 교체/해제·드래그는 키화 후 가능. */}
            <div style={connectedRow}>
              <code data-testid={`g7le-inline-binding-expr-${row.bindingIndex}`} style={exprCode}>
                {row.expression}
              </code>
            </div>
          </div>
        ))
      )}

      {/* 신규 데이터 삽입 = 즉시 키화(번역 탭 활성 + 칩 노출). */}
      <div data-testid="g7le-inline-binding-append" style={appendStyle}>
        <div style={appendHint}>{t('layout_editor.inline_binding.append_hint')}</div>
        <InlineBindingScalarPicker candidates={candidates} t={t} onSelect={insertDataKeyify} testIdSuffix="append" />
      </div>

      <div style={divider} />
    </div>
  );
}

const sectionStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', marginBottom: 8 };
const sectionHead: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 6 };
const rowStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: '8px 0' };
const connectedRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const emptyRow: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const exprCode: React.CSSProperties = { fontSize: 11, fontFamily: 'monospace', background: '#f1f5f9', padding: '2px 6px', borderRadius: 4, color: '#0f172a', wordBreak: 'break-all' };
const clearBtn: React.CSSProperties = { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 4, background: '#fff', color: '#dc2626', padding: '2px 8px', cursor: 'pointer' };
const complexBadge: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, fontSize: 11, color: '#64748b' };
const appendStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, padding: '8px 0 0' };
const appendHint: React.CSSProperties = { fontSize: 11, color: '#475569', fontWeight: 600 };
const divider: React.CSSProperties = { borderTop: '1px solid #e2e8f0', marginTop: 4 };
