/**
 * useRouteTree.buildRouteTree 테스트
 *
 * 입력 axis cross product:
 *  - route_source: template / module / plugin / core
 *  - route_label_source: editor_label / title / path
 *  - route_visibility: visible / hidden / redirect
 *  - route_nesting: flat / has_parent (path prefix 또는 meta.parent)
 *  - modal_definition: 없음 / 있음
 *  - base_layout_present: 없음 / 있음
 */

import { describe, it, expect } from 'vitest';
import { buildRouteTree, type RouteResponseItem } from '../../hooks/useRouteTree';

function tplRoute(path: string, layout: string, meta: any = {}): RouteResponseItem {
  return { path, layout, meta, source: { kind: 'template', identifier: null } };
}

function modRoute(id: string, path: string, layout: string, meta: any = {}): RouteResponseItem {
  return { path, layout, meta, source: { kind: 'module', identifier: id } };
}

function plgRoute(id: string, path: string, layout: string, meta: any = {}): RouteResponseItem {
  return { path, layout, meta, source: { kind: 'plugin', identifier: id } };
}

function coreRoute(path: string, layout: string): RouteResponseItem {
  return { path, layout, source: { kind: 'core', identifier: null } };
}

const NO_MODULES = {};
const NO_PLUGINS = {};

describe('buildRouteTree — 그룹 구성', () => {
  it('템플릿 자체 라우트만 있을 때 template 그룹 1개', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home'), tplRoute('/about', 'about')],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    expect(tree).toHaveLength(1);
    expect(tree[0].path).toBe('__group__/template');
    expect(tree[0].children).toHaveLength(2);
  });

  it('모듈 라우트만 있을 때 module:{id} 그룹', () => {
    const tree = buildRouteTree({
      routes: [modRoute('sirsoft-board', '/board/list', 'board.list')],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: { 'sirsoft-board': 'Board' },
      pluginDisplayNames: NO_PLUGINS,
    });
    expect(tree).toHaveLength(1);
    expect(tree[0].path).toBe('__group__/module:sirsoft-board');
  });

  it('5그룹 모두 (base / template / module / plugin / modal)', () => {
    const tree = buildRouteTree({
      routes: [
        tplRoute('/', 'home'),
        modRoute('sirsoft-board', '/board/list', 'sirsoft-board.board.list'),
        plgRoute('sirsoft-gdpr', '/gdpr', 'sirsoft-gdpr.consent'),
      ],
      modals: [{ modalId: 'login', hostLayout: '_user_base' }],
      baseLayouts: [{ layoutName: '_user_base' }],
      moduleDisplayNames: { 'sirsoft-board': 'Board' },
      pluginDisplayNames: { 'sirsoft-gdpr': 'GDPR' },
    });
    expect(tree).toHaveLength(5);
    expect(tree[0].path).toBe('__group__/base');
    expect(tree[1].path).toBe('__group__/template');
    expect(tree[2].path).toBe('__group__/module:sirsoft-board');
    expect(tree[3].path).toBe('__group__/plugin:sirsoft-gdpr');
    expect(tree[4].path).toBe('__group__/modal');
  });

  it('core source 라우트는 트리에서 제외 (preview 등 시스템 라우트)', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home'), coreRoute('*/preview:token', '__preview__')],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    expect(tree).toHaveLength(1);
    expect(tree[0].path).toBe('__group__/template');
    // template 그룹에 home 만 포함
    expect(tree[0].children).toHaveLength(1);
    expect(tree[0].children![0].path).toBe('/');
  });
});

describe('buildRouteTree — 라벨 해석 우선순위', () => {
  it('editor_label > title > path', () => {
    const tree = buildRouteTree({
      routes: [
        tplRoute('/p1', 'p1', { editor_label: '$t:custom.label', title: '$t:p1.title' }),
        tplRoute('/p2', 'p2', { title: '$t:p2.title' }),
        tplRoute('/p3', 'p3'),
      ],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const children = tree[0].children!;
    const byPath = Object.fromEntries(children.map((c) => [c.path, c]));
    expect(byPath['/p1'].label).toBe('$t:custom.label');
    expect(byPath['/p1'].labelSource).toBe('editor_label');
    expect(byPath['/p2'].label).toBe('$t:p2.title');
    expect(byPath['/p2'].labelSource).toBe('title');
    expect(byPath['/p3'].label).toBe('/p3');
    expect(byPath['/p3'].labelSource).toBe('path');
  });
});

describe('buildRouteTree — 숨김/리다이렉트 플래그', () => {
  it('meta.hidden → isHidden=true, redirect → isRedirect=true', () => {
    const tree = buildRouteTree({
      routes: [
        tplRoute('/hidden', 'h', { hidden: true }),
        { ...tplRoute('/old', 'o'), redirect: '/new' },
        tplRoute('/normal', 'n'),
      ],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const byPath = Object.fromEntries(tree[0].children!.map((c) => [c.path, c]));
    expect(byPath['/hidden'].isHidden).toBe(true);
    expect(byPath['/hidden'].isRedirect).toBeFalsy();
    expect(byPath['/old'].isRedirect).toBe(true);
    expect(byPath['/normal'].isHidden).toBeFalsy();
    expect(byPath['/normal'].isRedirect).toBeFalsy();
  });
});

describe('buildRouteTree — 중첩 (계층) 메뉴', () => {
  it('path prefix 로 부모-자식 인식 + 같은 그룹 안에서만 중첩', () => {
    const tree = buildRouteTree({
      routes: [
        tplRoute('/board', 'board'),
        tplRoute('/board/list', 'board-list'),
        tplRoute('/board/notice', 'board-notice'),
        modRoute('sirsoft-board', '/board/admin', 'sirsoft-board.admin'),
      ],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: { 'sirsoft-board': 'Board' },
      pluginDisplayNames: NO_PLUGINS,
    });
    // template 그룹: /board 가 루트, /board/list, /board/notice 가 자식
    const templateGroup = tree.find((n) => n.path === '__group__/template')!;
    expect(templateGroup.children).toHaveLength(1);
    expect(templateGroup.children![0].path).toBe('/board');
    expect(templateGroup.children![0].children).toHaveLength(2);

    // 모듈 그룹의 /board/admin 은 module 그룹 안에서 독립 (그룹 경계 유지)
    const moduleGroup = tree.find((n) => n.path === '__group__/module:sirsoft-board')!;
    expect(moduleGroup.children).toHaveLength(1);
    expect(moduleGroup.children![0].path).toBe('/board/admin');
  });

  it('meta.parent 명시 지원', () => {
    const tree = buildRouteTree({
      routes: [
        tplRoute('/dashboard', 'dash'),
        tplRoute('/stats', 'stats', { parent: '/dashboard' }),
      ],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    expect(tree[0].children).toHaveLength(1);
    expect(tree[0].children![0].path).toBe('/dashboard');
    expect(tree[0].children![0].children![0].path).toBe('/stats');
  });
});

describe('buildRouteTree — 모달 그룹', () => {
  it('모달 노드에 modalId + modalHostLayout 보유', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [
        { modalId: 'login', hostLayout: '_user_base' },
        { modalId: 'cart', hostLayout: 'shop' },
      ],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const modalGroup = tree.find((n) => n.path === '__group__/modal')!;
    expect(modalGroup.children).toHaveLength(2);
    expect(modalGroup.children![0].kind).toBe('modal');
    expect(modalGroup.children![0].modalId).toBe('login');
    expect(modalGroup.children![0].modalHostLayout).toBe('_user_base');
  });

  // 모달 정의의 meta.editor_label($t: 키)이 TemplateService 를 거쳐 modal.label 로
  // 들어오면 트리 노드 라벨은 그 키를 채택(labelSource='editor_label'), modalId 폴백을 하지 않는다.
  // label 부재 시에만 modalId 로 폴백(labelSource='path'). 라우트의 editor_label 채택과 동형.
  it('모달 노드 라벨: meta.editor_label($t:) 채택, 부재 시 modalId 폴백', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [
        { modalId: 'termsModal', hostLayout: '_user_base', label: '$t:user.modal_label.auth.termsModal' },
        { modalId: 'cart', hostLayout: 'shop' },
      ],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const modalGroup = tree.find((n) => n.path === '__group__/modal')!;
    const labeled = modalGroup.children!.find((c) => c.modalId === 'termsModal')!;
    const unlabeled = modalGroup.children!.find((c) => c.modalId === 'cart')!;
    // editor_label($t: 키) 채택 — modalId 폴백 안 함
    expect(labeled.label).toBe('$t:user.modal_label.auth.termsModal');
    expect(labeled.labelSource).toBe('editor_label');
    // label 부재 → modalId 폴백
    expect(unlabeled.label).toBe('cart');
    expect(unlabeled.labelSource).toBe('path');
  });
});

describe('buildRouteTree — base 그룹', () => {
  it('base 레이아웃 노드는 kind=base + layoutName 보유', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [],
      baseLayouts: [{ layoutName: '_user_base' }, { layoutName: '_admin_base' }],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const baseGroup = tree.find((n) => n.path === '__group__/base')!;
    expect(baseGroup.children).toHaveLength(2);
    expect(baseGroup.children![0].kind).toBe('base');
    expect(baseGroup.children![0].layoutName).toBe('_user_base');
  });

  // base 호스트(`_user_base`)에 선언된 모달도 호스트 하위 "이 화면의 모달"
  // 연결 그룹으로 노출돼야 한다(라우트 호스트와 동일). 종전 attachConnectedGroups 는
  // route-only 라 base 호스트 모달이 호스트 하위에 전혀 안 떴다.
  it('base 호스트에 선언된 모달은 base 노드 하위 "이 화면의 모달" 그룹으로 부착', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [
        { modalId: 'identity-challenge-modal', hostLayout: '_user_base', label: '본인확인' },
        { modalId: 'notification_delete_all_confirm_modal', hostLayout: '_user_base', label: '알림 전체삭제' },
      ],
      baseLayouts: [{ layoutName: '_user_base' }],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const baseGroup = tree.find((n) => n.path === '__group__/base')!;
    const baseNode = baseGroup.children!.find((c) => c.layoutName === '_user_base')!;
    const modalGroup = baseNode.children!.find((c) => c.path.startsWith('__conngroup__/modals/'))!;
    expect(modalGroup).toBeDefined();
    expect(modalGroup.label).toContain('count=2');
    expect(modalGroup.children).toHaveLength(2);
    const modalNode = modalGroup.children![0];
    expect(modalNode.kind).toBe('modal');
    expect(modalNode.modalHostLayout).toBe('_user_base');
    // 호스트 하위 진입 → 클릭 시 ENTER_MODAL_EDIT 가 modalHostLayout 으로 바로 진입(picker 불필요).
    expect(['identity-challenge-modal', 'notification_delete_all_confirm_modal']).toContain(modalNode.modalId);
  });
});

describe('buildRouteTree — 확장 주입 그룹', () => {
  const extGroups = [
    {
      sourceIdentifier: 'sirsoft-board',
      sourceType: 'module' as const,
      sourceLabel: '게시판',
      extensions: [
        { id: 1, extensionType: 'extension_point' as const, targetName: 'header_ext', priority: 100, isActive: true, isModified: false, hostLayouts: ['some_layout'] },
        { id: 2, extensionType: 'overlay' as const, targetName: 'admin_user_detail', priority: 50, isActive: true, isModified: true, hostLayouts: ['admin_user_detail'] },
      ],
    },
    {
      sourceIdentifier: 'sirsoft-analytics',
      sourceType: 'plugin' as const,
      sourceLabel: 'Analytics',
      extensions: [
        { id: 3, extensionType: 'overlay' as const, targetName: 'admin_dashboard', priority: 10, isActive: false, isModified: false, hostLayouts: ['admin_dashboard'] },
      ],
    },
  ];

  it('[확장 주입] 그룹은 출처별 하위그룹 + 확장 항목으로 구성', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [],
      baseLayouts: [],
      extensionGroups: extGroups,
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const extGroup = tree.find((n) => n.path === '__group__/extension')!;
    expect(extGroup).toBeDefined();
    // 출처 2개 → 하위그룹 2개
    expect(extGroup.children).toHaveLength(2);
    const boardSub = extGroup.children!.find((n) => n.path === '__extgroup__/module/sirsoft-board')!;
    expect(boardSub.label).toBe('🧩 게시판');
    expect(boardSub.children).toHaveLength(2);
    const analyticsSub = extGroup.children!.find((n) => n.path === '__extgroup__/plugin/sirsoft-analytics')!;
    expect(analyticsSub.label).toBe('🔌 Analytics');
  });

  it('확장 노드는 kind=extension + extensionId/Type/Priority/TargetName + 가상 path 보유', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [],
      baseLayouts: [],
      extensionGroups: extGroups,
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const extGroup = tree.find((n) => n.path === '__group__/extension')!;
    const boardSub = extGroup.children!.find((n) => n.path === '__extgroup__/module/sirsoft-board')!;
    const node = boardSub.children![0];
    expect(node.kind).toBe('extension');
    expect(node.extensionId).toBe('1');
    expect(node.path).toBe('__extension__/1');
    expect(node.extensionType).toBe('extension_point');
    expect(node.extensionPriority).toBe(100);
    expect(node.extensionTargetName).toBe('header_ext');
    expect(node.source).toEqual({ kind: 'module', identifier: 'sirsoft-board' });
  });

  it('is_modified=true → isModified, is_active=false → isInactive (트리에서 제거 안 됨)', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [],
      baseLayouts: [],
      extensionGroups: extGroups,
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const extGroup = tree.find((n) => n.path === '__group__/extension')!;
    const modifiedNode = extGroup.children!
      .flatMap((g) => g.children!)
      .find((n) => n.extensionId === '2')!;
    expect(modifiedNode.isModified).toBe(true);
    // 비활성 확장(id=3)도 트리에 남되 흐림(isInactive)
    const inactiveNode = extGroup.children!
      .flatMap((g) => g.children!)
      .find((n) => n.extensionId === '3')!;
    expect(inactiveNode.isInactive).toBe(true);
  });

  it('extensionGroups 미전달 시 [확장 주입] 그룹 미표시 (디그레이드)', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    expect(tree.find((n) => n.path === '__group__/extension')).toBeUndefined();
  });

  it('확장 그룹은 모달 그룹 뒤(최하단)에 배치', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/', 'home')],
      modals: [{ modalId: 'login', hostLayout: '_user_base' }],
      baseLayouts: [{ layoutName: '_user_base' }],
      extensionGroups: extGroups,
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const paths = tree.map((n) => n.path);
    expect(paths[paths.length - 1]).toBe('__group__/extension');
    expect(paths.indexOf('__group__/modal')).toBeLessThan(paths.indexOf('__group__/extension'));
  });
});

describe('buildRouteTree — 라우트↔모달/확장 정적 연결', () => {
  // 정적 매칭 axis: modal_host_match(있음/없음), ext_host_match(있음/없음),
  //   ext_type(overlay/extension_point — 둘 다 hostLayouts 로 매칭), connected_count(0/1/N)
  const connExtGroups = [
    {
      sourceIdentifier: 'sirsoft-board',
      sourceType: 'module' as const,
      sourceLabel: '게시판',
      extensions: [
        // EP — hostLayouts(슬롯 포함 레이아웃 전체)가 라우트 layout 과 일치하면 정적 부착
        { id: 1, extensionType: 'extension_point' as const, targetName: 'header_ext', isActive: true, isModified: false, hostLayouts: ['shop_checkout'] },
        // overlay — target_layout(=hostLayouts) 이 라우트 layout 과 일치하면 정적 부착
        { id: 2, extensionType: 'overlay' as const, targetName: 'admin_user_list', isActive: true, isModified: true, hostLayouts: ['admin_user_list'] },
      ],
    },
  ];

  function findRouteNode(tree: ReturnType<typeof buildRouteTree>, path: string) {
    const group = tree.find((n) => n.path.startsWith('__group__/'));
    return group?.children?.find((c) => c.path === path);
  }

  it('모달 host_layout 이 라우트 layout 과 일치하면 "이 화면의 모달" 자식 그룹 부착', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/admin/users', 'admin_user_list', { title: '회원 목록' })],
      modals: [
        { modalId: 'delete_confirm', hostLayout: 'admin_user_list', label: '삭제 확인' },
        { modalId: 'bulk_block', hostLayout: 'admin_user_list', label: '일괄 차단' },
        { modalId: 'unrelated', hostLayout: 'other_layout', label: '무관' },
      ],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/admin/users')!;
    const modalGroup = route.children!.find((c) => c.path.startsWith('__conngroup__/modals/'))!;
    expect(modalGroup).toBeDefined();
    expect(modalGroup.label).toContain('count=2'); // 같은 host 2건만, 무관 모달 제외
    expect(modalGroup.children).toHaveLength(2);
    const modalNode = modalGroup.children![0];
    expect(modalNode.kind).toBe('modal');
    expect(modalNode.modalHostLayout).toBe('admin_user_list');
    expect(['delete_confirm', 'bulk_block']).toContain(modalNode.modalId);
    // 호스트 라우트 path 가 자식에 부여돼 강조 유지에 쓰인다
    expect(modalNode.connectedHostRoutePath).toBe('/admin/users');
  });

  it('overlay 확장 target_layout 이 라우트 layout 과 일치하면 "주입되는 확장" 자식 그룹 부착', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/admin/users', 'admin_user_list')],
      modals: [],
      baseLayouts: [],
      extensionGroups: connExtGroups,
      moduleDisplayNames: { 'sirsoft-board': '게시판' },
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/admin/users')!;
    const extGroup = route.children!.find((c) => c.path.startsWith('__conngroup__/extensions/'))!;
    expect(extGroup).toBeDefined();
    expect(extGroup.label).toContain('count=1');
    const extNode = extGroup.children![0];
    expect(extNode.kind).toBe('extension');
    expect(extNode.extensionId).toBe('2');
    expect(extNode.extensionType).toBe('overlay');
    expect(extNode.isModified).toBe(true);
    expect(extNode.connectedHostRoutePath).toBe('/admin/users');
  });

  it('extension_point 타입도 hostLayouts 가 라우트 layout 과 일치하면 정적 부착 (클릭 불필요)', () => {
    const tree = buildRouteTree({
      // EP 의 hostLayouts=['shop_checkout'] 이 라우트 layout 과 일치 → 정적 부착
      routes: [tplRoute('/shop/checkout', 'shop_checkout', { title: '주문서 작성' })],
      modals: [],
      baseLayouts: [],
      extensionGroups: connExtGroups,
      moduleDisplayNames: { 'sirsoft-board': '게시판' },
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/shop/checkout')!;
    const extGroup = route.children!.find((c) => c.path.startsWith('__conngroup__/extensions/'))!;
    expect(extGroup).toBeDefined();
    expect(extGroup.label).toContain('count=1');
    const extNode = extGroup.children![0];
    expect(extNode.extensionId).toBe('1');
    expect(extNode.extensionType).toBe('extension_point');
    expect(extNode.connectedHostRoutePath).toBe('/shop/checkout');
  });

  it('hostLayouts 에 없는 라우트에는 확장 미부착', () => {
    const tree = buildRouteTree({
      // header_ext 슬롯명을 layout 으로 가져도, hostLayouts 매칭이 아니면 부착 안 됨
      routes: [tplRoute('/admin/users', 'header_ext')],
      modals: [],
      baseLayouts: [],
      extensionGroups: connExtGroups,
      moduleDisplayNames: { 'sirsoft-board': '게시판' },
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/admin/users')!;
    const extGroup = route.children?.find((c) => c.path.startsWith('__conngroup__/extensions/'));
    expect(extGroup).toBeUndefined();
  });

  it('연결 모달/확장이 0건이면 연결 자식 그룹 미부착 (노이즈 방지)', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/admin/orphan', 'orphan_layout')],
      modals: [{ modalId: 'm', hostLayout: 'other', label: 'M' }],
      baseLayouts: [],
      extensionGroups: connExtGroups,
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/admin/orphan')!;
    const connGroups = (route.children ?? []).filter((c) => c.path.startsWith('__conngroup__/'));
    expect(connGroups).toHaveLength(0);
  });

  it('연결 그룹은 children 앞쪽 — 하위 라우트보다 먼저 배치', () => {
    const tree = buildRouteTree({
      routes: [
        tplRoute('/admin', 'admin_user_list'),
        tplRoute('/admin/sub', 'sub_layout'), // path prefix 로 /admin 의 자식
      ],
      modals: [{ modalId: 'delete_confirm', hostLayout: 'admin_user_list', label: '삭제' }],
      baseLayouts: [],
      moduleDisplayNames: NO_MODULES,
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/admin')!;
    expect(route.children![0].path).toMatch(/^__conngroup__\/modals\//);
    // 하위 라우트(/admin/sub)는 연결 그룹 뒤
    expect(route.children!.some((c) => c.path === '/admin/sub')).toBe(true);
    expect(route.children!.findIndex((c) => c.path === '/admin/sub')).toBeGreaterThan(0);
  });

  it('모달 + overlay 둘 다 매칭 시 두 연결 그룹 부착', () => {
    const tree = buildRouteTree({
      routes: [tplRoute('/admin/users', 'admin_user_list')],
      modals: [{ modalId: 'delete_confirm', hostLayout: 'admin_user_list', label: '삭제' }],
      baseLayouts: [],
      extensionGroups: connExtGroups,
      moduleDisplayNames: { 'sirsoft-board': '게시판' },
      pluginDisplayNames: NO_PLUGINS,
    });
    const route = findRouteNode(tree, '/admin/users')!;
    const connGroups = (route.children ?? []).filter((c) => c.path.startsWith('__conngroup__/'));
    expect(connGroups).toHaveLength(2);
    expect(connGroups[0].path).toMatch(/^__conngroup__\/modals\//);
    expect(connGroups[1].path).toMatch(/^__conngroup__\/extensions\//);
  });
});
