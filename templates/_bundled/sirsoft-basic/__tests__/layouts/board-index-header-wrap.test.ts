/**
 * @file board-index-header-wrap.test.ts
 * @description 게시판 목록 머리말 — 좁은 화면 [글쓰기] 글자 꺾임 회귀 (7.1.0 영역 J D-J12, 관측 O-I2-5)
 *
 * 390px 화면에서 게시판 이름 칸과 버튼 묶음이 한 줄을 나눠 쓰며 [글쓰기] 버튼이 68px 로 줄어 「글쓰/기」 로
 * 꺾였다(높이 88px). 제목 칸은 줄어들 수 있게(min-w-0), 버튼 묶음은 줄지 않고(shrink-0) 글자를 한 줄로(nowrap) 둔다.
 * 일반형·카드형·갤러리형 세 목록이 같은 머리말 구조를 쓴다.
 */

import { describe, it, expect } from 'vitest';

type Node = Record<string, any>;

/** 조건에 맞는 첫 노드를 깊이 우선으로 찾는다. */
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

describe.each(['basic', 'card', 'gallery'])('게시판 %s 목록 머리말', (type) => {
  const layout = require(`../../layouts/partials/board/types/${type}/index.json`);

  /**
   * @scenario control=board_index_header
   * @effects board_header_actions_never_wrap_per_char
   */
  it('머리 줄은 제목 칸 min-w-0, 버튼 묶음 shrink-0·whitespace-nowrap 이다', () => {
    const row = find(layout, (n) => typeof n.props?.className === 'string' && n.props.className.includes('justify-between items-start') && n.props.className.includes('mb-6'));
    expect(row).toBeDefined();
    const [title, actions] = row!.children;
    expect(title.props.className).toContain('min-w-0');
    expect(actions.props.className).toContain('shrink-0');
    expect(actions.props.className).toContain('whitespace-nowrap');
  });
});
