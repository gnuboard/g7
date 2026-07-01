// E2E: tests/Playwright/specs/layout-editor/prop-i18n-text-field.spec.ts (부록7 7-b — 공통 SSoT 키 생성/갱신 경로).
// e2e:allow 평문/키 생성 경로는 위 Playwright spec 으로 커버하나-param
// 칩 분류(classifyCustomText paramKey)는 contentEditable/합성 PointerEvent 칩 입력기로 라우팅돼 Playwright
// 부적합 — Chrome MCP 매트릭스 + 단위(prop-i18n-text-field.test classify 5건)로 검증(정책).
/**
 * useCustomTranslation.ts — 텍스트 ↔ `$t:custom.*` 동적 다국어 공통 SSoT
 *
 * 인라인 편집(useInlineEdit)·목록 항목 텍스트(ChildrenListControl)·속성 패널 텍스트
 * propControl(I18nTextField)·data_sources label_key 가 **동일한** 커스텀 키 생성/수정
 * 모델을 공유하도록, `createCustomKey`/`updateCustomKeyValue`/`findCustomKeyRow` 위에
 * 한 줄짜리 "텍스트 값 커밋" 연산을 얹어 단일 진입점으로 노출한다(별도 저장소/엔드포인트
 * 신설 금지 — 부록7 §위험/주의 "모델 단일 SSoT").
 *
 * 동작:
 *  - 현재값이 `$t:custom.*` 키면 → 현재 로케일 값만 PUT(`updateCustomKeyValue`).
 *  - 현재값이 평문(또는 임의 `$t:` 키)이면 → POST 로 새 커스텀 키 생성 후 그 토큰
 *    문자열(`$t:custom....`)을 반환(호출자가 prop/노드 값에 기록).
 *  - `{{...}}` 바인딩식이면 → blocked(다국어 대상 아님 — 부록6 가드 정합).
 *
 * 본 모듈은 **값(문자열) 만** 다룬다(노드 패치는 호출자 책임). 그래서 prop 값 토큰·노드
 * text·목록 항목 자손 text·label_key 어디에 기록하든 같은 SSoT 를 공유한다.
 *
 * @since engine-v1.50.0
 */

import { useCallback } from 'react';
import { useLayoutEditor } from '../LayoutEditorContext';
import {
  createCustomKey,
  updateCustomKeyValue,
  bustTranslationCache,
} from './useInlineEdit';
import { TranslationEngine } from '../../TranslationEngine';
import { extractParamBindings } from '../spec/inlineBindingUtils';

/** `$t:custom.*` 단일 키 토큰 판정(앞뒤 공백 허용). */
const CUSTOM_KEY_RE = /^\s*\$t:(custom\.[a-zA-Z0-9._-]+)\s*$/;
/** 임의 `$t:` 키 토큰 판정. */
const ANY_T_KEY_RE = /^\s*\$t:([a-zA-Z0-9._-]+)\s*$/;
/** `{{...}}` 바인딩식 — 다국어 대상 아님. */
const BINDING_RE = /\{\{.*?\}\}/;

/** 텍스트 값 분류 결과(노드 비종속 — 순수 문자열 기준). */
export interface CustomTextClassification {
  /** `$t:custom.*` 키면 그 키(접두 `$t:` 제외), 아니면 null */
  customKey: string | null;
  /** `{{...}}` 바인딩식이면 true(편집 비대상) */
  binding: boolean;
  /**
   * param 정규화된 **custom** 키(`$t:custom.X|pN={{}}`)면 true.
   * 종전엔 이 형태가 `BINDING_RE`(`|pN={{}}` 안의 `{{...}}`)에 먼저 걸려 `binding:true` 로 오분류돼
   * I18nTextField 가 raw `$t:custom.X|count={{...}}` 를 읽기전용 코드 배지로 노출했다(스크린샷
   * `$t:auth.register.password_placeholder|count=8`). param 키는 다국어 **편집 대상**(키 값의 `{pN}`
   * 자리표시 문장을 칩/평문으로 편집)이므로, binding 보다 **먼저** 분기해 칩 입력기로 라우팅한다.
   * `customKey` 에 그 custom 키를 채운다(파라미터 부착 제외). lang named-param(`$t:user.*|count={{}}`)은
   * custom 키가 아니므로 본 분기 대상이 아니다(키화 전 — 평문/raw 경로로 폴백).
   */
  paramKey: boolean;
  /** 현재 로케일 미리보기 값 — 평문이면 그대로, 키면 해석값(미해석 시 빈 문자열) */
  displayValue: string;
}

/**
 * 문자열 값을 분류한다(노드 비종속 순수 함수). prop 값/노드 text/항목 text/label_key 공용.
 *
 * @param raw 분류할 문자열(undefined/null 허용)
 * @param translate 키 → 현재 로케일 해석기(없으면 빈 미리보기)
 * @return 분류 결과
 */
export function classifyCustomText(
  raw: string | null | undefined,
  translate?: (key: string) => string,
): CustomTextClassification {
  const value = typeof raw === 'string' ? raw : '';

  const customMatch = CUSTOM_KEY_RE.exec(value);
  if (customMatch) {
    const key = customMatch[1]!;
    const display = translate ? translate(key) : '';
    return { customKey: key, binding: false, paramKey: false, displayValue: display };
  }

  // param 정규화된 custom 키(`$t:custom.X|pN={{}}`) — `{{...}}` 바인딩 검사보다 **먼저**
  // 분기한다(param 값 보간이 BINDING_RE 에 걸려 binding 으로 오분류되는 것을 차단). 키는 다국어
  // **편집 대상**(키 값의 `{pN}` 자리표시 문장을 칩/평문으로 편집)이므로 customKey 를 채워 칩 입력기로
  // 라우팅한다. lang named-param(`$t:user.*|count={{}}`)은 `custom.` 접두가 아니라 본 분기에서 제외
  // (키화 전 — 아래 평문/raw 폴백 경로)..
  const paramized = extractParamBindings(value);
  if (paramized && paramized.key.startsWith('custom.')) {
    const display = translate ? translate(paramized.key) : '';
    return { customKey: paramized.key, binding: false, paramKey: true, displayValue: display };
  }

  if (BINDING_RE.test(value)) {
    return { customKey: null, binding: true, paramKey: false, displayValue: value };
  }

  // 임의 `$t:` 키(템플릿/언어팩) → 평문과 동격으로 편집(인라인 편집 규칙과 동일). 시작값은
  // 키의 현재 로케일 해석값(raw 키 노출 회피 — 미해석 시 빈 문자열).
  const anyKeyMatch = ANY_T_KEY_RE.exec(value);
  if (anyKeyMatch) {
    const key = anyKeyMatch[1]!;
    const display = translate ? translate(key) : '';
    return { customKey: null, binding: false, paramKey: false, displayValue: display && display !== key ? display : '' };
  }

  return { customKey: null, binding: false, paramKey: false, displayValue: value };
}

/** 텍스트 커밋 결과. */
export interface CustomTextCommitResult {
  kind: 'created' | 'updated' | 'noop' | 'blocked' | 'error';
  /** 생성/갱신된 커스텀 키(접두 `$t:` 제외) */
  customKey?: string;
  /** created 시 호출자가 값에 기록할 토큰(`$t:custom....`) */
  token?: string;
  /** error 시 메시지 */
  message?: string;
}

export interface UseCustomTranslationResult {
  /** 활성 콘텐츠 로케일(미리보기/편집 대상 언어) */
  locale: string;
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 키 → 현재 로케일 해석(미해석 시 빈 문자열) */
  translate: (key: string) => string;
  /** 문자열 분류(미리보기/키 인지) */
  classify: (raw: string | null | undefined) => CustomTextClassification;
  /**
   * 평문/키 텍스트를 현재 로케일 값으로 커밋한다.
   *  - 기존 커스텀 키: 그 키의 현재 로케일 값만 PUT(token 무변경).
   *  - 그 외: POST 로 키 생성 후 `token`(`$t:custom....`) 반환 — 호출자가 값에 기록.
   *
   * @param current 현재 값(prop 값/노드 text/label_key)
   * @param nextValue 사용자가 입력한 새 평문(현재 로케일)
   * @return 커밋 결과
   */
  commitText: (current: string | null | undefined, nextValue: string) => Promise<CustomTextCommitResult>;
}

/**
 * 동적 다국어 텍스트 공통 hook. 인라인/목록/propControl/label_key 가 공유한다.
 */
export function useCustomTranslation(): UseCustomTranslationResult {
  const { state } = useLayoutEditor();
  const templateIdentifier = state.templateIdentifier;
  const locale = state.locale;
  const layoutName = state.selectedRoute?.layoutName ?? null;

  const translate = useCallback(
    (key: string): string => {
      try {
        const engine = TranslationEngine.getInstance();
        const resolved = engine.translate(key, { templateId: templateIdentifier, locale });
        return resolved && resolved !== key ? resolved : '';
      } catch {
        return '';
      }
    },
    [templateIdentifier, locale],
  );

  const classify = useCallback(
    (raw: string | null | undefined): CustomTextClassification => classifyCustomText(raw, translate),
    [translate],
  );

  const commitText = useCallback(
    async (current: string | null | undefined, nextValue: string): Promise<CustomTextCommitResult> => {
      const cls = classifyCustomText(current, translate);
      if (cls.binding) {
        return { kind: 'blocked' };
      }

      // (1) 기존 커스텀 키 → 현재 로케일 값만 PUT.
      if (cls.customKey) {
        if (nextValue === cls.displayValue) {
          return { kind: 'noop', customKey: cls.customKey };
        }
        const result = await updateCustomKeyValue(templateIdentifier, cls.customKey, locale, nextValue);
        if (result.kind === 'error') {
          return { kind: 'error', message: result.message };
        }
        await bustTranslationCache(templateIdentifier, locale);
        return { kind: 'updated', customKey: cls.customKey };
      }

      // (2) 평문/임의 키 → 새 커스텀 키 생성 후 토큰 반환.
      const created = await createCustomKey(templateIdentifier, layoutName, locale, nextValue);
      if (created.kind === 'error' || !created.resource) {
        return { kind: 'error', message: created.message };
      }
      const key = created.resource.translation_key;
      await bustTranslationCache(templateIdentifier, locale);
      return { kind: 'created', customKey: key, token: `$t:${key}` };
    },
    [templateIdentifier, locale, layoutName, translate],
  );

  return { locale, templateIdentifier, translate, classify, commitText };
}
