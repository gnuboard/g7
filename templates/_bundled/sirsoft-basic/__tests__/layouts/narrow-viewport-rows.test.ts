/**
 * @file narrow-viewport-rows.test.ts
 * @description 297·320px 좁은 화면에서 고정 문구·단추 줄이 화면 밖으로 넘치지 않는다 (#143 실측 후속)
 *
 * 2026-10-13 Chrome MCP 실측(긴 글과 무관, 375px 이상은 정상):
 * - 상품 목록·카테고리의 검색 줄: 입력칸이 `flex-1` 이지만 `min-w-0` 이 없어 기본 최소 폭 아래로 줄지 않아 [검색] 단추가
 *   313px 까지 나갔다 — 297px 문서 31px, 320px 8px 넘침.
 * - 게시글 상세 하단 단추 묶음(관리자: 답변·스크랩·수정·삭제 271px): 한 줄 고정이라 297px 29px, 320px 6px 넘침.
 * - 인기글 기간 단추 줄(오늘·이번 주·이번 달·올해): 297px 에서 「1년」 단추가 302px 까지 나가 20px 넘침.
 *
 * @scenario-manifest tests/scenarios/mobile-viewport-overflow.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const layoutsDir = path.resolve(__dirname, '../../layouts');

/** 레이아웃 JSON 을 로드한다. */
function loadLayout(relativePath: string): Node {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relativePath), 'utf-8'));
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

describe('좁은 화면 고정 줄', () => {
  /**
   * @scenario case=shop_search_row_narrow
   * @effects document_no_horizontal_overflow
   */
  it.each(['partials/shop/list/_search_filter_bar.json', 'shop/category.json'])('%s 상품 검색 입력칸은 flex-1 min-w-0', (file) => {
    const inputs = findAll(loadLayout(file), (n) => n.name === 'Input' && n.props?.name === 'keyword');
    expect(inputs.length).toBe(1);
    expect(tokens(inputs[0])).toEqual(expect.arrayContaining(['flex-1', 'min-w-0']));
  });

  /**
   * @scenario case=post_action_buttons_wrap
   * @effects document_no_horizontal_overflow
   */
  it('게시글 상세 하단 단추 묶음은 flex-wrap', () => {
    const groups = findAll(loadLayout('partials/board/types/basic/show.json'), (n) => tokens(n).includes('ml-auto') && tokens(n).includes('justify-end'));
    expect(groups.length).toBe(1);
    expect(tokens(groups[0])).toContain('flex-wrap');
  });

  /**
   * @scenario case=popular_period_filter_wrap
   * @effects document_no_horizontal_overflow
   */
  it('인기글 기간 단추 줄은 flex-wrap', () => {
    const rows = findAll(loadLayout('board/popular.json'), (n) => (n.children ?? []).filter((c: Node) => c.name === 'Button' && JSON.stringify(c.actions ?? []).includes('/boards/popular?period=')).length >= 3);
    expect(rows.length).toBe(1);
    expect(tokens(rows[0])).toContain('flex-wrap');
  });
});
