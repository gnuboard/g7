/**
 * @file admin-long-text-overflow.test.ts
 * @description 관리자 화면의 긴 게시판 이름·글 제목 넘침 방지 (#143 실측 후속)
 *
 * 2026-10-13 Chrome MCP 실측: 공백 없는 긴 게시판 이름·글 제목(`https://example.com/aaaa…`)이면
 * - 관리자 대시보드 「최근 게시글」 카드의 메타 줄(게시판 · 작성자 · 시각)이 줄바꿈 없이 뻗어 297px 본문이 378px 넘쳤다.
 * - 게시판 글 관리 목록의 제목 칸(`flex-1`, `min-w-0` 없음)이 줄지 않아 320px 본문이 622px 넘쳤다.
 *
 * @scenario-manifest tests/scenarios/admin-long-text-overflow.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const root = path.resolve(__dirname, '../../../..');

/** 모듈 루트 기준 JSON 을 읽는다. */
function load(relativePath: string): Node {
  return JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf-8'));
}

/** 술어를 만족하는 모든 노드를 모은다. */
function findAll(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    for (const c of node) findAll(c, pred, out);
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    for (const v of Object.values(node as Node)) findAll(v, pred, out);
  }

  return out;
}

/** className 토큰 배열. */
function tokens(node: Node | undefined): string[] {
  return String(node?.props?.className ?? '').split(/\s+/);
}

describe('관리자 긴 글 넘침', () => {
  /**
   * @scenario surface=dashboard_latest_posts
   * @effects admin_content_no_overflow
   */
  it('대시보드 최근 게시글 메타 줄 — 게시판·작성자만 truncate, 시간은 shrink-0 로 항상 보인다(디자인 결정 2026-10-13)', () => {
    const layout = load('resources/extensions/admin_dashboard_community.json');
    const meta = findAll(layout, (n) => typeof n.id === 'string' && n.id.startsWith('latest_post_meta_'));
    expect(meta.length).toBe(1);
    const [names, time] = meta[0].children ?? [];
    expect(tokens(names)).toEqual(expect.arrayContaining(['min-w-0', 'truncate']));
    expect(tokens(time)).toEqual(expect.arrayContaining(['shrink-0', 'whitespace-nowrap']));
    expect(String(time.text)).toContain('created_at');
  });

  /**
   * @scenario surface=posts_index_title
   * @effects admin_content_no_overflow
   */
  it('글 관리 목록 제목 칸은 flex-1 min-w-0, 제목은 min-w-0 wrap-anywhere', () => {
    const layout = load('resources/layouts/admin/admin_board_posts_index.json');
    const title = findAll(layout, (n) => n.name === 'Span' && n.text === '{{row.title}}');
    expect(title.length).toBe(1);
    expect(tokens(title[0])).toEqual(expect.arrayContaining(['min-w-0', 'wrap-anywhere']));
    const column = findAll(layout, (n) => tokens(n).includes('flex-1') && findAll(n.children ?? [], (c) => c === title[0]).length > 0);
    expect(column.length).toBe(1);
    expect(tokens(column[0])).toContain('min-w-0');
  });
});
