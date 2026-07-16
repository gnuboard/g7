/**
 * useEditorTemplateAssets.ts — 편집 대상 템플릿의 자산 부트로더
 *
 * 편집기 진입 시 호스트 페이지(`/admin/...`)에는 호스트 템플릿의 ComponentRegistry/
 * TranslationEngine 만 로드되어 있다. 편집 대상 템플릿(예: `sirsoft-basic`)을
 * 캔버스에 렌더하려면 그 템플릿의 IIFE 번들 + lang dictionary 를 동적으로
 * 로드해 격리 인스턴스에 등록해야 한다.
 *
 * 본 hook 은:
 *  1. `/api/admin/templates/{identifier}/editor-assets` 로 IIFE/CSS URL 조회
 *  2. <script>/<link> 동적 주입 (이미 로드된 자산은 중복 주입 회피)
 *  3. 격리 ComponentRegistry 인스턴스 생성 + `loadComponents()` 호출
 *  4. 독립 TranslationEngine 인스턴스 생성 + `loadTranslations()` 호출
 *
 * 반환값:
 *  - `componentRegistry` / `translationEngine` — PreviewCanvas 가 DynamicRenderer
 *    에 주입하는 격리 인스턴스
 *  - `isReady` — 모든 자산 로드 완료 여부
 *  - `error` — 로드 실패 메시지
 *
 * @since engine-v1.50.0
 */

import { useEffect, useState } from 'react';
import { ComponentRegistry } from '../../ComponentRegistry';
import { TranslationEngine } from '../../TranslationEngine';
import {
  buildEditorAccessError,
  buildNetworkError,
  isEditorAccessError,
  type EditorAccessError,
} from '../types/editorErrors';
import { reseedPendingIntoEngine } from './pendingCustomTranslations';

export interface EditorTemplateAssetsState {
  componentRegistry: ComponentRegistry | null;
  translationEngine: TranslationEngine | null;
  isReady: boolean;
  /** 자산 로드 실패 (null = 정상) — AccessErrorPanel 이 구조화 분기 렌더 */
  error: EditorAccessError | null;
}

interface EditorAssetsManifest {
  identifier: string;
  js: string[];
  css: string[];
  manifest_present: boolean;
}

/**
 * 전역 `window` 에 대상 IIFE 번들 변수가 노출됐는지 확인.
 * ComponentRegistry.loadComponentBundle 의 동작과 동일한 변수명 규칙.
 */
function getGlobalVarName(templateId: string): string {
  return templateId
    .split(/[-_]/)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join('');
}

/**
 * 자산 매니페스트 fetch — admin 권한 가드 통과 필요.
 *
 * 실패 시 호출자가 status 별 분기 처리 가능하도록 EditorAccessError 를 throw.
 */
async function fetchEditorAssets(identifier: string): Promise<EditorAssetsManifest> {
  const token =
    typeof window !== 'undefined' ? window.localStorage?.getItem('auth_token') : null;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  const response = await fetch(
    `/api/admin/templates/${encodeURIComponent(identifier)}/editor-assets`,
    { headers, credentials: 'same-origin' },
  );
  if (!response.ok) {
    const body = await response.json().catch(() => null);
    // throw 한 객체를 catch 블록이 그대로 setState 한다 (kind/status/메시지 보존).
    throw buildEditorAccessError(response.status, body, 'assets');
  }
  const body = await response.json();
  return (body.data || body) as EditorAssetsManifest;
}

/**
 * 편집 대상 템플릿의 최신 `cache_version` 을 config.json(비캐시)에서 읽는다.
 *
 * `serveLanguage` 는 `template.language.{id}.{locale}.v{cacheVersion}` 키로 HTTP 캐시되므로,
 * 독립 TranslationEngine 이 버전 없이 `lang/{locale}.json` 을 fetch 하면 신규 다국어 키가
 * 누락된 옛 스냅샷을 받는다. config.json 은 비캐시라 항상 최신 버전을 노출한다 —
 * 그 버전을 엔진에 주입해 신선한 lang 을 받게 한다. 조회 실패 시 0(버전 없음 — 기존 동작).
 *
 * @param identifier 편집 대상 템플릿 식별자
 * @return 최신 cache_version (실패 시 0)
 */
async function fetchLatestCacheVersion(identifier: string): Promise<number> {
  try {
    if (typeof fetch !== 'function') return 0;
    const res = await fetch(
      `/api/templates/${encodeURIComponent(identifier)}/config.json`,
      { credentials: 'same-origin', headers: { Accept: 'application/json' } },
    );
    if (!res.ok) return 0;
    const cfg = await res.json().catch(() => null);
    const v = cfg?.data?.cache_version ?? cfg?.cache_version;
    return typeof v === 'number' && v > 0 ? v : 0;
  } catch {
    return 0;
  }
}

/**
 * <script src="..."> 를 head 에 주입하고 로드 완료를 기다린다.
 * 이미 같은 src 가 등록되어 있으면 그 기존 태그를 재사용.
 */
function injectScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (typeof document === 'undefined') {
      reject(new Error('document undefined'));
      return;
    }
    const existing = document.querySelector(`script[data-g7le-asset="${src}"]`);
    if (existing) {
      // 이미 로드된 상태로 간주 (실패한 경우라도 재시도는 페이지 새로고침으로)
      resolve();
      return;
    }
    const script = document.createElement('script');
    script.src = src;
    script.async = false;
    script.setAttribute('data-g7le-asset', src);
    script.addEventListener('load', () => resolve());
    script.addEventListener('error', () => reject(new Error(`script load failed: ${src}`)));
    document.head.appendChild(script);
  });
}

/**
 * 편집기 CSS 주입. 권한 가드된 admin 엔드포인트(`/api/admin/...`)는 `<link>` 태그가
 * Authorization 헤더를 못 실어 401/리다이렉트(500)로 떨어지므로, Bearer 토큰을 실은
 * fetch 로 텍스트를 받아 `<style>` 로 주입한다(다크 격리 CSS 가
 * 편집 권한 가드 엔드포인트로 이동). 공개 자산(`/api/templates/assets/...`)이나 외부
 * URL 은 종전대로 `<link>` 로 주입(인증 불필요).
 *
 * @param href CSS URL
 */
async function injectStylesheet(href: string): Promise<void> {
  if (typeof document === 'undefined') return;
  const marker = `[data-g7le-asset="${href}"]`;
  if (document.querySelector(`link${marker}, style${marker}`)) return;

  // 권한 가드된 admin CSS 엔드포인트는 Bearer fetch → <style> 주입
  const isGuardedAdminCss = href.includes('/api/admin/');
  if (isGuardedAdminCss) {
    try {
      const token =
        typeof window !== 'undefined' ? window.localStorage?.getItem('auth_token') : null;
      const headers: Record<string, string> = { Accept: 'text/css' };
      if (token) headers.Authorization = `Bearer ${token}`;
      const res = await fetch(href, { headers, credentials: 'same-origin' });
      if (res.ok) {
        const css = await res.text();
        const style = document.createElement('style');
        style.textContent = css;
        style.setAttribute('data-g7le-asset', href);
        document.head.appendChild(style);
        return;
      }
      // 실패 시 <link> 폴백(공개 변종이 가능하면 동작)
    } catch {
      // 네트워크 실패 → <link> 폴백
    }
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.setAttribute('data-g7le-asset', href);
  document.head.appendChild(link);
}

/**
 * 대상 템플릿의 자산을 격리 인스턴스에 부트스트랩.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param locale 컨텐츠 로케일 (lang dictionary)
 * @param templateType admin 또는 user (Phase 1 의 셸은 user 가 기본 — admin 편집 시 변경 가능)
 */
export function useEditorTemplateAssets(
  templateIdentifier: string,
  locale: string,
  templateType: 'admin' | 'user' = 'user',
): EditorTemplateAssetsState {
  const [state, setState] = useState<EditorTemplateAssetsState>({
    componentRegistry: null,
    translationEngine: null,
    isReady: false,
    error: null,
  });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setState((prev) => ({ ...prev, isReady: false, error: null }));

      try {
        // 1. 자산 매니페스트 fetch
        const manifest = await fetchEditorAssets(templateIdentifier);
        if (cancelled) return;

        // 2. CSS 주입 — 권한 가드된 admin CSS 는 Bearer fetch→<style>(다크 격리),
        //    공개/외부 URL 은 <link>. 가드 CSS fetch 가 캔버스 다크 스타일을 좌우하므로 await.
        for (const href of manifest.css ?? []) {
          await injectStylesheet(href);
          if (cancelled) return;
        }

        // 3. IIFE 번들 주입 — 이미 호스트 페이지가 같은 템플릿을 로드한 경우
        //    (예: admin 화면이 같은 admin 템플릿을 편집할 때) 전역 변수가 이미
        //    있으므로 추가 주입 없이 통과.
        const globalVarName = getGlobalVarName(templateIdentifier);
        const alreadyLoaded =
          typeof window !== 'undefined' && typeof (window as any)[globalVarName] === 'object';
        if (!alreadyLoaded) {
          for (const src of manifest.js ?? []) {
            await injectScript(src);
            if (cancelled) return;
          }
        }

        // 4. 격리 ComponentRegistry 인스턴스 + loadComponents
        const componentRegistry = ComponentRegistry.createIsolatedInstance();
        await componentRegistry.loadComponents(templateIdentifier, templateType);
        if (cancelled) return;

        // 5. 독립 TranslationEngine 인스턴스 + loadTranslations
        //
        // 캐시 버전 동기 — 독립 엔진은 cacheVersion 기본값 0 이라
        // `loadTranslations` 가 버전 파라미터 없이 `lang/{locale}.json` 을 fetch 한다.
        // 서버 `serveLanguage` 는 `template.language.{id}.{locale}.v{cacheVersion}` 키로
        // HTTP 캐시하므로, 버전 없는(또는 stale 버전) 요청은 **신규 다국어 키가 누락된
        // 옛 스냅샷**을 받는다(편집기 진입 초기 로드 경로엔 cacheVersion 주입이 없어
        // editor-spec 라벨/formErrors 의 신규 `editor.*` 키가 raw 로 표시되던 결함).
        // config.json(비캐시)이 노출하는 최신 cache_version 을 읽어 fetch 전에 주입한다.
        // (useInlineEdit.bustTranslationCache 와 동일 SSoT — 초기 로드로 확장 적용.)
        const cacheVersion = await fetchLatestCacheVersion(templateIdentifier);
        if (cancelled) return;
        const translationEngine = new TranslationEngine({ defaultLocale: locale });
        if (cacheVersion > 0) translationEngine.setCacheVersion(cacheVersion);
        await translationEngine.loadTranslations(templateIdentifier, locale);
        if (cancelled) return;
        // 콘텐츠 언어 전환으로 사전을 새로 로드하면 저장-지연 버퍼(pending)로 seed 했던 키 값이
        // 서버 스냅샷으로 덮어써진다. 버퍼를 다시 주입해 레이아웃 [저장] 전에도 전 로케일에서
        // 새 키 값(`{pN}`)이 캔버스에 반영되게 한다.
        reseedPendingIntoEngine(translationEngine, templateIdentifier);

        // 5-b. 호스트 TranslationEngine 싱글톤에도 대상 템플릿 사전을 누적 로드 —
        //      DynamicRenderer 의 `resolveTranslationsDeep` 가 호스트 엔진 인스턴스
        //      (`TranslationEngine.getInstance()`)를 통해 `$t:board.write` 같은
        //      표현식 결과 토큰을 해석할 수 있도록 보장. 일반 사이트 렌더 시 호스트
        //      엔진은 자기 템플릿(admin) 사전만 가지므로 편집 대상 사전이 누적되어도
        //      cacheKey(`{templateId}:{locale}`)가 분리되어 호스트 admin 사전 조회에
        //  영향 없음. (라우트 10/12 글쓰기/글 수정 h1
        //      "board.write" raw 키 노출 결함 수정)
        try {
          const hostEngine = TranslationEngine.getInstance();
          // 호스트 엔진도 동일 최신 버전으로 — TemplateApp 초기화 시점의 버전이 stale 일 수
          // 있어(편집기 진입 후 키 추가), 누적 로드 전에 최신 버전을 보장한다.
          if (cacheVersion > hostEngine.getCacheVersion()) {
            hostEngine.setCacheVersion(cacheVersion);
          }
          await hostEngine.loadTranslations(templateIdentifier, locale);
          // 호스트 싱글톤(DynamicRenderer 가 $t: 토큰 해석에 사용)에도 pending 재주입 — 격리 엔진과
          // 동일하게 언어 전환 후 저장 전 키 값을 라이브 반영.
          reseedPendingIntoEngine(hostEngine, templateIdentifier);
        } catch {
          // 호스트 엔진 사전 누적 실패는 격리 인스턴스만으로 폴백
        }
        if (cancelled) return;

        setState({
          componentRegistry,
          translationEngine,
          isReady: true,
          error: null,
        });
      } catch (err: unknown) {
        if (cancelled) return;
        // fetchEditorAssets 가 throw 한 EditorAccessError 는 그대로 전달.
        // 그 외(스크립트 로드 실패, ComponentRegistry/TranslationEngine 예외) 는
        // network 종류로 wrap — kind 별 안내 문구를 분기할 수 있도록 보장.
        const error: EditorAccessError =
          isEditorAccessError(err) ? err : buildNetworkError(err, 'assets');
        setState({
          componentRegistry: null,
          translationEngine: null,
          isReady: false,
          error,
        });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier, locale, templateType]);

  return state;
}
