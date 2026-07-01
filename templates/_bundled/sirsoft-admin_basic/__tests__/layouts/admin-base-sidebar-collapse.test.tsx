/**
 * @file admin-base-sidebar-collapse.test.tsx
 * @description 데스크톱 사이드바 접기 기능의 레이아웃 정합 테스트.
 *
 * 배경:
 * - 기존 PC 햄버거(menu_toggle_btn)는 _global.sidebarOpen(모바일 슬라이드 상태)만
 *   토글해 데스크톱에서는 시각 효과가 없었다(접기 미구현).
 * - 데스크톱 사이드바 접기 기능을 추가: 햄버거 → toggleSidebar 핸들러,
 *   left_sidebar_area 가 _global.sidebarCollapsed 에 반응해 .left_sidebar_area_collapsed
 *   변형(width 0)을 받는다. 접힘 상태는 localStorage 로 영속(sidebarHandler).
 * - 본 테스트는 레이아웃 JSON 의 배선(햄버거 액션 / 사이드바 조건부 클래스)이
 *   유지됨을 보장한다.
 */

import { describe, it, expect } from 'vitest';
import adminBase from '../../layouts/_admin_base.json';

function findById(node: unknown, id: string): any {
  if (!node || typeof node !== 'object') return undefined;
  const v = node as any;
  if (v.id === id) return v;
  for (const child of Object.values(v)) {
    const found = findById(child, id);
    if (found) return found;
  }
  return undefined;
}

describe('데스크톱 사이드바 접기 레이아웃 정합', () => {
  it('PC 햄버거(menu_toggle_btn)가 toggleSidebar 핸들러를 호출한다', () => {
    const btn = findById(adminBase, 'menu_toggle_btn');
    expect(btn).toBeDefined();
    const actions = btn.actions ?? [];
    const handlers = actions.map((a: any) => a.handler);
    expect(handlers).toContain('toggleSidebar');
    // 기존 모바일용 setState(sidebarOpen) 직접 토글이 아니어야 한다
    const togglesSidebarOpen = actions.some(
      (a: any) => a.handler === 'setState' && a.params?.sidebarOpen !== undefined
    );
    expect(togglesSidebarOpen).toBe(false);
  });

  it('left_sidebar_area 가 sidebarCollapsed 에 반응하는 조건부 클래스를 가진다', () => {
    const sidebar = findById(adminBase, 'left_sidebar_area');
    expect(sidebar).toBeDefined();
    const className = sidebar.props?.className ?? '';
    expect(className).toContain('left_sidebar_area');
    expect(className).toContain('sidebarCollapsed');
    expect(className).toContain('left_sidebar_area_collapsed');
  });

  it('모바일(portable) 사이드바는 기존 sidebarOpen 슬라이드 동작을 유지한다', () => {
    const sidebar = findById(adminBase, 'left_sidebar_area');
    const portableClass = sidebar.responsive?.portable?.props?.className ?? '';
    expect(portableClass).toContain('left_sidebar_area_portable');
    expect(portableClass).toContain('sidebarOpen');
    expect(portableClass).toContain('left_sidebar_area_portable_open');
  });
});
