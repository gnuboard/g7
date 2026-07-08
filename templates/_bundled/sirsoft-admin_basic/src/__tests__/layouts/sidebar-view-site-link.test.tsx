/**
 * @file sidebar-view-site-link.test.tsx
 * @description 관리자 사이드바 하단 "사이트 보기" 링크 + 모바일 드로어 상단 잘림 수정 회귀 테스트 (이슈 #450)
 *
 * 검증 항목:
 * 1. _admin_base.json 에 sidebar_footer > "사이트 보기" 링크 노드 존재
 * 2. 링크는 A 태그, href="/", target="_blank", rel="noopener noreferrer"
 * 3. 링크는 스크롤 메뉴(admin_sidebar_menu) 밖의 형제로 배치 (하단 고정)
 * 4. 모바일 드로어(.left_sidebar_area_portable) 가 inset-y-0(top:0) 대신 헤더 아래(top:4rem)에서 시작
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

import adminBase from '../../../layouts/_admin_base.json';

interface LayoutNode {
  id?: string;
  name?: string;
  props?: Record<string, unknown>;
  children?: LayoutNode[];
  [key: string]: unknown;
}

function findById(node: LayoutNode | LayoutNode[] | undefined, id: string): LayoutNode | null {
  if (!node) return null;
  const nodes = Array.isArray(node) ? node : [node];
  for (const n of nodes) {
    if (n && typeof n === 'object') {
      if (n.id === id) return n;
      for (const key of Object.keys(n)) {
        const child = (n as Record<string, unknown>)[key];
        if (Array.isArray(child) || (child && typeof child === 'object')) {
          const found = findById(child as LayoutNode | LayoutNode[], id);
          if (found) return found;
        }
      }
    }
  }
  return null;
}

describe('이슈 #450 — 관리자 사이드바 "사이트 보기" 링크', () => {
  it('sidebar_footer 노드가 존재한다', () => {
    const footer = findById(adminBase as LayoutNode, 'sidebar_footer');
    expect(footer).not.toBeNull();
    expect(footer?.name).toBe('Div');
  });

  it('사이트 보기 링크는 A 태그로 유저 홈(/)을 새 탭으로 연다', () => {
    const link = findById(adminBase as LayoutNode, 'sidebar_view_site_link');
    expect(link).not.toBeNull();
    expect(link?.name).toBe('A');
    expect(link?.props?.href).toBe('/');
    expect(link?.props?.target).toBe('_blank');
    expect(link?.props?.rel).toBe('noopener noreferrer');
  });

  it('노출 조건(if)이 없어 관리자 화면에서 항상 표시된다', () => {
    const link = findById(adminBase as LayoutNode, 'sidebar_view_site_link');
    expect(link?.if).toBeUndefined();
  });

  it('sidebar_footer 는 스크롤 메뉴(admin_sidebar_menu) 내부가 아닌 left_sidebar_area 의 직접 자식이다', () => {
    const sidebarArea = findById(adminBase as LayoutNode, 'left_sidebar_area');
    const childIds = (sidebarArea?.children ?? []).map((c) => c.id);
    expect(childIds).toContain('sidebar_footer');
    // 스크롤 메뉴 내부에 footer 가 중첩되지 않아야 함 (하단 고정 보장)
    const menu = findById(adminBase as LayoutNode, 'admin_sidebar_menu');
    const menuChildIds = (menu?.children ?? []).map((c) => c.id);
    expect(menuChildIds).not.toContain('sidebar_footer');
  });
});

describe('이슈 #450 — 모바일 드로어 상단 잘림 수정 (CSS)', () => {
  const css = fs.readFileSync(
    path.resolve(__dirname, '../../styles/layout/_admin_base.css'),
    'utf8',
  );

  it('.left_sidebar_area_portable 는 inset-y-0(top:0)로 헤더를 덮지 않는다', () => {
    const block = css.match(/\.left_sidebar_area_portable\s*\{[^}]*\}/s)?.[0] ?? '';
    expect(block).not.toContain('inset-y-0');
  });

  it('.left_sidebar_area_portable 는 헤더 높이(4rem)만큼 내려 시작한다', () => {
    const block = css.match(/\.left_sidebar_area_portable\s*\{[^}]*\}/s)?.[0] ?? '';
    expect(block).toMatch(/top:\s*4rem/);
    expect(block).toMatch(/bottom:\s*0/);
  });

  it('.sidebar_view_site_link 스타일이 정의되어 있다', () => {
    expect(css).toContain('.sidebar_view_site_link');
    expect(css).toContain('.sidebar_footer');
  });
});
