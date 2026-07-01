/**
 * useEditorRoutes.ts
 *
 * 편집 대상 템플릿의 routes + modals + base 레이아웃 + 활성 모듈/플러그인 표시명
 * 을 fetch 하여 RouteTreeNode[] 로 빌드한 뒤 LayoutEditorContext 에 dispatch.
 *
 * 호출 위치: `LayoutEditorChrome` 마운트 직후 (useEffect).
 *
 * fetch 자원:
 * - `/api/templates/{templateIdentifier}/routes.json` — source 태깅된 routes
 * - `window.G7Config.activeModules` / `activePlugins` — 표시명 lookup (코어 부팅
 *  시점에 admin.blade.php / app.blade.php 가 주입한 메타)
 *
 * 모달/base 레이아웃은 본 Phase 에서는 빈 배열로 둔다(Phase 2 캔버스 로드 직후
 * 호스트 레이아웃에서 modals 를 수집하도록 인계).
 *
 * @since engine-v1.50.0
 */

import { useEffect } from 'react';
import { buildRouteTree, type RouteResponseItem } from './useRouteTree';
import { useLayoutEditor } from '../LayoutEditorContext';
import { useTranslation } from '../../TranslationContext';
import { createLogger } from '../../../utils/Logger';
import { DataBindingEngine } from '../../DataBindingEngine';
import {
  buildEditorAccessError,
  buildNetworkError,
  isEditorAccessError,
  type EditorAccessError,
} from '../types/editorErrors';
import { buildAuthHeaders } from '../utils/authToken';

const logger = createLogger('useEditorRoutes');

/** 백엔드 base 레이아웃 항목 (getEditorRoutesDataWithModules) */
interface BaseLayoutResponseItem {
  layout_name: string;
  label?: string | null;
}

/** 백엔드 인라인 모달 항목 (getEditorRoutesDataWithModules) */
interface ModalResponseItem {
  modal_id: string;
  host_layout: string;
  label?: string | null;
}

interface RoutesResponse {
  success: boolean;
  data?: {
    routes?: RouteResponseItem[];
    /** base 레이아웃 목록 — `[공통 레이아웃]` 트리 그룹 */
    base_layouts?: BaseLayoutResponseItem[];
    /** 인라인 모달 목록 — `[모달]` 트리 그룹 */
    modals?: ModalResponseItem[];
    /**
     * 레이아웃 이름 → 현재(최신) 저장 버전 번호.
     * 버전 이력이 있는 레이아웃만 포함(미저장 원본 = 키 없음 → 배지 미표시).
     */
    layout_versions?: Record<string, number>;
  };
}

/** 확장 항목 (layout-extensions 응답) */
interface ExtensionResponseItem {
  id: number;
  extension_type: 'extension_point' | 'overlay';
  target_name: string;
  priority?: number;
  is_active: boolean;
  is_modified: boolean;
  /** 호스트 레이아웃 목록 — 이 확장이 주입되는 레이아웃명들. */
  host_layouts?: string[];
  /**
   * 현재(최신) 저장 버전 번호.
   * 버전 이력이 없는(원본) 확장은 null.
   */
  current_version?: number | null;
}

/** 출처별 확장 그룹 (layout-extensions 응답 — 출처별 그룹핑) */
interface ExtensionGroupResponseItem {
  source_identifier: string;
  source_type: 'module' | 'plugin' | 'template';
  source_label: string;
  extensions: ExtensionResponseItem[];
}

interface ExtensionGroupsResponse {
  success: boolean;
  data?: ExtensionGroupResponseItem[];
}

interface ActiveExtensionMeta {
  identifier: string;
  /**
   * 백엔드(`CollectsActiveExtensionMeta::collectActiveModulesMeta`)는 `$module->getName()`
   * 을 그대로 노출하며, module.json/plugin.json 의 `name` 은 다국어 객체(`{ko,en,...}`)
   * 형태가 일반적이다. 따라서 본 키는 평문 string 또는 로케일 맵 둘 다 받을 수 있어야 한다.
   */
  display_name?: string | Record<string, string>;
  version?: string;
}

/**
 * 다국어 표시명 객체(`{ko, en, ...}`) 또는 평문 문자열 어느 쪽이든 받아
 * 단일 표시명 문자열로 정규화. 객체일 때 현재 로케일 → en → 첫 값 순으로 fallback.
 *
 * 빈 문자열/유효하지 않은 형식은 `null` 반환 — 호출자가 식별자로 폴백하도록.
 */
function normalizeDisplayName(
  value: string | Record<string, string> | undefined,
  locale: string
): string | null {
  if (typeof value === 'string') {
    return value.length > 0 ? value : null;
  }
  if (value && typeof value === 'object') {
    const localized = value[locale];
    if (typeof localized === 'string' && localized.length > 0) return localized;
    if (typeof value.en === 'string' && value.en.length > 0) return value.en;
    for (const v of Object.values(value)) {
      if (typeof v === 'string' && v.length > 0) return v;
    }
  }
  return null;
}

/**
 * window.G7Config.activeModules / activePlugins 에서 식별자 → 표시명 매핑 추출.
 *
 * 백엔드가 노출한 `display_name` 이 다국어 객체일 때 현재 로케일로 해석한다.
 * (표시명 객체가 그대로 `String(value)` 처리되어
 *  `[object Object]` 로 렌더되던 결함 수정)
 */
function readExtensionDisplayNames(
  key: 'activeModules' | 'activePlugins',
  locale: string
): Record<string, string> {
  const result: Record<string, string> = {};
  if (typeof window === 'undefined') return result;
  const config = (window as any).G7Config as Record<string, unknown> | undefined;
  const list = config?.[key];
  if (!Array.isArray(list)) return result;
  for (const item of list as ActiveExtensionMeta[]) {
    if (item && typeof item.identifier === 'string') {
      result[item.identifier] = normalizeDisplayName(item.display_name, locale) ?? item.identifier;
    }
  }
  return result;
}

/**
 * routes 응답의 path/redirect 내 `{{...}}` 표현식을 `window.G7Config` 기반
 * `_global` 컨텍스트로 치환한다.
 *
 * 정상 사이트 부팅(`TemplateApp.resolveRouteExpressions` — TemplateApp.ts:369)
 * 과 동등한 처리. 편집기는 TemplateApp.init() 을 거치지 않으므로 같은 단계를
 * 직접 수행해야 라우트 트리/URL 이 raw 표현식 노출되지 않는다.
 *
 * 컨텍스트 키: `settings` / `plugins` / `modules` / `appConfig` — admin.blade.php /
 * app.blade.php 가 `window.G7Config` 에 주입하는 4개 키. 메타(`activeModules`/
 * `activePlugins`/`moduleAssets`/`pluginAssets`)는 일반 라우트 표현식이 참조하지
 * 않으므로 제외.
 *
 * 치환 실패(표현식 평가 에러로 `{{...}}` 잔존) 시 정상 부팅과 동일하게 표현식을
 * 비워 fallback 처리한다.
 *
 * @since engine-v1.50.0
 */
function resolveEditorRouteExpressions(routes: RouteResponseItem[]): RouteResponseItem[] {
  const bindingEngine = new DataBindingEngine();
  const g7Config =
    typeof window !== 'undefined'
      ? ((window as any).G7Config as Record<string, unknown> | undefined)
      : undefined;
  const context = {
    _global: {
      settings: g7Config?.settings ?? {},
      plugins: g7Config?.plugins ?? {},
      modules: g7Config?.modules ?? {},
      appConfig: g7Config?.appConfig ?? {},
    },
  };

  return routes.map((route) => {
    const updated: RouteResponseItem = { ...route };

    if (typeof updated.path === 'string' && updated.path.includes('{{')) {
      const originalPath = updated.path;
      let resolved = bindingEngine.resolveBindings(updated.path, context) as string;
      // 빈 값 치환으로 발생할 수 있는 `//` 정리
      resolved = resolved.replace(/\/\/+/g, '/') || '/';
      if (resolved.includes('{{')) {
        logger.warn('편집기 route path 표현식 평가 실패 — fallback 적용', { originalPath });
        resolved = originalPath.replace(/\{\{[^}]+\}\}/g, '').replace(/\/\/+/g, '/') || '/';
      }
      updated.path = resolved;
    }

    if (typeof (updated as any).redirect === 'string' && (updated as any).redirect.includes('{{')) {
      const originalRedirect = (updated as any).redirect as string;
      let resolved = bindingEngine.resolveBindings(originalRedirect, context) as string;
      resolved = resolved.replace(/\/\/+/g, '/') || '/';
      if (resolved.includes('{{')) {
        logger.warn('편집기 route redirect 표현식 평가 실패 — fallback 적용', { originalRedirect });
        resolved = originalRedirect.replace(/\{\{[^}]+\}\}/g, '').replace(/\/\/+/g, '/') || '/';
      }
      (updated as any).redirect = resolved;
    }

    return updated;
  });
}

export interface UseEditorRoutesOptions {
  /** 편집 대상 템플릿 식별자 (부팅 템플릿과 다를 수 있음 — admin 부팅 + user 템플릿 편집) */
  templateIdentifier: string;
  /** 캐시 버전 — 변경 시 강제 재fetch. 미지정 시 window.G7Config.cache_version 사용 */
  cacheVersion?: number | string;
}

/**
 * 편집 대상 템플릿의 routes 를 fetch + RouteTreeNode[] 빌드 + dispatch.
 */
export function useEditorRoutes(options: UseEditorRoutesOptions): void {
  const { dispatch } = useLayoutEditor();
  const { translationEngine, translationContext } = useTranslation();
  const { templateIdentifier, cacheVersion } = options;

  useEffect(() => {
    let cancelled = false;

    const resolvedVersion =
      cacheVersion ??
      (typeof window !== 'undefined' ? (window as any).G7Config?.cache_version : undefined) ??
      '';
    const versionQuery = resolvedVersion !== '' ? `?v=${encodeURIComponent(String(resolvedVersion))}` : '';

    // 권한 가드된 편집기 전용 routes 엔드포인트 — `permission:admin,core.templates.
    // layouts.edit` 미들웨어 하에 있다(routes/api.php). 종전엔 공개(인증 불필요)
    // `/api/templates/{id}/routes.json` 을 호출해 세션 만료/비로그인 상태에서도 200 을
    // 받아, 편집기 진입 시점에 401/자동 로그아웃이 발동하지 않고 레이아웃 선택 전까지
    // 접근이 허용되는 결함이 있었다. 본 fetch 는 편집기 마운트 직후 가장
    // 먼저 실행되는 진입 fetch 이므로, 이를 가드 엔드포인트 + Bearer 토큰으로 전환하면
    // 미인증/권한부족이 진입 시점에 즉시 401/403 으로 감지되어 chrome(트리/캔버스)이
    // 렌더되기 전 AccessErrorPanel → 로그인 리다이렉트로 분기된다.
    const url = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/editor/routes.json${versionQuery}`;

    // 편집 대상 템플릿의 lang dictionary 도 함께 적재 — 라우트 meta.title 에 있는
    // `$t:user.*` 키가 부팅 템플릿(admin) lang 에는 없으므로 별도 로드 필요.
    // routes dispatch 는 lang 로드 완료 이후에 수행 — 그렇지 않으면 첫 렌더 시
    // dictionary 미존재로 라벨이 raw 키로 출력되고 이후 React 가 Map 변경을 감지
    // 못 해 re-render 도 일어나지 않음.
    const targetLocale = translationContext?.locale ?? 'ko';
    const targetLangPromise =
      translationEngine && templateIdentifier !== translationContext?.templateId
        ? translationEngine
            .loadTranslations(templateIdentifier, targetLocale)
            .then(() => {
              logger.log('편집 대상 lang 로드 완료', { targetTemplate: templateIdentifier, locale: targetLocale });
            })
            .catch((err) => {
              logger.warn('편집 대상 lang 로드 실패 — 라우트 라벨 fallback 사용', err);
            })
        : Promise.resolve();

    // fetch 가 환경에 정의되지 않거나 stub(undefined 반환)인 경우(테스트 jsdom 등) 즉시
    // reject 반환 — 셸 마운트 자체는 깨지지 않게 한다. !res.ok 경우는 HTTP status 를
    // 보존한 EditorAccessError 를 throw 하여 catch 블록이 AccessErrorPanel 로 노출.
    const routesPromise: Promise<RoutesResponse> = (() => {
      if (typeof fetch !== 'function') {
        return Promise.reject(buildNetworkError(new Error('fetch unavailable'), 'routes'));
      }
      try {
        const result = fetch(url, { credentials: 'same-origin', headers: buildAuthHeaders() });
        if (!result || typeof (result as Promise<unknown>).then !== 'function') {
          return Promise.reject(
            buildNetworkError(new Error('fetch returned non-Promise (test stub?)'), 'routes'),
          );
        }
        return (result as Promise<Response>).then(async (res) => {
          if (!res.ok) {
            // 401/403/5xx → 사용자에게 보여줄 풍성 안내 UI 로 분기되도록 status 보존.
            const body = await res.json().catch(() => null);
            throw buildEditorAccessError(res.status, body, 'routes');
          }
          return res.json() as Promise<RoutesResponse>;
        });
      } catch (err) {
        return Promise.reject(err);
      }
    })();

    // 확장 주입 목록 — `[확장 주입]` 트리 그룹. routes 와 별도
    // 엔드포인트. 실패해도 라우트 트리 자체는 떠야 하므로 비치명적(빈 배열 디그레이드).
    const extensionsUrl = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/layout-extensions${versionQuery}`;
    const extensionsPromise: Promise<ExtensionGroupsResponse | null> = (() => {
      if (typeof fetch !== 'function') return Promise.resolve(null);
      try {
        const result = fetch(extensionsUrl, { credentials: 'same-origin', headers: buildAuthHeaders() });
        if (!result || typeof (result as Promise<unknown>).then !== 'function') {
          return Promise.resolve(null);
        }
        return (result as Promise<Response>)
          .then(async (res) => (res.ok ? ((await res.json()) as ExtensionGroupsResponse) : null))
          .catch(() => null);
      } catch {
        return Promise.resolve(null);
      }
    })();

    Promise.all([routesPromise, targetLangPromise, extensionsPromise])
      .then(([body, , extensionsBody]) => {
        if (cancelled) return;
        if (!body || !body.success || !Array.isArray(body.data?.routes)) {
          logger.warn('routes 응답 형식이 비정상 — empty tree 로 둠', body);
          // 응답 200 이지만 형식 비정상도 사용자에게 노출 — 디버깅 가능하도록.
          dispatch({
            type: 'SET_ROUTES_ERROR',
            error: {
              kind: 'unknown',
              status: 200,
              message: 'routes 응답 형식이 비정상입니다.',
              source: 'routes',
            },
          });
          return;
        }

        // 정상 사이트 부팅(TemplateApp.resolveRouteExpressions) 과 동일하게 path/redirect
        // 의 `{{...}}` 표현식을 `window.G7Config` 기반 `_global` 컨텍스트로 평가.
        // 미평가 시 트리 노드 path 가 raw 표현식이라 다이렉트 진입 URL/pushState URL 이
        // 깨지는 결함이 발생한다.
        const routes = resolveEditorRouteExpressions(body.data!.routes!);
        const moduleDisplayNames = readExtensionDisplayNames('activeModules', targetLocale);
        const pluginDisplayNames = readExtensionDisplayNames('activePlugins', targetLocale);

        // base 레이아웃 / 인라인 모달 — 백엔드 응답(snake_case)을 트리 입력(camelCase)으로 매핑.
        // 응답 누락 시(구버전 백엔드/형식 비정상) 빈 배열로 디그레이드 — 라우트 그룹은 정상 표시.
        const baseLayouts = (body.data!.base_layouts ?? []).map((b) => ({
          layoutName: b.layout_name,
          label: b.label ?? undefined,
        }));
        const modals = (body.data!.modals ?? []).map((m) => ({
          modalId: m.modal_id,
          hostLayout: m.host_layout,
          label: m.label ?? undefined,
        }));

        // 확장 주입 그룹 — 비치명적 fetch 결과(snake_case)를 트리 입력(camelCase)으로 매핑.
        // null(실패/구버전) 이면 빈 배열 → `[확장 주입]` 그룹 미표시 디그레이드.
        const extensionGroups = (extensionsBody?.success && Array.isArray(extensionsBody.data)
          ? extensionsBody.data
          : []
        ).map((g) => ({
          sourceIdentifier: g.source_identifier,
          sourceType: g.source_type,
          sourceLabel: g.source_label,
          extensions: (g.extensions ?? []).map((e) => ({
            id: e.id,
            extensionType: e.extension_type,
            targetName: e.target_name,
            priority: e.priority,
            isActive: e.is_active,
            isModified: e.is_modified,
            // 호스트 레이아웃 목록 — 라우트 layoutName 매칭으로 화면별 연결 확장을
            // 클릭(캔버스 로드) 없이 정적 부착하기 위한 키.
            hostLayouts: Array.isArray(e.host_layouts) ? e.host_layouts : [],
            // 현재 저장 버전 — 확장 노드 버전 배지 수집용.
            currentVersion: typeof e.current_version === 'number' ? e.current_version : undefined,
          })),
        }));

        const tree = buildRouteTree({
          routes,
          modals,
          baseLayouts,
          extensionGroups,
          moduleDisplayNames,
          pluginDisplayNames,
        });

        dispatch({ type: 'SET_ROUTE_TREE', tree });

        // 레이아웃별 현재 저장 버전 맵 — 라우트 트리 버전 배지 데이터.
        // 응답 누락(구버전 백엔드)/형식 비정상이면 빈 맵으로 디그레이드(배지만 미표시).
        const rawVersions = body.data!.layout_versions;
        const layoutVersions: Record<string, number> = {};
        if (rawVersions && typeof rawVersions === 'object' && !Array.isArray(rawVersions)) {
          for (const [name, version] of Object.entries(rawVersions)) {
            if (typeof version === 'number' && Number.isFinite(version)) {
              layoutVersions[name] = version;
            }
          }
        }
        dispatch({ type: 'SET_LAYOUT_VERSIONS', versions: layoutVersions });

        // 확장별 현재 저장 버전 맵 — 확장 노드 버전 배지 데이터.
        // layout-extensions 응답의 확장별 current_version 에서 수집(숫자만 채택 — 이력
        // 없는 확장의 null/구버전 백엔드 누락은 자연 제외 → 배지 미표시).
        const extensionVersions: Record<string, number> = {};
        for (const group of extensionGroups) {
          for (const ext of group.extensions) {
            const version = (ext as { currentVersion?: unknown }).currentVersion;
            if (typeof version === 'number' && Number.isFinite(version)) {
              extensionVersions[String(ext.id)] = version;
            }
          }
        }
        dispatch({ type: 'SET_EXTENSION_VERSIONS', versions: extensionVersions });

        logger.log('routes 로드 완료', { count: routes.length, groups: tree.length });
      })
      .catch((err) => {
        if (cancelled) return;
        logger.error('routes 로드 실패', err);
        // EditorAccessError 면 그대로, 그 외 (jsdom fetch unavailable 등) 은 network 로 wrap.
        const error: EditorAccessError =
          isEditorAccessError(err) ? err : buildNetworkError(err, 'routes');
        dispatch({ type: 'SET_ROUTES_ERROR', error });
      });

    return () => {
      cancelled = true;
    };
  }, [templateIdentifier, cacheVersion, dispatch, translationEngine, translationContext?.locale, translationContext?.templateId]);
}
