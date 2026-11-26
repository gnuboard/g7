/**
 * @file admin-board-reaction-icon-thumbnail-exempt.test.ts
 * @description 관리자 게시판 화면의 반응 아이콘 — 썸네일 규격을 붙이지 않고 면제 사유를 남긴다 (검토)
 *
 * 배경: 반응 아이콘(`reaction_type.icon_url`)은 20px 칸에 `object-contain` 으로 모양을 그대로 보인다. 이 자리에
 * `thumbPreset="xs"`(64×64 가운데 자르기)를 붙이면 정사각이 아닌 아이콘(가로로 긴 이모지 묶음 등)의 양 끝이
 * 서버에서 잘려 나가 화면에서 모양이 바뀐다. 그래서 규격 없이 원본을 받고, 레이아웃 감사 규칙(룰 4)이 경고하지
 * 않도록 바로 위 주석에 `thumb-exempt:` 사유를 남긴다.
 *
 * 검증 방식: 굽힌 `.json` 에서 반응 아이콘 주소를 그리는 `Img` 를 모두 모아 규격이 없고 면제 주석이 있는지 본다.
 *
 * @scenario-manifest tests/scenarios/admin-board-reaction-icon-thumbnail-exempt.yaml
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import reactionsIndex from '../../../layouts/admin/admin_board_reactions_index.json';
import boardFormBasic from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import postCardContent from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import postComment from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';
import reactionTypeCards from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_cards.json';
import reactionTypeEditor from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_editor.json';
import settingsBasic from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_basic.json';

type Node = Record<string, any>;

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @param out 결과 누적 배열
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
  } else if (node && typeof node === 'object') {
    const obj = node as Node;
    if (predicate(obj)) out.push(obj);
    Object.values(obj).forEach((value) => findAll(value, predicate, out));
  }

  return out;
}

const LAYOUTS: Array<[string, unknown]> = [
  ['admin_board_reactions_index', reactionsIndex],
  ['admin_board_form/_tab_basic', boardFormBasic],
  ['admin_board_post_detail/_post_card_content', postCardContent],
  ['admin_board_post_detail/_comment', postComment],
  ['admin_board_settings/_reaction_type_cards', reactionTypeCards],
  ['admin_board_settings/_reaction_type_editor', reactionTypeEditor],
  ['admin_board_settings/_tab_board_settings_basic', settingsBasic],
];

describe('관리자 게시판 반응 아이콘 썸네일 면제 (검토)', () => {
  for (const [name, layout] of LAYOUTS) {
    // @scenario screen=admin_board_reaction_icons
    // @effects reaction_icon_keeps_original_shape
    it(`${name} — 반응 아이콘 Img 는 규격이 없고 면제 사유를 남긴다`, () => {
      const icons = findAll(layout, (n) => n.name === 'Img' && /icon_url/.test(String(n.props?.src ?? '')));
      expect(icons.length, name).toBeGreaterThan(0);
      for (const icon of icons) {
        expect(icon.props.thumbPreset, name).toBeUndefined();
        expect(String(icon.props.className ?? ''), name).toContain('object-contain');
        expect(String(icon.comment ?? ''), name).toMatch(/^thumb-exempt: 반응 아이콘은 contain 표시라/);
      }
    });
  }
});
