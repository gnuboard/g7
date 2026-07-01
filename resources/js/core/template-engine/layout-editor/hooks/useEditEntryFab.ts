/**
 * useEditEntryFab.ts
 *
 * 운영 화면 좌측 하단 편집 진입 FAB 의 권한 판별 + 진입 URL 조립 (pure logic).
 *
 * 표시 조건 (8.2.2 (1)):
 * - 일반 렌더 모드 (편집 모드 URL 아님)
 * - 인증된 사용자 + `core.templates.layouts.edit` 권한 보유
 *
 * 동작 (8.2.2 (3)):
 * - 진입 URL: `/admin/layout-editor/{templateIdentifier}?route={현재 path}`
 * - openWindow 핸들러로 새 탭 열기
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
}

/**
 * FAB 표시 여부 판정.
 *
 * 표시 조건:
 * - 편집 모드 URL 이 아님 (`/admin/layout-editor/...` 외)
 * - 인증된 사용자
 * - 편집 권한 보유
 */
export function shouldRenderEditEntryFab(input: FabVisibilityInput): boolean {
  if (checkLayoutEditorMode(input.pathname) !== null) {
    return false;
  }
  if (!input.isAuthenticated) return false;
  if (!input.hasLayoutEditPermission) return false;
  return true;
}

/**
 * 진입 URL 조립 — 현재 라우트 path 를 `?route=` 파라미터로 전달.
 *
 * @param templateIdentifier 현재 운영 사이트가 부팅된 템플릿 식별자
 * @param currentRoutePath 현재 보고 있는 라우트 path
 * @returns 새 탭에서 열 진입 URL
 */
export function buildEditEntryUrl(templateIdentifier: string, currentRoutePath: string): string {
  const base = `/admin/layout-editor/${encodeURIComponent(templateIdentifier)}`;
  const route = currentRoutePath && currentRoutePath.length > 0 ? `?route=${encodeURIComponent(currentRoutePath)}` : '';
  return `${base}${route}`;
}
