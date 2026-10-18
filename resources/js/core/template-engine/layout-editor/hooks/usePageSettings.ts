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

import { useCallback } from 'react';
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

  const getValue = useCallback(
    <T = unknown>(key: string, fallback?: T): T => {
      const v = raw?.[key];
      return (v === undefined ? fallback : v) as T;
    },
    [raw],
  );

  const patch = useCallback(
    (key: string, value: unknown, ...rest: [unknown?]): void => {
      if (!docCtx) return;
      // 화살표 함수는 자체 arguments 가 없으므로 rest 길이로 3번째 인자 유무를 판정한다.
      if (rest.length >= 1) {
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
