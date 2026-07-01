/**
 * useEditorMode.ts
 *
 * 편집 모드 / 대상 템플릿 식별자 파라미터 추출 — URL 파싱.
 *
 * URL 패턴: `/admin/layout-editor/{templateIdentifier}` (선택) `?route={path}`
 *
 * 본 hook 은 클라이언트 사이드 라우팅이 진입 직후 부팅 시점에 호출된다.
 * URL 변화 추적은 Phase 6 의 LocaleSwitcher 와 별개로 본 hook 은 단발성
 * (현재 URL 만 1회 파싱).
 *
 * @since engine-v1.50.0
 */

export interface EditorModeParams {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 초기 라우트 path (`?route=` 쿼리 파라미터) — 없으면 null */
  initialRoutePath: string | null;
}

/** URL 패턴: /admin/layout-editor/{identifier} */
const LAYOUT_EDITOR_URL_RE = /^\/admin\/layout-editor\/([^/?#]+)\/?$/;

/**
 * 현재 URL 이 레이아웃 편집기 모드인지 판정.
 *
 * `template-engine.ts` 의 `renderTemplate()` 분기에서 호출.
 *
 * @param pathname URL pathname (window.location.pathname)
 * @returns 편집 모드 진입 정보 또는 null (편집 모드 아님)
 */
export function checkLayoutEditorMode(pathname: string): { templateIdentifier: string } | null {
  const match = LAYOUT_EDITOR_URL_RE.exec(pathname);
  if (!match) return null;
  return { templateIdentifier: match[1] };
}

/**
 * URL 의 `?route=` 파라미터 추출.
 *
 * @param search URL search string (window.location.search)
 * @returns 초기 라우트 path 또는 null
 */
export function extractInitialRoutePath(search: string): string | null {
  if (!search) return null;
  const params = new URLSearchParams(search);
  const route = params.get('route');
  return route && route.length > 0 ? route : null;
}

/**
 * 현재 URL 로부터 EditorModeParams 추출.
 *
 * @param pathname window.location.pathname
 * @param search window.location.search
 * @returns EditorModeParams 또는 null (편집 모드 아님)
 */
export function parseEditorMode(pathname: string, search: string): EditorModeParams | null {
  const mode = checkLayoutEditorMode(pathname);
  if (!mode) return null;
  return {
    templateIdentifier: mode.templateIdentifier,
    initialRoutePath: extractInitialRoutePath(search),
  };
}

/**
 * 편집기 URL 조립 — `pushState` / 부팅 진입 양쪽에서 단일 SSoT 로 사용.
 *
 * - 일반 라우트 → `?route=<path>`.
 * - 별도 편집 모드 가상 path(`__base__/...` / `__modal__/...` / `__extension__/...` /
 *  `__iteration__/...`) → `?edit=<가상 path>`.
 *   가상 path 자체에 모드+대상이 모두 인코딩돼 있으므로 그대로 직렬화하면 새로고침/
 *   뒤로가기 복원이 가능하다.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param routePath 현재 선택된 라우트 path (없으면 쿼리 생략)
 * @returns 절대 path (origin 미포함) — `pushState` / `<a href>` 에 그대로 사용 가능
 */
export function buildEditorUrl(
  templateIdentifier: string,
  routePath?: string | null,
  layoutName?: string | null,
): string {
  const base = `/admin/layout-editor/${encodeURIComponent(templateIdentifier)}`;
  if (!routePath || routePath.length === 0) return base;
  // 별도 편집 모드 가상 path → ?edit=
  if (routePath.startsWith('__')) {
    let url = `${base}?edit=${encodeURIComponent(routePath)}`;
    // 모달/반복항목은 호스트 레이아웃명이 path 에 없어 복원에 필요 → &host= 로 동반.
    if (
      (routePath.startsWith('__modal__/') || routePath.startsWith('__iteration__/')) &&
      layoutName
    ) {
      url += `&host=${encodeURIComponent(layoutName)}`;
    }
    return url;
  }
  return `${base}?route=${encodeURIComponent(routePath)}`;
}

/**
 * URL 의 `?host=` 파라미터(모달/반복항목 편집 모드의 호스트 레이아웃명) 추출.
 *
 * @param search URL search string
 * @returns 호스트 레이아웃명 또는 null
 */
export function extractEditModeHost(search: string): string | null {
  if (!search) return null;
  const params = new URLSearchParams(search);
  const host = params.get('host');
  return host && host.length > 0 ? host : null;
}

/**
 * URL 의 `?edit=` 파라미터(별도 편집 모드 가상 path) 추출.
 *
 * @param search URL search string (window.location.search)
 * @returns 가상 path(`__base__/...` 등) 또는 null
 */
export function extractEditModePath(search: string): string | null {
  if (!search) return null;
  const params = new URLSearchParams(search);
  const edit = params.get('edit');
  return edit && edit.length > 0 && edit.startsWith('__') ? edit : null;
}

/**
 * 위지윅 편집기 → 코드 편집기(`/admin/templates/{id}/edit`) 전환 URL 조립.
 *
 * 위지윅 캔버스의 selectedRoute.path 는 routes.json 원본 path 를 그대로 보존하므로
 * admin 라우트는 선행 와일드카드(별표 + /admin/...)를 포함한다. 반면 코드 편집기는 서버
 * LayoutResource.route_path (= TemplateService::resolveRoutePathExpressions, 선행 별표를
 * ltrim 으로 제거)와 ?route= 를 === 매칭한다. 두 표현이 어긋나면 매칭 실패로
 * 코드 편집기가 첫 레이아웃(_admin_base.json)을 폴백 선택한다.
 *
 * 따라서 코드 편집기로 넘길 때만 선행 `*` 를 제거해 서버 정규화와 일치시킨다. 위지윅
 * 내부 트리/URL 표현(`*` 보존)은 건드리지 않는다.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param routePath 위지윅에서 선택 중이던 라우트 path (없거나 가상 path 면 쿼리 생략)
 * @returns 코드 편집기 절대 path (origin 미포함)
 */
export function buildCodeEditorUrl(templateIdentifier: string, routePath?: string | null): string {
  const base = `/admin/templates/${encodeURIComponent(templateIdentifier)}/edit`;
  if (!routePath || routePath.length === 0) return base;
  // base/modal/extension 가상 path 는 코드 편집기 라우트 선택 대상 아님
  if (routePath.startsWith('__')) return base;
  // 서버 route_path 정규화(ltrim '*')와 일치 — 선행 와일드카드 제거
  const normalized = routePath.replace(/^\*+/, '');
  if (normalized.length === 0) return base;
  return `${base}?route=${encodeURIComponent(normalized)}`;
}

/**
 * `pushState` 가 만든 state 객체임을 식별하기 위한 마커.
 * `popstate` 핸들러가 자기 자신이 만든 항목인지 판정해 외부 라이브러리/
 * 다른 컴포넌트의 history 이벤트와 구분한다.
 */
export const EDITOR_HISTORY_STATE_MARKER = 'g7le-editor-route';
