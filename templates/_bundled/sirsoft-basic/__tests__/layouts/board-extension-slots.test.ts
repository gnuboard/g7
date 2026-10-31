/**
 * @file board-extension-slots.test.ts
 * @description 글·댓글·글쓰기 확장점 5종이 글이 보이는 모든 화면에 열려 있는지 정적 검증 (#149)
 *
 * 배경(제보 — 확장 모듈로 글마다 「언어 배지」, 글쓰기에 「글 언어」 입력을 붙이려는 외부 개발자):
 *   target-id 로 지목하는 overlay 는 트리에서 첫 일치 노드 하나에만 주입된다. 게시판 글쓰기·상세는 같은 파일을
 *   유형별 블록(basic·gallery·card) 세 곳에 include 하고, 목록은 세 유형 파일을 한 레이아웃에 함께 둔다 — 그래서
 *   data-ref 를 열어도 basic 블록에만 닿는다. 확장점은 같은 이름의 모든 자리를 채우므로 확장점으로 연다.
 *
 * 잠그는 것:
 *   - 파일마다 확장점 이름·개수(계획 §6.1 표 19곳)와 반복(iteration) 노드 안에 있는지
 *   - 정규화 props(postId·boardSlug·context 등)와 context 값 — 확장이 화면을 구분하는 계약
 *   - 호스트 className `contents`, 표지 `data-extension-point`, 노드 id 없음(행·3중 include 마다 DOM id 중복 방지)
 *   - 서빙 등가: board/index·board/form·board/show 레이아웃을 partial 까지 펼쳤을 때의 노드 수
 *
 * 검증 방식: 굽기 산출 `.json` 정적 대조(DOM 비의존).
 *
 * @scenario-manifest tests/scenarios/board-extension-slots.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const LAYOUTS = path.resolve(__dirname, '../../layouts');

const load = (rel: string): Node => JSON.parse(fs.readFileSync(path.join(LAYOUTS, rel), 'utf8'));

/** partial 참조 경로 해석 — 레이아웃 루트 기준을 먼저 보고, 없으면 부르는 파일 기준 */
const resolvePartial = (ref: string, fromRel: string): string => {
  if (fs.existsSync(path.join(LAYOUTS, ref))) return ref;
  const sibling = path.posix.join(path.posix.dirname(fromRel), ref);
  if (fs.existsSync(path.join(LAYOUTS, sibling))) return sibling;
  throw new Error(`partial 을 찾지 못했다: ${ref} (from ${fromRel})`);
};

/** `{ "partial": … }` 참조를 파일에서 읽어 재귀로 인라인한다 — 설치(서빙)가 하는 전개와 같은 모양 */
const inlineFile = (rel: string): unknown => {
  const walk = (node: unknown): unknown => {
    if (Array.isArray(node)) return node.map(walk);
    if (node && typeof node === 'object') {
      const obj = node as Node;
      if (typeof obj.partial === 'string') return inlineFile(resolvePartial(obj.partial, rel));
      return Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, walk(v)]));
    }
    return node;
  };
  return walk(load(rel));
};

/** 노드와 그 조상 사슬(반복 노드 여부 판정용)을 함께 모은다 */
const collectSlots = (root: unknown, names: string[]): Array<{ node: Node; inIteration: boolean }> => {
  const out: Array<{ node: Node; inIteration: boolean }> = [];
  const walk = (node: unknown, inIteration: boolean): void => {
    if (Array.isArray(node)) {
      node.forEach((child) => walk(child, inIteration));
      return;
    }
    if (!node || typeof node !== 'object') return;
    const obj = node as Node;
    const here = inIteration || obj.iteration != null;
    if (obj.type === 'extension_point' && names.includes(obj.name)) out.push({ node: obj, inIteration: here });
    for (const value of Object.values(obj)) if (value && typeof value === 'object') walk(value, here);
  };
  walk(root, false);
  return out;
};

const SLOT_NAMES = [
  'board_post_row_badges',
  'board_post_header_badges',
  'board_comment_row_badges',
  'board_post_form_top',
  'board_post_form_bottom',
];

interface FileCase {
  /** 시나리오 매니페스트 surface 축 값 */
  surface: string;
  file: string;
  name: string;
  count: number;
  context?: string;
  /** 반복 노드 안이어야 하는가 (폼·상세 머리는 반복 밖) */
  inIteration: boolean;
  props: Record<string, string>;
}

const ROW = (postId: string, boardSlug: string, context: string, post = '{{post}}') => ({
  post,
  postId: `{{${postId}}}`,
  boardSlug: `{{${boardSlug}}}`,
  context,
});

const FORM_PROPS = {
  mode: "{{route.id ? 'edit' : ((form_data?.data?.parent_id || query?.parent_id) ? 'reply' : 'create')}}",
  boardSlug: '{{route.slug}}',
  postId: '{{route.id ?? null}}',
  board: '{{form_meta?.data?.board}}',
};

const CASES: FileCase[] = [
  /**
   * @scenario surface=board_list_basic, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values, basic_list_and_scrap_have_pc_and_mobile_slots
   */
  { surface: 'board_list_basic', file: 'partials/board/types/basic/index.json', name: 'board_post_row_badges', count: 2, inIteration: true, props: ROW('post?.id', 'route?.slug', 'board_list') },
  /**
   * @scenario surface=board_list_card, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'board_list_card', file: 'partials/board/types/card/index.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post?.id', 'route?.slug', 'board_list') },
  /**
   * @scenario surface=board_list_gallery, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'board_list_gallery', file: 'partials/board/types/gallery/index.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post?.id', 'route?.slug', 'board_list') },
  // 인기글 한 줄은 부르는 쪽(_popular_list)의 반복 안에서 include 된다 — 이 파일 단독으로는 반복 밖
  /**
   * @scenario surface=popular, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'popular', file: 'partials/board/popular/_popular_item.json', name: 'board_post_row_badges', count: 1, inIteration: false, props: ROW('post.id', 'post.board_slug', 'popular') },
  /**
   * @scenario surface=home_recent, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'home_recent', file: 'partials/home/_recent_posts.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post.id', 'post.board_slug', 'home_recent') },
  /**
   * @scenario surface=home_board_summary, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'home_board_summary', file: 'partials/home/_board_summary.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post.id', 'item.slug', 'home_board_summary') },
  /**
   * @scenario surface=user_posts, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'user_posts', file: 'users/posts.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post.id', 'post.board_slug', 'user_posts') },
  /**
   * @scenario surface=user_profile_recent, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'user_profile_recent', file: 'users/show.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post.id', 'post.board_slug', 'user_profile_recent') },
  /**
   * @scenario surface=mypage_posts, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'mypage_posts', file: 'partials/mypage/board/_my_posts.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post.id', 'post.board_slug', 'mypage_posts') },
  /**
   * @scenario surface=mypage_scrap, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values, basic_list_and_scrap_have_pc_and_mobile_slots
   */
  { surface: 'mypage_scrap', file: 'partials/mypage/scrap/_list.json', name: 'board_post_row_badges', count: 2, inIteration: true, props: ROW('item.post_id', 'item.board_slug', 'mypage_scrap', '{{item}}') },
  /**
   * @scenario surface=search_all, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'search_all', file: 'partials/search/posts/_section.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post?.id', 'post?.board_slug', 'search_all') },
  /**
   * @scenario surface=search_posts, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'search_posts', file: 'partials/search/posts/_list.json', name: 'board_post_row_badges', count: 1, inIteration: true, props: ROW('post?.id', 'post?.board_slug', 'search_posts') },
  /**
   * @scenario surface=board_show, locale=ko, site_name=empty
   * @effects show_header_and_comment_slots_reach_all_three_type_branches, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'board_show', file: 'partials/board/types/basic/show.json', name: 'board_post_header_badges', count: 1, inIteration: false, props: ROW('post?.data?.id', 'route?.slug', 'board_show', '{{post?.data}}') },
  /**
   * @scenario surface=board_show_comment, locale=ko, site_name=empty
   * @effects show_header_and_comment_slots_reach_all_three_type_branches, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'board_show_comment', 
    file: 'partials/board/show/_comment_item.json', name: 'board_comment_row_badges', count: 1, inIteration: false,
    props: { commentData: '{{comment}}', commentId: '{{comment?.id}}', postId: '{{post?.data?.id}}', boardSlug: '{{route?.slug}}', context: 'board_show_comment' },
  },
  /**
   * @scenario surface=mypage_comments, locale=ko, site_name=empty
   * @effects show_header_and_comment_slots_reach_all_three_type_branches, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'mypage_comments', 
    file: 'partials/mypage/board/_my_comments.json', name: 'board_comment_row_badges', count: 1, inIteration: true,
    props: { commentData: '{{comment}}', commentId: '{{comment.id}}', postId: '{{comment.post_id_val}}', boardSlug: '{{comment.board_slug}}', context: 'mypage_comments' },
  },
  /**
   * @scenario surface=board_form, locale=ko, site_name=empty
   * @effects form_top_and_bottom_slots_reach_all_three_type_branches, slot_props_follow_normalized_contract_and_context_values
   */
  { surface: 'board_form', file: 'partials/board/form/_post_form.json', name: 'board_post_form_top', count: 1, inIteration: false, props: FORM_PROPS },
  { surface: 'board_form', file: 'partials/board/form/_post_form.json', name: 'board_post_form_bottom', count: 1, inIteration: false, props: FORM_PROPS },
];

describe('게시판 확장점 — 파일별 자리 (#149 §6.1)', () => {
  /**
   * @scenario surface=board_list_basic, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges
   */
  it('계획 표의 자리 수는 19곳이다', () => {
    expect(CASES.reduce((sum, c) => sum + c.count, 0)).toBe(19);
  });

  for (const c of CASES) {
    describe(`${c.file} — ${c.name}`, () => {
      const slots = collectSlots(load(c.file), [c.name]);

      /**
       * @scenario surface=board_list_basic, locale=ko, site_name=empty
       * @effects every_post_list_row_has_board_post_row_badges, basic_list_and_scrap_have_pc_and_mobile_slots
       */
      it(`자리가 ${c.count}개 있다`, () => {
        expect(slots).toHaveLength(c.count);
      });

      /**
       * @scenario surface=board_list_basic, locale=ko, site_name=empty
       * @effects every_post_list_row_has_board_post_row_badges
       */
      it(c.inIteration ? '반복 노드 안에 있다' : '반복 밖(행 문맥이 아닌 자리)이다', () => {
        for (const s of slots) expect(s.inIteration).toBe(c.inIteration);
      });

      /**
       * @scenario surface=board_list_basic, locale=ko, site_name=empty
       * @effects slot_props_follow_normalized_contract_and_context_values
       */
      it('정규화 props 와 context 값이 계약대로다', () => {
        for (const s of slots) {
          for (const [key, value] of Object.entries(c.props)) expect(s.node.props?.[key], key).toBe(value);
        }
      });

      /**
       * @scenario surface=board_list_basic, locale=ko, site_name=empty
       * @effects slot_hosts_have_no_node_id_and_use_contents_marker
       */
      it('호스트는 contents·data-extension-point 표지를 갖고 노드 id 가 없다', () => {
        for (const s of slots) {
          expect(s.node.props?.className).toBe('contents');
          expect(s.node.props?.['data-extension-point']).toBe(c.name);
          expect(s.node.id).toBeUndefined();
          // data-slot 은 슬롯 시스템 지시어(노드 slot)라 쓰면 호스트가 SlotContainer 로 옮겨진다
          expect(s.node.slot).toBeUndefined();
        }
      });
    });
  }

  /**
   * @scenario surface=board_show_comment, locale=ko, site_name=empty
   * @effects slot_props_follow_normalized_contract_and_context_values
   */
  it('댓글 자리의 행 원본 prop 은 comment 가 아니다 — 서빙이 props.comment 를 개발자 주석으로 지운다', () => {
    for (const file of ['partials/board/show/_comment_item.json', 'partials/mypage/board/_my_comments.json']) {
      for (const s of collectSlots(load(file), ['board_comment_row_badges'])) expect(s.node.props).not.toHaveProperty('comment');
    }
  });
});

describe('게시판 확장점 — 서빙 등가(레이아웃을 partial 까지 펼친 트리)', () => {
  const count = (layout: string, name: string) => collectSlots(inlineFile(layout), [name]).length;

  /**
   * @scenario surface=board_list_gallery, locale=ko, site_name=empty
   * @effects every_post_list_row_has_board_post_row_badges, basic_list_and_scrap_have_pc_and_mobile_slots
   */
  it('board/index — 세 목록 유형 모두에 글 행 자리(basic 은 PC·모바일 2)가 있어 4노드다', () => {
    expect(count('board/index.json', 'board_post_row_badges')).toBe(4);
  });

  /**
   * @scenario surface=board_form, locale=ko, site_name=empty
   * @effects form_top_and_bottom_slots_reach_all_three_type_branches
   */
  it('board/form — 유형별 세 갈래 모두에 맨 위·맨 아래 자리가 한 쌍씩 있다', () => {
    expect(count('board/form.json', 'board_post_form_top')).toBe(3);
    expect(count('board/form.json', 'board_post_form_bottom')).toBe(3);
  });

  /**
   * @scenario surface=board_show, locale=ko, site_name=empty
   * @effects show_header_and_comment_slots_reach_all_three_type_branches
   */
  it('board/show — 세 갈래 모두에 상세 머리·댓글 자리가 있다', () => {
    expect(count('board/show.json', 'board_post_header_badges')).toBe(3);
    expect(count('board/show.json', 'board_comment_row_badges')).toBe(3);
  });

  /**
   * @scenario surface=board_list_card, locale=ko, site_name=empty
   * @effects slot_hosts_have_no_node_id_and_use_contents_marker
   */
  it('펼친 트리의 다섯 자리 모두 노드 id 가 없다(3중 include 에서 id 중복이 생기지 않는다)', () => {
    for (const layout of ['board/index.json', 'board/form.json', 'board/show.json']) {
      for (const s of collectSlots(inlineFile(layout), SLOT_NAMES)) expect(s.node.id, layout).toBeUndefined();
    }
  });
});
