/**
 * @file guestClaimShellContract.test.tsx
 * @description 비회원 클레임 화면의 **구조 계약** (#599 S14)
 *
 * 비회원 화면은 회원 화면과 **같은 partial 을 무수정으로** 쓴다 — 다른 것은 셸이 세우는 계약값과
 * 데이터소스 주소뿐이다. 그래서 이 파일이 재는 것은 "그려지는가" 가 아니라 그 규율이 지켜지는가다:
 *
 *  ① 공유 partial 이 회원/비회원을 스스로 판단하지 않는다 (`_global.currentUser` 참조 0건)
 *  ② 공유 partial 이 화면 주소를 리터럴로 들지 않는다 (`/mypage/` 0건)
 *  ③ 두 셸이 같은 partial 을 같은 순서로 마운트한다 (섹션 diff 0)
 *  ④ 셸이 계약값을 빠짐없이 세운다 — 하나라도 비면 그 액션은 요청을 내지 않거나 빈 주소로 이동한다
 *  ⑤ 비회원 데이터소스는 조회 토큰을 명시 주입하고, 잃으면 조회 폼으로 돌려보낸다
 *
 * 어느 축이 어긋나도 화면은 정상으로 보인다 — 오류도 토스트도 남지 않고, 회원 계정으로 열면
 * 재현되지도 않는다.
 *
 * @scenario claim_type=exchange, screen=detail, entry=guest
 * @effects guest_claim_shells_reuse_the_member_partials,
 *   guest_claim_shells_declare_the_order_scoped_bases,
 *   guest_claim_datasources_carry_the_lookup_token
 *
 * @vitest-environment jsdom
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';

function load(rel: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../', rel), 'utf-8'));
}

function flatten(node: any, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => flatten(n, out));

    return out;
  }
  out.push(node);
  for (const value of Object.values(node)) flatten(value, out);

  return out;
}

/** 노드 트리에서 마운트된 partial 경로를 선언 순서대로 모은다. */
function partialsOf(node: any): string[] {
  return flatten(node)
    .filter((n) => typeof n.partial === 'string')
    .map((n) => n.partial);
}

function actionsWith(root: any, handler: string): any[] {
  return flatten(root).filter((n) => n.handler === handler);
}

const guestDetail = load('layouts/shop/guest_claim_show.json');
const guestApply = load('layouts/shop/guest_claim_apply.json');
const guestOrder = load('layouts/shop/guest_order_show.json');
const guestForm = load('layouts/shop/guest_order_form.json');
const memberDetail = load('layouts/mypage/claims/show.json');
const memberApply = load('layouts/mypage/claims/apply.json');
const memberList = load('layouts/mypage/claims.json');
const routes = load('routes.json');

/** 상점 기준 경로를 품은 라우트 path 를 비교하기 쉬운 형태로 줄인다. */
function shopPath(p: string): string {
  return p.replace(/^\/\{\{[^}]*\}\}[^/]*/, '{shopBase}').replace(/^\/\{\{.*?\}\}/, '{shopBase}');
}

const SHARED_CLAIM_PARTIALS = [
  '_list.json',
  '_detail_progress.json',
  '_detail_items.json',
  '_detail_shipping.json',
  '_detail_claim_info.json',
  '_detail_reason.json',
  '_modal_withdraw.json',
  '_modal_image_viewer.json',
  '_apply_items.json',
  '_apply_redelivery.json',
  '_apply_reason.json',
  '_apply_refund_account.json',
  '_apply_summary.json',
  '_apply_comparison.json',
  '_modal_change_product.json',
];

describe('공유 partial 규율 — 회원/비회원을 스스로 판단하지 않는다', () => {
  // @scenario claim_type=exchange, entry=with_token, screen=detail
  // @effects shared_claim_partials_never_read_the_current_user
  it('클레임 partial 전건이 _global.currentUser 를 읽지 않는다', () => {
    const offenders: string[] = [];

    for (const file of SHARED_CLAIM_PARTIALS) {
      const raw = fs.readFileSync(
        path.resolve(__dirname, '../../layouts/partials/mypage/claims/', file),
        'utf-8'
      );

      if (raw.includes('currentUser')) offenders.push(file);
    }

    // 모집단이 비면 red — 파일명이 바뀌었는데 검사만 통과하는 상태를 막는다.
    expect(SHARED_CLAIM_PARTIALS.length).toBe(15);
    expect(offenders).toEqual([]);
  });

  // @scenario claim_type=return, entry=with_token, screen=detail
  // @effects shared_claim_partials_hold_no_member_only_path
  it('클레임 partial 전건이 회원 전용 주소를 리터럴로 들지 않는다', () => {
    // 리터럴로 들면 비회원이 회원 전용 주소로 보내진다 — 그 주소는 로그인 화면을 열고
    // 오류도 토스트도 남지 않는다. 주소는 셸이 `_local` 로 준다.
    const offenders: Array<{ file: string; line: string }> = [];

    for (const file of SHARED_CLAIM_PARTIALS) {
      const raw = fs.readFileSync(
        path.resolve(__dirname, '../../layouts/partials/mypage/claims/', file),
        'utf-8'
      );

      for (const line of raw.split('\n')) {
        // 주석(`_comment*` / `comment`)은 규율을 설명하느라 그 주소를 인용한다.
        if (!line.includes('/mypage/')) continue;
        if (/"(_comment[^"]*|comment)"\s*:/.test(line)) continue;
        // partial 참조는 화면 주소가 아니라 레이아웃 파일 경로다 — 공유 partial 이 형제 partial 을 싣는 것은 셸과 무관하다.
        if (/"partial"\s*:\s*"partials\/mypage\/claims\//.test(line)) continue;
        offenders.push({ file, line: line.trim() });
      }
    }

    expect(offenders).toEqual([]);
  });
});

describe('비회원 셸 — 회원 셸과 같은 partial 을 같은 순서로 마운트한다', () => {
  // @scenario claim_type=exchange, entry=with_token, screen=detail
  // @scenario claim_type=return, entry=with_token, screen=detail
  // @scenario claim_type=refund, entry=with_token, screen=detail
  // @scenario claim_type=cancel, entry=with_token, screen=detail
  // @effects guest_claim_shells_reuse_the_member_partials
  it('상세 셸의 섹션 구성이 회원과 완전히 같다', () => {
    expect(partialsOf(guestDetail)).toEqual(partialsOf(memberDetail));
    // 모집단이 비면 red — 섹션을 통째로 지워도 통과하는 상태를 막는다.
    expect(partialsOf(guestDetail).length).toBeGreaterThan(0);
  });

  // @scenario claim_type=exchange, entry=with_token, screen=apply
  // @scenario claim_type=return, entry=with_token, screen=apply
  // @scenario claim_type=refund, entry=with_token, screen=apply
  // @scenario claim_type=exchange, entry=with_token, screen=edit
  // @scenario claim_type=return, entry=with_token, screen=edit
  // @scenario claim_type=refund, entry=with_token, screen=edit
  // @effects guest_claim_shells_reuse_the_member_partials
  it('작성·수정 셸의 섹션 구성이 회원과 완전히 같다', () => {
    expect(partialsOf(guestApply)).toEqual(partialsOf(memberApply));
    expect(partialsOf(guestApply).length).toBeGreaterThan(0);
  });

  // @scenario claim_type=exchange, entry=with_token, screen=order_section
  // @scenario claim_type=return, entry=with_token, screen=order_section
  // @scenario claim_type=refund, entry=with_token, screen=order_section
  // @scenario claim_type=cancel, entry=with_token, screen=order_section
  // @effects guest_order_claim_section_reuses_the_member_list_partial
  it('비회원 주문상세가 회원 신청내역과 같은 목록 partial 을 쓴다', () => {
    // §5-1-3 — 비회원에게는 주문을 가로지르는 목록 화면이 없다. 이 섹션이 곧 그 목록이므로
    // 카드 규칙을 복제하면 두 벌이 되어 한쪽만 낡는다.
    expect(partialsOf(guestOrder)).toContain('partials/mypage/claims/_list.json');
    expect(partialsOf(memberList)).toContain('partials/mypage/claims/_list.json');
  });
});

describe('셸 계약값 — 비회원은 주문 스코프가 붙는다', () => {
  const GUEST_PREFIX = "'/api/modules/sirsoft-ecommerce/guest/orders/'";

  /** init_actions 최상위의 setState 가 세운 _local 값을 모은다. */
  function seededLocals(shell: any): Record<string, string> {
    const seeds: Record<string, string> = {};

    for (const action of shell.init_actions ?? []) {
      if (action.handler !== 'setState') continue;
      const params = action.params ?? {};
      if (params.target !== 'local' && params.target !== '_local') continue;

      for (const [key, value] of Object.entries(params)) {
        if (key === 'target' || key.startsWith('_comment') || key === 'comment') continue;
        seeds[key] = String(value);
      }
    }

    return seeds;
  }

  // @scenario claim_type=exchange, entry=with_token, screen=detail
  // @effects guest_claim_shells_declare_the_order_scoped_bases
  it('상세 셸이 철회·추가결제 두 주소를 세우고 회원 셸과 같은 키 집합을 갖는다', () => {
    const seeds = seededLocals(guestDetail);

    for (const key of ['claimWithdrawApiBase', 'claimPayApiBase']) {
      expect(seeds[key], `${key} 를 세우지 않으면 그 액션은 요청을 내지 않는다`).toBeTruthy();
      expect(seeds[key]).toContain(GUEST_PREFIX);
      // 주문번호는 조회 토큰의 짝이다 — 이 화면의 주소에는 주문번호가 없다.
      expect(seeds[key]).toContain('_global.guestOrderNumber');
    }

    expect(guestDetail.initLocal.isGuest).toBe(true);
    expect(guestDetail.initLocal.withdrawRefreshDataSourceId).toBe('claim');

    // 회원 셸이 리터럴로 갖는 것과 같은 키 집합이어야 한다 — 한쪽에만 있는 값은 그 화면에서만
    // 동작하는 액션을 만든다.
    for (const key of ['claimWithdrawApiBase', 'claimPayApiBase']) {
      expect(memberDetail.initLocal[key], `회원 상세 셸의 ${key}`).toBeTruthy();
    }

    // 이 화면이 부르지 않는 주소는 두지 않는다 — 쓰이지 않는 값이 살아 있는 배선처럼 보인다.
    expect(seeds.imageDeleteBase).toBeUndefined();
    expect(memberDetail.initLocal.imageDeleteBase).toBeUndefined();
  });

  // @scenario claim_type=exchange, entry=with_token, screen=apply
  // @effects guest_claim_shells_declare_the_order_scoped_bases
  it('작성·수정 셸이 신청·첨부·수정 주소와 돌아갈 곳을 세운다', () => {
    const seeds = seededLocals(guestApply);

    expect(seeds.claimApiBase).toContain(GUEST_PREFIX);
    expect(seeds.imageDeleteBase).toContain('/claims/images');
    expect(seeds.claimEditApiBase).toContain('/claims');
    // 접수 뒤 목적지는 이 주문의 상세다 — 비회원에게는 신청내역 목록이 없다.
    expect(seeds.claimListPath).toContain('/guest/orders/');
    expect(seeds.claimDetailPathBase).toContain('/guest/claims');
    expect(guestApply.initLocal.isGuest).toBe(true);
  });

  // @scenario claim_type=exchange, entry=with_token, screen=order_section
  // @effects guest_claim_shells_declare_the_order_scoped_bases
  it('비회원 주문상세가 목록 partial 이 쓸 계약값을 세운다', () => {
    const seeds = seededLocals(guestOrder);

    expect(seeds.claimWithdrawApiBase).toContain('/guest/orders/');
    expect(seeds.claimListPath).toContain('/guest/orders/');
    expect(seeds.claimDetailPathBase).toContain('/guest/claims');
    // 빈 목록에서 나갈 곳 — 비회원에게는 주문 내역 화면이 없다.
    expect(seeds.claimEmptyCtaPath).not.toContain('/mypage/');
    expect(guestOrder.initLocal.isGuest).toBe(true);
    expect(guestOrder.initLocal.withdrawRefreshDataSourceId).toBe('claims');
    // 옛 [주문 취소] 버튼과 그 셸 표식은 삭제됐다(#599 후속) — 공유 partial 에 그 핸들러 참조가 없어야 비회원도 클레임 진입만 본다.
    expect(guestOrder.initLocal.useClaimActions).toBeUndefined();
    const orderItemsRaw = fs.readFileSync(path.resolve(__dirname, '../../layouts/partials/mypage/orders/_items.json'), 'utf-8');
    expect(orderItemsRaw).not.toContain('initUserCancelItems');
    expect((guestOrder.modals ?? []).some((m: any) => String(m.partial ?? '').includes('_modal_cancel.json'))).toBe(false);
    // 번호가 곧 상세 링크인 셸 표식. 비회원은 같은 창이다 — 조회 토큰이 탭(sessionStorage)에 묶여 새 창에 실리지 않는다.
    expect(guestOrder.initLocal.claimDetailLinkMode).toBe('same_window');
  });

  // @scenario claim_type=exchange, entry=with_token, screen=edit
  // @effects guest_claim_edit_entry_reads_global_not_local
  it('수정 진입 핸들러는 최상위에 있고 _local 을 읽지 않는다', () => {
    // init_actions 는 렌더 전에 실행되고 레이아웃 initLocal 은 마운트 시점에 적용된다 —
    // 그 자리에서 `_local` 은 언제나 비어 있다(2026-09-01 회원 화면 브라우저 실측 결함 ⓑ).
    // `_global` 은 부모 레이아웃의 init_actions 가 이미 세웠으므로 그 제약을 받지 않는다.
    const editInit = (guestApply.init_actions ?? []).find(
      (a: any) => a.handler === 'sirsoft-ecommerce.initClaimEdit'
    );

    expect(editInit, '수정 진입 핸들러가 init_actions 최상위에 없다').toBeTruthy();
    expect(String(editInit.if)).toContain('route.claim_number');
    expect(editInit.params.isGuest).toBe(true);
    expect(String(editInit.params.claimApiBase)).toContain('_global.guestOrderNumber');
    expect(JSON.stringify(editInit.params)).not.toContain('_local');

    // 승인 이후 신청서의 `/edit` 은 비회원 상세로 돌려보낸다(#599 T2-D7) — 상점 기준 경로가 앞에 붙는다.
    expect(String(editInit.params.detailPath)).toContain("'/guest/claims/'");
    expect(String(editInit.params.detailPath)).toContain('_global.shopBase');
    expect(String(editInit.params.detailPath)).toContain('route.claim_number');

    // 어떤 conditions 분기에도 확장 핸들러를 중첩하지 않는다.
    const nested = JSON.stringify(
      (guestApply.init_actions ?? []).filter((a: any) => a.handler === 'conditions')
    );
    expect(nested).not.toContain('sirsoft-ecommerce.');
  });
});

describe('비회원 데이터소스 — 조회 토큰이 유일한 자격이다', () => {
  function sources(shell: any): any[] {
    return shell.data_sources ?? [];
  }

  // @scenario claim_type=exchange, entry=with_token, screen=detail
  // @effects guest_claim_datasources_carry_the_lookup_token
  it('클레임을 읽는 데이터소스마다 토큰을 명시 주입한다', () => {
    // globalHeaders 의 광역 패턴에 기대면 회원 라우트에도 토큰이 실릴 수 있다
    // (guest_order_show 가 세운 규율).
    const guarded = [
      ...sources(guestDetail),
      ...sources(guestApply),
      ...sources(guestOrder).filter((s: any) => ['claimable', 'claims', 'order'].includes(s.id)),
    ];

    expect(guarded.length).toBeGreaterThan(0);

    for (const source of guarded) {
      expect(source.headers?.['X-Guest-Order-Token'], `${source.id} 에 토큰 헤더가 없다`).toBe(
        '{{_global.guestOrderToken}}'
      );
      // 비회원 경로는 인증을 강제하지 않는다 — 자격은 토큰이 판정한다.
      expect(source.auth_required).toBeUndefined();
      expect(source.auth_mode).toBe('optional');
    }
  });

  // @scenario claim_type=exchange, entry=without_token, screen=detail
  // @scenario claim_type=exchange, entry=expired_token, screen=detail
  // @scenario claim_type=exchange, entry=without_token, screen=apply
  // @scenario claim_type=exchange, entry=expired_token, screen=apply
  // @scenario claim_type=exchange, entry=without_token, screen=edit
  // @scenario claim_type=exchange, entry=expired_token, screen=edit
  // @effects guest_claim_screens_clear_the_token_and_return_to_lookup
  it('토큰을 잃으면 남은 토큰을 비우고 조회 폼으로 돌려보낸다', () => {
    // 비우지 않으면 다음 진입이 만료된 토큰을 다시 들고 같은 404 를 반복한다.
    for (const [name, shell, id] of [
      ['상세', guestDetail, 'claim'],
      ['작성', guestApply, 'order'],
    ] as const) {
      const source = sources(shell).find((s: any) => s.id === id);
      expect(source, `${name} 셸의 ${id} 데이터소스`).toBeTruthy();

      for (const status of ['401', '404']) {
        const handled = JSON.stringify(source.errorHandling?.[status] ?? {});
        expect(handled, `${name} ${status}`).toContain('clearGuestOrderToken');
        expect(handled).toContain('/guest/orders');
      }
    }
  });

  // @scenario claim_type=exchange, entry=with_token, screen=detail
  // @effects guest_claim_detail_order_is_fetched_by_the_chain_only
  // @scenario claim_type=exchange, entry=with_token, screen=apply
  // @effects guest_claim_datasources_carry_the_lookup_token
  it('비회원 데이터소스는 blocking 이 아니다 (토큰이 서기 전에 나간다)', () => {
    // 2026-09-01 브라우저 실측: 유효한 토큰을 쥔 사용자가 [반품 신청] 을 눌렀는데 작성 화면이
    // 열리지 않고 조회 폼으로 튕겼다. 코어는 blocking 데이터소스를 **렌더 전에** 발행하는데
    // 조회 토큰을 `_global` 에 세우는 것은 부모 레이아웃의 init_actions 라 그보다 뒤다 —
    // 토큰 헤더가 빈 채 나간 요청이 404 가 되고, 그 404 가 토큰을 폐기하며 화면을 닫았다.
    //
    // 빈 화면 노출은 blur_until_loaded 가 막는다(guest_order_show 와 같은 처방).
    for (const [name, shell, id] of [
      ['상세', guestDetail, 'claim'],
      ['작성', guestApply, 'order'],
    ] as const) {
      const source = (shell.data_sources ?? []).find((s: any) => s.id === id);

      expect(source.loading_strategy, `${name} 셸의 ${id} 가 blocking 이다`).not.toBe('blocking');

      const gate = flatten(shell).find((n: any) => n.blur_until_loaded?.enabled === true);
      expect(gate, `${name} 셸에 blur 게이트가 없다`).toBeTruthy();
      expect(gate.blur_until_loaded.data_sources).toContain(id);
    }

    // 비회원 주문상세도 같은 이유로 blocking 을 쓰지 않는다(종전부터의 형태).
    for (const source of guestOrder.data_sources ?? []) {
      expect(source.loading_strategy, `주문상세 ${source.id}`).not.toBe('blocking');
    }
  });

  // @scenario claim_type=exchange, entry=expired_token, screen=detail
  // @effects guest_claim_screens_clear_the_token_and_return_to_lookup
  it('돌아갈 주소의 폴백이 상대 경로가 되지 않는다', () => {
    // 2026-09-01 브라우저 실측: 토큰 없이 신청서 상세로 들어가면
    // `/shop/guest/claims/undefined/guest/orders` 로 이동해 404 가 열렸다. `_global.shopBase` 는
    // 부모 레이아웃의 init_actions 가 세우는데 **blocking 데이터소스의 응답은 그보다 먼저**
    // 해소된다 — 그 순간 `_global.shopBase + '/guest/orders'` 는 문자열 "undefined/guest/orders"
    // 가 되고, 앞에 `/` 가 없어 현재 주소 뒤에 이어 붙는다.
    //
    // 1순위는 서버가 준 주소(`errors.redirect_to`)다 — 상점 주소 설정을 아는 유일한 쪽이다.
    // 폴백은 그것이 없을 때만 쓰이며, 그때도 반드시 루트 절대 경로여야 한다.
    const raw = JSON.stringify([guestDetail, guestApply, guestOrder]);
    const fallbacks = raw.match(/\(\(?_global\.shopBase[^)]*\)?[^"]*guest\/orders/g) ?? [];

    expect(fallbacks.length).toBeGreaterThan(0);

    for (const fallback of fallbacks) {
      expect(fallback, `폴백이 undefined 를 문자열로 이어 붙인다: ${fallback}`).toContain("shopBase ?? ''");
    }

    // 서버가 준 주소를 1순위로 읽는다.
    expect(raw).toContain('error.errors?.redirect_to');
  });

  /** @effects guest_claim_detail_loads_order_after_claim_response */
  it('원 주문은 스스로 부르지 않는다 (첫 호출의 404 가 받아 둔 주문을 덮어쓴다)', () => {
    const order = sources(guestDetail).find((s: any) => s.id === 'order');

    expect(order.auto_fetch).toBe(false);
    // 신청서 응답의 체인이 주문번호를 세운 뒤 refetch 로만 부른다.
    const chain = JSON.stringify(sources(guestDetail).find((s: any) => s.id === 'claim').onSuccess);
    expect(chain).toContain('claimOrderNumber');
    expect(chain).toContain('refetchDataSource');
  });

  // @scenario claim_type=exchange, entry=with_token, screen=order_section
  // @effects guest_order_claim_list_is_paginated_by_type
  it('이 주문의 클레임 목록은 유형·페이지를 주소에서 읽는다 (유형이 없으면 비워 보내 서버가 고른다)', () => {
    const claims = sources(guestOrder).find((s: any) => s.id === 'claims');

    expect(claims.endpoint).toContain('/guest/orders/');
    expect(claims.endpoint).toContain('/claims');
    // 주소에 유형이 없으면 type 을 비워 보낸다 — 서버가 접수 건이 있는 첫 유형을 골라 claim_type 으로 알려 준다.
    expect(claims.params.type).toBe("{{_local.claimsType ?? query.type ?? ''}}");
    expect(claims.params.page).toBe('{{_local.claimsPage ?? query.page ?? 1}}');
    // 철회한 신청서도 목록에 남으므로(행이 사라지지 않는다) 매번 다시 읽는다.
    expect(claims.cache).toBe(false);
    expect(claims.refetchOnMount).toBe(true);
  });
});

describe('비회원 라우트 — 서버측 주소 SSoT 와 같은 모양이다', () => {
  /** @effects guest_claim_routes_allow_guest_and_use_claim_number */
  it('작성·수정·상세 세 라우트가 등록되어 있고 인증을 요구하지 않는다', () => {
    const guestRoutes = routes.routes.filter((r: any) => String(r.path).includes('/guest/'));
    const byLayout = Object.fromEntries(guestRoutes.map((r: any) => [r.path, r]));

    const paths = guestRoutes.map((r: any) => shopPath(String(r.path)));

    expect(paths).toContain('{shopBase}/guest/orders/:order_number/claims/apply/:type');
    expect(paths).toContain('{shopBase}/guest/claims/:claim_number/edit');
    expect(paths).toContain('{shopBase}/guest/claims/:claim_number');

    for (const route of guestRoutes) {
      expect(route.auth_required, String(route.path)).toBe(false);
    }

    // 수정은 작성 셸을 edit 모드로 재사용한다(§5-1-6) — 별도 셸을 만들면 같은 폼이 두 벌이 된다.
    const edit = guestRoutes.find((r: any) => shopPath(String(r.path)).endsWith('/edit'));
    expect(edit.layout).toBe('shop/guest_claim_apply');
    expect(byLayout[edit.path]).toBeTruthy();

    // 세그먼트 수가 같은 두 경로가 서로를 가리지 않도록 더 구체적인 쪽을 먼저 선언한다.
    const all = routes.routes.map((r: any) => shopPath(String(r.path)));
    expect(all.indexOf('{shopBase}/guest/claims/:claim_number/edit')).toBeLessThan(
      all.indexOf('{shopBase}/guest/claims/:claim_number')
    );
  });

  /** @effects guest_claim_routes_allow_guest_and_use_claim_number */
  it('수정 주소는 위치로 번호를 읽을 수 있는 모양이다 (접두사로 유형을 가르지 않는다)', () => {
    // resolveClaimEditNumber 는 `.../claims/{번호}/edit` 의 위치로 읽는다 — 유형별 접두사
    // (EX/RT/RF/CN)는 운영자가 채번 설정에서 바꿀 수 있어 접두사로 가르면 그 상점에서만 어긋난다.
    const edit = routes.routes
      .map((r: any) => shopPath(String(r.path)))
      .find((p: string) => p.endsWith('/guest/claims/:claim_number/edit'));

    const segments = String(edit).split('/').filter(Boolean);
    expect(segments[segments.length - 1]).toBe('edit');
    expect(segments[segments.length - 3]).toBe('claims');
  });
});

describe('비회원 결제 복귀 — 결과를 받는 화면에 안내가 있다', () => {
  // @scenario claim_type=exchange, entry=with_token, screen=order_section
  // @effects guest_claim_payment_result_banner_stands_on_the_lookup_form
  it('조회 폼이 claim_payment 세 결과를 구분해 그린다', () => {
    // 비회원 복귀 주소가 신청서 상세가 아니라 조회 화면인 이유는, 결제창에서 돌아온 브라우저에
    // 조회 토큰이 남아 있다고 보장할 수 없기 때문이다(ClaimPaymentRedirect). 안내가 없으면
    // 구매자는 돈이 나갔는지 알 방법이 없고 실패도 사유 없이 지나간다.
    const banner = flatten(guestForm).find((n: any) =>
      String(n.if ?? '').includes('query.claim_payment')
    );

    expect(banner, '조회 폼에 결제 결과 안내가 없다').toBeTruthy();

    const raw = JSON.stringify(banner);
    for (const key of ['success', 'failed', 'cancelled']) {
      expect(raw).toContain(`claim.payment_result.${key}`);
    }
    // 어느 신청서의 결제였는지 — 이 화면은 주소에 번호를 품지 않아 쿼리가 유일한 통로다.
    expect(raw).toContain('query.claim_number');
  });
});

describe('클레임 신청 진입 — 비회원도 세 유형 버튼을 갖는다', () => {
  // @scenario claim_type=exchange, entry=with_token, screen=apply
  // @effects guest_order_detail_offers_the_three_claim_entries
  it('세 버튼이 비회원 작성 라우트를 가리키고 선택 상품을 실어 보낸다', () => {
    const entries = actionsWith(guestOrder, 'navigate').filter((a: any) =>
      String(a.params?.path ?? '').includes('/claims/apply/')
    );

    expect(entries).toHaveLength(3);

    const kinds = entries.map((a: any) => String(a.params.path).split('/claims/apply/')[1]);
    expect(kinds.sort()).toEqual(['exchange', 'refund', 'return']);

    for (const entry of entries) {
      expect(String(entry.params.path)).toContain('/guest/orders/');
      // 체크한 상품을 넘기지 않으면 작성 화면이 빈 신청서로 열린다.
      expect(String(entry.params.query?.options ?? '')).toContain('selectedItemIds');
      expect(entry.params.mergeQuery).toBe(true);
    }
  });
});
