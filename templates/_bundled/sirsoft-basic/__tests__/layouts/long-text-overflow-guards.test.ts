/**
 * @file long-text-overflow-guards.test.ts
 * @description 긴 텍스트 넘침 방지 — 레이아웃 JSON 의 className 토큰 회귀 차단 (#143)
 *
 * 사이트 이름이 공백 없이 길면(`SirSoftBasicMenuReverse`) 모바일 상단 우측 단추가 화면 밖으로 밀리고
 * 홈 환영 카드 제목이 카드를 넘쳤다. flex 자식은 기본 `min-width:auto` 라 줄바꿈할 수 없는 토큰 폭 아래로
 * 줄지 않고, 블록 제목에는 단어 중간 줄바꿈이 없었다. 같은 결함이 이름·제목·본문을 그리는 화면 곳곳에 있어
 * 네 가지 표시 규칙으로 일괄 보호한다.
 *
 * - 상단 바 이름: 감싼 flex 자식 `min-w-0 flex-1`, 이름 `truncate`, 우측 단추 묶음 `shrink-0`
 * - R2 블록 글: `break-words`
 * - R3 flex 줄 안의 글: `min-w-0` + `wrap-anywhere` (`break-words` 는 flex 최소 폭 계산을 줄이지 못한다)
 * - R4 좁은 칸: `truncate` + 폭 상한(flex 자식이면 `min-w-0`, 줄지 않는 배지면 `max-w-32`)
 *
 * `responsive.*.props.className` 은 기본 className 을 통째로 교체하므로 반응형 문자열에도 같은 클래스가 있어야 한다.
 *
 * @scenario-manifest tests/scenarios/long-text-overflow.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const layoutsDir = path.resolve(__dirname, '../../layouts');

/**
 * 레이아웃 JSON 을 로드합니다.
 *
 * @param relativePath layouts/ 기준 상대 경로
 * @return 파싱된 레이아웃 객체
 */
function loadLayout(relativePath: string): Node {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relativePath), 'utf-8'));
}

/**
 * 술어를 만족하는 모든 노드를 깊이 우선으로 모읍니다.
 *
 * @param node 탐색 시작 노드/배열
 * @param predicate 판정 함수
 * @param out 결과 누적 배열
 * @return 찾은 노드 배열
 */
function findAll(node: unknown, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    for (const c of node) findAll(c, predicate, out);
  } else if (node && typeof node === 'object') {
    if (predicate(node as Node)) out.push(node as Node);
    for (const v of Object.values(node as Node)) findAll(v, predicate, out);
  }

  return out;
}

/**
 * 술어를 만족하는 첫 노드를 찾습니다. 없으면 테스트를 실패시킵니다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @param label 실패 메시지용 이름
 * @return 찾은 노드
 */
function findOne(node: unknown, predicate: (n: Node) => boolean, label: string): Node {
  const found = findAll(node, predicate);
  expect(found.length, `${label} 노드를 찾지 못했다`).toBeGreaterThan(0);

  return found[0];
}

/**
 * 노드의 기본 className 과 모든 반응형 className 문자열을 돌려줍니다.
 *
 * @param node 레이아웃 노드
 * @return className 문자열 목록(기본 + 반응형)
 */
function classStrings(node: Node): string[] {
  const out: string[] = [];
  if (typeof node.props?.className === 'string') out.push(node.props.className);
  for (const r of Object.values((node.responsive ?? {}) as Record<string, Node>)) {
    if (typeof r?.props?.className === 'string') out.push(r.props.className);
  }

  return out;
}

/**
 * 기본·반응형 className 모두에 지정한 클래스 토큰이 들어 있는지 단언합니다(부분 문자열이 아니라 토큰 비교).
 *
 * @param node 레이아웃 노드
 * @param tokens 있어야 할 클래스 토큰
 * @param label 실패 메시지용 이름
 */
function expectTokens(node: Node, tokens: string[], label: string): void {
  const strings = classStrings(node);
  expect(strings.length, `${label}: className 없음`).toBeGreaterThan(0);
  for (const s of strings) {
    const have = s.split(/\s+/);
    for (const t of tokens) {
      expect(have, `${label}: "${s}" 에 ${t} 없음`).toContain(t);
    }
  }
}

/** text 바인딩에 표현식 조각이 들어 있는 노드 술어. */
/** className(반응형 문자열 포함) 의 토큰 전체 — 「없어야 하는 토큰」 단언용. */
const tokens = (node: Node): string[] => classStrings(node).flatMap((s) => s.split(/\s+/));

const textHas = (needle: string) => (n: Node) => typeof n.text === 'string' && n.text.includes(needle);

/** className 토큰에 지정 토큰이 모두 있는 노드 술어. */
const classHas = (...tokens: string[]) => (n: Node) =>
  typeof n.props?.className === 'string' && tokens.every((t) => n.props.className.split(/\s+/).includes(t));

describe('U1·U10 사용자 기본 레이아웃(_user_base)', () => {
  const layout = loadLayout('_user_base.json');

  /**
   * @scenario case=mobile_header_long_site_name
   * @effects header_right_buttons_in_viewport, site_name_single_line_ellipsis
   */
  it('모바일 상단: 좌측 이름 칸 min-w-0 flex-1, 이름 truncate, 우측 단추 묶음 shrink-0', () => {
    const left = findOne(layout, (n) => n.id === 'mobile_header_left', 'mobile_header_left');
    expectTokens(left, ['min-w-0', 'flex-1'], 'mobile_header_left');
    const name = findOne(left, textHas('site_name'), '모바일 상단 이름 Span');
    expectTokens(name, ['truncate'], '모바일 상단 이름 Span');
    const right = findOne(layout, (n) => n.id === 'mobile_header_right', 'mobile_header_right');
    expectTokens(right, ['shrink-0'], 'mobile_header_right');
  });

  /**
   * @scenario case=drawer_board_menu_long_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('모바일 서랍 게시판 메뉴: 이름 min-w-0 truncate, 글 수 shrink-0', () => {
    const name = findOne(layout, (n) => n.text === '{{board.name}}', '서랍 게시판 이름');
    expectTokens(name, ['min-w-0', 'truncate'], '서랍 게시판 이름');
    const count = findOne(layout, (n) => n.text === '{{board.post_count}}', '서랍 게시판 글 수');
    expectTokens(count, ['shrink-0'], '서랍 게시판 글 수');
  });
});

describe('U2 홈 환영 카드', () => {
  const layout = loadLayout('partials/home/_welcome_card.json');

  /**
   * @scenario case=home_welcome_card_long_site_name
   * @effects block_text_wraps_within_box
   */
  it('제목 H2 와 설명 P 는 break-words', () => {
    expectTokens(findOne(layout, textHas('site_name'), '환영 제목'), ['break-words'], '환영 제목');
    expectTokens(findOne(layout, textHas('site_description'), '환영 설명'), ['break-words'], '환영 설명');
  });
});

describe('U3 상품 상세 머리', () => {
  const layout = loadLayout('partials/shop/detail/_header.json');

  /**
   * @scenario case=product_detail_long_name
   * @effects flex_text_shrinks_and_wraps
   */
  it('상품명 H1 min-w-0 wrap-anywhere, 감싼 열 min-w-0', () => {
    expectTokens(findOne(layout, textHas('name_localized'), '상품명'), ['min-w-0', 'wrap-anywhere'], '상품명');
    const column = findOne(
      layout,
      (n) => (n.children ?? []).some((c: Node) => typeof c.text === 'string' && c.text.includes('category_name')),
      '상품명 감싼 열',
    );
    expectTokens(column, ['min-w-0'], '상품명 감싼 열');
  });
});

describe('U4 마이페이지 프로필', () => {
  const layout = loadLayout('partials/mypage/profile/_view.json');

  /**
   * @scenario case=mypage_profile_long_name
   * @effects flex_text_shrinks_and_wraps, block_text_wraps_within_box
   */
  it('이름·닉네임 min-w-0 wrap-anywhere(반응형 포함), 상태 배지 shrink-0, 이메일 break-all, 주소 break-words', () => {
    expectTokens(findOne(layout, (n) => n.text === "{{user.data?.name ?? '-'}}", '프로필 이름'), ['min-w-0', 'wrap-anywhere'], '프로필 이름');
    expectTokens(findOne(layout, textHas('user.data?.nickname'), '프로필 닉네임'), ['min-w-0', 'wrap-anywhere'], '프로필 닉네임');
    expectTokens(findOne(layout, textHas('user.data?.status_label'), '프로필 상태 배지'), ['shrink-0'], '프로필 상태 배지');
    expectTokens(findOne(layout, textHas('user.data?.email'), '프로필 이메일'), ['break-all'], '프로필 이메일');
    expectTokens(findOne(layout, textHas('user.data?.address_detail'), '프로필 주소'), ['break-words'], '프로필 주소');
  });
});

describe('U5 게시글 댓글', () => {
  const layout = loadLayout('partials/board/show/_comment_item.json');

  /**
   * @scenario case=comment_long_url
   * @effects flex_text_shrinks_and_wraps
   */
  it('댓글 본문 P 3곳 wrap-anywhere, 본문 칸 flex-1 min-w-0', () => {
    const bodies = findAll(layout, (n) => n.name === 'P' && n.text === '{{comment?.content}}');
    expect(bodies.length).toBe(3);
    bodies.forEach((b, i) => expectTokens(b, ['wrap-anywhere'], `댓글 본문 ${i + 1}`));
    const root = Array.isArray(layout.components) ? layout.components[0] : layout;
    const column = findOne(root, classHas('flex-1'), '댓글 본문 칸');
    expectTokens(column, ['flex-1', 'min-w-0'], '댓글 본문 칸');
  });
});

describe.each(['partials/search/posts/_list.json', 'partials/search/posts/_section.json'])('U6 통합 검색 %s', (file) => {
  const layout = loadLayout(file);

  /**
   * @scenario case=search_result_long_title
   * @effects flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap
   */
  it('제목 HtmlContent min-w-0 wrap-anywhere, 게시판 배지 truncate max-w-32', () => {
    const title = findOne(layout, (n) => n.name === 'HtmlContent' && String(n.props?.content ?? '').includes('title_highlighted'), '검색 제목');
    expectTokens(title, ['min-w-0', 'wrap-anywhere'], '검색 제목');
    expectTokens(findOne(layout, (n) => n.text === '{{post?.board_name}}', '검색 게시판 배지'), ['truncate', 'max-w-32'], '검색 게시판 배지');
  });
});

describe('U7 쇼핑 카테고리', () => {
  const layout = loadLayout('shop/category.json');

  /**
   * @scenario case=shop_category_long_name
   * @effects flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap
   */
  it('이름·설명 min-w-0 wrap-anywhere, 경로 줄 flex-wrap, 경로 이름 truncate max-w-32', () => {
    expectTokens(findOne(layout, (n) => n.name === 'Span' && textHas('category?.data?.name_localized')(n), '카테고리 이름'), ['min-w-0', 'wrap-anywhere'], '카테고리 이름');
    expectTokens(findOne(layout, textHas('description_localized'), '카테고리 설명'), ['min-w-0', 'wrap-anywhere'], '카테고리 설명');
    const crumbs = findAll(layout, (n) => n.text === '{{crumb.name}}');
    expect(crumbs.length).toBe(2);
    crumbs.forEach((c, i) => expectTokens(c, ['truncate', 'max-w-32'], `경로 이름 ${i + 1}`));
    const row = findOne(layout, (n) => (n.children ?? []).some((c: Node) => c.iteration?.source?.includes('breadcrumb') || String(c.iteration?.source ?? '').includes('breadcrumb')), '경로 줄');
    expectTokens(row, ['flex-wrap'], '경로 줄');
  });
});

describe('U8 게시판 목록 카드', () => {
  const layout = loadLayout('partials/board/boards/_board_card.json');

  /**
   * @scenario case=board_card_long_name
   * @effects flex_text_shrinks_and_wraps
   */
  it('게시판 이름 H3 와 감싼 칸 min-w-0, 이름 wrap-anywhere, 글 수 배지 shrink-0', () => {
    expectTokens(findOne(layout, (n) => n.text === '{{board.name}}', '카드 게시판 이름'), ['min-w-0', 'wrap-anywhere'], '카드 게시판 이름');
    const wrap = findOne(layout, (n) => (n.children ?? []).some((c: Node) => c.text === '{{board.name}}'), '카드 이름 칸');
    expectTokens(wrap, ['min-w-0'], '카드 이름 칸');
    expectTokens(findOne(layout, textHas('board.posts_count'), '카드 글 수'), ['shrink-0'], '카드 글 수');
  });
});

describe('U9 홈 게시판 요약', () => {
  const layout = loadLayout('partials/home/_board_summary.json');

  /**
   * @scenario case=home_board_summary_long_name
   * @effects flex_text_shrinks_and_wraps
   */
  it('게시판 이름 Button min-w-0 wrap-anywhere, 글 수 배지 shrink-0', () => {
    expectTokens(findOne(layout, (n) => n.text === '{{item.name}}', '요약 게시판 이름'), ['min-w-0', 'wrap-anywhere'], '요약 게시판 이름');
    expectTokens(findOne(layout, textHas('item.posts_count'), '요약 글 수'), ['shrink-0'], '요약 글 수');
  });
});

describe('U11 게시글 이전/다음 탐색', () => {
  const layout = loadLayout('partials/board/show/_navigation.json');

  /**
   * @scenario case=post_navigation_long_board_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('게시판 이름 min-w-0 truncate (기본·mobile)', () => {
    expectTokens(findOne(layout, textHas('post?.data?.board?.name'), '탐색 게시판 이름'), ['min-w-0', 'truncate'], '탐색 게시판 이름');
  });
});

describe('U12 게시글 상세 제목', () => {
  const layout = loadLayout('partials/board/types/basic/show.json');

  /**
   * @scenario case=post_title_long
   * @effects flex_text_shrinks_and_wraps
   */
  it('제목 H1 은 배지와 같은 flex 줄이라 min-w-0 wrap-anywhere', () => {
    expectTokens(findOne(layout, (n) => n.name === 'H1' && textHas('post?.data?.title')(n), '게시글 제목'), ['min-w-0', 'wrap-anywhere'], '게시글 제목');
  });
});

describe.each(['basic', 'card', 'gallery'])('U13 게시판 %s 목록 머리', (type) => {
  const layout = loadLayout(`partials/board/types/${type}/index.json`);

  /**
   * @scenario case=board_index_header_long
   * @effects block_text_wraps_within_box
   */
  it('게시판 이름 H1·설명 P break-words', () => {
    expectTokens(findOne(layout, (n) => n.name === 'H1' && textHas('board?.name')(n), '목록 게시판 이름'), ['break-words'], '목록 게시판 이름');
    expectTokens(findOne(layout, (n) => n.name === 'P' && textHas('board?.description')(n), '목록 게시판 설명'), ['break-words'], '목록 게시판 설명');
  });
});

describe('U14 페이지', () => {
  const layout = loadLayout('page/show.json');

  /**
   * @scenario case=page_title_long
   * @effects flex_text_shrinks_and_wraps
   */
  it('제목 H1 min-w-0 wrap-anywhere (기본·portable)', () => {
    expectTokens(findOne(layout, (n) => n.name === 'H1' && textHas('page?.data?.title')(n), '페이지 제목'), ['min-w-0', 'wrap-anywhere'], '페이지 제목');
  });
});

describe('U15 회원 공개 프로필', () => {
  /**
   * @scenario case=public_profile_long_name
   * @effects block_text_wraps_within_box, flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap
   */
  it('프로필: 이름 H2 break-words(반응형 포함), 감싼 칸 min-w-0, 자기소개 break-words', () => {
    const layout = loadLayout('users/show.json');
    const name = findOne(layout, (n) => n.name === 'H2' && textHas('profile.data?.name')(n), '공개 프로필 이름');
    expectTokens(name, ['break-words'], '공개 프로필 이름');
    const column = findOne(layout, (n) => (n.children ?? []).includes(name), '공개 프로필 정보 칸');
    expectTokens(column, ['min-w-0'], '공개 프로필 정보 칸');
    expectTokens(findOne(layout, (n) => n.name === 'P' && textHas('profile.data?.bio')(n), '자기소개'), ['break-words'], '자기소개');
  });

  /**
   * @scenario case=public_profile_long_name
   * @effects flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap
   */
  it('작성글: 경로 이름 단추 min-w-0 truncate, 제목 H1 min-w-0 wrap-anywhere', () => {
    const layout = loadLayout('users/posts.json');
    expectTokens(findOne(layout, (n) => ['A', 'Button'].includes(n.name) && textHas('userProfile.data?.name')(n), '작성글 경로 이름'), ['min-w-0', 'truncate'], '작성글 경로 이름');
    expectTokens(findOne(layout, (n) => n.name === 'H1' && textHas('userProfile.data?.name')(n), '작성글 제목'), ['min-w-0', 'wrap-anywhere'], '작성글 제목');
  });
});

describe('U16·U17 홈 인기 게시판·최근 글', () => {
  /**
   * @scenario case=home_popular_boards_long_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('인기 게시판 이름 truncate, 글 수 배지 shrink-0', () => {
    const layout = loadLayout('partials/home/_popular_boards.json');
    expectTokens(findOne(layout, (n) => n.text === '{{board.name}}', '인기 게시판 이름'), ['truncate'], '인기 게시판 이름');
    expectTokens(findOne(layout, textHas('board.posts_count'), '인기 글 수'), ['shrink-0'], '인기 글 수');
  });

  /**
   * @scenario case=home_recent_posts_board_badge
   * @effects narrow_label_ellipsis_with_cap
   */
  it('최근 글 게시판 배지 truncate max-w-32', () => {
    const layout = loadLayout('partials/home/_recent_posts.json');
    expectTokens(findOne(layout, (n) => n.text === '{{post.board_name}}', '최근 글 게시판 배지'), ['truncate', 'max-w-32'], '최근 글 게시판 배지');
  });
});

describe('U18·U19 마이페이지 스크랩·알림', () => {
  /**
   * @scenario case=mypage_scrap_board_badge
   * @effects narrow_label_ellipsis_with_cap
   */
  it('스크랩 카드 배지(shrink-0) 3곳 truncate max-w-32 — PC 표 칸은 수정 전 자동 배치(디자인 결정 2026-10-13)', () => {
    const layout = loadLayout('partials/mypage/scrap/_list.json');
    const badges = findAll(layout, (n) => textHas('item.board_name')(n) && classStrings(n).some((c) => /(^|\s)shrink-0(\s|$)/.test(c)));
    expect(badges.length).toBe(3);
    badges.forEach((n, i) => expectTokens(n, ['truncate', 'max-w-32'], `스크랩 게시판 배지 ${i + 1}`));
  });

  /**
   * @scenario case=mypage_notification_long_body
   * @effects block_text_wraps_within_box
   */
  it('알림 본문 P break-words', () => {
    const layout = loadLayout('partials/mypage/notifications/_list.json');
    expectTokens(findOne(layout, textHas('notification.body'), '알림 본문'), ['break-words'], '알림 본문');
  });
});

describe('실측 발견 — 같은 결함군 추가 보호 (2026-10-13 Chrome MCP)', () => {
  /**
   * @scenario case=board_index_header_long
   * @effects block_text_wraps_within_box
   */
  it.each(['basic', 'card', 'gallery'])('%s 목록 머리 줄은 flex-wrap — 긴 게시판 이름이 297px 에서 54px 칸으로 26줄 접히지 않게 단추 묶음이 다음 줄로 내린다', (type) => {
    const layout = loadLayout(`partials/board/types/${type}/index.json`);
    const row = findOne(layout, classHas('justify-between', 'items-start', 'mb-6'), '목록 머리 줄');
    expectTokens(row, ['flex-wrap'], '목록 머리 줄');
  });

  /**
   * @scenario case=board_index_header_long
   * @effects narrow_label_ellipsis_with_cap
   */
  it('일반형 목록 모바일 글 제목 min-w-0 truncate — 753px 로 뻗어 N 배지·댓글 수를 화면 밖으로 밀었다', () => {
    const layout = loadLayout('partials/board/types/basic/index.json');
    const titles = findAll(layout, (n) => n.name === 'Span' && n.text === '{{post?.title}}');
    expect(titles.length).toBe(2);
    titles.forEach((t, i) => expectTokens(t, ['min-w-0', 'truncate'], `목록 글 제목 ${i + 1}`));
  });

  /**
   * @scenario case=home_board_summary_long_name
   * @scenario case=home_recent_posts_board_badge
   * @effects narrow_label_ellipsis_with_cap
   */
  it('홈 글 제목(line-clamp-1)은 min-w-0 wrap-anywhere — 공백 없는 제목이 … 없이 잘렸다', () => {
    const summary = loadLayout('partials/home/_board_summary.json');
    expectTokens(findOne(summary, classHas('line-clamp-1'), '요약 글 제목'), ['min-w-0', 'wrap-anywhere'], '요약 글 제목');
    const recent = loadLayout('partials/home/_recent_posts.json');
    expectTokens(findOne(recent, (n) => n.text === '{{post.title}}', '최근 글 제목'), ['min-w-0', 'wrap-anywhere'], '최근 글 제목');
  });

  /**
   * @scenario case=board_card_long_name
   * @effects block_text_wraps_within_box
   */
  it('게시판 카드 설명(line-clamp-2)은 break-words', () => {
    const layout = loadLayout('partials/board/boards/_board_card.json');
    expectTokens(findOne(layout, textHas('board.description'), '카드 설명'), ['break-words'], '카드 설명');
  });
});

describe('게시판 이름 배지 전수 — 모집단을 레이아웃에서 파생', () => {
  /**
   * 계획 조사에서 빠졌던 배지(인기글·내 글·내 댓글·회원 공개 프로필·회원 작성글)를 실측에서 찾았다 — 줄지 않는(shrink-0)
   * 배지가 게시판 이름 폭(640px)으로 뻗어 297px 에서 화면 밖 399px 까지 나갔다. 새 화면이 같은 배지를 추가해도 잡히도록
   * layouts 전체에서 `board_name` 을 그리는 노드를 모은다.
   *
   * @scenario case=mypage_scrap_board_badge
   * @scenario case=public_profile_long_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('board_name 만 그리는 노드는 truncate(줄지 않는 배지는 max-w-32), 다른 글과 한 줄인 노드는 min-w-0 wrap-anywhere', () => {
    const files: string[] = [];
    const walk = (dir: string) => {
      for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (e.name.endsWith('.json')) files.push(p);
      }
    };
    walk(layoutsDir);
    let count = 0;
    for (const file of files) {
      const rel = path.relative(layoutsDir, file).split(path.sep).join('/');
      const nodes = findAll(JSON.parse(fs.readFileSync(file, 'utf-8')), (n) => typeof n.text === 'string' && /\bboard_name\b/.test(n.text) && typeof n.props?.className === 'string');
      for (const n of nodes) {
        count++;
        // 마이페이지 PC 표(내 반응·스크랩)의 칸은 디자인 결정(2026-10-13)으로 수정 전 자동 배치 — 칸 안 이름은 줄임 대상이 아니다.
        if (/^partials\/mypage\/(board\/_my_reactions|scrap\/_list)\.json$/.test(rel) && !classStrings(n).some((c) => /(^|\s)(flex-)?shrink-0(\s|$)|wrap-anywhere/.test(c))) continue;
        if (!/^\{\{[^}]*board_name[^}]*\}\}$/.test(n.text.trim())) {
          expectTokens(n, ['min-w-0', 'wrap-anywhere'], `${rel} 게시판 이름이 든 줄`);
          continue;
        }
        expectTokens(n, ['truncate'], `${rel} 게시판 이름`);
        if (classStrings(n).some((s) => /(^|\s)(flex-)?shrink-0(\s|$)/.test(s))) {
          expectTokens(n, ['max-w-32'], `${rel} 게시판 이름 배지`);
        }
      }
    }
    expect(count).toBeGreaterThanOrEqual(12);
  });
});

describe('실측 발견 — 상품 상세의 운영자 입력 이름 (2026-10-13 Chrome MCP)', () => {
  /**
   * 297px 에서 카테고리 이름(733px)·옵션 이름(요약 줄 w-16 칸, 옵션 선택 라벨)이 칸 밖으로 그려져 문서가 487px 넘쳤다.
   *
   * @scenario case=product_detail_long_name
   * @effects narrow_label_ellipsis_with_cap, block_text_wraps_within_box
   */
  it('카테고리 이름 block truncate, 옵션 요약 이름 shrink-0 truncate, 옵션 선택 라벨 break-words', () => {
    const header = loadLayout('partials/shop/detail/_header.json');
    expectTokens(findOne(header, textHas('category_name'), '상품 카테고리 이름'), ['block', 'truncate'], '상품 카테고리 이름');
    const summary = loadLayout('partials/shop/detail/_info_summary.json');
    expectTokens(findOne(summary, (n) => typeof n.text === 'string' && n.text.includes('group.name_localized'), '옵션 요약 이름'), ['shrink-0', 'truncate'], '옵션 요약 이름');
    const purchase = loadLayout('partials/shop/detail/_purchase_card.json');
    expectTokens(findOne(purchase, (n) => n.name === 'Label' && typeof n.text === 'string' && n.text.includes('group.name_localized'), '옵션 선택 라벨'), ['break-words'], '옵션 선택 라벨');
  });
});

describe('실측 발견 — 하위 카테고리 칩 (2026-10-13 Chrome MCP)', () => {
  /**
   * 칩 줄은 flex-wrap 이지만 칩 하나가 화면보다 넓으면(758px) 줄을 바꿔도 넘친다 — 297px 에서 문서가 492px 넘쳤다.
   *
   * @scenario case=shop_category_long_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('칩 래퍼·단추는 max-w-full, 칩 이름은 min-w-0 truncate', () => {
    const layout = loadLayout('shop/category.json');
    const name = findOne(layout, (n) => n.text === '{{sub?.name_localized}}', '하위 카테고리 칩 이름');
    expectTokens(name, ['min-w-0', 'truncate'], '하위 카테고리 칩 이름');
    const chip = findOne(layout, (n) => ['A', 'Button'].includes(n.name) && (n.children ?? []).includes(name), '하위 카테고리 칩');
    expectTokens(chip, ['max-w-full'], '하위 카테고리 칩');
    const wrap = findOne(layout, (n) => (n.children ?? []).includes(chip), '하위 카테고리 칩 래퍼');
    expectTokens(wrap, ['max-w-full'], '하위 카테고리 칩 래퍼');
  });
});

describe.each(['partials/search/posts/_list.json', 'partials/search/posts/_section.json'])('실측 발견 — 통합 검색 제목 줄 %s', (file) => {
  /**
   * 297px 에서 게시판 배지(128px) 옆 제목 칸이 81px 로 눌려 12줄로 접혔다. 배지와 제목은 한 줄에 두고(디자인 결정 2026-10-13 —
   * 보통 제목에서 배지·제목이 두 줄로 갈라지지 않게) 배지는 폭 상한으로 줄이며, 제목은 줄어들며 줄을 바꾼다.
   *
   * @scenario case=search_result_long_title
   * @effects flex_text_shrinks_and_wraps
   */
  it('배지·제목 한 줄(flex-wrap 없음), 배지 truncate max-w-32', () => {
    const layout = loadLayout(file);
    const row = findOne(layout, (n) => (n.children ?? []).some((c: Node) => c.text === '{{post?.board_name}}'), '검색 제목 줄');
    expect(tokens(row)).not.toContain('flex-wrap');
    expectTokens(findOne(row.children ?? [], (c: Node) => c.text === '{{post?.board_name}}', '검색 배지'), ['truncate', 'max-w-32'], '검색 배지');
  });
});

describe('실측 발견 — 마이페이지 표의 제목 칸 (2026-10-13 Chrome MCP)', () => {
  /**
   * 제목 칸을 w-full max-w-0 으로 남는 폭에 맞췄더니 보통 제목에서도 다른 칸(배지·날짜·해제·머리글)이 2~3줄로 눌렸다 —
   * 디자인 결정(2026-10-13)으로 표는 수정 전 자동 배치로 되돌린다. 제목 칸에 폭 강제 클래스를 다시 넣지 않는다.
   *
   * @scenario case=mypage_scrap_board_badge
   * @effects narrow_label_ellipsis_with_cap
   */
  it.each([
    ['partials/mypage/scrap/_list.json', /item\.title/, 2],
    ['partials/mypage/board/_my_reactions.json', /reaction\.(excerpt|title)|deleted_comment/, 3],
  ])('%s 제목 칸은 수정 전 자동 배치(w-full max-w-0 없음)', (file, needle, expected) => {
    const layout = loadLayout(file as string);
    const cells = findAll(layout, (n) => n.name === 'Td' && findAll(n.children ?? [], (c) => typeof c.text === 'string' && (needle as RegExp).test(c.text) && c.name !== 'Td').length > 0
      && findAll(n.children ?? [], (c) => typeof c.text === 'string' && /board_name/.test(c.text)).length === 0);
    expect(cells.length).toBe(expected);
    cells.forEach((td) => {
      expect(tokens(td)).not.toContain('max-w-0');
    });
  });
});

describe('실측 발견 — 인기글·내 글·내 댓글 목록 (2026-10-13 Chrome MCP)', () => {
  /**
   * 297px: 인기글 제목 단추에 min-w-0 이 없어 줄지 않고 작성 시각을 화면 밖으로 밀어 문서가 23px 넘쳤다.
   * 내 글·내 댓글은 게시판 배지(128px) 옆 제목이 31px 로 눌렸고, 본문 미리보기(line-clamp-2)는 줄바꿈 없이 잘렸다.
   *
   * @scenario case=home_popular_boards_long_name
   * @scenario case=mypage_scrap_board_badge
   * @effects flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap, block_text_wraps_within_box
   */
  /**
   * 배지·제목은 한 묶음으로 늘 한 줄(디자인 결정 2026-10-13), N·시각은 따로 묶어 좁은 폭에서만 다음 줄로 내린다(디자인 결정 2026-10-13
   * validate-plan 라운드 3) — 한 줄 고정이던 때 297px 에서 제목이 0px 였고, 긴 게시판 이름이면 문서가 28px 넘쳤다.
   * 제목은 최소 3rem 을 지키고 배지가 줄어든다 — 배지가 줄지 않으면 360px·긴 이름에서 제목이 2px 였다.
   */
  it('인기글 배지·제목 묶음은 한 줄(min-w-0 flex-1 basis-40), 제목 min-w-12·배지 줄어듦, 줄은 flex-wrap, N·시각 묶음 shrink-0', () => {
    const layout = loadLayout('partials/board/popular/_popular_item.json');
    const title = findOne(layout, (n) => n.text === '{{post.title}}', '인기글 제목');
    expectTokens(title, ['min-w-12', 'truncate', 'flex-1'], '인기글 제목');
    const group = findOne(layout, (n) => (n.children ?? []).includes(title), '인기글 배지·제목 묶음');
    // 기준 폭 8rem(basis-32)이면 320px 에서도 시각이 같은 줄에 남아 제목이 79px(4~5자)였다 — 10rem 이면 320px 에서 시각이 내려가고 제목 122px (7.1.1 실측)
    expectTokens(group, ['min-w-0', 'flex-1', 'basis-40'], '인기글 배지·제목 묶음');
    expect(tokens(group)).not.toContain('flex-wrap');
    const badge = findOne(group.children ?? [], (c: Node) => c.text === '{{post.board_name}}', '인기글 배지');
    expectTokens(badge, ['min-w-0', 'truncate', 'max-w-32'], '인기글 배지');
    expect(tokens(badge)).not.toContain('flex-shrink-0');
    const row = findOne(layout, (n) => (n.children ?? []).includes(group), '인기글 제목 줄');
    expectTokens(row, ['flex-wrap'], '인기글 제목 줄');
    const meta = findOne(row.children ?? [], (c: Node) => (c.children ?? []).some((k: Node) => String(k.text ?? '').includes('created_at_formatted')), '인기글 N·시각 묶음');
    expectTokens(meta, ['shrink-0'], '인기글 N·시각 묶음');
  });

  /**
   * 320px 에서 작성자 묶음이 줄어들 수 없어(min-w-0 없음) 비회원 작성자 「관리자」·「비회원」 배지가 글자 단위로 접혔다(7.1.1).
   * 작성자 묶음은 줄어들고 조회·댓글 수 묶음은 제 폭을 지킨다.
   *
   * @scenario case=home_popular_boards_long_name
   * @effects flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap
   */
  it('인기글 작성자 줄: 작성자 묶음 min-w-0, 조회·댓글 수 묶음 shrink-0', () => {
    const layout = loadLayout('partials/board/popular/_popular_item.json');
    const author = findOne(layout, (n) => (n.children ?? []).some((c: Node) => c.name === 'UserInfo'), '인기글 작성자 묶음');
    expectTokens(author, ['min-w-0'], '인기글 작성자 묶음');
    const row = findOne(layout, (n) => (n.children ?? []).includes(author), '인기글 작성자 줄');
    const stats = findOne(row.children ?? [], (c: Node) => c !== author && findAll(c, (k) => String(k.text ?? '').includes('view_count')).length > 0, '조회·댓글 수 묶음');
    expectTokens(stats, ['shrink-0'], '조회·댓글 수 묶음');
  });

  /**
   * @scenario case=mypage_scrap_board_badge
   * @effects narrow_label_ellipsis_with_cap, block_text_wraps_within_box
   */
  it.each([
    ['partials/mypage/board/_my_posts.json', 'post.content_plain'],
    ['partials/mypage/board/_my_comments.json', 'comment.content'],
  ])('%s 배지·제목 한 줄(디자인 결정 2026-10-13 — flex-wrap 없음), 미리보기 break-words', (file, preview) => {
    const layout = loadLayout(file);
    const row = findOne(layout, classHas('flex-1', 'min-w-0', 'flex', 'items-center', 'gap-2'), '배지·제목 줄');
    expect(tokens(row)).not.toContain('flex-wrap');
    expectTokens(findOne(layout, (n) => ['P', 'Div'].includes(n.name) && typeof n.text === 'string' && n.text.includes(preview), '미리보기'), ['break-words'], `${file} 미리보기`);
  });
});

describe('실측 발견 — 로고 이미지 비율 (2026-10-13 Chrome MCP)', () => {
  /**
   * 이름 칸이 줄어들 수 있게 되면서(min-w-0) 가로로 긴 로고(300×60)가 297px 에서 68×32 로 가로만 눌려 찌그러졌다.
   * 상자 안에서 비율을 지키고 왼쪽에 붙인다.
   *
   * @scenario case=mobile_header_long_site_name
   * @effects header_right_buttons_in_viewport
   */
  it('모바일 상단 로고 img 는 max-w-full object-contain object-left, 감싼 칸 min-w-0', () => {
    const layout = loadLayout('_user_base.json');
    const left = findOne(layout, (n) => n.id === 'mobile_header_left', 'mobile_header_left');
    const img = findOne(left, (n) => n.name === 'Img', '모바일 상단 로고');
    expectTokens(img, ['max-w-full', 'object-contain', 'object-left'], '모바일 상단 로고');
    expectTokens(findOne(left, (n) => (n.children ?? []).includes(img), '로고 감싼 칸'), ['min-w-0'], '로고 감싼 칸');
  });
});


describe('게시글 분류 배지 전수 — 모집단을 레이아웃에서 파생 (7.1.1)', () => {
  /**
   * 글 보기의 분류 배지가 줄지 않는 배지 묶음 안에서 폭 상한이 없어, 띄어쓰기 없는 긴 분류 이름이 카드 밖으로 나가
   * 390px 에서 문서가 628px 로 넓어졌다(basic 1.2.1 「카테고리 칩 같은 좁은 칸은 한 줄로 줄임」). 글 목록(일반·카드·갤러리)·
   * 홈 게시판 요약·회원 작성글·문의 분류 배지도 같은 모양이라 함께 잠근다.
   *
   * @scenario case=post_title_long
   * @effects narrow_label_ellipsis_with_cap, document_no_horizontal_overflow
   */
  it('분류 값만 그리는 노드는 truncate max-w-32', () => {
    const files: string[] = [];
    const walk = (dir: string) => {
      for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (e.name.endsWith('.json')) files.push(p);
      }
    };
    walk(layoutsDir);
    const seen: string[] = [];
    for (const file of files) {
      const rel = path.relative(layoutsDir, file).split(path.sep).join('/');
      const nodes = findAll(JSON.parse(fs.readFileSync(file, 'utf-8')), (n) => typeof n.text === 'string' && /^\{\{\s*[\w?.]+\.category(\s*\?\?\s*'')?\s*\}\}$/.test(n.text.trim()));
      for (const n of nodes) {
        seen.push(rel);
        expectTokens(n, ['truncate', 'max-w-32'], `${rel} 분류 배지 ${n.text}`);
      }
    }
    // 글 보기 2(본문·원글) · 일반 목록 2 · 카드 · 갤러리 · 홈 요약 · 회원 작성글 2 · 문의 2
    expect(seen.length).toBeGreaterThanOrEqual(11);
    expect(seen).toContain('partials/board/types/basic/show.json');
  });
});
