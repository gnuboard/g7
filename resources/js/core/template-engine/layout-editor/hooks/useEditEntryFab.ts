import { withBasePrefix } from '../../../support/assetUrl';
/**
 * useEditEntryFab.ts
 *
 * 운영 화면 우측 하단 편집 진입 FAB 의 권한 판별 + 진입 URL 조립 (pure logic).
 *
 * 표시 조건 (8.2.2 (1)):
 * - 일반 렌더 모드 (편집 모드 URL 아님)
 * - 인증된 사용자 + `core.templates.layouts.edit` 권한 보유
 * - 환경설정 > 일반 「레이아웃 편집 단추」(`general.layout_edit_button_enabled`)가 꺼져 있지 않음 (7.2.0)
 *
 * 동작 (8.2.2 (3)):
 * - 진입 URL: `/admin/layout-editor/{templateIdentifier}?route={매칭된 라우트 정의 path}`
 * - 같은 탭 이동 (`window.location.assign`) — 새 탭 금지 (결함 K, 합의 2026-05-26)
 *
 * @since engine-v1.50.0
 */

import { checkLayoutEditorMode } from './useEditorMode';

export interface FabVisibilityInput {
  /** 현재 URL pathname */
  pathname: string;
  /** 인증 사용자 여부 */
  isAuthenticated: boolean;
  /** 사용자 능력 — core.templates.layouts.edit 보유 여부 */
  hasLayoutEditPermission: boolean;
  /** 환경설정 「레이아웃 편집 단추」 — false 면 숨긴다(생략하면 켜짐) */
  enabled?: boolean;
}

/**
 * FAB 표시 여부 판정.
 *
 * 표시 조건:
 * - 환경설정에서 끄지 않음
 * - 편집 모드 URL 이 아님 (`/admin/layout-editor/...` 외)
 * - 인증된 사용자
 * - 편집 권한 보유
 */
export function shouldRenderEditEntryFab(input: FabVisibilityInput): boolean {
  if (input.enabled === false) return false;
  if (checkLayoutEditorMode(input.pathname) !== null) {
    return false;
  }
  if (!input.isAuthenticated) return false;
  if (!input.hasLayoutEditPermission) return false;
  return true;
}

/**
 * 환경설정 「레이아웃 편집 단추」가 켜져 있는지 (7.2.0).
 *
 * 값은 공개 설정 `general.layout_edit_button_enabled` 다. 전역 상태(`_global.settings`)를 먼저 본다 — 환경설정 화면이
 * 저장 성공 때 이 값을 새 공개 설정으로 바꾸므로 새로고침 없이 바로 따라간다. 전역 상태에 설정이 아직 없으면
 * 셸이 실어 준 `window.G7Config.settings` 를 본다. 값이 없으면(키가 없는 옛 서버) 켜진 것으로 본다.
 *
 * @param globalState 전역 상태(`_global`)
 * @returns 꺼져 있지 않으면 true
 */
export function isEditEntryButtonEnabled(globalState: unknown): boolean {
  const fromGlobal = (globalState as { settings?: { general?: Record<string, unknown> } } | null | undefined)?.settings
    ?.general;
  const general =
    fromGlobal ??
    (typeof window !== 'undefined'
      ? ((window as any).G7Config?.settings?.general as Record<string, unknown> | undefined)
      : undefined);
  return general?.layout_edit_button_enabled !== false;
}

/**
 * 진입 URL 조립 — 현재 라우트 path 를 `?route=` 파라미터로 전달.
 *
 * @param templateIdentifier 현재 운영 사이트가 부팅된 템플릿 식별자
 * @param currentRoutePath 현재 보고 있는 라우트 path
 * @returns 같은 탭에서 열 진입 URL
 */
export function buildEditEntryUrl(templateIdentifier: string, currentRoutePath: string): string {
  // 형제 `buildEditorUrl` 과 같은 이유로 접두사를 붙인다 — 이 값은 새 탭에서
  // 브라우저가 그대로 여는 주소다.
  const base = withBasePrefix(`/admin/layout-editor/${encodeURIComponent(templateIdentifier)}`);
  const route = currentRoutePath && currentRoutePath.length > 0 ? `?route=${encodeURIComponent(currentRoutePath)}` : '';
  return `${base}${route}`;
}

/** 라우트 대조기 — 코어 Router 의 `match()` 와 같은 모양만 요구한다 */
export interface RouteMatcherLike {
  match(pathname: string): { route: { path: string } } | null;
}

/**
 * 지금 보는 화면에 매칭된 **라우트 정의 path** 를 돌려준다.
 *
 * 편집기 트리는 routes 원본 path(`*\/admin/users`, `/board/:slug`)로 노드를 만들고 `?route=` 를 그 값과
 * 정확히 견준다. 브라우저 주소(`/admin/users`, `/board/notice`)를 넘기면 어느 노드와도 맞지 않아 편집기가
 * 빈 상태로 남는다. 라우트 패턴은 사이트 접두사 없이 등록되므로 접두사를 벗긴 주소로 대조한다.
 *
 * @param router 코어 라우터(없으면 빈 문자열)
 * @param appPathname 접두사를 벗긴 앱 기준 주소
 * @returns 매칭된 라우트 정의 path, 매칭이 없으면 빈 문자열(편집기는 트리만 연다)
 */
export function resolveCurrentRouteDefinitionPath(
  router: RouteMatcherLike | null | undefined,
  appPathname: string,
): string {
  if (!router || typeof router.match !== 'function') return '';
  try {
    const matched = router.match(appPathname);
    return matched?.route?.path ?? '';
  } catch {
    return '';
  }
}

/**
 * 로그인 사용자 응답에서 레이아웃 편집 권한 판정을 읽는다.
 *
 * 서버가 편집기 API 가드와 같은 판정(`can_edit_layouts`)을 실어 준다. 응답의 `permissions` 목록은
 * 사용 중지한 역할의 권한까지 담으므로 쓰지 않는다. 값이 없으면(로그인 응답 직후 등) 숨긴다.
 *
 * @param user AuthManager 사용자
 * @returns 편집 권한 보유 여부
 */
export function readLayoutEditAbility(user: unknown): boolean {
  return (user as { can_edit_layouts?: unknown } | null)?.can_edit_layouts === true;
}

/** 화면 위 사각형(뷰포트 기준) */
export interface FabRect {
  left: number;
  top: number;
  right: number;
  bottom: number;
  /** 화면에 고정(fixed·sticky)된 상자인지 — false 면 하단 바로 보지 않는다(스크롤과 함께 움직인다) */
  pinned?: boolean;
}

/** 단추 위치 — 뷰포트 오른쪽·아래로부터의 거리(px). 7.2.0 에 왼쪽 아래에서 오른쪽 아래로 옮겼다 */
export interface FabOffset {
  right: number;
  bottom: number;
}

/** 단추 기본 여백(px) */
export const EDIT_ENTRY_FAB_GAP = 16;

/** 단추 지름(px) — 글자 없이 아이콘만 둔 원형 단추다(7.2.0) */
export const EDIT_ENTRY_FAB_SIZE = 44;

/** 화면 아래 끝에서 이만큼(px) 안에 떠 있는 고정 요소까지 하단 요소로 본다 */
const BOTTOM_ANCHOR_SLACK = 48;

/**
 * 옆 패널 모양인지 — 오른쪽 끝에 붙고 높이가 화면 절반 이상, 너비가 절반 이하.
 *
 * 단추가 오른쪽 아래에 있으므로 오른쪽 옆 패널(오른쪽 고정 서랍·도움말 패널 등)만 비킨다. 왼쪽 관리자 사이드바는 단추 자리와
 * 겹치지 않는다.
 *
 * @param r 사각형
 * @param viewport 뷰포트 크기
 * @returns 옆 패널 모양이면 true
 */
export function isSidePanelRect(r: FabRect, viewport: { width: number; height: number }): boolean {
  const w = r.right - r.left;
  const h = r.bottom - r.top;
  return w > 0 && h > 0 && r.right >= viewport.width - 1 && h >= viewport.height * 0.5 && w <= viewport.width * 0.5;
}

/**
 * 단추 자리에 걸친 고정 요소를 피한 위치를 계산한다.
 *
 * - 오른쪽 옆 패널(오른쪽 끝에 붙고 높이가 화면 절반 이상, 너비가 절반 이하)은 그 왼쪽으로 비킨다.
 *   고정 요소가 아니어도 된다 — 화면 높이 레이아웃 안의 `relative` 상자일 수 있다.
 * - 아래 고정 바(아래 끝에 붙었거나 48px 안에 떠 있고 화면 아래쪽 절반에 있음 — 모바일 구매 바, 하단 탭, 토스트, 쿠키 동의 배너,
 *   맨 위로 단추)는 그 위로 올라간다. 이쪽은 고정 요소만 본다 — 흐름 안의 꼬리말은 스크롤하면 비켜난다.
 * - 화면 전체를 덮는 막(모달 배경, 열린 드로어의 덮개)은 피하지 않는다 — 단추가 그 아래 층(z-index)에 있다.
 *
 * @param obstacles 단추 자리에서 찾은 고정 요소 사각형
 * @param viewport 뷰포트 크기(스크롤 막대를 뺀 폭)
 * @param fabSize 단추 크기
 * @returns 피한 위치
 */
export function computeEditEntryFabOffset(
  obstacles: FabRect[],
  viewport: { width: number; height: number },
  fabSize: { width: number; height: number },
): FabOffset {
  let right = EDIT_ENTRY_FAB_GAP;
  let bottom = EDIT_ENTRY_FAB_GAP;
  const { width: vw, height: vh } = viewport;

  for (const r of obstacles) {
    if (isSidePanelRect(r, viewport)) right = Math.max(right, Math.ceil(vw - r.left) + EDIT_ENTRY_FAB_GAP);
  }
  // 옆으로 비켜도 화면 밖으로 나가지 않게 한다
  right = Math.min(right, Math.max(EDIT_ENTRY_FAB_GAP, vw - fabSize.width - EDIT_ENTRY_FAB_GAP));

  // 단추가 차지하는 가로 띠 [bandLeft, bandRight]
  const bandRight = vw - right;
  const bandLeft = bandRight - fabSize.width;
  for (const r of obstacles) {
    const w = r.right - r.left;
    const h = r.bottom - r.top;
    if (w <= 0 || h <= 0) continue;
    // 아래 끝에 붙었거나 그 근처(BOTTOM_ANCHOR_SLACK 안)에 떠 있는 고정 요소 — `bottom-4` 쿠키 동의 배너·토스트 묶음 포함
    const isBottomBar = r.pinned !== false && r.bottom >= vh - BOTTOM_ANCHOR_SLACK && r.top >= vh * 0.5;
    const overlapsBand = r.left < bandRight && r.right > bandLeft;
    if (isBottomBar && overlapsBand) bottom = Math.max(bottom, Math.ceil(vh - r.top) + EDIT_ENTRY_FAB_GAP);
  }
  // 아래 바가 화면 절반을 넘게 덮어도 단추는 화면 안에 둔다
  bottom = Math.min(bottom, Math.max(EDIT_ENTRY_FAB_GAP, vh - fabSize.height - EDIT_ENTRY_FAB_GAP));

  return { right, bottom };
}

/**
 * 지금 창이 다른 화면 안의 iframe 인지 — 미리보기·임베드 안에서는 단추를 띄우지 않는다.
 *
 * @param win 창
 * @returns iframe 안이면 true (교차 출처로 top 을 못 읽어도 true)
 */
export function isEmbeddedFrame(win: Window): boolean {
  try {
    return win.self !== win.top;
  } catch {
    return true;
  }
}

/**
 * 사용자 객체에 서버 판정(`can_edit_layouts`)이 실려 있는지.
 *
 * 부팅 때 인증 확인(로그인 사용자 응답)은 싣지만, 로그인 응답(`UserResource::toArray`)은 싣지 않는다. 로그인 뒤
 * SPA 로 이동하면 새로고침 전까지 그 응답이 세션 사용자로 남아 단추가 숨었다(사례 대조).
 *
 * @param user AuthManager 사용자
 * @returns 판정 필드가 있으면 true
 */
export function hasLayoutEditAbilityField(user: unknown): boolean {
  return !!user && typeof user === 'object' && 'can_edit_layouts' in (user as Record<string, unknown>);
}

/**
 * 로그인 사용자 조회로 레이아웃 편집 권한 판정만 받아 온다 — 인증 상태를 바꾸지 않는 조회다.
 *
 * `AuthManager.checkAuth` 는 늦으면 확인 중 표시를 띄우고 실패하면 세션을 비우므로 쓰지 않는다. 실패하면 거짓(숨김).
 * 주소는 여기서 `withBasePrefix` 로 조립한다(하위 폴더 설치) — 호출자는 API 경로만 넘긴다.
 *
 * @param userEndpoint 로그인 사용자 조회 API 경로(`/admin/auth/user`, `/auth/user` — `/api` 뒤)
 * @param token Bearer 토큰
 * @param fetcher fetch 구현
 * @returns 편집 권한 보유 여부
 */
export async function loadLayoutEditAbility(
  userEndpoint: string,
  token: string | null,
  fetcher: typeof fetch | undefined = typeof fetch !== 'undefined' ? fetch : undefined,
): Promise<boolean> {
  if (!fetcher || !token) return false;
  try {
    const res = await fetcher(withBasePrefix(`/api${userEndpoint}`), {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return false;
    const body = (await res.json().catch(() => null)) as { data?: unknown } | null;
    return readLayoutEditAbility(body?.data);
  } catch {
    return false;
  }
}
