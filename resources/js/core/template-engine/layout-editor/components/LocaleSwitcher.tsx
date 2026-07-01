/**
 * LocaleSwitcher.tsx — 콘텐츠 로케일 전환
 *
 * 편집 대상 콘텐츠(캔버스 프리뷰)의 로케일만 바꾼다. 편집기 chrome(툴바·메뉴·모달)의
 * 표시 언어는 관리자 UI 로케일로 고정되며 이 전환에 영향받지 않는다.
 *
 * 로케일 목록 = `config('app.supported_locales')` + 활성 언어팩 로케일의 합집합 —
 * 백엔드가 `window.G7Config.appConfig.supportedLocales` 로 이미 그 합집합을 주입한다
 * (`SettingsService::getAppConfigForFrontend` → `LanguagePackService::getActiveLocales`).
 * 별도 fetch 없이 그 값을 읽는다(노출 범위 추가 없음). 미주입 시 현재 로케일만.
 *
 * 전환 시 `SET_LOCALE` dispatch → useEditorTemplateAssets 가 새 로케일 사전을 재로드 →
 * PreviewCanvas 가 새 로케일로 재렌더. chrome 의 `useTranslation` 컨텍스트는 불변.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만. 모든 문자열은 `$t:layout_editor.*`.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect } from 'react';
import { useTranslation } from '../../TranslationContext';
import { useLayoutEditor } from '../LayoutEditorContext';
import { trackEditorI18n } from '../devtools/editorTrackers';

/**
 * 로케일 표시 라벨 — `layout_editor.locale.{code}` 키. 미정 시 코드 대문자 폴백.
 * LocaleSwitcher 와 TranslationField(번역 탭)가 공유한다.
 *
 * @param code 로케일 코드 (`ko`/`en`/`ja` 등)
 * @param t 다국어 해석기
 * @return 표시 라벨
 */
export function localeDisplayLabel(
  code: string,
  t: (key: string) => string,
): string {
  const key = `layout_editor.locale.${code}`;
  const resolved = t(key);
  return resolved && resolved !== key ? resolved : code.toUpperCase();
}

/**
 * 활성 콘텐츠 로케일 목록을 G7Config 에서 읽는다. 미주입(SSR/테스트) 시 빈 배열.
 *
 * @return 활성 로케일 코드 배열
 */
export function readSupportedLocales(): string[] {
  if (typeof window === 'undefined') return [];
  const cfg = (window as unknown as {
    G7Config?: { appConfig?: { supportedLocales?: unknown } };
  }).G7Config;
  const list = cfg?.appConfig?.supportedLocales;
  if (Array.isArray(list)) {
    return list.filter((l): l is string => typeof l === 'string' && l.length > 0);
  }
  return [];
}

export interface LocaleSwitcherProps {
  /** 로케일 목록 주입(테스트용). 미전달 시 G7Config 에서 읽음. */
  locales?: string[];
}

export function LocaleSwitcher({ locales }: LocaleSwitcherProps = {}): React.ReactElement | null {
  const { t } = useTranslation();
  const { state, dispatch } = useLayoutEditor();

  // 현재 콘텐츠 로케일을 window 에 노출 — useInlineEdit 의 bustTranslationCache 가
  // cache-bust 재로드 대상 로케일을 알 수 있게 한다(전역 단일 캔버스 기준).
  useEffect(() => {
    (window as unknown as { __g7EditorContentLocale?: string }).__g7EditorContentLocale =
      state.locale;
  }, [state.locale]);

  const available = locales ?? readSupportedLocales();
  // 현재 로케일이 목록에 없으면(언어팩 비활성 등) 앞에 추가해 항상 선택 가능하게.
  const options = available.includes(state.locale)
    ? available
    : [state.locale, ...available];

  // 로케일이 1개뿐이면 전환 UI 불필요 — 라벨만 표시(전환 토글 부재).
  if (options.length <= 1) {
    return (
      <span
        className="g7le-locale-switcher g7le-locale-switcher--single"
        data-testid="g7le-locale-switcher-single"
        style={{ fontSize: 11, color: '#475569', padding: '0 4px' }}
      >
        {t('layout_editor.chrome.toolbar.locale_switcher_label')}: {localeDisplayLabel(state.locale, t)}
      </span>
    );
  }

  const handleSwitch = (next: string): void => {
    if (next === state.locale) return;
    const from = state.locale;
    dispatch({ type: 'SET_LOCALE', locale: next });
    trackEditorI18n({
      op: 'locale_switch',
      fromLocale: from,
      toLocale: next,
      timestamp: Date.now(),
    });
  };

  return (
    <div
      className="g7le-locale-switcher"
      data-testid="g7le-locale-switcher"
      role="group"
      aria-label={t('layout_editor.chrome.toolbar.locale_switcher_label')}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
    >
      <span style={{ fontSize: 11, color: '#475569' }}>
        {t('layout_editor.chrome.toolbar.locale_switcher_label')}
      </span>
      <div style={{ display: 'inline-flex', gap: 2 }}>
        {options.map((code) => {
          const active = code === state.locale;
          return (
            <button
              key={code}
              type="button"
              data-testid={`g7le-locale-${code}`}
              aria-pressed={active}
              onClick={() => handleSwitch(code)}
              style={{
                padding: '2px 8px',
                fontSize: 12,
                cursor: 'pointer',
                background: active ? '#0f172a' : '#f8fafc',
                color: active ? '#fff' : '#0f172a',
                border: '1px solid #cbd5e1',
                borderRadius: 4,
              }}
            >
              {localeDisplayLabel(code, t)}
            </button>
          );
        })}
      </div>
    </div>
  );
}
