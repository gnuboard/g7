// e2e:allow 레이아웃 뿌리 키 동작의 엔진 렌더 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(모바일 폭 관리자 화면 사이드바 Esc 닫기)이 덮는다.
/**
 * @file admin-base-mobile-sidebar-escape.test.tsx
 * @description 관리자 모바일 사이드바를 Esc 로 닫는다 (7.1.1 키보드 범위)
 *
 * 모바일 폭에서 사이드바를 열면 바깥 덮개(`mobile_overlay`)는 클릭으로만 닫혔고 Esc 로는 닫히지 않았다(사용자 템플릿은 닫힘 —
 * `_user_base` 뿌리의 Escape 받기). 사이드바를 연 직후 초점은 머리 띠의 [메뉴] 단추에 있어 사이드바 자신은 키를 받지 못하므로,
 * 사용자 템플릿과 같이 레이아웃 뿌리(`admin_layout_root`)에서 Escape 를 받아 **열려 있을 때만** 닫는다(닫혀 있을 때 Esc 마다 전역을
 * 흔들지 않게).
 *
 * @scenario surface=admin_base
 * @effects mobile_sidebar_closes_on_escape
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import userEvent from '@testing-library/user-event';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  waitFor,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import adminBase from '../../layouts/_admin_base.json';

type Node = Record<string, any>;

/**
 * id 로 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 노드 또는 undefined
 */
function findById(node: unknown, id: string): Node | undefined {
  if (!node || typeof node !== 'object') return undefined;
  const v = node as Node;
  if (v.id === id) return v;
  for (const child of Object.values(v)) {
    const found = findById(child, id);
    if (found) return found;
  }

  return undefined;
}

/**
 * 실제 뿌리 노드의 키 동작만 남기고 자식을 초점 받을 단추 하나로 바꾼 레이아웃 — 머리 띠·사이드바의 데이터 의존 없이 뿌리 동작만 잰다.
 *
 * @returns 시험 레이아웃
 */
function rootOnlyLayout(): any {
  const root = findById(adminBase, 'admin_layout_root')!;

  return {
    version: '1.0.0',
    layout_name: 'test/admin-base-escape',
    components: [{ ...root, children: [{ type: 'basic', name: 'Button', props: { type: 'button' }, text: 'menu' }] }],
  };
}

describe('관리자 모바일 사이드바 Esc 닫기 (7.1.1)', () => {
  let registry: MockComponentRegistry;

  beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  // @scenario surface=admin_base
  // @effects mobile_sidebar_closes_on_escape
  it('사이드바가 열린 채 Esc 를 누르면 _global.sidebarOpen 이 false 가 된다', async () => {
    const utils = createLayoutTest(rootOnlyLayout(), { componentRegistry: registry as any, locale: 'ko', initialState: { _global: { sidebarOpen: true } } });
    await utils.render();
    const user = userEvent.setup();
    await user.tab();
    expect(document.activeElement).toBe(screen.getByRole('button'));
    await user.keyboard('{Escape}');
    await waitFor(() => expect(utils.getState()._global.sidebarOpen).toBe(false));
    utils.cleanup();
  });

  // @scenario surface=admin_base
  // @effects mobile_sidebar_closes_on_escape
  it('사이드바가 닫혀 있으면 Esc 는 전역을 쓰지 않고, Esc 밖 키는 닫지 않는다', async () => {
    const closed = createLayoutTest(rootOnlyLayout(), { componentRegistry: registry as any, locale: 'ko', initialState: { _global: { sidebarOpen: false } } });
    await closed.render();
    const user = userEvent.setup();
    await user.tab();
    await user.keyboard('{Escape}');
    expect(closed.getState()._global.sidebarOpen).toBe(false);
    closed.cleanup();

    const open = createLayoutTest(rootOnlyLayout(), { componentRegistry: registry as any, locale: 'ko', initialState: { _global: { sidebarOpen: true } } });
    await open.render();
    await user.tab();
    await user.keyboard('a');
    expect(open.getState()._global.sidebarOpen).toBe(true);
    open.cleanup();
  });

  // @scenario surface=admin_base
  // @effects mobile_sidebar_closes_on_escape
  it('뿌리의 Escape 받기는 열려 있을 때만 닫는 조건 갈래다(사용자 템플릿 _user_base 와 같은 꼴)', () => {
    const root = findById(adminBase, 'admin_layout_root')!;
    const esc = (root.actions ?? []).filter((a: any) => a.type === 'keydown' && a.key === 'Escape');
    expect(esc).toHaveLength(1);
    expect(JSON.stringify(esc[0])).toContain('_global.sidebarOpen');
    expect(JSON.stringify(esc[0])).toContain('"sidebarOpen":false');
  });
});
