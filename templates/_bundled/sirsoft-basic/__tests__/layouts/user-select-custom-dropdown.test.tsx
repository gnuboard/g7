/**
 * @file user-select-custom-dropdown.test.tsx
 * @description 사용자 화면의 드롭다운은 브라우저 기본 선택상자가 아니라 이 템플릿의 목록형 드롭다운으로 그려진다
 *
 * Select 는 `props.options` 가 있으면 단추 + 목록 상자(커스텀 드롭다운)로, 없으면 기본 `<select>` + `<option>` 자식으로
 * 그린다. 레이아웃이 `<select>` 직속 `<option>` 으로 적은 자리(게시글 신고 사유 · 마이페이지 게시판 활동 필터 ·
 * 클레임 신청 사유 · 주문 목록 상태 · 통합검색 게시판 · 주문서 쿠폰·배송 메모)는 운영체제 기본 목록으로 펼쳐져
 * 같은 화면의 다른 드롭다운과 모양이 달랐다.
 *
 * 잰다:
 *  ① basic 레이아웃 전체에 options 없는 Select 가 하나도 없다 (모집단은 레이아웃에서 파생)
 *  ② 고친 자리마다 실제 Select 로 그리면 기본 선택상자가 없고 `button[aria-haspopup=listbox]` 가 선다
 *  ③ 목록에서 고르면 종전과 같은 값(문자열)이 상태에 담기고 같은 동작(조회·이동·주문서 재계산)이 이어진다
 *  ④ 빈 값 항목(전체·선택 안 함·안내 문구)과 고를 수 없는 항목(이미 쓴 쿠폰)이 그대로다
 *
 * @scenario-manifest tests/scenarios/user-select-custom-dropdown.yaml
 *
 * @vitest-environment jsdom
 */

import fs from 'node:fs';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  fireEvent,
  screen,
  waitFor,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { Select } from '../../src/components/basic/Select';

/*
 * 템플릿 폴더에는 React 사본이 따로 있다. 실제 Select(훅 사용)를 엔진 렌더러 안에서 그리려면 엔진과 같은
 * React 를 써야 한다(사본이 섞이면 훅이 null 디스패처를 읽는다) — 템플릿 쪽 react·react-dom 을 저장소 루트 사본으로 돌린다.
 */
const rootModules = vi.hoisted(() => require('node:path').resolve(__dirname, '../../../../../node_modules'));
vi.mock('react', () => { const m = require(rootModules + '/react'); return { ...m, default: m }; });
vi.mock('react/jsx-runtime', () => require(rootModules + '/react/jsx-runtime'));
vi.mock('react/jsx-dev-runtime', () => require(rootModules + '/react/jsx-dev-runtime'));
vi.mock('react-dom', () => { const m = require(rootModules + '/react-dom'); return { ...m, default: m }; });

type Node = Record<string, any>;

const layoutsRoot = path.resolve(__dirname, '../../layouts');
const extensionsRoot = path.resolve(__dirname, '../../extensions');

/**
 * 디렉터리 아래 모든 `.json` 레이아웃 경로를 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns 파일 경로 목록
 */
function jsonFiles(dir: string): string[] {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) return jsonFiles(p);
    return e.name.endsWith('.json') ? [p] : [];
  });
}

/**
 * 객체 트리 안의 모든 컴포넌트 노드를 모은다 (children·slots·modals·responsive 등 자리를 가리지 않는다).
 *
 * @param value 시작 값
 * @param out 누적 배열
 * @returns 노드 목록
 */
function allNodes(value: unknown, out: Node[] = []): Node[] {
  if (!value || typeof value !== 'object') return out;
  if (Array.isArray(value)) {
    value.forEach((v) => allNodes(v, out));
    return out;
  }
  const node = value as Node;
  if (typeof node.name === 'string') out.push(node);
  Object.values(node).forEach((v) => allNodes(v, out));
  return out;
}

/**
 * 조각 JSON 을 읽는다.
 *
 * @param rel layouts/ 기준 경로
 * @returns 파싱한 조각
 */
function load(rel: string): Node {
  return JSON.parse(fs.readFileSync(path.join(layoutsRoot, rel), 'utf-8'));
}

/**
 * 조각에서 value 식에 key 가 들어간 Select 노드를 찾는다.
 *
 * @param rel layouts/ 기준 경로
 * @param key value 식에 들어간 상태 이름
 * @returns Select 노드
 */
function selectOf(rel: string, key: string): Node {
  const found = allNodes(load(rel)).find((n) => n.name === 'Select' && String(n.props?.value ?? '').includes(key));
  if (!found) throw new Error(`${rel} 에 ${key} 를 읽는 Select 가 없다`);
  return found;
}

/** 템플릿의 실제 Select 를 얹은 등록부 */
function registry() {
  const r = createMockComponentRegistryWithBasics();
  r.register('basic', 'Select', Select as any);
  return r;
}

let active: ReturnType<typeof createLayoutTest> | undefined;
afterEach(() => {
  active?.cleanup();
  active = undefined;
});

/**
 * 노드 하나를 실제 Select 로 그린다.
 *
 * @param components 그릴 노드
 * @param options createLayoutTest 옵션
 * @returns 테스트 핸들
 */
async function renderNodes(components: Node[], options: Record<string, any> = {}) {
  active = createLayoutTest({ components } as any, { componentRegistry: registry(), ...options } as any);
  await active.render();
  return active;
}

/**
 * 이름으로 드롭다운 단추를 찾아 열고 항목을 누른다.
 *
 * @param name 단추 접근 가능한 이름(칸 이름으로 시작)
 * @param option 고를 항목 글자
 */
async function pick(name: RegExp, option: string) {
  const trigger = screen.getByRole('button', { name });
  fireEvent.click(trigger);
  await screen.findByRole('listbox');
  fireEvent.click(optionByText(option));
}

/**
 * 열린 목록에서 글자가 같은 항목을 찾는다.
 *
 * @param text 항목 글자
 * @returns 항목 요소
 */
function optionByText(text: string): HTMLButtonElement {
  const found = Array.from(screen.getByRole('listbox').querySelectorAll<HTMLButtonElement>('[role="option"]'))
    .find((o) => o.textContent === text);
  if (!found) throw new Error(`목록에 「${text}」 가 없다`);
  return found;
}

/**
 * 드롭다운을 열어 보이는 항목들을 돌려주고 닫는다.
 *
 * @param name 단추 이름
 * @returns 항목 요소 목록
 */
async function optionsOf(name: RegExp): Promise<HTMLButtonElement[]> {
  fireEvent.click(screen.getByRole('button', { name }));
  const listbox = await screen.findByRole('listbox');
  return Array.from(listbox.querySelectorAll<HTMLButtonElement>('[role="option"]'));
}

/** 렌더한 화면에 기본 선택상자가 없고 목록형 단추가 섰는지 */
function expectCustomDropdown(container: HTMLElement, count = 1) {
  expect(container.querySelectorAll('select')).toHaveLength(0);
  expect(container.querySelectorAll('button[aria-haspopup="listbox"]')).toHaveLength(count);
}

const T = {
  common: { sort_order: '정렬' },
  board: { post: { report_reason: '신고 사유', select_reason: '사유를 선택하세요' } },
  mypage: {
    board_activities: {
      reactions: { table: { board: '게시판', type: '반응 종류' }, filter: { all_types: '전체 반응' } },
      filter: { all_boards: '전체 게시판' },
      sort: { latest: '최신순', oldest: '오래된순', views: '조회순' },
    },
    orders: {
      status_filter_label: '주문 상태',
      filter: { all: '전체', pending: '결제대기', paid: '결제완료', preparing: '상품준비중', shipped: '배송중', delivered: '배송완료', confirmed: '구매확정', cancelled: '주문취소' },
    },
  },
  claim: { apply: { reason_label: '사유', reason_placeholder: '사유를 선택하세요' } },
  nav: { boards: '게시판' },
  search: { filter: { all_boards: '전체 게시판' } },
  shop: {
    checkout: {
      order_coupon_select: '주문 쿠폰',
      order_coupon_placeholder: '주문 쿠폰 선택',
      order_coupon_no_available: '사용 가능한 쿠폰 없음',
      order_coupon_applied: '적용됨',
      order_coupon_removed: '해제됨',
      product_coupon_1: '상품 쿠폰 1',
      product_coupon_2: '상품 쿠폰 2',
      coupon_no_available: '사용 가능한 쿠폰 없음',
      coupon_already_used: '이미 사용',
      shipping_memo: '배송 요청사항',
      memo_none: '선택 안 함',
      memo_door: '문 앞',
      memo_security: '경비실',
      memo_parcel_box: '택배함',
      memo_call: '전화',
      memo_custom: '직접 입력',
    },
  },
};

/** 고친 18자리 — [조각, value 식의 상태 이름] */
const CONVERTED: Array<[string, string]> = [
  ['partials/board/show/modals/_modal_report.json', 'reportForm?.reason_type'],
  ['partials/mypage/board/_my_posts.json', 'myPostsBoard'],
  ['partials/mypage/board/_my_posts.json', 'myPostsSort'],
  ['partials/mypage/board/_my_comments.json', 'myCommentsBoard'],
  ['partials/mypage/board/_my_comments.json', 'myCommentsSort'],
  ['partials/mypage/board/_my_reactions.json', 'myReactionsBoard'],
  ['partials/mypage/board/_my_reactions.json', 'myReactionsTypeId'],
  ['partials/mypage/board/_my_reactions.json', 'myReactionsSort'],
  ['partials/mypage/board/_my_reports.json', 'myReportsBoard'],
  ['partials/mypage/board/_my_reports.json', 'myReportsSort'],
  ['partials/mypage/claims/_apply_reason.json', 'claimReason'],
  ['partials/mypage/orders/_list.json', 'ordersStatus'],
  ['partials/search/_search_filters.json', 'board_slug'],
  ['partials/shop/_checkout_discount.json', 'order_coupon_issue_id'],
  ['partials/shop/_checkout_discount.json', 'shipping_coupon_issue_id'],
  ['partials/shop/_checkout_items.json', '?.[0]'],
  ['partials/shop/_checkout_items.json', '?.[1]'],
  ['partials/shop/_checkout_shipping.json', 'shippingMemo'],
];

describe('사용자 화면 드롭다운 — 기본 선택상자 0 (전수)', () => {
  /**
   * 화면별 조합(@scenario)은 아래 화면별 시험이 하나씩 맡는다 — 이 시험은 모든 화면을 한 번에 훑는 전수 검사다.
   *
   * @effects user_select_uses_custom_dropdown
   */
  it('basic 레이아웃 전체에 options 없이 <option> 자식으로 그리는 Select 가 없다', () => {
    const offenders = [...jsonFiles(layoutsRoot), ...jsonFiles(extensionsRoot)].flatMap((file) => {
      let json: unknown;
      try {
        json = JSON.parse(fs.readFileSync(file, 'utf-8'));
      } catch {
        return [];
      }
      return allNodes(json)
        .filter((n) => n.name === 'Select' && n.props?.options === undefined)
        .map((n) => `${path.relative(layoutsRoot, file)} ${String(n.props?.value ?? '')}`);
    });

    expect(offenders).toEqual([]);
  });

  /**
   * 화면별 조합(@scenario)은 아래 화면별 시험이 하나씩 맡는다 — 이 시험은 모든 화면을 한 번에 훑는 전수 검사다.
   *
   * @effects user_select_uses_custom_dropdown
   */
  it.each(CONVERTED)('%s (%s) 는 options 로 목록을 받고 Option 자식이 없다', (rel, key) => {
    const node = selectOf(rel, key);
    expect(node.props.options, '목록이 options 로 오지 않는다').toBeDefined();
    expect((node.children ?? []).filter((c: Node) => c.name === 'Option')).toHaveLength(0);
    // change 동작은 그대로 $event.target.value 를 읽는다
    expect(JSON.stringify(node.actions ?? [])).toContain('$event.target.value');
  });
});

describe('마이페이지 게시판 활동 필터', () => {
  /**
   * 좁은 화면에서 필터 줄은 세로로 쌓인다(flex-col). 기본 선택상자는 줄 폭만큼 늘어났지만 목록형 단추는 호출처 외형(bg-)을
   * 그대로 쓰므로 폭을 적지 않으면 글자 폭으로 줄어든다(375px 실측: 바깥 칸 343px · 단추 100~120px).
   *
   * @scenario surface=mypage_board_filters
   * @effects user_select_uses_custom_dropdown
   */
  it.each([
    ['partials/mypage/board/_my_posts.json', ['myPostsBoard', 'myPostsSort']],
    ['partials/mypage/board/_my_comments.json', ['myCommentsBoard', 'myCommentsSort']],
    ['partials/mypage/board/_my_reactions.json', ['myReactionsBoard', 'myReactionsTypeId', 'myReactionsTargetType', 'myReactionsSort']],
    ['partials/mypage/board/_my_reports.json', ['myReportsBoard', 'myReportsStatus', 'myReportsTargetType', 'myReportsSort']],
  ] as Array<[string, string[]]>)('%s 필터 드롭다운은 줄 폭을 채운다(w-full)', (rel, keys) => {
    keys.forEach((key) => {
      expect(String(selectOf(rel, key).props.className).split(/\s+/), key).toContain('w-full');
    });
  });

  /**
   * @scenario surface=mypage_board_filters
   * @effects user_select_uses_custom_dropdown, user_select_keeps_string_value_and_handler
   */
  it('내 글 게시판 필터 — 목록형으로 그려지고 고르면 게시판 slug 와 1쪽이 전역에 담긴다', async () => {
    const t = await renderNodes([selectOf('partials/mypage/board/_my_posts.json', 'myPostsBoard')], {
      translations: T,
      initialData: { boards: { data: [{ slug: 'free', name: '자유게시판' }, { slug: 'notice', name: '공지사항' }] } },
      initialState: { _global: { myPostsBoard: '', myPostsPage: 3 } },
    });
    expectCustomDropdown(document.body);
    expect(screen.getByRole('button', { name: /게시판/ }).textContent).toContain('전체 게시판');

    const labels = (await optionsOf(/게시판/)).map((o) => o.textContent);
    expect(labels).toEqual(['전체 게시판', '자유게시판', '공지사항']);
    fireEvent.click(optionByText('공지사항'));

    await waitFor(() => expect(t.getState()._global.myPostsBoard).toBe('notice'));
    expect(t.getState()._global.myPostsPage).toBe(1);
  });

  /**
   * @scenario surface=mypage_board_filters
   * @effects user_select_keeps_string_value_and_handler
   */
  it('반응 종류 필터 — 숫자 id 도 종전 기본 선택상자처럼 문자열로 담긴다', async () => {
    const t = await renderNodes([selectOf('partials/mypage/board/_my_reactions.json', 'myReactionsTypeId')], {
      translations: T,
      initialData: { myReactions: { data: { reaction_types: [{ id: 3, name: '좋아요' }, { id: 7, name: '슬퍼요' }] } } },
      initialState: { _global: { myReactionsTypeId: '', myReactionsSelectedIds: [5] } },
    });
    expectCustomDropdown(document.body);

    await pick(/반응 종류/, '슬퍼요');

    await waitFor(() => expect(t.getState()._global.myReactionsTypeId).toBe('7'));
    expect(t.getState()._global.myReactionsSelectedIds).toEqual([]);
  });

  /**
   * @scenario surface=mypage_board_filters
   * @effects user_select_uses_custom_dropdown, user_select_keeps_string_value_and_handler
   */
  it('정렬 — 정적 목록도 목록형이고 고른 값이 담긴다', async () => {
    const t = await renderNodes([selectOf('partials/mypage/board/_my_posts.json', 'myPostsSort')], {
      translations: T,
      initialState: { _global: { myPostsSort: 'latest' } },
    });
    expectCustomDropdown(document.body);
    expect((await optionsOf(/정렬/)).map((o) => o.textContent)).toEqual(['최신순', '오래된순', '조회순']);
    fireEvent.click(optionByText('조회순'));

    await waitFor(() => expect(t.getState()._global.myPostsSort).toBe('views'));
  });
});

describe('게시글 신고 사유', () => {
  /**
   * @scenario surface=board_report_modal
   * @effects user_select_uses_custom_dropdown, user_select_keeps_empty_and_disabled_items
   */
  it('게시판 설정의 사유 앞에 「사유를 선택하세요」 빈 값이 서고, 고른 사유가 신고 입력값에 담긴다', async () => {
    const t = await renderNodes([selectOf('partials/board/show/modals/_modal_report.json', 'reason_type')], {
      translations: T,
      initialData: { post: { data: { board: { report_types: [{ value: 'spam', label: '스팸' }, { value: 'abuse', label: '욕설' }] } } } },
      initialState: { _global: { reportForm: { reason_type: '', reason_detail: '' } } },
    });
    expectCustomDropdown(document.body);
    expect(screen.getByRole('button', { name: /신고 사유/ }).textContent).toContain('사유를 선택하세요');

    await pick(/신고 사유/, '욕설');

    await waitFor(() => expect(t.getState()._global.reportForm?.reason_type).toBe('abuse'));
  });
});

describe('클레임 신청 사유', () => {
  /**
   * @scenario surface=claim_apply
   * @effects user_select_uses_custom_dropdown, user_select_keeps_empty_and_disabled_items
   */
  it('유형별 카탈로그(스냅샷 우선)를 안내 빈 값 뒤에 목록형으로 보인다', async () => {
    await renderNodes([selectOf('partials/mypage/claims/_apply_reason.json', 'claimReason')], {
      translations: T,
      initialState: {
        _local: {
          claimType: 'return',
          claimReason: '',
          claimableSnapshot: { reasons: { return: [{ code: 'size_mismatch', localized_name: '사이즈가 맞지 않음' }], refund: [{ code: 'changed_mind', localized_name: '단순 변심' }] } },
        },
      },
    });
    expectCustomDropdown(document.body);

    expect((await optionsOf(/사유/)).map((o) => o.textContent)).toEqual(['사유를 선택하세요', '사이즈가 맞지 않음']);
  });
});

describe('주문 목록 상태 필터', () => {
  /**
   * @scenario surface=mypage_orders
   * @effects user_select_uses_custom_dropdown, user_select_keeps_string_value_and_handler
   */
  it('고르면 로컬 상태와 주소 쿼리(1쪽)를 함께 맞춘다', async () => {
    const t = await renderNodes([selectOf('partials/mypage/orders/_list.json', 'ordersStatus')], {
      translations: T,
      initialState: { _local: { ordersStatus: '' } },
    });
    expectCustomDropdown(document.body);
    expect((await optionsOf(/주문 상태/))).toHaveLength(8);
    fireEvent.click(optionByText('배송중'));

    await waitFor(() => expect(t.getState()._local.ordersStatus).toBe('shipping'));
    expect(t.getNavigationHistory().join(' ')).toMatch(/status=shipping/);
  });
});

describe('통합검색 게시판 필터', () => {
  /**
   * @scenario surface=search_filters
   * @effects user_select_uses_custom_dropdown, user_select_keeps_string_value_and_handler
   */
  it('결과에 실린 게시판을 목록형으로 보이고 고르면 board_slug 로 이동한다', async () => {
    const t = await renderNodes([selectOf('partials/search/_search_filters.json', 'board_slug')], {
      translations: T,
      queryParams: { type: 'posts', q: '테스트' },
      initialData: { searchResults: { data: { posts: { available_boards: [{ slug: 'free', name: '자유게시판' }] } } } },
    });
    expectCustomDropdown(document.body);

    await pick(/게시판/, '자유게시판');

    await waitFor(() => expect(t.getNavigationHistory().join(' ')).toMatch(/board_slug=free/));
  });
});

describe('주문서 쿠폰·배송 메모', () => {
  const coupons = [
    { id: 12, target_type: 'order_amount', target_type_short_label: '주문', localized_name: '첫구매', benefit_formatted: '1,000원' },
    { id: 15, target_type: 'shipping_fee', target_type_short_label: '배송', localized_name: '무료배송', benefit_formatted: '3,000원' },
  ];

  /**
   * @scenario surface=checkout
   * @effects user_select_keeps_string_value_and_handler
   */
  it('주문 쿠폰 — 고르면 문자열 id 로 담고 그 값으로 주문서를 다시 계산(PUT)하며, 빈 값을 고르면 null 로 해제한다', async () => {
    const t = await renderNodes([selectOf('partials/shop/_checkout_discount.json', 'order_coupon_issue_id')], {
      translations: T,
      initialData: { checkoutData: { data: { available_coupons: coupons } } },
      initialState: { _local: { checkout: { order_coupon_issue_id: null, shipping_coupon_issue_id: null } } },
    });
    t.mockApi('checkout', { response: { available_coupons: coupons } });
    expectCustomDropdown(document.body);
    // 주문 금액 쿠폰만 — 배송비 쿠폰은 섞이지 않는다
    expect((await optionsOf(/주문 쿠폰/)).map((o) => o.textContent)).toEqual(['주문 쿠폰 선택', '[주문] 첫구매 (1,000원)']);
    fireEvent.click(optionByText('[주문] 첫구매 (1,000원)'));

    await waitFor(() => expect(t.getState()._local.checkout.order_coupon_issue_id).toBe('12'));
    await waitFor(() => {
      const put = (globalThis.fetch as any).mock.calls.find(
        ([url, init]: [string, RequestInit]) => String(url).includes('/checkout') && init?.method === 'PUT',
      );
      expect(put, '주문서 재계산 요청이 나가지 않았다').toBeDefined();
      expect(JSON.parse(String(put[1].body)).order_coupon_issue_id).toBe('12');
    });

    await pick(/주문 쿠폰/, '주문 쿠폰 선택');
    await waitFor(() => expect(t.getState()._local.checkout.order_coupon_issue_id).toBeNull());
  });

  /**
   * @scenario surface=checkout
   * @effects user_select_keeps_empty_and_disabled_items
   */
  it('품목 쿠폰 — 다른 칸에서 고른 쿠폰과 이미 쓴 쿠폰은 고를 수 없고 「이미 사용」 이 붙는다', async () => {
    const item = {
      product_option_id: 901,
      product: { name: '티셔츠' },
      available_coupons: [
        { id: 21, target_type_short_label: '상품', localized_name: 'A쿠폰', benefit_formatted: '500원' },
        { id: 22, target_type_short_label: '상품', localized_name: 'B쿠폰', benefit_formatted: '700원' },
        { id: 23, target_type_short_label: '상품', localized_name: 'C쿠폰', benefit_formatted: '900원' },
      ],
      disabled_coupon_ids: [23],
    };
    const select = selectOf('partials/shop/_checkout_items.json', '?.[0]');
    const t = await renderNodes(
      [{ type: 'basic', name: 'Div', iteration: { source: '{{_local.items}}', item_var: 'item' }, children: [select] }],
      {
        translations: T,
        initialState: { _local: { items: [item], checkout: { item_coupons: { 901: [null, '22'] } } } },
      },
    );
    expectCustomDropdown(document.body);

    const opts = await optionsOf(/상품 쿠폰 1/);
    expect(opts.map((o) => o.textContent)).toEqual(['상품 쿠폰 1', '[상품] A쿠폰 (500원)', '[상품] B쿠폰 (700원)', '[상품] C쿠폰 (이미 사용)']);
    expect(opts.map((o) => o.disabled)).toEqual([false, false, true, true]);

    fireEvent.click(optionByText('[상품] A쿠폰 (500원)'));
    await waitFor(() => expect(t.getState()._local.checkout.item_coupons[901]).toEqual(['21', '22']));
  });

  /**
   * @scenario surface=checkout
   * @effects user_select_uses_custom_dropdown, user_select_keeps_empty_and_disabled_items
   */
  it('배송 메모 — 「선택 안 함」 빈 값이 맨 앞이고 「직접 입력」 을 고르면 custom 이 담긴다', async () => {
    const t = await renderNodes([selectOf('partials/shop/_checkout_shipping.json', 'shippingMemo')], {
      translations: T,
      initialState: { _local: { shippingMemo: '' } },
    });
    expectCustomDropdown(document.body);
    expect(screen.getByRole('button', { name: /배송 요청사항/ }).textContent).toContain('선택 안 함');

    await pick(/배송 요청사항/, '직접 입력');

    await waitFor(() => expect(t.getState()._local.shippingMemo).toBe('custom'));
  });
});
