/**
 * @file userClaimListDetailContract.test.tsx
 * @description 클레임 신청내역 목록·상세·수정의 **구조 계약** (#599 S13)
 *
 * 형제 파일 셋(`userClaimListRender` / `userClaimDetailRender` / `userClaimEditRender`)은 화면을
 * 실제로 그려 글자가 나오는지를 잰다. 이 파일은 그려서는 드러나지 않는 축을 레이아웃 JSON 의
 * 구조로 잰다 — 이동이 목록 상태를 물려주는가, 진입점이 두 화면 모두에 있는가, 셸이 공유 partial
 * 에게 줘야 할 계약값을 세우는가. 셋 다 어긋나도 화면은 정상으로 보이고 오류도 남지 않는다.
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

/** 노드 트리에서 특정 핸들러를 쓰는 액션을 모은다. */
function actionsWith(root: any, handler: string): any[] {
  return flatten(root).filter((n) => n.handler === handler);
}

const listShell = load('layouts/mypage/claims.json');
const listPartial = load('layouts/partials/mypage/claims/_list.json');
const detailShell = load('layouts/mypage/claims/show.json');
const applyShell = load('layouts/mypage/claims/apply.json');
const routes = load('routes.json');

describe('신청내역 라우트 — 세 화면이 실제로 서 있다', () => {
  // @scenario claim_type=exchange, screen=list, entry=none
  // @effects claim_list_type_tabs_follow_the_query
  it('목록·상세·수정 라우트가 모두 등록되어 있다', () => {
    const paths = routes.routes.map((r: any) => r.path);

    expect(paths).toContain('/mypage/claims');
    expect(paths).toContain('/mypage/claims/:claim_number');
    expect(paths).toContain('/mypage/claims/:claim_number/edit');

    // 수정은 작성 셸을 재사용한다(§5-1-6) — 별도 셸을 만들면 같은 폼이 두 벌이 된다.
    const edit = routes.routes.find((r: any) => r.path === '/mypage/claims/:claim_number/edit');
    expect(edit.layout).toBe('mypage/claims/apply');
    expect(edit.auth_required).toBe(true);

    // 세그먼트 수가 같은 두 경로가 서로를 가리지 않도록 더 구체적인 쪽을 먼저 선언한다.
    const editIndex = paths.indexOf('/mypage/claims/:claim_number/edit');
    const showIndex = paths.indexOf('/mypage/claims/:claim_number');
    expect(editIndex).toBeLessThan(showIndex);
  });

  // @scenario claim_type=cancel, screen=list, entry=none
  // @effects claim_list_type_tabs_follow_the_query
  it('마이페이지 탭에 신청내역이 있고 목적지가 그 라우트다', () => {
    const nav = load('layouts/partials/mypage/_tab_navigation.json');
    const ids = nav.props.tabs.map((t: any) => t.id);

    expect(ids).toContain('claims');
    // 주문에서 파생된 내역이므로 주문내역 바로 뒤에 둔다 — 찾는 자리가 그곳이다.
    expect(ids.indexOf('claims')).toBe(ids.indexOf('orders') + 1);
    // 탭 전환은 `/mypage/${id}` 로 간다(공용 액션) — 라우트가 그 규칙을 따라야 열린다.
    expect(routes.routes.map((r: any) => r.path)).toContain('/mypage/claims');

    // 목록 셸이 그 탭을 활성으로 표시한다.
    expect(listShell.computed.currentTab).toBe('claims');
  });
});

describe('목록 컨텍스트 왕복 — 같은 목록 안의 이동만 상태를 물려준다', () => {
  // @scenario claim_type=exchange, screen=list, entry=none
  // @effects claim_list_tab_switch_does_not_inherit_the_previous_page, claim_list_type_tabs_follow_the_query
  it('유형 서브탭 전환은 앞 유형의 페이지를 물려받지 않는다', () => {
    const tabNavigate = actionsWith(listPartial, 'replaceUrl').find(
      (a) => a.params?.query?.type === '{{tab.id}}'
    );

    expect(tabNavigate).toBeTruthy();
    // 유형마다 서버가 다른 테이블을 읽는다 — 3페이지가 없는 유형에서 빈 화면이 열린다.
    expect(tabNavigate.params.mergeQuery).toBeUndefined();
    expect(tabNavigate.params.query.page).toBe('1');
    // 의도적 비승계임을 코드에 남긴다 — 남기지 않으면 다음 사람이 누락으로 읽고 되돌린다.
    expect(String(tabNavigate.comment ?? '')).toContain('audit:allow');
  });

  // @scenario claim_type=return, screen=list, entry=none
  // @effects claim_list_page_and_detail_round_trip_keeps_the_list_state
  it('페이지 이동과 상세 진입은 서브탭·페이지를 그대로 물려준다', () => {
    const navigates = actionsWith(listPartial, 'navigate');

    const pager = actionsWith(listPartial, 'replaceUrl').find((a) => a.params?.query?.page === '{{$args[0]}}');
    expect(pager).toBeTruthy();
    expect(pager.params.mergeQuery).toBe(true);

    const detail = navigates.find((a) => String(a.params?.path ?? '').includes('claim.claim_number')
      && !String(a.params?.path ?? '').includes('/edit'));
    expect(detail).toBeTruthy();
    expect(detail.params.mergeQuery).toBe(true);
    expect(detail.params.query).toEqual({});
  });

  // @scenario claim_type=refund, screen=detail, entry=none
  // @effects claim_list_page_and_detail_round_trip_keeps_the_list_state
  it('상세에서 목록으로 돌아가는 이동도 상태를 물려준다', () => {
    const back = actionsWith(detailShell, 'navigate').find(
      (a) => a.params?.path === '/mypage/claims'
    );

    expect(back).toBeTruthy();
    expect(back.params.mergeQuery).toBe(true);
  });

  // @scenario claim_type=cancel, screen=detail, entry=none
  // @effects claim_list_pagination_survives_a_truncated_total
  it('총 건수가 잘린 응답에서도 페이저가 선다', () => {
    const pager = flatten(listPartial).find((n) => n.name === 'Pagination');

    expect(pager).toBeTruthy();
    // 상한에 잘리면 서버가 `last_page` 를 null 로 내려보낸다 — 그 값만 보고 감추면 1페이지 밖
    // 신청서가 조용히 잘린다. `has_more_pages` 실측이 함께 게이트에 들어가야 한다.
    expect(String(pager.if)).toContain('has_more_pages');
    expect(String(pager.props.totalPages)).toContain('?? null');
  });
});

describe('철회·수정 진입점 — 목록과 상세 두 곳에 있다', () => {
  // @scenario claim_type=exchange, screen=edit, entry=list
  // @effects claim_withdraw_entry_points_exist_on_list_and_detail
  it('목록 카드에 [수정]·[철회] 가 있고 승인 전에만 선다', () => {
    const withdraw = actionsWith(listPartial, 'sirsoft-ecommerce.openClaimWithdraw');
    expect(withdraw).toHaveLength(1);
    expect(withdraw[0].params.claimNumber).toBe('{{claim.claim_number}}');

    const editNavigate = actionsWith(listPartial, 'navigate').find((a) =>
      String(a.params?.path ?? '').includes('/edit')
    );
    expect(editNavigate).toBeTruthy();

    // 노출 조건은 서버 판정이다 — 화면이 상태 문자열로 재현하면 상태가 늘 때마다 갈린다.
    const buttons = flatten(listPartial).filter((n) => n.name === 'Button' && n.if);
    expect(buttons.some((b) => String(b.if).includes('is_editable'))).toBe(true);
    expect(buttons.some((b) => String(b.if).includes('is_withdrawable'))).toBe(true);
  });

  // @scenario claim_type=return, screen=edit, entry=detail
  // @effects claim_withdraw_entry_points_exist_on_list_and_detail
  it('상세 헤더에도 같은 진입점이 있고 같은 확인 모달을 연다', () => {
    const withdraw = actionsWith(detailShell, 'sirsoft-ecommerce.openClaimWithdraw');
    expect(withdraw).toHaveLength(1);

    const editNavigate = actionsWith(detailShell, 'navigate').find((a) =>
      String(a.params?.path ?? '').includes('/edit')
    );
    expect(editNavigate).toBeTruthy();

    // 확인 절차는 한 벌이다 — 화면마다 복제하면 한 곳만 고쳤을 때 나머지가 옛 규칙으로 남는다.
    const modals = (detailShell.modals ?? []).map((m: any) => m.partial);
    expect(modals).toContain('partials/mypage/claims/_modal_withdraw.json');
    expect((listShell.modals ?? []).map((m: any) => m.partial)).toContain(
      'partials/mypage/claims/_modal_withdraw.json'
    );
  });

  // @scenario claim_type=refund, screen=edit, entry=list
  // @effects claim_withdraw_entry_points_exist_on_list_and_detail
  it('두 셸이 철회 주소의 앞부분을 세운다 (없으면 요청이 나가지 않는다)', () => {
    // 회원 철회 라우트는 주문 스코프가 아니고 비회원은 있다 — 핸들러가 조립할 수 없어 셸이 준다.
    for (const shell of [listShell, detailShell]) {
      expect(shell.initLocal.claimWithdrawApiBase).toBe(
        '/api/modules/sirsoft-ecommerce/user/claims'
      );
      expect(shell.initLocal.isGuest).toBe(false);
      expect(shell.initLocal.withdrawRefreshDataSourceId).toBeTruthy();
    }

    // 철회 뒤 다시 읽을 데이터소스는 화면마다 다르다 — 목록은 목록을, 상세는 그 신청서를.
    expect(listShell.initLocal.withdrawRefreshDataSourceId).toBe('claims');
    expect(detailShell.initLocal.withdrawRefreshDataSourceId).toBe('claim');
  });

  // @scenario claim_type=exchange, screen=detail, entry=list
  // @effects claim_detail_outstanding_payment_has_an_entry_point
  it('상세 셸이 추가결제 주소의 앞부분을 세우고 partial 은 그 값만 읽는다', () => {
    // 승인 시점 결제(pay_on_approval)는 운영자가 승인할 때 서버가 결제 대기 건(CP)을 만든다.
    // 그 뒤 낼 사람은 구매자인데 진입점이 없으면 상세는 미수납 금액만 보여 주고 결제는
    // 영원히 완결되지 않는다 — 오류도 경고도 남지 않는다(2026-09-01 2차 확정).
    expect(detailShell.initLocal.claimPayApiBase).toBe(
      '/api/modules/sirsoft-ecommerce/user/claim-payments'
    );

    const claimInfo = load('layouts/partials/mypage/claims/_detail_claim_info.json');
    const payActions = flatten(claimInfo)
      .flatMap((n: any) => n.actions ?? [])
      .filter((a: any) => a.handler === 'sirsoft-ecommerce.payClaimOutstanding');

    // 모집단이 비면 red — 버튼이 사라졌는데 초록으로 통과하는 것을 막는다.
    expect(payActions.length).toBeGreaterThan(0);

    for (const action of payActions) {
      // 공유 partial 은 회원/비회원을 스스로 판단하지 않는다 — 주소는 셸이 통째로 준다.
      expect(action.params?.payApiBase).toBe('{{_local.claimPayApiBase}}');
      expect(action.params?.isGuest).toBe('{{_local.isGuest === true}}');
      expect(String(action.params?.paymentNumber ?? '')).toContain('pending_payment');
    }
  });

  // @scenario claim_type=exchange, screen=detail, entry=list
  // @effects claim_detail_outstanding_payment_has_an_entry_point
  it('결제 진입점은 결제 대기 건이 있을 때만 선다 (낼 것이 없으면 거절만 돌아온다)', () => {
    const claimInfo = load('layouts/partials/mypage/claims/_detail_claim_info.json');
    const payNodes = flatten(claimInfo).filter((n: any) =>
      (n.actions ?? []).some((a: any) => a.handler === 'sirsoft-ecommerce.payClaimOutstanding')
    );

    expect(payNodes.length).toBeGreaterThan(0);

    // 버튼을 감싼 블록의 게이트가 결제 대기 건과 미수납 둘 다를 본다.
    const gates = flatten(claimInfo)
      .map((n: any) => String(n.if ?? ''))
      .filter((v) => v.includes('pending_payment'));

    expect(gates.length).toBeGreaterThan(0);
    expect(gates.some((g) => g.includes('outstanding_amount'))).toBe(true);
  });

  // @scenario claim_type=refund, screen=edit, entry=detail
  // @effects claim_edit_route_resolves_number_by_position
  it('수정 진입 핸들러는 init_actions 최상위에 있고 _local 을 읽지 않는다', () => {
    // 2026-09-01 브라우저 실측이 찾은 결함 둘의 회귀 가드다. 둘 다 화면은 정상으로 보이고
    // (작성 폼이 그대로 열린다) 오류도 토스트도 남지 않았다.
    //
    //  ⓐ conditions 의 then 안에 넣으면 실행되지 않는다 — 코어는 init_actions **최상위**의
    //     네임스페이스 핸들러만 모아 확장 번들 등록을 기다리는데(waitForHandlers), 중첩된
    //     것은 그 목록에 들어가지 않아 아직 등록 전인 순간에 미등록 핸들러로 건너뛰어진다.
    //  ⓑ params 를 `{{_local.…}}` 로 읽으면 언제나 빈 값이다 — init_actions 는 렌더 전에
    //     실행되고 레이아웃 initLocal 은 마운트 시점에 적용된다.
    const editInit = applyShell.init_actions.find(
      (a: any) => a.handler === 'sirsoft-ecommerce.initClaimEdit'
    );

    expect(editInit, '수정 진입 핸들러가 init_actions 최상위에 없다').toBeTruthy();
    expect(String(editInit.if)).toContain('route.claim_number');
    expect(editInit.params.claimApiBase).toBe('/api/modules/sirsoft-ecommerce/user/claims');
    expect(JSON.stringify(editInit.params)).not.toContain('_local');

    // 승인 이후 신청서의 `/edit` 은 상세로 돌려보낸다(#599 T2-D7) — 돌아갈 주소는 셸이 준다.
    // 핸들러가 조립하면 회원·비회원 주소 모양이 달라 한쪽이 404 가 된다(claimApiBase 와 같은 근거).
    expect(String(editInit.params.detailPath)).toContain("'/mypage/claims/'");
    expect(String(editInit.params.detailPath)).toContain('route.claim_number');

    // 셸이 렌더 이후 쓰는 값과 같아야 한다 — 두 값이 갈리면 진입과 저장이 다른 주소를 쓴다.
    expect(applyShell.initLocal.claimEditApiBase).toBe(editInit.params.claimApiBase);

    // 어떤 conditions 분기에도 확장 핸들러를 중첩하지 않는다.
    const nested = JSON.stringify(
      applyShell.init_actions.filter((a: any) => a.handler === 'conditions')
    );
    expect(nested).not.toContain('sirsoft-ecommerce.');

    // 작성 진입의 유형 가드는 그대로 남는다 — 없으면 빈 신청서가 열리고 제출에서야 거절된다.
    const guard = applyShell.init_actions.find((a: any) => a.handler === 'conditions');
    const typeGuard = guard.conditions.find((c: any) => String(c.if).includes("'exchange'"));
    expect(typeGuard).toBeTruthy();
    expect(String(typeGuard.if)).toContain('!route.claim_number');
    expect(typeGuard.then.params.errorCode).toBe(404);
  });

  // @scenario claim_type=exchange, screen=detail, entry=none
  // @effects claim_edit_order_404_is_a_normal_path
  it('작성 셸의 주문 조회 404 는 핸들러가 가른다 (수정 진입의 첫 호출은 정상 경로)', () => {
    const order = applyShell.data_sources.find((s: any) => s.id === 'order');

    expect(String(order.endpoint)).toContain('_local.claimEditOrderNumber');
    // errorHandling 은 조건 분기를 받지 않는다 — 그래서 판정을 핸들러가 한다.
    expect(order.errorHandling['404'].handler).toBe('sirsoft-ecommerce.claimApplyOrderMissing');
  });
});

describe('상세 셸 — 원 주문을 신청서 뒤에 읽는다', () => {
  // @scenario claim_type=return, screen=detail, entry=none
  // @effects claim_detail_loads_the_relations_the_screen_draws
  it('신청서가 알려 준 주문번호로 주문을 다시 부른다', () => {
    const claim = detailShell.data_sources.find((s: any) => s.id === 'claim');
    const order = detailShell.data_sources.find((s: any) => s.id === 'order');

    expect(String(claim.endpoint)).toContain('{{route.claim_number}}');
    // 신청서를 열 자격은 claim 데이터소스가 판정한다 — 그 404 는 오류 화면을 세운다.
    expect(claim.errorHandling['404'].handler).toBe('showErrorPage');

    // setState 와 refetch 는 같은 sequence 안에 있어야 한다 — refetch 가 읽는 로컬 상태는
    // 직전 setState 의 pending 값이다.
    const chain = claim.onSuccess[0];
    expect(chain.handler).toBe('sequence');
    expect(chain.actions[0].handler).toBe('setState');
    expect(chain.actions[1].params.dataSourceId).toBe('order');

    // 주문은 **스스로 부르지 않는다**. auto_fetch 를 켜 두면 주문번호를 모르는 첫 호출이
    // 나가고, 그 404 가 체인의 200 뒤에 해소되면서 이미 받아 둔 주문을 덮어 쓴다 —
    // 대비표의 '원 주문' 열과 결제 정보 금액이 통째로 비고, 404 는 억제돼 있어 오류도
    // 남지 않는다(2026-09-01 브라우저 실측).
    expect(order.auto_fetch).toBe(false);
    expect(String(order.endpoint)).toContain('_local.claimOrderNumber');
    expect(order.errorHandling['404'].handler).toBe('suppress');
  });

  // @scenario claim_type=exchange, screen=list, entry=none
  // @effects claim_list_response_keeps_the_item_rows_the_card_draws
  it('목록 카드가 상품 행을 그린다 (그래서 목록 응답이 그 행을 싣는다)', () => {
    // 목록 응답의 하위 컬렉션 축소는 "화면이 그리지 않는 것" 만 뺀다. 상품 행은 그린다 —
    // 이 단언이 red 가 되면 서버 쪽 `items` 도 함께 뺄 수 있다는 뜻이다.
    const itemLoop = flatten(listPartial).find(
      (n) => n.iteration?.item_var === 'item' && String(n.iteration.source).includes('claim.items')
    );

    expect(itemLoop).toBeTruthy();

    const drawn = flatten(itemLoop)
      .map((n) => String(n.text ?? '') + String(n.props?.src ?? ''))
      .join(' ');

    expect(drawn).toContain('item.product_name');
    expect(drawn).toContain('item.product_option_name');
    expect(drawn).toContain('item.subtotal_amount_formatted');
    expect(drawn).toContain('item.thumbnail_url');
  });

  // @scenario claim_type=return, screen=edit, entry=list
  // @effects claim_edit_save_sends_only_editable_fields
  it('수정 저장은 셸이 준 주소 앞부분을 쓰고 그 신청서 상세로 돌아간다', () => {
    const summary = load('layouts/partials/mypage/claims/_apply_summary.json');
    const save = actionsWith(summary, 'sirsoft-ecommerce.saveClaimEdit');

    expect(save).toHaveLength(1);
    expect(save[0].params.claimEditApiBase).toBe("{{_local.claimEditApiBase ?? ''}}");
    // 돌아갈 곳의 앞부분도 셸이 준다 — partial 이 회원 주소를 리터럴로 들면 비회원이 회원
    // 전용 주소로 보내져 로그인 화면이 열린다(오류도 토스트도 남지 않는다, S14).
    expect(String(save[0].params.successPath)).toContain('_local.claimDetailPathBase');
    expect(String(save[0].params.successPath)).not.toContain('/mypage/');

    expect(applyShell.initLocal.claimEditApiBase).toBe(
      '/api/modules/sirsoft-ecommerce/user/claims'
    );
    expect(applyShell.initLocal.claimDetailPathBase).toBe('/mypage/claims');
  });

  // @scenario claim_type=cancel, screen=list, entry=none
  // @effects claim_list_row_actions_follow_server_flags
  it('취소 이력에는 수정·철회가 서지 않는다 (서버가 두 플래그를 false 로 낸다)', () => {
    // 화면은 유형으로 가르지 않고 서버 판정만 읽는다 — 취소 이력은 그 판정이 언제나 false 다.
    const gate = flatten(listPartial).find(
      (n) => String(n.if ?? '').includes('is_editable') && String(n.if ?? '').includes('is_withdrawable')
    );

    expect(gate).toBeTruthy();
    expect(String(gate.if)).not.toContain('claim_type');
  });

  // @scenario claim_type=exchange, screen=edit, entry=detail
  // @effects claim_edit_hides_charge_axes_and_shows_the_recorded_method
  it('수정 화면의 되돌아갈 곳은 그 신청서다 (작성은 주문 상세)', () => {
    const back = actionsWith(applyShell, 'navigate').find((a) =>
      String(a.params?.path ?? '').includes('route.claim_number')
    );

    expect(back).toBeTruthy();
    expect(String(back.params.path)).toContain('/mypage/claims/');
    expect(String(back.params.path)).toContain('/mypage/orders/');
    expect(back.params.mergeQuery).toBe(true);
  });

  // @scenario claim_type=return, screen=detail, entry=none
  // @effects claim_detail_contrast_axis_shows_both_sides
  it('상세가 주문 상세의 정본 partial 을 그대로 재사용한다', () => {
    const partials = flatten(detailShell.slots.content)
      .map((n) => n.partial)
      .filter(Boolean);

    // 주문자·배송지·결제 정보는 복제하지 않는다 — 복제하면 주문상세가 바뀔 때 한쪽만 낡는다.
    expect(partials).toContain('partials/mypage/orders/_orderer.json');
    expect(partials).toContain('partials/mypage/orders/_shipping.json');
    expect(partials).toContain('partials/mypage/orders/_payment.json');
    expect(partials).toContain('partials/mypage/claims/_detail_claim_info.json');
  });

  // @scenario claim_type=refund, screen=detail, entry=none
  // @effects claim_detail_order_shipping_is_read_only
  it('배송지 카드는 조회 전용으로 열린다 (이 화면에는 배송지 변경 모달이 없다)', () => {
    // 셸이 이 값을 세우지 않으면 [배송지 변경] 버튼이 서고, 누르면 열 모달이 없어 아무 일도
    // 일어나지 않는다 — 오류도 토스트도 남지 않는다.
    expect(detailShell.initLocal.orderShippingReadonly).toBe(true);

    const shipping = load('layouts/partials/mypage/orders/_shipping.json');
    const buttons = flatten(shipping).filter((n) => n.name === 'Button');
    const changeButtons = buttons.filter((n) =>
      JSON.stringify(n.actions ?? []).includes('changeAddressModal')
    );

    // 정본 partial 쪽도 그 값을 실제로 읽어야 한다 — 셸만 세우고 partial 이 안 읽으면
    // 값은 있는데 버튼은 그대로 선다.
    expect(changeButtons.length).toBeGreaterThan(0);
    expect(
      changeButtons.every((n) => String(n.if ?? '').includes('orderShippingReadonly'))
    ).toBe(true);

    // 주문 상세·비회원 주문 상세는 그 값을 세우지 않는다 — 거기서는 버튼이 살아 있어야 한다.
    const orderShell = load('layouts/mypage/orders/show.json');
    expect(orderShell.initLocal?.orderShippingReadonly).toBeUndefined();
  });

  // @scenario claim_type=refund, screen=detail, entry=none
  // @effects claim_detail_payment_provider_blocks_follow_the_shared_card
  it('PG 제공 블록은 결제 정보 카드를 따라간다 (레이아웃 이름을 겨냥하지 않는다)', () => {
    // 네 결제 플러그인이 target_layout(mypage/orders/show)을 겨냥하면 그 한 화면에서만 뜨고,
    // 같은 카드를 재사용하는 이 화면은 카드는 나오는데 입금 안내·영수증만 빠진 채 열린다.
    const payment = load('layouts/partials/mypage/orders/_payment.json');
    const slots = flatten(payment)
      .filter((n) => n.type === 'extension_point')
      .map((n) => n.name);

    expect(slots).toContain('mypage_order_payment_provider_slot');

    // 모집단을 저장소에서 파생한다 — 손으로 적으면 결제 플러그인이 하나 늘어도 통과한다.
    // 옛 형태(target_layout + target_id: order_payment_info_panel)도 걸려야 하므로
    // **앵커 id 또는 슬롯 이름이 등장하는 파일**을 모은다. 슬롯 이름만으로 모으면 "이미
    // 슬롯을 선언한 파일" 만 걸려 검사가 항등식이 되고 옛 형태가 조용히 빠진다.
    const pluginsDir = path.resolve(__dirname, '../../../../../plugins/_bundled');
    const pluginExtensions: string[] = [];

    for (const plugin of fs.readdirSync(pluginsDir)) {
      const extDir = path.join(pluginsDir, plugin, 'resources/extensions');
      if (!fs.existsSync(extDir)) continue;

      for (const file of fs.readdirSync(extDir)) {
        if (!file.endsWith('.json')) continue;
        const full = path.join(extDir, file);
        const src = fs.readFileSync(full, 'utf-8');
        if (src.includes('order_payment_info_panel') || src.includes('mypage_order_payment_provider_slot')) {
          pluginExtensions.push(full);
        }
      }
    }

    // 모집단이 비면 red — 파일이 옮겨졌는데 초록으로 통과하는 것을 막는다.
    expect(pluginExtensions.length).toBeGreaterThan(0);

    for (const full of pluginExtensions) {
      const ext = JSON.parse(fs.readFileSync(full, 'utf-8'));

      expect(ext.extension_point, `${full}`).toBe('mypage_order_payment_provider_slot');
      expect(ext.target_layout, `${full} 이 슬롯 대신 레이아웃 이름을 겨냥한다`).toBeUndefined();
      expect((ext.components ?? []).length).toBeGreaterThan(0);
    }
  });

  // @scenario claim_type=refund, screen=detail, entry=none
  // @effects claim_detail_payment_provider_blocks_follow_the_shared_card
  it('결제 플러그인은 화면 주소 글자가 아니라 공유 결제 카드 id 로 이 화면을 찾는다', () => {
    // 영수증 버튼·가상계좌 입금 안내는 예전에 플러그인 JS 가 주소(`/mypage/orders/{n}`)를 보고 DOM 에 직접 그렸다 —
    // 그 스크립트가 클레임 신청서 상세 주소를 모르면 카드는 뜨는데 안내만 빠졌다. 이제는 결제 플러그인의 화면 조각이
    // 주문 상세와 신청서 상세가 함께 쓰는 결제 카드에 붙으므로, 주소를 글자로 판정하는 플러그인 스크립트가 남아 있으면 안 된다.
    // 경로 리터럴은 소스마다 이스케이프가 다르다(정규식 안이면 `mypage\/orders`). 문자열 비교로 재면 이스케이프 한 글자
    // 차이로 모집단이 비어 조용히 통과한다.
    const ORDER_PATH_RE = /mypage[\\/]+orders/;
    const pluginsDir = path.resolve(__dirname, '../../../../../plugins/_bundled');

    const pathGated: string[] = [];
    const fragmentsOnSharedCard: string[] = [];
    for (const plugin of fs.readdirSync(pluginsDir)) {
      const jsDir = path.join(pluginsDir, plugin, 'resources/js');
      if (fs.existsSync(jsDir)) {
        for (const file of fs.readdirSync(jsDir)) {
          if (!file.endsWith('.ts')) continue;
          const src = fs.readFileSync(path.join(jsDir, file), 'utf-8');
          if (src.includes('order_payment_info_panel') && ORDER_PATH_RE.test(src)) {
            pathGated.push(`${plugin}/${file}`);
          }
        }
      }

      const extDir = path.join(pluginsDir, plugin, 'resources/extensions');
      if (fs.existsSync(extDir)) {
        for (const file of fs.readdirSync(extDir)) {
          if (!file.endsWith('.json')) continue;
          const src = fs.readFileSync(path.join(extDir, file), 'utf-8');
          if (src.includes('order_payment_info_panel') || src.includes('mypage_order_payment_provider_slot')) {
            fragmentsOnSharedCard.push(`${plugin}/${file}`);
          }
        }
      }
    }

    expect(pathGated, '주소 글자로 결제 카드 화면을 판정하는 플러그인 스크립트').toEqual([]);
    // 모집단이 비면 red — 조각이 다른 자리로 옮겨졌는데 초록으로 통과하는 것을 막는다.
    expect(fragmentsOnSharedCard.length).toBeGreaterThan(0);
  });

  // @scenario claim_type=refund, screen=list, entry=none
  // @effects claim_list_amount_column_branches_by_type
  it('목록 데이터소스가 유형을 서버 게이트에 넘긴다 (기본값은 교환)', () => {
    const claims = listShell.data_sources.find((s: any) => s.id === 'claims');

    expect(String(claims.params.type)).toContain("query.type ?? 'exchange'");
    expect(String(claims.params.page)).toContain('query.page');
    // 철회한 신청서도 목록에 남으므로(행이 사라지지 않는다) 매번 다시 읽는다.
    expect(claims.cache).toBe(false);
    expect(claims.refetchOnMount).toBe(true);
  });

  // @scenario claim_type=refund, screen=detail, entry=list
  // @effects claim_edit_replaces_submit_with_save
  it('수정 진입은 목록과 상세 두 곳에서 같은 주소를 만든다', () => {
    const fromList = actionsWith(listPartial, 'navigate').find((a) =>
      String(a.params?.path ?? '').includes('/edit')
    );
    const fromDetail = actionsWith(detailShell, 'navigate').find((a) =>
      String(a.params?.path ?? '').includes('/edit')
    );

    // 목록은 여러 셸이 공유하므로 앞부분을 셸에서 받는다(S14). 상세 셸은 자기 화면이라 리터럴이다.
    expect(String(fromList.params.path)).toContain('_local.claimDetailPathBase');
    expect(String(fromList.params.path)).toContain("'/edit'");
    expect(String(fromDetail.params.path)).toMatch(/^\/mypage\/claims\/.+\/edit$/);
    // 두 진입 모두 목록 상태를 물려준다 — 수정을 마치면 보던 목록으로 돌아간다.
    expect(fromList.params.mergeQuery).toBe(true);
    expect(fromDetail.params.mergeQuery).toBe(true);
  });
});
