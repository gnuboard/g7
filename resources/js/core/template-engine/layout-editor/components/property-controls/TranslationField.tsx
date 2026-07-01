// e2e:allow 레이아웃 편집기 속성 모달 [번역] 탭 — 속성 모달 칩 위젯/contentEditable 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위(TranslationField.saving.test.tsx)로 검증 (InlineParamChipEditor.tsx 와 동일 정책)
/**
 * TranslationField.tsx — 속성 편집 모달 [번역] 탭 콘텐츠
 *
 * 커스텀 키 노드(`text === "$t:custom.*"`) 선택 시 전체 활성 로케일 값을 한 폼에서
 * 일괄 편집한다. 미번역(폴백값) 로케일은 회색 + "번역 필요" 마크. 값 변경 → 디바운스 없이
 * 폼 blur 시 `PUT /custom-translations/{id}` (전체 values 일괄 + expected_lock_version).
 *
 * 커스텀 키가 아닌 노드(평문/바인딩식/코어 키)는 "이 요소는 다국어 키가 아닙니다" 안내 —
 * 인라인 편집으로 먼저 키를 생성하라는 힌트(흐름과 연계).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만. 모든 문자열은 `$t:layout_editor.*`.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { buildAuthHeaders } from '../../utils/authToken';
import { readSupportedLocales, localeDisplayLabel } from '../LocaleSwitcher';
import {
  findCustomKeyRow,
  bustTranslationCache,
  EDITOR_TRANSLATIONS_REFRESHED_EVENT,
} from '../../hooks/useInlineEdit';
import { trackEditorI18n } from '../../devtools/editorTrackers';
import {
  hasInlineBinding,
  sameBindingTokenSet,
  paramPlaceholderTokens,
  extractParamBindings,
  bindingChipLabel,
  removePlaceholderFromKeyValue,
} from '../../spec/inlineBindingUtils';
import { PlaceholderChipInput } from './PlaceholderChipInput';
import { useLayoutDocumentContext } from '../../LayoutDocumentContext';
import { setPendingValues, setPendingValue, getPendingValue } from '../../hooks/pendingCustomTranslations';
import { fireTranslationsRefreshed } from './inlineBindingApi';

/** 단일 `$t:custom.*` 키 텍스트 판정 정규식(param 미부착). */
const CUSTOM_KEY_RE = /^\s*\$t:(custom\.[a-zA-Z0-9._-]+)\s*$/;

export interface TranslationFieldProps {
  /**
   * 편집 대상 커스텀 키(`custom.*`, 접두 `$t:` 제외) 또는 null (node 비의존
   * 추상화). [번역] 탭은 `extractCustomKeyFromNode(node)` 로, I18nTextField 칸자리 펼침은
   * `classify(value).customKey` 로 동일 컴포넌트를 공유한다(펼침=[번역]탭 통합 SSoT, 계획).
   * param 여부는 node.text 파싱이 아니라 **로드된 키 값의 `{pN}` 자리표시 존재**로 판정한다(node 무의존).
   */
  customKey: string | null;
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * param 친화 라벨(`pN` → 데이터 경로명). node.text 의 `|pN={{expr}}`
   * 에서 도출되는 칩 표시명이므로, node 를 가진 호출처([번역]탭/칸자리 펼침)가 계산해 주입한다.
   * 미주입 시 PlaceholderChipInput 이 param 이름(`p0`)을 그대로 표시(폴백).
   */
  paramLabels?: Record<string, string>;
  /** 활성 로케일 목록 주입(테스트용). 미전달 시 G7Config 에서 읽음. */
  locales?: string[];
  /**
   * 칩 우측 X = 데이터 연결 '해제'. 펼침은 node.text 미보유(키만 안다)이므로
   * node.text 의 `|pN=` 제거는 **호출처(PropertyEditorModal/칸자리)**가 수행한다. 전 로케일 `{pN}` 제거 +
   * 동기화 신호는 호출처가 `disconnectParamAllLocales` 로 처리한다. 미전달 시 칩 X 미노출(node 미접근
   * 컨텍스트 — 키 관리 모달 등). 펼침은 refresh 이벤트 구독으로 제거 결과를 재로드한다. */
  onRemoveParam?: (paramName: string) => void;
}

/**
 * 두 키 값의 자리표시 칩 **구조**(`{pN}` 멀티셋·순서)가 달라졌는지.
 * 칩 이동/제거는 순서·구성을 바꾸고, 평문 타이핑은 바꾸지 않는다. 구조 변경일 때만 펼침→칸자리
 * 즉시 동기화(pending 기록 + 이벤트 발화)를 트리거해 keystroke 마다 PUT/이벤트가 발화하지 않게 한다.
 *
 * @param before 변경 전 키 값
 * @param after 변경 후 키 값
 * @returns 칩 순서/구성이 달라졌으면 true
 */
export function isChipStructureChange(before: string, after: string): boolean {
  // 순서 보존 추출 — paramPlaceholderTokens 는 `.sort()` 하므로 칩 **이동**(같은 집합·다른 순서)을
  // 구분 못 한다. 칩 이동도 구조 변경이므로 등장 순서대로 비교한다.
  const ordered = (s: string): string[] => {
    const out: string[] = [];
    const re = /\{\{?(p\d+)\}?\}/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(s)) !== null) out.push(m[1]);
    return out;
  };
  const a = ordered(before);
  const b = ordered(after);
  if (a.length !== b.length) return true;
  return a.some((tok, i) => tok !== b[i]);
}

interface LoadState {
  status: 'idle' | 'loading' | 'loaded' | 'not_a_key' | 'error';
  id?: number;
  lockVersion?: number;
  values: Record<string, string>;
}

/**
 * 노드 text 에서 커스텀 키 추출 (없으면 null). param 부착 키(`$t:custom.X|p0=..`)도 인지.
 * [번역] 탭 호출처(PropertyEditorModal)가 customKey prop 으로 어댑트하는 데 쓴다.
 */
export function extractCustomKeyFromNode(node: EditorNode): string | null {
  const text = node.text;
  if (typeof text !== 'string') return null;
  const m = CUSTOM_KEY_RE.exec(text);
  if (m) return m[1];
  // param 정규화 키 — `$t:custom.X|p0={{a}}`. 키 부분만 추출(번역 탭이 키 값/자리표시 편집).
  const paramized = extractParamBindings(text);
  if (paramized && paramized.key.startsWith('custom.')) return paramized.key;
  return null;
}

/**
 * param 키 텍스트(`$t:custom.X|pN={{expr}}`)의 `|pN={{expr}}` 에서 param 친화 라벨 맵을 도출한다
 * bindingChipLabel 이 파이프 필터(`| date`) 보간도 경로(예 `data.published_at`)로
 * 추출한다. node 보유 호출처([번역]탭)와 value 보유 호출처(I18nTextField 칸자리)가 공유한다.
 */
export function deriveParamLabelsFromText(text: string | null | undefined): Record<string, string> {
  const out: Record<string, string> = {};
  const paramized = extractParamBindings(typeof text === 'string' ? text : '');
  for (const p of paramized?.params ?? []) out[p.name] = bindingChipLabel(p.expression);
  return out;
}

/** 노드 text 에서 param 친화 라벨 맵 도출. */
export function deriveParamLabelsFromNode(node: EditorNode): Record<string, string> {
  return deriveParamLabelsFromText(typeof node.text === 'string' ? node.text : '');
}

export function TranslationField({
  customKey,
  templateIdentifier,
  t,
  paramLabels: paramLabelsProp,
  locales,
  onRemoveParam,
}: TranslationFieldProps): React.ReactElement {
  const activeLocales = locales ?? readSupportedLocales();
  const docCtx = useLayoutDocumentContext();
  const paramLabels = paramLabelsProp ?? {};

  const [load, setLoad] = useState<LoadState>({ status: 'idle', values: {} });
  const [saving, setSaving] = useState(false);
  // 저장 완료 후 짧게 "저장됨" 피드백. param 키 저장은 저장-지연 버퍼
  // 기록이라 동기적으로 즉시 끝나, 저장이 됐는지 사용자가 인지할 표시가 없었다(스피너도 안 보임).
  const [saved, setSaved] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  // 로드 시점 원본 로케일 값 — 토큰 불변 가드(쟁점 5)의 기준. 사용자가 번역값을 자유
  // 편집해도 그 값에 박혀 있던 `{{...}}` 보간 토큰의 멀티셋은 보존돼야 한다(데이터 연결
  // 무손상). 저장 직전 각 로케일 값을 이 기준과 대조한다.
  const baselineValuesRef = useRef<Record<string, string>>({});

  // 커스텀 키면 서버에서 행(values + lock_version) 조회.
  useEffect(() => {
    let cancelled = false;
    if (!customKey) {
      setLoad({ status: 'not_a_key', values: {} });
      return;
    }
    setLoad({ status: 'loading', values: {} });
    (async () => {
      const row = await findCustomKeyRow(templateIdentifier, customKey);
      if (cancelled) return;
      if (!row) {
        setLoad({ status: 'error', values: {} });
        return;
      }
      // 서버 값 위에 저장-지연 버퍼의 보류 값을 덮어쓴다(미저장 칩 편집 누적 반영). 편집 안 한
      // 로케일은 서버 값 유지. baseline(가드 기준)도 보류 반영값으로 — 연속 편집 정합.
      const merged: Record<string, string> = { ...(row.values ?? {}) };
      for (const loc of Object.keys(merged)) {
        const p = getPendingValue(customKey, loc);
        if (p !== undefined) merged[loc] = p;
      }
      for (const loc of activeLocales) {
        const p = getPendingValue(customKey, loc);
        if (p !== undefined) merged[loc] = p;
      }
      baselineValuesRef.current = { ...merged };
      setLoad({
        status: 'loaded',
        id: row.id,
        lockVersion: row.lock_version,
        values: merged,
      });
    })();
    return () => {
      cancelled = true;
    };
  }, [customKey, templateIdentifier]);

  // 칸자리↔펼침 동기화 — 같은 customKey 를 다른 위젯(I18nTextField 칸자리
  // PlaceholderChipInput / 다른 TranslationField 인스턴스)이 편집해 pending 버퍼를 바꾸면,
  // 이 인스턴스는 로드 시점 값을 local state(load.values)로 복사해 둔 상태라 자동 반영되지 않는다
  // (같은 customKey 면 위 load useEffect 의존성 불변 → 재로드 0). setPendingValue 가 발화하는
  // EDITOR_TRANSLATIONS_REFRESHED_EVENT 를 구독해, 통지 시 pending 값을 다시 읽어 load.values 를
  // 갱신한다(파생 상태 — 수동 토글 금지, feedback_modal_derived_ui_state_not_manual_toggle 정합).
  // 단, 사용자가 이 인스턴스에서 입력 중인(미저장) 편집을 덮어쓰지 않도록, 이벤트 출처가 다른
  // 위젯일 때만(현재 편집 중이 아닐 때) 머지한다 — pending 값은 SSoT 이므로 그대로 신뢰한다.
  useEffect(() => {
    if (typeof window === 'undefined' || !customKey) return;
    const handler = (e: Event): void => {
      const detail = (e as CustomEvent).detail as { templateIdentifier?: string } | undefined;
      if (detail?.templateIdentifier && detail.templateIdentifier !== templateIdentifier) return;
      setLoad((prev) => {
        if (prev.status !== 'loaded') return prev;
        let changed = false;
        const next = { ...prev.values };
        for (const loc of Object.keys(next)) {
          const p = getPendingValue(customKey, loc);
          if (p !== undefined && p !== next[loc]) {
            next[loc] = p;
            changed = true;
          }
        }
        if (!changed) return prev;
        baselineValuesRef.current = { ...baselineValuesRef.current, ...next };
        return { ...prev, values: next };
      });
    };
    window.addEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, handler);
    return () => window.removeEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, handler);
  }, [customKey, templateIdentifier]);

  const handleFieldChange = useCallback((locale: string, value: string): void => {
    setLoad((prev) => {
      const before = prev.values[locale] ?? '';
      const next = { ...prev, values: { ...prev.values, [locale]: value } };
      // (b) 펼침→칸자리 즉시 동기화. 칩 **구조 변경**
      // (칩 이동/제거 — `{pN}` 멀티셋/순서 변동)일 때만 pending 버퍼에 즉시 기록하고 동기화 신호를
      // 발화한다 → 칸자리/다른 펼침 인스턴스가 즉시 재읽기. 평문 타이핑(자리표시 멀티셋 불변)은
      // draft 유지(저장 시 flush) — keystroke 마다 PUT 하지 않아 입력 성능/커서 안정 보존.
      if (customKey && isChipStructureChange(before, value)) {
        setPendingValue(templateIdentifier, customKey, locale, value);
        baselineValuesRef.current = { ...baselineValuesRef.current, [locale]: value };
        docCtx?.markDirty?.();
        fireTranslationsRefreshed(templateIdentifier, locale);
      }
      return next;
    });
  }, [customKey, templateIdentifier, docCtx]);

  // 칩 우측 X = 데이터 연결 '해제'. 펼침은 node.text 미보유이므로
  // node.text `|pN=` 제거 + 전 로케일 `{pN}` 제거 + 동기화는 호출처(onRemoveParam)에 위임한다.
  // 본 컴포넌트는 표시 즉시성을 위해 로드된 전 로케일 행에서 `{pN}` 을 낙관적으로 제거(refresh
  // 이벤트 재로드 전까지 칩이 남아 보이는 깜빡임 방지). baseline 도 함께 갱신(토큰 가드 오탐 방지).
  const handleRemoveParam = useCallback((paramName: string): void => {
    setLoad((prev) => {
      const nextValues: Record<string, string> = {};
      for (const [loc, v] of Object.entries(prev.values)) {
        nextValues[loc] = removePlaceholderFromKeyValue(v, paramName);
      }
      baselineValuesRef.current = { ...nextValues };
      return { ...prev, values: nextValues };
    });
    onRemoveParam?.(paramName);
  }, [onRemoveParam]);

  // param 키 판정 — node.text 파싱이 아니라 **로드된 키 값(또는 baseline)의 `{pN}` 자리표시 존재**로
  // 한다. 자리표시는 로케일별 독립이라 어느 로케일에든 `{pN}` 이
  // 있으면 param 키다(칩 합성 위젯으로 편집). 자리표시 0 이면 비-param 평문 키(평문 input).
  const isParamKey = useMemo<boolean>(() => {
    const pools = [...Object.values(load.values), ...Object.values(baselineValuesRef.current)];
    return pools.some((v) => paramPlaceholderTokens(v).length > 0);
  }, [load.values]);

  // 저장 직후 "저장됨" 피드백을 짧게 표시. 다음 편집/저장 시 자동 해제.
  const savedTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const flashSaved = useCallback((): void => {
    setSaved(true);
    if (savedTimerRef.current) clearTimeout(savedTimerRef.current);
    savedTimerRef.current = setTimeout(() => setSaved(false), 1500);
  }, []);
  useEffect(() => () => { if (savedTimerRef.current) clearTimeout(savedTimerRef.current); }, []);

  // blur/저장 클릭 시 일괄 PUT — 전체 values + expected_lock_version.
  const handleSave = useCallback(async (): Promise<void> => {
    setSaved(false);
    if (load.status !== 'loaded' || load.id === undefined) return;
    // 토큰 불변 가드 — 원본에 `{{...}}` 보간 토큰이 **있던** 로케일 값은
    // 저장 시 그 토큰 멀티셋이 보존돼야 한다(데이터 연결 무손상). 사용자가 번역 텍스트/라벨은
    // 자유 편집하되 박힌 토큰을 변형/삭제하면 차단한다. 원본 토큰이 0 인 로케일은 면제 —
    // 미번역 로케일을 다른 로케일의 토큰을 가져와 번역하는 정상 행위(예 ko 의 `{{user.name}}`
    // 를 en 번역에 동일 포함)를 허용하기 위함. (raw 바인딩 신규 작성을 굳이 막지 않는 이유:
    // 번역값에 보간을 넣는 것 자체는 런타임 유효하며, 토큰 손상만이 데이터 연결을 깨뜨린다.)
    // param 정규화 키: 키 값은 `{p0}`/`{p1}` 자리표시 문장이다. 번역가가 문장/어순은
    // 자유 편집하되 자리표시 멀티셋은 보존돼야 한다(자리표시가 깨지면 param 치환 불발 → 보간 소멸).
    //  param 여부는 위 useMemo(isParamKey, 로드 값의 `{pN}` 존재)로 판정 — node 비의존.
    const baseline = baselineValuesRef.current;
    for (const [locale, value] of Object.entries(load.values)) {
      const base = baseline[locale] ?? '';
      if (isParamKey) {
        // 자리표시 가드 — 원본 값에 자리표시가 있던 로케일만 검사(미번역→자리표시 도입은 허용).
        const baseTokens = paramPlaceholderTokens(base);
        if (baseTokens.length === 0) continue;
        const valueTokens = paramPlaceholderTokens(value);
        const same =
          baseTokens.length === valueTokens.length &&
          baseTokens.every((tok, i) => tok === valueTokens[i]);
        if (!same) {
          setSaveError(t('layout_editor.translation.placeholder_mismatch'));
          return;
        }
        continue;
      }
      if (!hasInlineBinding(base)) continue; // 원본 토큰 0 → 자유 편집 허용.
      if (!sameBindingTokenSet(base, value)) {
        setSaveError(t('layout_editor.translation.token_mismatch'));
        return;
      }
    }
    setSaving(true);
    setSaveError(null);

    // param 키는 node.text 의 `|pN=` 와 짝이므로 **즉시 PUT 하지 않고** 저장-지연 버퍼에
    // 기록한다(레이아웃 [저장] 시 node.text 와 함께 flush → desync 0). dirty 표시로 저장 버튼 활성.
    // 비-param 커스텀 키는 node.text 의존이 없으므로 종전대로 즉시 PUT.
    if (isParamKey) {
      setPendingValues(templateIdentifier, customKey ?? '', load.values);
      baselineValuesRef.current = { ...load.values };
      docCtx?.markDirty?.();
      trackEditorI18n({ op: 'translation_field_update', translationKey: customKey, changedLocales: Object.keys(load.values), timestamp: Date.now() });
      // param 키 저장은 저장-지연 버퍼 기록이라 동기적으로 즉시 끝난다. setSaving(true)→(false) 가
      // 같은 동기 블록이면 React 가 배치 처리해 "저장 중" 상태가 1프레임도 렌더되지 않아(스피너
      // 미표시) 저장됐는지 알 수 없었다. 비동기 경계(await)를 둬 saving=true
      // 가 먼저 렌더된 뒤 완료 처리하고, 저장 직후 짧게 "저장됨" 피드백을 표시한다(비-param PUT 경로와
      // 동일한 사용자 인지 보장 — 그쪽은 fetch await 로 자연히 표시됨).
      await new Promise<void>((resolve) => setTimeout(resolve, 0));
      setSaving(false);
      flashSaved();
      return;
    }

    const url = `/api/admin/templates/${encodeURIComponent(
      templateIdentifier,
    )}/custom-translations/${load.id}`;
    try {
      const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          values: load.values,
          expected_lock_version: load.lockVersion ?? 0,
        }),
      });
      const body = await response.json().catch(() => null);
      if (response.status === 409) {
        setSaveError(t('layout_editor.translation.conflict'));
        return;
      }
      if (!response.ok) {
        setSaveError(
          (body as { message?: string })?.message ?? t('layout_editor.translation.save_failed'),
        );
        return;
      }
      // 새 lock_version 반영 — 연속 저장 시 409 회피.
      const newLock =
        typeof (body as { data?: { lock_version?: number } })?.data?.lock_version === 'number'
          ? (body as { data: { lock_version: number } }).data.lock_version
          : (load.lockVersion ?? 0) + 1;
      setLoad((prev) => ({ ...prev, lockVersion: newLock }));
      // 저장 성공 — 토큰 가드 기준을 방금 저장한 값으로 갱신(연속 저장 시 누적 기준 유지).
      baselineValuesRef.current = { ...load.values };
      await bustTranslationCache(templateIdentifier);
      trackEditorI18n({
        op: 'translation_field_update',
        translationKey: customKey,
        changedLocales: Object.keys(load.values),
        timestamp: Date.now(),
      });
      flashSaved(); // 저장 완료 피드백.
    } catch (err: unknown) {
      setSaveError(err instanceof Error ? err.message : t('layout_editor.translation.save_failed'));
    } finally {
      setSaving(false);
    }
  }, [load, templateIdentifier, customKey, t]);

  // 커스텀 키가 아닌 노드 — 안내 (인라인 편집으로 키 먼저 생성).
  if (load.status === 'not_a_key' || !customKey) {
    return (
      <div data-testid="g7le-translation-not-a-key" style={emptyNotice}>
        {t('layout_editor.translation.not_a_key')}
      </div>
    );
  }

  if (load.status === 'loading' || load.status === 'idle') {
    return (
      <div data-testid="g7le-translation-loading" style={emptyNotice}>
        {t('layout_editor.translation.loading')}
      </div>
    );
  }

  if (load.status === 'error') {
    return (
      <div data-testid="g7le-translation-error" style={emptyNotice}>
        {t('layout_editor.translation.load_failed')}
      </div>
    );
  }

  // 표시 로케일 — 활성 로케일 ∪ 행에 이미 값이 있는 로케일.
  const localeSet = new Set<string>([...activeLocales, ...Object.keys(load.values)]);
  const displayLocales = Array.from(localeSet);

  return (
    <div data-testid="g7le-translation-field" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div data-testid="g7le-translation-key" style={keyRow}>
        {t('layout_editor.translation.key_label')}: <code style={keyCode}>{customKey}</code>
      </div>

      {displayLocales.map((locale) => {
        const value = load.values[locale] ?? '';
        const missing = value.trim().length === 0;
        return (
          <div key={locale} className="g7le-translation-row" data-testid={`g7le-translation-row-${locale}`} style={fieldRow}>
            <label
              htmlFor={`g7le-translation-input-${locale}`}
              style={{ fontSize: 12, color: '#475569', minWidth: 72, display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              {localeDisplayLabel(locale, t)}
              {missing && (
                <span data-testid={`g7le-translation-missing-${locale}`} style={missingMark}>
                  {t('layout_editor.translation.needs_translation')}
                </span>
              )}
            </label>
            {isParamKey ? (
              // param 키 — 칩 합성 위젯(자리표시 칩 원자 + 평문 편집 + 칩 드래그 이동).
              // 새 칩 삽입('+데이터')은 후보 풀이 있는 [속성]탭 데이터 연결에서 수행 → 여기선 미노출.
              // 칩 이동/평문 변경 후 저장은 명시 [저장] 버튼(blur 가 칩 조작과 충돌하므로).
              <div style={{ flex: 1 }} data-testid={`g7le-translation-chip-${locale}`}>
                <PlaceholderChipInput
                  value={value}
                  onChange={(next) => handleFieldChange(locale, next)}
                  t={t}
                  paramLabels={paramLabels}
                  testIdSuffix={locale}
                  onRemoveChip={onRemoveParam ? handleRemoveParam : undefined}
                />
              </div>
            ) : (
              <input
                id={`g7le-translation-input-${locale}`}
                type="text"
                data-testid={`g7le-translation-input-${locale}`}
                value={value}
                placeholder={missing ? t('layout_editor.translation.needs_translation') : ''}
                onChange={(e) => handleFieldChange(locale, e.currentTarget.value)}
                onBlur={handleSave}
                style={{
                  flex: 1,
                  padding: '4px 8px',
                  fontSize: 13,
                  border: '1px solid #cbd5e1',
                  borderRadius: 4,
                  color: missing ? '#94a3b8' : '#0f172a',
                }}
              />
            )}
          </div>
        );
      })}

      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <button
          type="button"
          data-testid="g7le-translation-save"
          disabled={saving}
          onClick={handleSave}
          style={saveBtn}
        >
          {saving ? t('layout_editor.translation.saving') : t('layout_editor.translation.save')}
        </button>
        {saved && !saving && (
          <span
            data-testid="g7le-translation-saved"
            style={{ fontSize: 11, color: '#16a34a', display: 'inline-flex', alignItems: 'center', gap: 4 }}
          >
            ✓ {t('layout_editor.translation.saved')}
          </span>
        )}
        {saveError && (
          <span data-testid="g7le-translation-save-error" style={{ fontSize: 11, color: '#dc2626' }}>
            {saveError}
          </span>
        )}
      </div>
    </div>
  );
}

const emptyNotice: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '16px 0', textAlign: 'center' };
const keyRow: React.CSSProperties = { fontSize: 12, color: '#475569' };
const keyCode: React.CSSProperties = { fontSize: 11, background: '#f1f5f9', padding: '1px 4px', borderRadius: 3, color: '#0f172a' };
const fieldRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8 };
const missingMark: React.CSSProperties = { fontSize: 10, color: '#b45309', background: '#fef3c7', padding: '0 4px', borderRadius: 3 };
const saveBtn: React.CSSProperties = { padding: '6px 12px', fontSize: 12, border: 'none', borderRadius: 6, background: '#2563eb', color: '#fff', cursor: 'pointer' };
