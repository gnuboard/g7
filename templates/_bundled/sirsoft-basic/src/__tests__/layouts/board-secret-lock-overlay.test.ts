// e2e:allow 레이아웃 표현식 계약 검증(카드·갤러리 비밀글 잠금 조건). 작성자 본인 화면은 브라우저 실측으로 별도 확인.
/**
 * @file board-secret-lock-overlay.test.ts
 * @description 카드형·갤러리형 게시판 목록 — 비밀글 잠금 오버레이를 원문을 열 수 있는 사람에게는 씌우지 않는다.
 *
 * 목록 항목에는 글마다의 abilities 가 없고, 열람 판정은 게시판 단위 권한(비밀글 열람·게시판 관리)과
 * 작성자 본인(`is_author`)으로 한다. 서버는 작성자 본인에게 비밀글 썸네일을 내려주므로, 작성자에게 잠금
 * 오버레이를 씌우면 자기 글 썸네일이 자물쇠로 가려진다.
 */

import { describe, it, expect } from 'vitest';

import cardIndex from '../../../layouts/partials/board/types/card/index.json';
import galleryIndex from '../../../layouts/partials/board/types/gallery/index.json';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** `{{ … }}` 식을 주어진 컨텍스트로 평가한다 */
function evaluate(expr: string, ctx: Record<string, unknown>): unknown {
  const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(ctx);

  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${body});`)(...names.map((n) => ctx[n]));
}

/** 비밀글 잠금 오버레이 노드(자물쇠 아이콘을 품은 절대 위치 div) */
const lockOverlays = (layout: unknown) =>
  collect(
    layout,
    (n) =>
      typeof n.if === 'string' &&
      (n.if as string).startsWith('{{post?.is_secret &&') &&
      JSON.stringify(n.children ?? []).includes('"name":"lock"'),
  );

const noAbilities = { data: { abilities: { can_read_secret: false, can_manage: false } } };

describe.each([
  ['카드형', cardIndex],
  ['갤러리형', galleryIndex],
])('%s 목록 — 비밀글 잠금 오버레이', (_label, layout) => {
  const overlays = lockOverlays(layout);

  /** @effects secret_overlay_follows_collection_ability */
  it('잠금 오버레이가 하나 있다', () => {
    expect(overlays).toHaveLength(1);
  });

  /**
   * @scenario case=secret_overlay_reader
   * @effects secret_overlay_follows_collection_ability
   */
  it('권한 없는 다른 사람에게는 잠금을 씌운다', () => {
    const shown = evaluate(overlays[0].if as string, { post: { is_secret: true, is_author: false }, posts: noAbilities });
    expect(Boolean(shown)).toBe(true);
  });

  /**
   * @scenario case=secret_overlay_author
   * @effects secret_overlay_skips_author
   */
  it('작성자 본인에게는 잠금을 씌우지 않는다', () => {
    const shown = evaluate(overlays[0].if as string, { post: { is_secret: true, is_author: true }, posts: noAbilities });
    expect(Boolean(shown)).toBe(false);
  });

  /**
   * @scenario case=secret_overlay_reader
   * @effects secret_overlay_follows_collection_ability
   */
  it('비밀글 열람 권한이나 게시판 관리 권한이 있으면 잠금을 씌우지 않는다', () => {
    const reader = { data: { abilities: { can_read_secret: true, can_manage: false } } };
    const manager = { data: { abilities: { can_read_secret: false, can_manage: true } } };
    expect(Boolean(evaluate(overlays[0].if as string, { post: { is_secret: true, is_author: false }, posts: reader }))).toBe(false);
    expect(Boolean(evaluate(overlays[0].if as string, { post: { is_secret: true, is_author: false }, posts: manager }))).toBe(false);
  });
});
