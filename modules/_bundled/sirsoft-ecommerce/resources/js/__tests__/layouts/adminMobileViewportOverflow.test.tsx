/**
 * @file adminMobileViewportOverflow.test.tsx
 * @description 관리자 모바일 뷰포트 넘침/압착 회귀 테스트 (이커머스 소유분)
 *
 * 각 단언은 브라우저 실측(390px / 320px)으로 확인된 결함에 1:1 대응한다.
 * 되돌리면 해당 결함이 그대로 재발하므로, 사유를 각 테스트에 남긴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const layoutsDir = path.resolve(__dirname, '../../../layouts/admin');

const load = (rel: string): any =>
  JSON.parse(fs.readFileSync(path.resolve(layoutsDir, rel), 'utf8'));

/** 트리 전체를 순회하며 술어를 만족하는 노드를 모은다. */
function collect(node: any, pred: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const n of node) collect(n, pred, acc);
    return acc;
  }
  if (pred(node)) acc.push(node);
  for (const v of Object.values(node)) {
    if (v && typeof v === 'object') collect(v, pred, acc);
  }
  return acc;
}

const cls = (n: any): string => {
  const c = n?.props?.className;
  return typeof c === 'string' ? c : '';
};

describe('B1 — 모달 좌우 여백 (max-w-full 트랩)', () => {
  // .modal-container 의 max-w-[calc(100vw-2rem)] 와 .max-w-full 은 명시도가 같고(0,1,0)
  // 빌드 CSS 에서 .max-w-full 이 뒤에 정의되므로 승리한다. 레이아웃에서 max-w-full 을
  // 주면 clamp 가 무효화되어 모달이 화면 좌우에 붙는다(여백 0).
  it.each([
    'partials/admin_ecommerce_order_detail/_modal_cancel_order.json',
    'partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json',
  ])('%s 는 max-w-full 을 갖지 않는다', (rel) => {
    const raw = fs.readFileSync(path.resolve(layoutsDir, rel), 'utf8');
    expect(raw).not.toContain('max-w-full');
  });
});

describe('B2 — 주문상세 모달 상품표 카드형 전환', () => {
  it.each([
    ['_modal_cancel_order.json', 'partials/admin_ecommerce_order_detail/_modal_cancel_order.json'],
    ['_modal_batch_change_confirm.json', 'partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json'],
  ])('%s: grid-cols-12 행이 portable 에서 1열로 스택된다', (_n, rel) => {
    const layout = load(rel);
    const grids = collect(layout, (n) => cls(n).includes('grid-cols-12'));

    // 헤더 행 1 + 본문(iteration) 행 1
    expect(grids.length).toBe(2);

    const header = grids.find((g) => cls(g).includes('bg-gray-50'));
    const body = grids.find((g) => !cls(g).includes('bg-gray-50'));

    // 카드형에서는 열 라벨 행이 의미 없다 → 미렌더
    expect(header.responsive?.portable?.if).toBe('{{false}}');

    // 390px 에서 12열이면 1열 = 16.4px → `249,000원`(56px) 같은 토큰이 숫자 내부에서 쪼개진다.
    expect(body.responsive?.portable?.props?.className).toContain('grid-cols-1');
    expect(body.responsive?.portable?.props?.className).not.toContain('grid-cols-12');
  });

  it.each([
    'partials/admin_ecommerce_order_detail/_modal_cancel_order.json',
    'partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json',
  ])('%s: 모든 col-span 셀이 portable 에서 span 을 벗는다', (rel) => {
    const layout = load(rel);
    const spanCells = collect(layout, (n) => /col-span-\d+/.test(cls(n)));
    expect(spanCells.length).toBeGreaterThan(0);

    for (const cell of spanCells) {
      const portable = cell.responsive?.portable?.props?.className;
      expect(portable).toBeDefined();
      // 1열 그리드에 col-span-5 가 남으면 암묵 열이 생겨 셀 폭이 들쭉날쭉해진다.
      expect(portable).not.toMatch(/col-span-\d+/);
    }
  });

  // 세로 스택으로 셀이 전폭(285px)이 되어 현재 데이터로는 절단이 없다(10억대 금액도 88px).
  // 그러나 셀이 다시 좁아지면 즉시 재발하므로, 방어선을 코드에 선언해 둔다.
  // 실측(16px 셀로 축소):
  //   - 금액 순수 토큰 + whitespace-nowrap → 1줄 유지, 숫자 내부 미분리
  //   - `소계 249,000원`(한글+금액 혼합) + break-keep → `소계` / `249,000원` 2줄, 숫자 보존
  //     (이 문자열에 nowrap 을 주면 셀을 65px 넘겨 더 나쁘다 — 그래서 break-keep)
  it('금액(순수 숫자) 노드는 whitespace-nowrap 으로 숫자 내부 줄바꿈을 막는다', () => {
    const cancel = load('partials/admin_ecommerce_order_detail/_modal_cancel_order.json');
    const batch = load('partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json');

    const unitPrice = collect(
      cancel,
      (n) => typeof n.text === 'string' && n.text.includes('unit_price') && n.text.includes('원'),
    )[0];
    expect(cls(unitPrice)).toContain('whitespace-nowrap');

    const batchAmounts = collect(
      batch,
      (n) => typeof n.text === 'string' && /Number\(confirmItem\.(original_price|unit_price)\)/.test(n.text),
    );
    expect(batchAmounts.length).toBe(2);
    for (const node of batchAmounts) {
      expect(cls(node)).toContain('whitespace-nowrap');
    }
  });

  it('한글 라벨 노드는 break-keep 으로 음절 경계 절단을 막는다', () => {
    const cancel = load('partials/admin_ecommerce_order_detail/_modal_cancel_order.json');
    const batch = load('partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json');

    // `소계 249,000원` — 한글 라벨 + 금액 혼합. nowrap 이 아니라 break-keep 이 정답.
    const subtotal = collect(
      cancel,
      (n) => typeof n.text === 'string' && n.text.includes('product_subtotal'),
    )[0];
    expect(cls(subtotal)).toContain('break-keep');
    expect(cls(subtotal)).not.toContain('whitespace-nowrap');

    // 상태 배지 (`결제완료` → `결제완`/`료` 방지)
    const cancelStatus = collect(
      cancel,
      (n) => typeof n.text === 'string' && n.text.includes('option_status_label'),
    )[0];
    expect(cls(cancelStatus)).toContain('break-keep');

    const batchStatus = collect(
      batch,
      (n) => typeof n.text === 'string' && n.text.includes('order.status.payment_complete'),
    )[0];
    expect(cls(batchStatus)).toContain('break-keep');
  });
});

describe('B3 — 은행 관리 모달', () => {
  const rel = 'partials/admin_ecommerce_settings/_bank_management_modal.json';

  it('은행코드 입력칸에 min-w-[80px] 하한이 있다', () => {
    const layout = load(rel);
    // 은행코드 input 은 code 오류 키를 참조하는 className 바인딩으로 식별한다.
    const input = collect(
      layout,
      (n) => n.name === 'Input' && cls(n).includes("+ '.code'"),
    )[0];

    expect(input).toBeTruthy();
    // table-layout:auto + td{min-width:0} 이라 좁은 뷰포트에서 input 이 24px(=좌우 padding)로
    // 압착되어 텍스트 영역이 0px 이 된다. width 지정은 auto 레이아웃이 무시하므로 min-width 필요.
    expect(cls(input)).toContain('min-w-[80px]');
    // 숫자 스케일 min-w-16 은 safelist 에 없어 CSS 가 생성되지 않는다(무효).
    expect(cls(input)).not.toContain('min-w-16');
  });

  it('표 래퍼가 overflow-x-auto 를 명시한다', () => {
    const layout = load(rel);
    const wrapper = collect(layout, (n) => cls(n).includes('max-h-96'))[0];
    expect(wrapper).toBeTruthy();
    // overflow-y 만 선언해도 computed 는 auto 가 되지만 암묵적 의존이다.
    expect(cls(wrapper)).toContain('overflow-x-auto');
  });
});

describe('C2 — 쿠폰 발급기간 입력 행', () => {
  const rel = 'partials/admin_ecommerce_promotion_coupon_form/_partial_issue_settings.json';

  it('portable 에서 행이 줄바꿈하고 datetime 입력이 전폭이 된다', () => {
    const layout = load(rel);
    const row = collect(layout, (n) => n.id === 'issue_period_range')[0];
    expect(row).toBeTruthy();
    // flex-center 는 nowrap. w-52(208px) 두 개가 좁은 뷰포트를 넘긴다.
    expect(row.responsive.portable.props.className).toContain('flex-wrap');

    const inputs = collect(row, (n) => n.name === 'Input');
    expect(inputs.length).toBe(2);
    for (const i of inputs) {
      expect(i.responsive.portable.props.className).toContain('w-full');
      expect(i.responsive.portable.props.className).not.toContain('w-52');
    }
  });
});

describe('C3 — 이커머스 환경설정 넘침', () => {
  it('탭바는 sticky-tab-nav-responsive 를 쓴다 (단계적 패딩과 일치)', () => {
    const layout = load('admin_ecommerce_settings.json');
    const nav = collect(layout, (n) => n.id === 'tab_navigation')[0];
    expect(nav).toBeTruthy();
    // 콘텐츠 래퍼가 p-4 sm:p-6 lg:p-8 이라 고정 24px 를 가정하는 sticky-tab-nav(-mx-6 px-6)는
    // 폰에서 좌우 8px 씩 과다 bleed 된다.
    expect(nav.props.className).toBe('sticky-tab-nav-responsive');
  });

  it('이메일 행이 portable 에서 줄바꿈한다 (rcaExcess 71px 의 진범)', () => {
    const layout = load('partials/admin_ecommerce_settings/_tab_basic_info.json');
    const emailGroup = collect(layout, (n) => n.id === 'field_email')[0];
    expect(emailGroup).toBeTruthy();
    const row = collect(emailGroup, (n) => cls(n).startsWith('flex-center'))[0];
    expect(row.responsive.portable.props.className).toContain('flex-wrap');
  });

  it('w-80 고정폭 입력은 mobile(0~767px) 에서만 전폭이 된다', () => {
    const layout = load('partials/admin_ecommerce_settings/_tab_basic_info.json');
    const w80 = collect(layout, (n) => n.name === 'Input' && cls(n).includes('w-80'));
    expect(w80.length).toBeGreaterThanOrEqual(2);

    const overridden = w80.filter((n) => n.responsive);
    expect(overridden.length).toBe(2); // 쇼핑몰 이름 + 종목

    for (const n of overridden) {
      // 태블릿(768~1023px)은 w-80 이 넘치지 않으므로 portable 로 잡으면 불필요하게 늘어난다.
      expect(n.responsive.mobile).toBeDefined();
      expect(n.responsive.portable).toBeUndefined();
      expect(n.responsive.mobile.props.className).toContain('w-full');
    }
  });
});

describe('C5 — 엑셀 다운로드 날짜 필터 행', () => {
  it('부모가 flex-wrap 이어도 자식 행에 flex-wrap 이 필요하다', () => {
    const layout = load('admin_ecommerce_excel_download_index.json');
    const parent = collect(layout, (n) => n.id === 'date_filter_row')[0];
    expect(parent).toBeTruthy();
    expect(cls(parent)).toContain('flex-wrap');

    // 부모만 wrap 이면 nowrap 인 자식 행(입력 2개 × w-36)이 11px 넘친다.
    const child = (parent.children ?? []).find((c: any) => cls(c).startsWith('flex-center'));
    expect(child).toBeTruthy();
    expect(cls(child)).toContain('flex-wrap');
  });
});

describe('C6 — 도서산간 템플릿 모달 표', () => {
  it('표 컨테이너가 overflow-x-auto 를 명시한다', () => {
    const layout = load('partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json');
    const wrapper = collect(layout, (n) => n.id === 'template_list')[0];
    expect(wrapper).toBeTruthy();
    // 현재는 computed 가 우연히 auto 라 동작하지만, overflow-y-visible 로 바뀌면 즉시 표가 잘린다.
    expect(cls(wrapper)).toContain('overflow-x-auto');
  });
});
