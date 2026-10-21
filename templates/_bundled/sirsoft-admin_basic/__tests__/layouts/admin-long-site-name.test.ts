/**
 * @file admin-long-site-name.test.ts
 * @description 관리자 긴 사이트 이름·사용자 이름 넘침 방지 — 클래스 계약 (#143)
 *
 * - A1 모바일 상단 `.mobile_header_left` 는 `min-w-0 flex-1` 이어야 한다. 이름 Span 은 이미 truncate 이고 우측은
 *   flex-shrink-0 이지만, 바깥 칸이 기본 `min-width:auto` 라 긴 이름 폭 아래로 줄지 않아 우측 단추를 화면 밖으로 밀었다.
 * - A2 로그인 화면 제목(`logo`)은 블록 제목이라 `break-words`.
 * - A3 PC 헤더 사용자 이름·이메일은 감싼 칸 `min-w-0` + 각 줄 `truncate max-w-xs`.
 * - A4 프로필 드롭다운 이름·이메일 `truncate`, A5 페이지 제목·설명 `break-words` (컴포넌트 원문 확인).
 *
 * @scenario-manifest tests/scenarios/admin-long-site-name.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const root = path.resolve(__dirname, '../..');

/** 템플릿 루트 기준 파일을 읽는다. */
function read(relativePath: string): string {
  return fs.readFileSync(path.join(root, relativePath), 'utf-8');
}

/** 술어를 만족하는 첫 노드를 깊이 우선으로 찾는다. */
function find(node: unknown, pred: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const c of node) {
      const r = find(c, pred);
      if (r) return r;
    }
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) return node as Node;
    for (const v of Object.values(node as Node)) {
      const r = find(v, pred);
      if (r) return r;
    }
  }

  return undefined;
}

/** id 로 노드를 찾아 기본 className 토큰을 돌려준다. */
function tokensById(layout: Node, id: string): string[] {
  const node = find(layout, (n) => n.id === id);
  expect(node, `${id} 노드 없음`).toBeDefined();

  return String(node!.props?.className ?? '').split(/\s+/);
}

/**
 * CSS 원문에서 선택자 블록의 @apply 토큰을 모은다.
 *
 * @param css CSS 원문
 * @param selector 선택자(예: `.mobile_header_left`)
 * @return @apply 토큰 목록
 */
function applyTokens(css: string, selector: string): string[] {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const block = css.match(new RegExp(`${escaped}\\s*\\{([^}]*)\\}`));
  expect(block, `${selector} 블록 없음`).toBeTruthy();

  return [...block![1].matchAll(/@apply\s+([^;]+);/g)].flatMap((m) => m[1].trim().split(/\s+/));
}

describe('관리자 긴 사이트 이름', () => {
  /**
   * @scenario surface=mobile_header
   * @effects mobile_header_right_in_viewport, mobile_site_name_single_line_ellipsis
   */
  it('A1 .mobile_header_left 는 min-w-0 flex-1 (우측은 flex-shrink-0, 이름 칸은 min-w-0 flex-1 유지)', () => {
    const css = read('src/styles/layout/_admin_base.css');
    expect(applyTokens(css, '.mobile_header_left')).toEqual(expect.arrayContaining(['min-w-0', 'flex-1']));
    expect(applyTokens(css, '.mobile_header_right')).toContain('flex-shrink-0');
    expect(applyTokens(css, '.mobile_logo')).toEqual(expect.arrayContaining(['min-w-0', 'flex-1']));
  });

  /**
   * @scenario surface=login_title
   * @effects login_title_wraps_within_card
   */
  it('A2 로그인 화면 제목 break-words', () => {
    const layout = JSON.parse(read('layouts/admin_login.json'));
    expect(tokensById(layout, 'logo')).toContain('break-words');
  });

  /**
   * @scenario surface=desktop_user_info
   * @effects user_info_ellipsis_with_cap
   */
  it('A3 PC 헤더 사용자 칸 min-w-0, 이름·이메일 truncate max-w-xs', () => {
    const layout = JSON.parse(read('layouts/_admin_base.json'));
    expect(tokensById(layout, 'user_info')).toContain('min-w-0');
    expect(tokensById(layout, 'user_name')).toEqual(expect.arrayContaining(['truncate', 'max-w-xs']));
    expect(tokensById(layout, 'user_email')).toEqual(expect.arrayContaining(['truncate', 'max-w-xs']));
  });

  /**
   * @scenario surface=profile_dropdown
   * @effects user_info_ellipsis_with_cap
   */
  it('A4 프로필 드롭다운 이름·이메일 truncate', () => {
    const src = read('src/components/composite/UserProfile.tsx');
    expect(src).toMatch(/<Div className="[^"]*\btruncate\b[^"]*">\{user\.name\}<\/Div>/);
    expect(src).toMatch(/<Div className="[^"]*\btruncate\b[^"]*">\{user\.email\}<\/Div>/);
  });

  /**
   * @scenario surface=page_header
   * @effects page_title_wraps
   */
  it('A5 페이지 제목·설명 break-words', () => {
    const src = read('src/components/composite/PageHeader.tsx');
    expect(src).toMatch(/<H1 className="[^"]*\bbreak-words\b[^"]*">\{title\}<\/H1>/);
    expect(src).toMatch(/<P className="[^"]*\bbreak-words\b[^"]*">\{description\}<\/P>/);
  });
});

describe('실측 발견 — 대시보드 최근 활동 (2026-10-13 Chrome MCP)', () => {
  /**
   * 활동 로그 제목에 긴 게시판 이름(「게시판 삭제 (https://…)」)이 들어가면 제목 P 가 754px 로 뻗어 1280px 대시보드
   * 본문이 305px 넘쳤다. 부모 칸은 이미 min-w-0 이라 블록 글의 break-words 로 충분하다.
   *
   * @scenario surface=page_header
   * @effects page_title_wraps
   */
  it('최근 활동 제목·설명 P 는 break-words', () => {
    const layout = JSON.parse(read('layouts/admin_dashboard.json'));
    const title = find(layout, (n) => typeof n.id === 'string' && n.id.startsWith('activity_title_'));
    const desc = find(layout, (n) => typeof n.id === 'string' && n.id.startsWith('activity_desc_'));
    expect(String(title?.props?.className ?? '').split(/\s+/)).toContain('break-words');
    expect(String(desc?.props?.className ?? '').split(/\s+/)).toContain('break-words');
  });
});
