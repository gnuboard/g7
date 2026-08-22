/**
 * initMenuFromUrlHandler 테스트
 *
 * 회귀 배경 (#518 / 공개 #76):
 * 목록 응답에서 하위 메뉴의 역할(`children[].roles`)을 프루닝한 뒤, 화면이 목록 행을 그대로
 * 편집 폼 초기값으로 쓰면 저장 시 빈 역할 배열이 전송되어 해당 메뉴의 역할 제한이 전부
 * 해제된다. 이 핸들러는 선택된 메뉴를 단건 조회로 보강해 그 경로를 막는다.
 *
 * @scenario resource=menu,endpoint=detail,observation=consumer_screen
 * @effects menu_edit_form_seeds_roles_from_detail
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { initMenuFromUrlHandler } from '../initMenuFromUrlHandler';

const PARENT_MENU = {
  id: 10,
  name: { ko: '부모 메뉴' },
  slug: 'parent-menu',
  url: '/parent',
  icon: 'fa-folder',
  order: 1,
  is_active: true,
  parent_id: null,
  roles: [{ id: 1, name: { ko: '관리자' }, permission_type: 'read' }],
  children: [
    {
      id: 11,
      name: { ko: '자식 메뉴' },
      slug: 'child-menu',
      url: '/parent/child',
      icon: 'fa-file',
      order: 1,
      is_active: true,
      parent_id: 10,
      // 목록 응답에는 하위 메뉴의 roles 키 자체가 없다 (#76 프루닝)
    },
  ],
};

/** 단건 조회가 돌려주는 자식 메뉴 — 목록에는 없던 역할이 실려 있다. */
const CHILD_DETAIL = {
  id: 11,
  name: { ko: '자식 메뉴' },
  slug: 'child-menu',
  url: '/parent/child',
  icon: 'fa-file',
  order: 1,
  is_active: true,
  parent_id: 10,
  extension_type: null,
  extension_identifier: null,
  roles: [
    { id: 3, name: { ko: '편집자' }, permission_type: 'read' },
    { id: 5, name: { ko: '검수자' }, permission_type: 'read' },
  ],
};

function setUrl(search: string): void {
  Object.defineProperty(window, 'location', {
    value: { search, pathname: '/admin/menus', href: `https://example.test/admin/menus${search}` },
    writable: true,
  });
}

describe('initMenuFromUrlHandler', () => {
  let stateSet: ReturnType<typeof vi.fn>;
  let apiGet: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    stateSet = vi.fn();
    apiGet = vi.fn(async (url: string) => {
      if (url === '/api/admin/menus/11') {
        return { success: true, data: CHILD_DETAIL };
      }
      if (url === '/api/admin/menus/10') {
        return { success: true, data: PARENT_MENU };
      }
      throw new Error(`unexpected url: ${url}`);
    });

    // 실측 형태 — 목록 데이터소스는 응답 envelope 를 그대로 담는다.
    // (`G7Core.state.getDataSource('menus')` → `{success, message, data: {data: [...], abilities}}`)
    // 종전 모킹은 `{data: [메뉴]}` 라는 존재하지 않는 형태였고, 그래서 이 핸들러가 실제
    // 화면에서 한 번도 동작하지 않는데도 테스트는 통과했다.
    (window as any).G7Core = {
      state: {
        set: stateSet,
        getDataSource: (id: string) =>
          id === 'menus'
            ? { success: true, message: 'ok', data: { data: [PARENT_MENU], abilities: {} } }
            : undefined,
      },
      api: { get: apiGet },
    };
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  it('menu 파라미터가 없으면 아무 상태도 건드리지 않는다', async () => {
    setUrl('');

    await initMenuFromUrlHandler({});

    expect(stateSet).not.toHaveBeenCalled();
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('하위 메뉴 선택 시 단건을 조회해 selectedMenu 를 보강한다', async () => {
    setUrl('?menu=child-menu&mode=view');

    await initMenuFromUrlHandler({});

    expect(apiGet).toHaveBeenCalledWith('/api/admin/menus/11');

    const selection = stateSet.mock.calls.map((call) => call[0]).find((arg) => 'selectedMenu' in arg);
    expect(selection).toBeDefined();
    expect(selection.selectedMenu.roles).toHaveLength(2);
    expect(selection.selectedMenuId).toBe(11);
    expect(selection.panelMode).toBe('view');
  });

  it('edit 모드에서 formData.roles 가 단건 조회의 역할 ID 로 채워진다 (회귀: 저장 시 역할 전체 해제)', async () => {
    setUrl('?menu=child-menu&mode=edit');

    await initMenuFromUrlHandler({});

    const formCall = stateSet.mock.calls.map((call) => call[0]).find((arg) => 'formData' in arg);
    expect(formCall).toBeDefined();

    // 존재를 먼저 확정한 뒤 값을 단언한다 (부재 단언이 렌더 전에 통과하는 함정 회피)
    expect(formCall.formData).toHaveProperty('roles');
    expect(formCall.formData.roles).toEqual([3, 5]);
    expect(formCall.formData.slug).toBe('child-menu');
  });

  it('단건 조회가 실패해도 목록 행으로 선택 상태는 유지한다', async () => {
    apiGet.mockRejectedValueOnce(new Error('network down'));
    setUrl('?menu=child-menu&mode=view');

    await initMenuFromUrlHandler({});

    const selection = stateSet.mock.calls.map((call) => call[0]).find((arg) => 'selectedMenu' in arg);
    expect(selection).toBeDefined();
    expect(selection.selectedMenuId).toBe(11);
  });

  it('상위 메뉴도 동일하게 단건 조회로 보강한다', async () => {
    setUrl('?menu=parent-menu&mode=edit');

    await initMenuFromUrlHandler({});

    expect(apiGet).toHaveBeenCalledWith('/api/admin/menus/10');

    const formCall = stateSet.mock.calls.map((call) => call[0]).find((arg) => 'formData' in arg);
    expect(formCall.formData.roles).toEqual([1]);
  });
});
