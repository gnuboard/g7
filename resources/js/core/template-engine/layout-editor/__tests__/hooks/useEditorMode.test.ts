/**
 * useEditorMode 테스트
 *
 * URL 패턴 매칭 + 초기 라우트 추출의 정확성을 입력 axis cross product 로 검증.
 */

import { describe, it, expect } from 'vitest';
import {
  buildCodeEditorUrl,
  buildEditorUrl,
  checkLayoutEditorMode,
  EDITOR_HISTORY_STATE_MARKER,
  extractInitialRoutePath,
  extractEditModePath,
  parseEditorMode,
} from '../../hooks/useEditorMode';

describe('checkLayoutEditorMode', () => {
  it.each([
    ['/admin/layout-editor/sirsoft-admin_basic', 'sirsoft-admin_basic'],
    ['/admin/layout-editor/sirsoft-basic', 'sirsoft-basic'],
    ['/admin/layout-editor/sirsoft-admin_basic/', 'sirsoft-admin_basic'],
  ])('편집 모드 URL %s → 식별자 %s', (path, expected) => {
    expect(checkLayoutEditorMode(path)).toEqual({ templateIdentifier: expected });
  });

  it.each([
    '/',
    '/admin',
    '/admin/templates',
    '/admin/templates/sirsoft-admin_basic/edit',
    '/admin/layout-editor',
    '/admin/layout-editor/',
    '/admin/layout-editor/sirsoft/sub',
    '/board/list',
  ])('편집 모드 아님 URL %s → null', (path) => {
    expect(checkLayoutEditorMode(path)).toBeNull();
  });
});

describe('extractInitialRoutePath', () => {
  it('?route=/board/list → /board/list', () => {
    expect(extractInitialRoutePath('?route=%2Fboard%2Flist')).toBe('/board/list');
  });

  it('?route= 가 없으면 null', () => {
    expect(extractInitialRoutePath('?other=value')).toBeNull();
  });

  it('빈 search 문자열 → null', () => {
    expect(extractInitialRoutePath('')).toBeNull();
  });

  it('?route= 빈 값 → null', () => {
    expect(extractInitialRoutePath('?route=')).toBeNull();
  });
});

describe('parseEditorMode', () => {
  it('편집 URL + route 파라미터 → 둘 다 추출', () => {
    expect(
      parseEditorMode('/admin/layout-editor/sirsoft-basic', '?route=%2Fhome'),
    ).toEqual({ templateIdentifier: 'sirsoft-basic', initialRoutePath: '/home' });
  });

  it('편집 URL + route 없음 → initialRoutePath null', () => {
    expect(parseEditorMode('/admin/layout-editor/sirsoft-basic', '')).toEqual({
      templateIdentifier: 'sirsoft-basic',
      initialRoutePath: null,
    });
  });

  it('편집 URL 아님 → null', () => {
    expect(parseEditorMode('/board/list', '?route=%2Fhome')).toBeNull();
  });
});

describe('buildEditorUrl', () => {
  it('라우트 미지정 → templateIdentifier 만의 base URL', () => {
    expect(buildEditorUrl('sirsoft-basic')).toBe('/admin/layout-editor/sirsoft-basic');
    expect(buildEditorUrl('sirsoft-basic', null)).toBe('/admin/layout-editor/sirsoft-basic');
    expect(buildEditorUrl('sirsoft-basic', '')).toBe('/admin/layout-editor/sirsoft-basic');
  });

  it('라우트 지정 → ?route= 쿼리로 직렬화', () => {
    expect(buildEditorUrl('sirsoft-basic', '/board/list')).toBe(
      '/admin/layout-editor/sirsoft-basic?route=%2Fboard%2Flist',
    );
  });

  it('templateIdentifier 에 특수문자 — encodeURIComponent 적용', () => {
    expect(buildEditorUrl('vendor/template')).toBe('/admin/layout-editor/vendor%2Ftemplate');
  });

  // 별도 편집 모드 가상 path 는 `?edit=` 쿼리로 직렬화(종전엔 쿼리 생략).
  it('base/extension/iteration 가상 path → ?edit= 직렬화', () => {
    expect(buildEditorUrl('sirsoft-basic', '__base__/_user_base')).toBe(
      '/admin/layout-editor/sirsoft-basic?edit=__base__%2F_user_base',
    );
    expect(buildEditorUrl('sirsoft-basic', '__extension__/9')).toBe(
      '/admin/layout-editor/sirsoft-basic?edit=__extension__%2F9',
    );
    expect(buildEditorUrl('sirsoft-basic', '__iteration__/0.children.2')).toBe(
      '/admin/layout-editor/sirsoft-basic?edit=__iteration__%2F0.children.2',
    );
  });

  it('modal/iteration 가상 path + layoutName → ?edit=&host= (복원에 호스트 필요)', () => {
    expect(buildEditorUrl('sirsoft-basic', '__modal__/login', '_user_base')).toBe(
      '/admin/layout-editor/sirsoft-basic?edit=__modal__%2Flogin&host=_user_base',
    );
    // base/extension 은 host 불필요 — layoutName 전달돼도 무시.
    expect(buildEditorUrl('sirsoft-basic', '__base__/_user_base', '_user_base')).toBe(
      '/admin/layout-editor/sirsoft-basic?edit=__base__%2F_user_base',
    );
  });

  it('?edit= round-trip — extractEditModePath 로 가상 path 복원', () => {
    const url = buildEditorUrl('sirsoft-basic', '__extension__/9');
    const search = url.slice(url.indexOf('?'));
    expect(extractEditModePath(search)).toBe('__extension__/9');
    // ?route= 만 있으면 extractEditModePath 는 null(편집 모드 아님).
    const routeUrl = buildEditorUrl('sirsoft-basic', '/board/list');
    expect(extractEditModePath(routeUrl.slice(routeUrl.indexOf('?')))).toBeNull();
  });

  it('buildEditorUrl 결과를 extractInitialRoutePath 가 round-trip 으로 복원', () => {
    const url = buildEditorUrl('sirsoft-basic', '/posts/1');
    const search = url.slice(url.indexOf('?'));
    expect(extractInitialRoutePath(search)).toBe('/posts/1');
  });

  it('EDITOR_HISTORY_STATE_MARKER 상수가 노출됨 — popstate source 식별용', () => {
    expect(typeof EDITOR_HISTORY_STATE_MARKER).toBe('string');
    expect(EDITOR_HISTORY_STATE_MARKER.length).toBeGreaterThan(0);
  });
});

describe('buildCodeEditorUrl', () => {
  it('라우트 미지정 → /edit base URL (쿼리 없음)', () => {
    expect(buildCodeEditorUrl('sirsoft-admin_basic')).toBe(
      '/admin/templates/sirsoft-admin_basic/edit',
    );
    expect(buildCodeEditorUrl('sirsoft-admin_basic', null)).toBe(
      '/admin/templates/sirsoft-admin_basic/edit',
    );
    expect(buildCodeEditorUrl('sirsoft-admin_basic', '')).toBe(
      '/admin/templates/sirsoft-admin_basic/edit',
    );
  });

  it('admin 라우트 선행 와일드카드(*) 제거 후 ?route= 직렬화 — 서버 route_path 와 일치', () => {
    // 위지윅 selectedRoute.path = `*/admin/modules` → 코드 편집기 `?route=/admin/modules`
    expect(buildCodeEditorUrl('sirsoft-admin_basic', '*/admin/modules')).toBe(
      '/admin/templates/sirsoft-admin_basic/edit?route=%2Fadmin%2Fmodules',
    );
  });

  it('와일드카드 없는 user 라우트는 그대로 직렬화', () => {
    expect(buildCodeEditorUrl('sirsoft-basic', '/board/list')).toBe(
      '/admin/templates/sirsoft-basic/edit?route=%2Fboard%2Flist',
    );
  });

  it('선행 * 가 여러 개라도 모두 제거', () => {
    expect(buildCodeEditorUrl('sirsoft-admin_basic', '**/admin/users')).toBe(
      '/admin/templates/sirsoft-admin_basic/edit?route=%2Fadmin%2Fusers',
    );
  });

  it('path 가 * 단독이면 정규화 후 빈 값 → 쿼리 생략', () => {
    expect(buildCodeEditorUrl('sirsoft-admin_basic', '*')).toBe(
      '/admin/templates/sirsoft-admin_basic/edit',
    );
  });

  it('base/modal/extension 가상 path (__ prefix) → 쿼리 생략', () => {
    expect(buildCodeEditorUrl('sirsoft-admin_basic', '__base__/_admin_base')).toBe(
      '/admin/templates/sirsoft-admin_basic/edit',
    );
  });

  it('templateIdentifier 특수문자 encodeURIComponent 적용', () => {
    expect(buildCodeEditorUrl('vendor/template', '/x')).toBe(
      '/admin/templates/vendor%2Ftemplate/edit?route=%2Fx',
    );
  });

  it('생성된 ?route= 를 extractInitialRoutePath 가 정규화된 path 로 복원', () => {
    const url = buildCodeEditorUrl('sirsoft-admin_basic', '*/admin/modules');
    const search = url.slice(url.indexOf('?'));
    expect(extractInitialRoutePath(search)).toBe('/admin/modules');
  });
});
