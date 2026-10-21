/**
 * @file list-loading-empty-state.test.tsx
 * @description 사용자 목록 화면 — 목록을 받는 동안에는 「없습니다」를 말하지 않는다
 *
 * 목록 데이터소스는 화면이 먼저 그려진 뒤에 받는다(progressive). 받기 전에는 엔진이 데이터소스
 * 값을 `undefined` 로 두고, 식 안의 `a.b.c` 접근도 안전하게 `undefined` 로 풀린다. 그래서
 * `!orders.data.data || orders.data.data.length === 0` 같은 빈 안내 조건은 받는 동안 참이 되어,
 * 주문·찜·게시판 목록 등에서 「내역이 없습니다」가 먼저 보였다가 목록이 들어왔다. 약관 창은
 * `termsContent?.loading` 으로 받는 중을 가르려 했지만 엔진은 데이터소스 값에 `loading` 을 싣지
 * 않아 그 조건은 늘 거짓이었다(스피너 대신 「내용이 없습니다」).
 *
 * 「받는 중」은 데이터소스 값이 `undefined` 인 것이다 — 엔진의 흐림 판정과 같은 기준이고, 받기에
 * 실패하면 엔진이 값을 `null` 로 바꾸므로 그때는 안내가 다시 보인다.
 *
 * 각 화면의 실제 레이아웃 JSON 에서 안내 노드를 꺼내 하네스로 렌더한다:
 *  1. 받는 중(`undefined`) — 안내가 없다. 2. 받은 뒤 0건 — 보인다. 3. 받기 실패(`null`) — 보인다.
 *  4. 받은 뒤 1건 이상 — 없다. 5. 전수 — 목록 빈 안내는 표본이거나 사유가 적힌 제외 대상이고,
 *     데이터소스 값의 `loading` 을 읽는 죽은 조건이 없다.
 *
 * 알림 센터·헤더는 스텁이다 — 실제 컴포넌트 규칙(목록이 비었고 `loading` 이 아니면 빈 문구)만 흉내 낸다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { normalizeConditionRecipes, parseConditionExpr, combineConditions } from '@core/template-engine/layout-editor/spec/conditionRecipeEngine';

type Node = Record<string, any>;

interface Case {
  screen: string;
  /** `layouts/` 기준 JSON 경로 */
  file: string;
  find: { id?: string; name?: string; ifIncludes?: string };
  source: string;
  kind: 'node' | 'notification' | 'header';
  /** 안내가 떠야 하는 받은 뒤 값 */
  empty: unknown;
  /** 안내가 없어야 하는 받은 뒤 값 */
  rows: unknown;
  initialData?: Record<string, any>;
  globalState?: Record<string, any>;
  /** 받기 실패(null) 때 대상이 보여야 하는가 — 빈 안내는 true, 금액 표기(무료배송)는 받은 값이 없으면 보이면 안 되므로 false */
  showOnError?: boolean;
}

const page = (rows: any[]) => ({ data: { data: rows, pagination: { current_page: 1, last_page: 1 } } });

const CASES: Case[] = [
  { screen: '전체 게시판', file: 'board/boards.json', find: { ifIncludes: 'boardList.data.length === 0' }, source: 'boardList', kind: 'node', empty: { data: [] }, rows: { data: [{ slug: 'free' }] } },
  { screen: '인기글', file: 'partials/board/popular/_popular_list.json', find: { ifIncludes: 'popularPosts.data ?? []).length === 0' }, source: 'popularPosts', kind: 'node', empty: { data: [] }, rows: { data: [{ id: 1 }] } },
  { screen: '홈 · 인기 게시판', file: 'partials/home/_popular_boards.json', find: { ifIncludes: 'popular_boards.data.length === 0' }, source: 'popular_boards', kind: 'node', empty: { data: [] }, rows: { data: [{ slug: 'free' }] } },
  { screen: '홈 · 최근 글', file: 'partials/home/_recent_posts.json', find: { ifIncludes: 'recent_posts.data.length === 0' }, source: 'recent_posts', kind: 'node', empty: { data: [] }, rows: { data: [{ id: 1 }] } },
  { screen: '마이페이지 · 내 글', file: 'partials/mypage/board/_my_posts.json', find: { ifIncludes: 'myPosts.data.data.length === 0' }, source: 'myPosts', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 내 댓글', file: 'partials/mypage/board/_my_comments.json', find: { ifIncludes: 'myComments.data.data.length === 0' }, source: 'myComments', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 내 반응', file: 'partials/mypage/board/_my_reactions.json', find: { id: 'my-reactions-empty' }, source: 'myReactions', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 내 신고', file: 'partials/mypage/board/_my_reports.json', find: { id: 'my-reports-empty' }, source: 'myReports', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 문의', file: 'partials/mypage/inquiries/_list.json', find: { ifIncludes: 'myInquiries.data.items.length === 0' }, source: 'myInquiries', kind: 'node', empty: { data: { items: [] } }, rows: { data: { items: [{ id: 1 }] } } },
  { screen: '마이페이지 · 마일리지 내역', file: 'partials/mypage/mileage/_list.json', find: { ifIncludes: 'transactions.data.length === 0' }, source: 'mileage_history', kind: 'node', empty: { data: { transactions: { data: [] } } }, rows: { data: { transactions: { data: [{ id: 1 }] } } }, initialData: { mileage_balance: { data: { mileage: { enabled: true } } } } },
  { screen: '마이페이지 · 마일리지 꺼짐 안내', file: 'partials/mypage/mileage/_list.json', find: { ifIncludes: '!mileage_balance?.data?.mileage?.enabled' }, source: 'mileage_balance', kind: 'node', empty: { data: { mileage: { enabled: false } } }, rows: { data: { mileage: { enabled: true } } } },
  { screen: '마이페이지 · 알림', file: 'partials/mypage/notifications/_list.json', find: { ifIncludes: 'userNotifications.data.data.length === 0' }, source: 'userNotifications', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 주문', file: 'partials/mypage/orders/_list.json', find: { ifIncludes: 'orders.data.data.length === 0' }, source: 'orders', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 스크랩', file: 'partials/mypage/scrap/_list.json', find: { ifIncludes: 'scrap.data.data.length === 0' }, source: 'scrap', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '마이페이지 · 찜', file: 'partials/mypage/wishlist/_list.json', find: { ifIncludes: 'wishlist.data.data.length === 0' }, source: 'wishlist', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '장바구니', file: 'partials/shop/_cart_list.json', find: { ifIncludes: 'cartItems.data.items.length === 0' }, source: 'cartItems', kind: 'node', empty: { data: { items: [] } }, rows: { data: { items: [{ id: 1 }] } } },
  { screen: '상품 목록', file: 'partials/shop/list/_product_grid.json', find: { ifIncludes: 'products?.data?.data ?? []).length === 0' }, source: 'products', kind: 'node', empty: page([]), rows: page([{ id: 1 }]) },
  { screen: '상품 상세 · 문의 탭', file: 'partials/shop/detail/_tab_qna.json', find: { ifIncludes: 'qna.data.items.length === 0' }, source: 'qna', kind: 'node', empty: { data: { items: [] } }, rows: { data: { items: [{ id: 1 }] } } },
  { screen: '상품 상세 · 후기 탭', file: 'partials/shop/detail/_tab_reviews.json', find: { ifIncludes: 'reviews.data.reviews.data.length === 0' }, source: 'reviews', kind: 'node', empty: { data: { reviews: { data: [] } } }, rows: { data: { reviews: { data: [{ id: 1 }] } } } },
  { screen: '주문 상세 · 무료배송 표기', file: 'partials/mypage/orders/_payment.json', find: { ifIncludes: 'total_shipping_amount ?? 0) === 0' }, source: 'order', kind: 'node', empty: { data: { total_shipping_amount: '0.00' } }, rows: { data: { total_shipping_amount: 3000 } }, showOnError: false },
  { screen: '알림 센터(모바일)', file: '_user_base.json', find: { id: 'mobile_notification_center' }, source: 'notifications', kind: 'notification', empty: page([]), rows: page([{ id: 1, subject: 'a' }]), globalState: { currentUser: { uuid: 'u-1' } } },
  { screen: '헤더 알림(데스크톱)', file: '_user_base.json', find: { id: 'desktop_header' }, source: 'notifications', kind: 'header', empty: page([]), rows: page([{ id: 1, subject: 'a' }]), globalState: { currentUser: { uuid: 'u-1' } } },
];


const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');

/**
 * 트리에서 조건에 맞는 노드를 모두 찾는다(props 안 셀 정의는 건너뛴다).
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param out 결과
 * @returns 노드 목록
 */
function findAll(node: any, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
    return out;
  }
  if (typeof node.name === 'string' && typeof node.type === 'string' && predicate(node)) out.push(node);
  for (const [key, value] of Object.entries(node)) {
    if (key === 'data_sources' || key === 'props') continue;
    findAll(value, predicate, out);
  }
  return out;
}

/**
 * 레이아웃 JSON 을 읽는다.
 *
 * @param file `layouts/` 기준 경로
 * @returns JSON
 */
function readLayout(file: string): Node {
  return JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, file), 'utf8'));
}

/**
 * 노드가 표본의 찾기 조건에 맞는지.
 *
 * @param c 표본
 * @param n 노드
 * @returns 맞으면 true
 */
function matchesFind(c: Case, n: Node): boolean {
  const ref = n.id ?? n.props?.['data-testid'];
  return (c.find.id === undefined || ref === c.find.id)
    && (c.find.name === undefined || n.name === c.find.name)
    && (c.find.ifIncludes === undefined || String(n.if ?? '').includes(c.find.ifIncludes));
}

/**
 * 표본의 대상 노드를 꺼낸다.
 *
 * @param c 표본
 * @returns 대상 노드
 */
function targetOf(c: Case): Node {
  const hits = findAll(readLayout(c.file), (n) => matchesFind(c, n));
  expect(hits, `${c.file} ${JSON.stringify(c.find)}`).toHaveLength(1);
  return hits[0];
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'NotificationCenter', ({ notifications, loading }: any) =>
    (notifications ?? []).length === 0 && !loading
      ? React.createElement('div', { 'data-testid': 'target' })
      : React.createElement('div', { 'data-testid': loading ? 'loading' : 'list' }));
  registry.register('composite', 'Header', ({ notifications, notificationLoading }: any) =>
    (notifications ?? []).length === 0 && !notificationLoading
      ? React.createElement('div', { 'data-testid': 'target' })
      : React.createElement('div', { 'data-testid': notificationLoading ? 'loading' : 'list' }));
});

/**
 * 노드 하나를 데이터소스 값과 함께 렌더하고 대상이 그려졌는지 돌려준다.
 *
 * @param node 렌더할 노드(자식 제외)
 * @param source 데이터소스 id
 * @param value 데이터소스 값 — `undefined` 는 받는 중, `null` 은 받기 실패
 * @param extra 그 밖의 값
 * @returns 대상이 그려졌으면 true
 */
async function renderShown(node: Node, source: string, value: unknown, extra: { initialData?: Record<string, any>; globalState?: Record<string, any> } = {}): Promise<boolean> {
  const utils = createLayoutTest(
    { version: '1.0.0', layout_name: `test/user-loading-empty/${source}`, components: [node] } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      initialState: extra.globalState ? { _global: extra.globalState } : undefined,
      initialData: { ...(extra.initialData ?? {}), [source]: value, $templateId: 'test-template', $locale: 'ko' },
    }
  );
  try {
    const { container } = await utils.render();
    return container.querySelector('[data-testid="target"]') !== null;
  } finally {
    utils.cleanup();
  }
}

/**
 * 표본 노드를 렌더용으로 다듬는다 — 자식은 빼고(그 화면의 다른 컴포넌트가 필요 없게) 표지를 단다.
 *
 * @param c 표본
 * @returns 렌더할 노드
 */
function renderableOf(c: Case): Node {
  const { meta, children, ...rest } = targetOf(c);
  if (c.kind !== 'node') return rest;
  return { ...rest, name: 'Div', props: { ...(rest.props ?? {}), 'data-testid': 'target' }, text: 'x' };
}

describe('[case:components-datagrid-empty-message-while-loading] 사용자 목록 — 받는 중에는 「없습니다」를 말하지 않는다', () => {
  /**
   * @scenario load_state=loading
   * @effects loading_hides_empty_notice
   */
  it.each(CASES)('$screen: 받는 중에는 빈 안내가 없다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, undefined, c)).toBe(false);
  });

  /**
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_notice
   */
  it.each(CASES)('$screen: 받은 뒤 0건이면 빈 안내가 보인다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, c.empty, c)).toBe(true);
  });

  /**
   * @scenario load_state=error
   * @effects load_failure_releases_notice
   */
  it.each(CASES)('$screen: 받기 실패 뒤에는 받는 중으로 남지 않는다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, null, c)).toBe(c.showOnError ?? true);
  });

  /**
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_notice
   */
  it.each(CASES)('$screen: 받은 뒤 행이 있으면 빈 안내가 없다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, c.rows, c)).toBe(false);
  });

});

/**
 * 받은 뒤에 여는 창 — 회원가입 화면은 `refetch-data-source` 를 기다린 뒤 약관·개인정보 창을 연다(핸들러가 refetch 를
 * await). 창이 열린 동안 「받는 중」은 없고, 열렸는데 값이 없으면 받기 실패다(refetch 실패는 값을 바꾸지 않는다)
 * — 그때 「내용이 없습니다」를 보인다. 받는 중으로 가리면 영영 도는 스피너만 남는다.
 */
const OPENED_AFTER_FETCH: Case[] = [
  { screen: '회원가입 · 약관 창 내용 없음', file: 'partials/auth/_modal_terms.json', find: { ifIncludes: '!termsContent?.data}}' }, source: 'termsContent', kind: 'node', empty: { data: null }, rows: { data: { content: '약관' } } },
  { screen: '회원가입 · 개인정보 창 내용 없음', file: 'partials/auth/_modal_privacy.json', find: { ifIncludes: '!privacyContent?.data}}' }, source: 'privacyContent', kind: 'node', empty: { data: null }, rows: { data: { content: '방침' } } },
];

describe('사용자 목록 — 받은 뒤에 여는 창', () => {
  /**
   * @scenario load_state=loading
   * @effects modal_opens_after_fetch
   */
  it.each([['termsContent', 'termsModal'], ['privacyContent', 'privacyModal']])('%s 를 받은 뒤에 %s 를 연다', (source, modal) => {
    const sequences: any[][] = [];
    const visit = (n: any): void => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) { n.forEach(visit); return; }
      if (n.handler === 'sequence' && Array.isArray(n.actions)
        && n.actions.some((x: any) => x?.handler === 'openModal' && x?.target === modal)) sequences.push(n.actions);
      Object.values(n).forEach(visit);
    };
    visit(readLayout('partials/auth/_register_form.json'));
    expect(sequences.length).toBeGreaterThan(0);
    for (const steps of sequences) {
      const fetchAt = steps.findIndex((x) => x?.handler === 'refetchDataSource' && x?.params?.dataSourceId === source);
      expect(fetchAt).toBeGreaterThanOrEqual(0);
      expect(fetchAt).toBeLessThan(steps.findIndex((x) => x?.handler === 'openModal' && x?.target === modal));
    }
  });

  /**
   * @scenario load_state=error
   * @effects opened_modal_failure_shows_notice
   */
  it.each(OPENED_AFTER_FETCH)('$screen: 열린 뒤 값이 없으면(받기 실패) 안내가 보인다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, undefined, c)).toBe(true);
    expect(await renderShown(renderableOf(c), c.source, null, c)).toBe(true);
  });

  /**
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_notice
   */
  it.each(OPENED_AFTER_FETCH)('$screen: 받은 본문이 없으면 안내가 보인다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, c.empty, c)).toBe(true);
  });

  /**
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_notice
   */
  it.each(OPENED_AFTER_FETCH)('$screen: 받은 본문이 있으면 안내가 없다', async (c) => {
    expect(await renderShown(renderableOf(c), c.source, c.rows, c)).toBe(false);
  });

  /**
   * 창이 열린 뒤에는 나올 수 없는 받는 중 분기를 두지 않는다(예전 `?.loading` 스피너는 한 번도 켜지지 않았다).
   *
   * @scenario load_state=loading
   * @effects no_dead_loading_flag
   */
  it.each(['partials/auth/_modal_terms.json', 'partials/auth/_modal_privacy.json'])('%s 에 받는 중 분기가 없다', (file) => {
    expect(JSON.stringify(readLayout(file))).not.toMatch(/Content\?\.loading|Content === undefined|Content !== undefined/);
  });
});

describe('사용자 목록 — 전수', () => {
  /**
   * 디렉터리 아래 JSON 레이아웃을 모두 모은다.
   *
   * @param dir 디렉터리
   * @param out 결과
   * @returns 파일 경로 목록
   */
  function walk(dir: string, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full, out);
      else if (entry.name.endsWith('.json')) out.push(full);
    }
    return out;
  }

  const files = walk(LAYOUT_ROOT).map((f) => path.relative(LAYOUT_ROOT, f).split(path.sep).join('/'));

  /** 화면 뒤에 받는(blocking 이 아닌) 데이터소스 id */
  const deferred = new Set<string>();
  const blockingOnly = new Map<string, boolean>();
  for (const file of files) {
    for (const ds of readLayout(file).data_sources ?? []) {
      const blocking = (ds.loading_strategy ?? 'progressive') === 'blocking';
      blockingOnly.set(ds.id, (blockingOnly.get(ds.id) ?? true) && blocking);
    }
  }
  for (const [id, onlyBlocking] of blockingOnly) if (!onlyBlocking) deferred.add(id);

  /** 표본이 아니어도 되는 빈 안내 — 받는 중에 참이 될 수 없는 이유 */
  const EXCLUDED: Array<{ file: string; match: string; reason: string }> = [
    { file: 'partials/board/index/_empty_states.json', match: 'posts?.data?.board?.slug', reason: '받은 응답의 게시판 정보가 있어야 참' },
    { file: 'partials/home/_board_summary.json', match: 'item.recent_posts', reason: '받은 게시판 항목을 도는 반복 안' },
    { file: 'partials/mypage/claims/_list.json', match: '!!claims.data', reason: '받은 응답이 있어야 참 / 반복 항목(claim.items)' },
    { file: 'partials/search/', match: 'searchResults', reason: '`query?.q && searchResults?.data` 감싸개 안 — 받은 뒤에만 그린다' },
    { file: 'partials/shop/_checkout_payment.json', match: 'paymentSettings?.data', reason: '받은 응답이 있어야 참' },
    { file: 'partials/shop/detail/_purchase_card.json', match: 'product.data', reason: 'product 는 상품 상세에서 blocking' },
    { file: 'partials/board/show/_comment_section.json', match: 'can_write_comments', reason: '받은 응답의 댓글 작성 권한이 있어야 참' },
    { file: 'partials/mypage/claims/_list.json', match: 'claim.items', reason: '받은 클레임을 도는 반복 항목 안' },
    { file: 'partials/mypage/orders/_items.json', match: 'item.product_option_id', reason: '받은 주문 상품을 도는 반복 항목 안(금액 표기)' },
    { file: 'partials/shop/_cart_item.json', match: 'item.product_option_id', reason: '받은 장바구니 항목을 도는 반복 항목 안(금액 표기)' },
    { file: 'partials/shop/_checkout_items.json', match: 'item.product_option_id', reason: '받은 주문서 항목을 도는 반복 항목 안(금액 표기)' },
    { file: 'partials/shop/_checkout_mileage.json', match: 'mileage?.available', reason: '`checkoutData.data.mileage?.enabled` 감싸개 안 — 받은 뒤에만 그린다' },
    { file: 'partials/mypage/claims/_detail', match: 'claim.data', reason: 'claim 은 클레임 상세에서 blocking' },
  ];

  /**
   * 식이 화면 뒤에 받는 데이터소스를 읽는지.
   *
   * @param text 식
   * @returns 읽는 데이터소스 id
   */
  function deferredSourcesIn(text: string): string[] {
    const ids = new Set<string>();
    for (const m of text.matchAll(/(?<![.\w$])([A-Za-z_]\w*)\s*(?:\?\.|\.)/g)) {
      if (deferred.has(m[1])) ids.add(m[1]);
    }
    return [...ids];
  }

  /**
   * 0건 조건을 가진 빈 안내 가운데 화면 뒤에 받는 목록에 묶인 것은 모두 표본에 있거나
   * 제외 사유가 적혀 있다.
   *
   * @scenario load_state=loading
   * @effects every_deferred_list_notice_gated
   */
  it('목록 빈 안내는 모두 받는 중 규칙을 지킨다', () => {
    const offenders: string[] = [];
    for (const file of files) {
      for (const node of findAll(readLayout(file), (n) => typeof n.if === 'string' && /length\s*===?\s*0|\?\?\s*0\)\s*===\s*0/.test(n.if))) {
        if ([...CASES, ...OPENED_AFTER_FETCH].some((c) => c.file === file && matchesFind(c, node))) continue;
        if (deferredSourcesIn(String(node.if)).length === 0) continue;
        if (EXCLUDED.some((e) => file.startsWith(e.file) && String(node.if).includes(e.match))) continue;
        offenders.push(`${file} ${String(node.if).slice(0, 120)}`);
      }
    }
    expect(offenders).toEqual([]);
  });

  /**
   * 엔진은 데이터소스 값에 `loading` 을 싣지 않는다 — 받는 중은 `소스 === undefined` 로 가른다.
   *
   * @scenario load_state=loading
   * @effects no_dead_loading_flag
   */
  it('화면 뒤에 받는 데이터소스 값의 loading 을 읽는 조건이 없다', () => {
    const offenders: string[] = [];
    for (const file of files) {
      const text = fs.readFileSync(path.join(LAYOUT_ROOT, file), 'utf8');
      for (const m of text.matchAll(/(?<![.\w$])([A-Za-z_]\w*)\??\.(?:loading|error)\b/g)) {
        if (deferred.has(m[1])) offenders.push(`${file}: ${m[0]}`);
      }
    }
    expect(offenders).toEqual([]);
  });
});

describe('편집기 조건 레시피 — 데이터소스 받는 중·비었음', () => {
  const recipes = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../editor-spec/conditionRecipes.json'), 'utf8')).operators as Array<{ value: string; expr: string }>;
  const engine = new DataBindingEngine();

  /**
   * 레시피 식에 데이터소스 id 를 넣어 실제 엔진으로 평가한다.
   *
   * @param value 레시피 값
   * @param ctx 데이터 문맥
   * @returns 평가 결과
   */
  function evaluate(value: string, ctx: Record<string, unknown>): unknown {
    const recipe = recipes.find((r) => r.value === value);
    expect(recipe, value).toBeTruthy();
    return engine.evaluateExpression(recipe!.expr.replace(/\{src\}/g, 'posts'), ctx as any, { skipCache: true });
  }

  /**
   * 「데이터 로딩 중」은 값이 아직 없을 때(undefined)만 참 — 엔진은 데이터소스 값에 loading 을 싣지 않는다.
   * 받기 실패(null)·받은 뒤에는 거짓.
   *
   * @scenario load_state=loading
   * @effects editor_recipe_matches_engine
   */
  it('dsLoading 은 받기 전에만 참이다', () => {
    expect(evaluate('dsLoading', {})).toBe(true);
    expect(evaluate('dsLoading', { posts: null })).toBe(false);
    expect(evaluate('dsLoading', { posts: { data: [] } })).toBe(false);
  });

  /**
   * 「오류일 때」는 엔진이 실패를 적는 `_dataSourceErrors.{src}` 를 읽는다 — 데이터소스 값에는 `error` 가 없다
   * (실패하면 값은 null). 예전 `!!{src}?.error` 는 늘 거짓이었다.
   *
   * @scenario load_state=error
   * @effects editor_recipe_matches_engine
   */
  it('dsError 는 엔진이 실패를 적었을 때만 참이다', () => {
    expect(evaluate('dsError', { posts: null, _dataSourceErrors: { posts: { message: 'x', status: 500 } } })).toBe(true);
    expect(evaluate('dsError', { posts: null, _dataSourceErrors: { other: { message: 'x' } } })).toBe(false);
    expect(evaluate('dsError', { posts: { data: [] } })).toBe(false);
    expect(evaluate('dsError', {})).toBe(false);
  });

  /**
   * 편집기가 노드의 `if` 를 다시 읽을 때(역파싱) 데이터소스 조건 셋은 자기 자신으로 돌아오고,
   * 범용 조건(값이 undefined·null·참)은 데이터소스 조건으로 바뀌어 보이지 않는다.
   *
   * @scenario load_state=loading
   * @effects editor_recipe_matches_engine
   */
  it('데이터소스 조건 셋은 역파싱이 자기 자신으로 돌아오고 범용 조건과 겹치지 않는다', () => {
    const ops = normalizeConditionRecipes({ operators: recipes } as never);
    for (const value of ['dsLoading', 'dsEmpty', 'dsError', 'dsHasData']) {
      if (!recipes.some((r) => r.value === value)) continue;
      const expr = combineConditions([{ operator: value, params: { src: 'posts' } }], 'and', ops);
      const parsed = parseConditionExpr(expr, ops);
      expect(parsed?.clauses, `${value}: ${expr}`).toEqual([{ operator: value, params: { src: 'posts' } }]);
    }
    const generic: Array<[string, string]> = [
      ['valueUndefined', '{{ _local.v === undefined }}'],
      ['valueIsNull', '{{ _local.v === null }}'],
    ];
    for (const [value, expr] of generic) {
      if (!recipes.some((r) => r.value === value)) continue;
      expect(parseConditionExpr(expr, ops)?.clauses?.[0]?.operator, expr).toBe(value);
    }
  });

  /**
   * 「데이터 비었음」은 받은 뒤 0건일 때만 참 — 받기 전에 참이면 그 레시피로 만든 빈 안내가 받는 동안 먼저 보인다.
   *
   * @scenario load_state=loaded_empty
   * @effects editor_recipe_matches_engine
   */
  it('dsEmpty 는 받은 뒤 0건일 때만 참이다', () => {
    expect(evaluate('dsEmpty', {})).toBe(false);
    expect(evaluate('dsEmpty', { posts: { data: [] } })).toBe(true);
    expect(evaluate('dsEmpty', { posts: { data: [1] } })).toBe(false);
  });
});
