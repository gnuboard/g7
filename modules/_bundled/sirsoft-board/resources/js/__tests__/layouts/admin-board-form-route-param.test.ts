/**
 * 관리자 게시판 폼 — 라우트 파라미터 계약 회귀 테스트 (76차)
 *
 * @description
 * 이 화면의 라우트는 `admin/boards/create` 와 `admin/boards/:slug/edit` 둘뿐이라
 * (앞에 와일드카드 접두어가 붙는다)
 * path param 은 **`slug` 하나**다(`resources/routes/admin.json`). 그런데 권한 탭 조각이
 * 「편집 중인가」를 `route?.id` 로 갈랐다. 그 값은 두 화면 모두에서 `undefined` 이므로
 *
 *   - `data-if="{{ !!route?.id }}"` 로 감싼 **역할별 접근 제어 UI 전체**(관리자·사용자 아코디언,
 *     안내 문구, 오류 알림)가 편집 화면에서도 렌더되지 않고,
 *   - 그 자리에 `data-if="{{ !route?.id }}"` 인 「생성 후 **수정 화면**에서 설정하세요」 안내가
 *     남아 편집 화면이 스스로를 가리키는 막다른 길이 되며,
 *   - 아코디언 안의 `hasChanges="{{ route?.id ? true : ... }}"` 는 렌더 자체가 안 되므로
 *     발화 불가능한 죽은 코드가 된다.
 *
 * 브라우저 실측(g7.dev, /admin/boards/free/edit): `permissions_section_admin`·`_user` 부재,
 * `permissions_create_mode_notice` 표시 — 신규 작성 화면과 결과가 완전히 동일했다.
 *
 * @scenario screen=create
 * @scenario screen=edit
 * @effects wait_for_references_declared_source_only
 *
 * 계약:
 *   ① 게시판 폼 계열 소스는 `route?.id` 를 쓰지 않는다 (path param 에 없는 이름).
 *   ② 편집/신규를 가르는 조건은 `route?.slug` 로 판정한다.
 *   ③ `transition_overlay.wait_for` 는 그 레이아웃이 **선언한** 데이터소스 id 만 참조한다.
 *      (엔진은 미존재 id 를 조용히 무시하므로 오탈자가 런타임에 드러나지 않는다 —
 *       `TemplateApp.ts` 「존재하지 않는 ID 도 자동 무시」)
 */

import { describe, it, expect } from 'vitest';
import boardForm from '../../../layouts/admin/admin_board_form.json';
import tabPermissions from '../../../layouts/admin/partials/admin_board_form/_tab_permissions.json';
import tabBasic from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import adminRoutes from '../../../routes/admin.json';

/** 레이아웃 조각 전체를 문자열로 훑어 표현식 등장 횟수를 센다. */
function occurrences(source: unknown, needle: string): number {
  return JSON.stringify(source).split(needle).length - 1;
}

/** routes/admin.json 에서 특정 layout 을 쓰는 path 목록을 뽑는다. */
function pathsForLayout(routes: any, layout: string): string[] {
  const out: string[] = [];
  const walk = (node: any) => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) return node.forEach(walk);
    if (node.layout === layout && typeof node.path === 'string') out.push(node.path);
    Object.values(node).forEach(walk);
  };
  walk(routes);

  return out;
}

/** `:name` 형태의 path param 이름을 모은다. */
function pathParams(paths: string[]): Set<string> {
  const params = new Set<string>();
  for (const p of paths) {
    for (const m of p.matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)) params.add(m[1]);
  }

  return params;
}

describe('관리자 게시판 폼 — 라우트 파라미터 계약', () => {
  it('이 화면의 라우트가 제공하는 path param 은 slug 뿐이다 (id 없음)', () => {
    const paths = pathsForLayout(adminRoutes, 'admin_board_form');
    expect(paths).toHaveLength(2);

    const params = pathParams(paths);
    expect([...params]).toEqual(['slug']);
    expect(params.has('id')).toBe(false);
  });

  it('권한 탭 조각은 route?.id 를 쓰지 않는다 (항상 undefined → 권한 UI 전면 미표시)', () => {
    expect(occurrences(tabPermissions, 'route?.id')).toBe(0);
  });

  it('기본 탭 조각도 route?.id 를 쓰지 않는다 (slug 입력 잠금 조건)', () => {
    expect(occurrences(tabBasic, 'route?.id')).toBe(0);
  });

  it('폼 본체도 route?.id 를 쓰지 않는다', () => {
    expect(occurrences(boardForm, 'route?.id')).toBe(0);
  });

  it('편집 판정은 route?.slug 로 한다 — 권한 UI 를 여는 조건이 실제로 존재한다', () => {
    // 부재 단언만으로는 "조건을 통째로 지웠다" 와 구분되지 않으므로 존재를 함께 못박는다.
    expect(occurrences(tabPermissions, '!!route?.slug')).toBeGreaterThanOrEqual(3);
    expect(occurrences(tabPermissions, '!route?.slug')).toBeGreaterThanOrEqual(1);
  });

  it('권한 UI 의 네 블록이 모두 남아 있다 (조건만 바꾸고 노드를 지우지 않았다)', () => {
    const s = JSON.stringify(tabPermissions);
    for (const ref of [
      'permissions_create_mode_notice',
      'permissions_error_alert',
      'permissions_section_admin',
      'permissions_section_user',
    ]) {
      expect(s).toContain(ref);
    }
  });

  it('wait_for 는 이 레이아웃이 선언한 데이터소스 id 만 참조한다', () => {
    const declared = new Set(
      ((boardForm as any).data_sources ?? []).map((s: any) => s.id)
    );
    const waitFor: string[] = (boardForm as any).transition_overlay?.wait_for ?? [];

    expect(waitFor.length).toBeGreaterThan(0);
    for (const id of waitFor) {
      expect(declared.has(id), `wait_for 의 "${id}" 가 선언된 데이터소스에 없다`).toBe(true);
    }
  });
});
