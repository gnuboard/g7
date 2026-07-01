/**
 * useEditEntryFab 테스트
 *
 * 입력 매트릭스:
 *  - user_state: authenticated_with_perm / authenticated_no_perm / guest
 *  - ui_mode: normal_render / edit_mode
 */

import { describe, it, expect } from 'vitest';
import { shouldRenderEditEntryFab, buildEditEntryUrl } from '../../hooks/useEditEntryFab';

describe('shouldRenderEditEntryFab — 권한 매트릭스', () => {
  it.each([
    [{ pathname: '/', isAuthenticated: true, hasLayoutEditPermission: true }, true],
    [{ pathname: '/', isAuthenticated: true, hasLayoutEditPermission: false }, false],
    [{ pathname: '/', isAuthenticated: false, hasLayoutEditPermission: true }, false],
    [{ pathname: '/', isAuthenticated: false, hasLayoutEditPermission: false }, false],
  ])('%j → %s', (input, expected) => {
    expect(shouldRenderEditEntryFab(input)).toBe(expected);
  });

  it.each([
    '/admin/layout-editor/sirsoft-basic',
    '/admin/layout-editor/sirsoft-admin_basic',
  ])('편집 모드 URL %s → 권한 보유자도 미표시', (pathname) => {
    expect(
      shouldRenderEditEntryFab({ pathname, isAuthenticated: true, hasLayoutEditPermission: true }),
    ).toBe(false);
  });

  it.each([
    '/',
    '/board/list',
    '/admin/templates',
    '/admin/users',
  ])('일반 렌더 URL %s + 권한 보유 → 표시', (pathname) => {
    expect(
      shouldRenderEditEntryFab({ pathname, isAuthenticated: true, hasLayoutEditPermission: true }),
    ).toBe(true);
  });
});

describe('buildEditEntryUrl — 진입 URL 조립', () => {
  it('템플릿 식별자 + 라우트 path', () => {
    expect(buildEditEntryUrl('sirsoft-admin_basic', '/board/list')).toBe(
      '/admin/layout-editor/sirsoft-admin_basic?route=%2Fboard%2Flist',
    );
  });

  it('라우트 path 가 빈 문자열이면 ?route= 미포함', () => {
    expect(buildEditEntryUrl('sirsoft-admin_basic', '')).toBe(
      '/admin/layout-editor/sirsoft-admin_basic',
    );
  });

  it('특수 문자 라우트 path 는 URL 인코딩', () => {
    expect(buildEditEntryUrl('tpl', '/board/list?q=hello world')).toBe(
      '/admin/layout-editor/tpl?route=%2Fboard%2Flist%3Fq%3Dhello%20world',
    );
  });

  it('템플릿 식별자도 URL 인코딩', () => {
    expect(buildEditEntryUrl('sirsoft/weird', '/')).toBe(
      '/admin/layout-editor/sirsoft%2Fweird?route=%2F',
    );
  });
});
