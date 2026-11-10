/**
 * usePageSettings.ts — 페이지 설정 최상위 속성 로드/패치 훅
 *
 * 페이지 설정 모달의 각 탭(MetaForm/SeoForm/InitActionsForm/...)이 레이아웃 **최상위 키**
 * (meta/permissions/init_actions/computed/transition_overlay/errorHandling/data_sources 등)를
 * 무손실로 읽고 패치하는 공용 진입점이다. 저장은 `patchDocumentRaw`(raw[key] + __editor.
 * original[key] 동시 갱신, useLayoutDocument.ts:653)에 위임 — 툴바 저장이 일임한다.
 *
 * 제목·설명 같은 다국어 텍스트 키 생성/수정은 `useInlineEdit` 의 `createCustomKey`/
 * `updateCustomKeyValue` 에 위임한다(별도 다국어 위젯 신설 0 — 383 다국어 인프라 일관).
 *
 * 신규 인프라 0 — patchDocumentRaw·createCustomKey 재사용. 본 훅은 얇은 조회/패치 래퍼.
 *
 * @since engine-v1.50.0
 */

import { useCallback, useRef } from 'react';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import { useLayoutEditor } from '../LayoutEditorContext';
import { createCustomKey, updateCustomKeyValue } from './useInlineEdit';

/** usePageSettings 결과 */
export interface UsePageSettingsResult {
  /** 편집 중 레이아웃 raw (병합본 — 최상위 키 읽기) */
  raw: Record<string, unknown> | null;
  /**
   * 최상위 키 1건의 현재 값을 읽는다. 미존재 시 `fallback`.
   *
   * @param key 레이아웃 최상위 키 (meta/permissions/init_actions/computed/...)
   * @param fallback 미존재 시 반환값
   * @return 그 키의 현재 값 또는 fallback
   */
  getValue: <T = unknown>(key: string, fallback?: T) => T;
  /**
   * 최상위 키 1건을 패치한다(무손실 라운드트립). originalValue 미지정 시 value 를 양쪽 기입.
   *
   * @param key 레이아웃 최상위 키
   * @param value 새 값
   * @param originalValue 저장 영속값(상속 키의 원형 보존 시 별도 지정)
   */
  patch: (key: string, value: unknown, originalValue?: unknown) => void;
  /**
   * 다국어 텍스트 커스텀 키를 생성한다(제목/설명 등) — useInlineEdit 위임.
   *
   * @param locale 현재 로케일
   * @param value 그 로케일 값(평문)
   * @return 생성 결과(생성된 키 포함) 또는 null(템플릿/레이아웃 미해석)
   */
  createI18nKey: (
    locale: string,
    value: string,
  ) => Promise<Awaited<ReturnType<typeof createCustomKey>> | null>;
  /**
   * 기존 다국어 커스텀 키의 현재 로케일 값을 수정한다 — useInlineEdit 위임.
   *
   * @param translationKey `$t:custom...` 키
   * @param locale 로케일
   * @param value 새 값
   * @return 수정 결과 또는 null(템플릿 미해석)
   */
  updateI18nKeyValue: (
    translationKey: string,
    locale: string,
    value: string,
  ) => Promise<Awaited<ReturnType<typeof updateCustomKeyValue>> | null>;
}

/**
 * patchDocumentRaw 와 같은 규칙으로 raw 사본을 갱신한다 — raw[key] = value, __editor.original[key] = 영속값.
 *
 * @param raw 현재 raw
 * @param key 최상위 키
 * @param value 병합본 값
 * @param persistValue 저장 영속값
 * @return 갱신된 raw 사본 (raw 가 없으면 null)
 */
function applyRawPatch(
  raw: Record<string, unknown> | null,
  key: string,
  value: unknown,
  persistValue: unknown,
): Record<string, unknown> | null {
  if (!raw) return raw;
  const next: Record<string, unknown> = { ...raw, [key]: value };
  const editorMeta = raw.__editor;
  if (editorMeta && typeof editorMeta === 'object' && !Array.isArray(editorMeta)) {
    const original = (editorMeta as Record<string, unknown>).original;
    if (original && typeof original === 'object' && !Array.isArray(original)) {
      next.__editor = {
        ...(editorMeta as Record<string, unknown>),
        original: { ...(original as Record<string, unknown>), [key]: persistValue },
      };
    }
  }
  return next;
}

/**
 * 페이지 설정 최상위 속성 로드/패치 훅.
 *
 * @return UsePageSettingsResult
 */
export function usePageSettings(): UsePageSettingsResult {
  const docCtx = useLayoutDocumentContext();
  const { state } = useLayoutEditor();
  const raw = (docCtx?.document?.raw as Record<string, unknown> | undefined) ?? null;
  const templateIdentifier = state.templateIdentifier ?? '';
  const layoutName = state.selectedRoute?.layoutName ?? '';

  // 최신 문서 — 폼은 칸 하나를 바꿀 때 "지금 문서의 meta 를 읽어 그 칸만 바꾼 meta" 를 patch 한다. 다국어 칸은
  // 평문 → `$t:custom.*` 키 생성이 서버 왕복(수 초) 뒤에 기록되므로, 그 기록이 그린 시점의 raw 를 읽으면 그사이 다른
  // 칸이 기록한 값을 옛 meta 로 덮어 지운다(먼저 적은 검색 제목이 뒤에 적은 검색 설명 기록에 지워지던 결함). 그래서
  // getValue 는 렌더 시점 raw 가 아니라 이 ref 를 읽고, patch 는 patchDocumentRaw 와 같은 규칙으로 ref 를 바로 갱신한다
  // (다시 그리기 전에 기록이 연달아 와도 서로 덮지 않는다). 문서가 바뀌면(새 raw) ref 를 그 raw 로 맞춘다.
  const liveRawRef = useRef<Record<string, unknown> | null>(raw);
  const seenRawRef = useRef<Record<string, unknown> | null>(raw);
  if (seenRawRef.current !== raw) {
    seenRawRef.current = raw;
    liveRawRef.current = raw;
  }

  // 식별자는 raw 가 바뀔 때마다 새로 만든다 — 폼의 useMemo/useCallback 이 getValue 를 의존성으로 다시 계산한다.
  const getValue = useCallback(
    <T = unknown>(key: string, fallback?: T): T => {
      const v = liveRawRef.current?.[key];
      return (v === undefined ? fallback : v) as T;
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [raw],
  );

  const patch = useCallback(
    (key: string, value: unknown, ...rest: [unknown?]): void => {
      if (!docCtx) return;
      // 화살표 함수는 자체 arguments 가 없으므로 rest 길이로 3번째 인자 유무를 판정한다.
      const hasOriginal = rest.length >= 1;
      liveRawRef.current = applyRawPatch(liveRawRef.current, key, value, hasOriginal ? rest[0] : value);
      if (hasOriginal) {
        docCtx.patchDocumentRaw(key, value, rest[0]);
      } else {
        docCtx.patchDocumentRaw(key, value);
      }
    },
    [docCtx],
  );

  const createI18nKey = useCallback(
    async (locale: string, value: string) => {
      if (!templateIdentifier || !layoutName) return null;
      return createCustomKey(templateIdentifier, layoutName, locale, value);
    },
    [templateIdentifier, layoutName],
  );

  const updateI18nKeyValue = useCallback(
    async (translationKey: string, locale: string, value: string) => {
      if (!templateIdentifier) return null;
      return updateCustomKeyValue(templateIdentifier, translationKey, locale, value);
    },
    [templateIdentifier],
  );

  return { raw, getValue, patch, createI18nKey, updateI18nKeyValue };
}
