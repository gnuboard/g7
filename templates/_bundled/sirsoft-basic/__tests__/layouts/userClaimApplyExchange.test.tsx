/**
 * @file userClaimApplyExchange.test.tsx
 * @description 유저 클레임 신청서 작성 화면 구조 회귀 테스트 (#599 S11)
 *
 * 이 화면에서 조용히 깨지는 지점만 고정한다.
 *
 * ① **교환 방식은 신청서 전체가 아니라 상품 속성이다 (D29).** 신청서 하나에 걸린 라디오로
 *    접으면 상품마다 다른 교환 대상을 표현할 수 없다. 그래서 '교환 방식' 카드는 없어야 하고
 *    [교환상품 변경] 버튼은 **상품 반복 안**에 있으며 그 행의 index 를 넘겨야 한다 — index 를
 *    흘리면 언제나 첫 상품만 바뀌고 오류 없이 잘못된 신청서가 저장된다.
 *
 * ② **사유 카탈로그는 유형별로 다른 목록이다.** 환불 사유를 교환 화면에 그리면 운영자가
 *    교환용으로 만든 사유가 화면에 영영 나타나지 않는다. 라벨은 서버가 열람자 언어로 준
 *    `localized_name` 이며, `name` 은 다국어 맵이라 바인딩하면 객체가 그대로 찍힌다.
 *
 * ③ **미리보기 거절은 화면에 남아야 한다 (P13).** 미리보기는 신청과 같은 자격 가드를 타므로
 *    그 사유가 곧 신청 거절 사유다. 토스트로만 알리면 사라진 뒤 왜 제출이 막혔는지 화면에
 *    남지 않는다. 제출 버튼은 미리보기 성공을 조건으로 잠긴다.
 *
 * ④ **청구는 2축이다 (D6).** 한 축으로 접으면 '승인 후 결제 + 마일리지' 조합이 표현 불가해진다.
 *    두 축 모두 운영자 허용 목록에서 나오며 리터럴 목록을 두지 않는다.
 *
 * ⑤ **주문 ID 의존 데이터소스는 순서를 지켜야 한다.** 회원 클레임 API 는 주문 ID(정수)를 받는데
 *    라우트는 주문번호를 쓴다. `refetchDataSource` 는 액션 params 의 localStateOverride 를
 *    읽지 않고 현재 로컬 상태를 병합하므로, setState 가 같은 sequence 의 앞에 있어야 한다.
 *
 * ⑥ **주문상세의 취소 버튼과 클레임 버튼이 함께 남으면** 같은 일을 하는 입구가 둘이 된다.
 *    공유 partial 이라 셸 표식(useClaimActions)으로 가른다 — 표식이 없는 비회원 셸은 종전 그대로.
 *
 * @scenario exchange_mode=same, preview_result=rejected
 * @effects claim_apply_total_charge_shows_secondary_currency,
 *   order_detail_claim_additional_paid_row_shows_secondary_currency,
 *   claim_apply_option_price_difference_broken_down_per_item,
 *   claim_apply_shipping_fee_basis_shown_for_policy_mode,
 *   claim_apply_has_no_global_exchange_mode_card,
 *   claim_apply_change_product_button_is_per_item,
 *   claim_apply_reason_catalog_is_type_scoped,
 *   claim_apply_reason_label_uses_localized_name,
 *   claim_apply_preview_rejection_stays_on_screen,
 *   claim_apply_submit_requires_successful_preview,
 *   claim_apply_charge_axes_are_two_and_operator_sourced,
 *   claim_apply_charge_axes_hidden_for_refund,
 *   claim_apply_route_type_guard_404,
 *   claim_apply_claimable_datasource_waits_for_order_id,
 *   claim_apply_unmount_clears_estimate_timers,
 *   order_detail_claim_entry_buttons_gated_by_claimable_types,
 *   order_detail_claim_entry_passes_checked_options,
 *   order_detail_old_cancel_button_removed,
 *   order_detail_claim_additional_paid_row_present,
 *   claim_apply_summary_amount_rows_all_show_secondary_currency,
 *   claim_apply_total_charge_row_hidden_for_refund,
 *   claim_apply_item_validation_error_maps_to_its_row
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const LAYOUTS = path.resolve(__dirname, '../../layouts');

interface Node {
  type?: string;
  name?: string;
  id?: string;
  if?: string;
  text?: string;
  comment?: string;
  props?: Record<string, any>;
  actions?: any[];
  iteration?: { source?: string; item_var?: string; index_var?: string };
  lifecycle?: { onMount?: any[]; onUnmount?: any[] };
  children?: Node[];
  [key: string]: any;
}

function load(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(LAYOUTS, relative), 'utf8'));
}

/** 노드 트리를 통째로 평탄화한다 (children 뿐 아니라 actions/params 안까지). */
function flatten(node: any, out: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return out;
  out.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((v) => flatten(v, out));
    else if (value && typeof value === 'object') flatten(value, out);
  }
  return out;
}

/** children 만 따라가는 평탄화 (조상 관계 판정용). */
function flattenChildren(node: Node | undefined, out: Node[] = []): Node[] {
  if (!node) return out;
  out.push(node);
  for (const child of node.children ?? []) flattenChildren(child, out);
  return out;
}

const apply = load('mypage/claims/apply.json');
const items = load('partials/mypage/claims/_apply_items.json');
const reason = load('partials/mypage/claims/_apply_reason.json');
const redelivery = load('partials/mypage/claims/_apply_redelivery.json');
const summary = load('partials/mypage/claims/_apply_summary.json');
const changeProduct = load('partials/mypage/claims/_modal_change_product.json');
const orderShow = load('mypage/orders/show.json');
const orderItems = load('partials/mypage/orders/_items.json');
const orderPayment = load('partials/mypage/orders/_payment.json');

describe('apply.json — 작성 셸', () => {
  /** @effects claim_apply_route_type_guard_404 */
  it('라우트 유형 가드가 허용값 밖을 404 로 세운다', () => {
    const guard = flatten(apply.init_actions).find(
      (n) => typeof n.if === 'string' && n.if.includes("'exchange'") && n.if.includes("'refund'")
    );
    expect(guard).toBeTruthy();
    expect(guard.then?.handler).toBe('showErrorPage');
    expect(guard.then?.params?.errorCode).toBe(404);
  });

  /** @effects claim_apply_claimable_datasource_waits_for_order_id */
  it('claimable 은 주소가 실어 온 주문 ID 로 곧바로 부른다 (체인만으로는 뒤로가기에서 값이 닿지 않는다)', () => {
    // 회귀(2026-08-31 브라우저 실측): order 응답 → setState → refetchDataSource 체인에만 기대면
    // SPA 뒤로가기로 되돌아왔을 때 refetch 결과가 새로 마운트된 화면의 상태에 닿지 않는다.
    // 요청은 200 이고 콘솔 오류도 없는데 화면만 '신청할 수 있는 상품이 없습니다' 로 남았다.
    const claimable = apply.data_sources.find((s: any) => s.id === 'claimable');
    expect(claimable.auto_fetch).toBe(true);
    expect(claimable.refetchOnMount).toBe(true);
    expect(claimable.cache).toBe(false);
    expect(claimable.endpoint).toContain('{{query.order_id ?? _local.claimOrderId ?? 0}}');
    expect(claimable.endpoint).toContain('/claimable-options');
    // order_id 없는 직접 진입의 첫 호출 404 는 화면을 세우지 않는다 (체인이 곧 다시 부른다).
    expect(claimable.errorHandling['404'].handler).toBe('suppress');
  });

  /** @effects order_detail_claim_entry_passes_checked_options */
  it('주문상세는 클레임 진입에 주문 ID 를 함께 넘긴다', () => {
    const buttons = flatten(orderShow).filter(
      (n: any) => n.name === 'Button' && String(n.props?.disabled ?? '').includes('claimable_types')
    );
    for (const button of buttons) {
      expect(button.actions[0].params.query.order_id).toBe("{{order.data.id ?? ''}}");
    }
  });

  /** @effects claim_apply_claimable_datasource_waits_for_order_id */
  it('주문 데이터소스는 진입할 때마다 다시 부른다 (뒤로가기 캐시)', () => {
    for (const layout of [apply, orderShow]) {
      const order = layout.data_sources.find((s: any) => s.id === 'order');
      expect(order.refetchOnMount).toBe(true);
      expect(order.cache).toBe(false);
    }
  });

  /** @effects claim_apply_claimable_datasource_waits_for_order_id */
  it('order.onSuccess 는 setState 다음에 refetchDataSource 를 둔다 (순서가 뒤바뀌면 빈 URL 로 조회된다)', () => {
    const order = apply.data_sources.find((s: any) => s.id === 'order');
    const sequence = order.onSuccess[0];
    expect(sequence.handler).toBe('sequence');

    const actions = sequence.actions;
    expect(actions[0].handler).toBe('setState');
    expect(actions[0].params.claimOrderId).toBe('{{response.data.data.id}}');
    expect(actions[1].handler).toBe('refetchDataSource');
    expect(actions[1].params.dataSourceId).toBe('claimable');
  });

  /**
   * @scenario claim_type=exchange, screen=edit, entry=detail
   * @effects claim_edit_shell_sets_api_base_on_the_order_response
   */
  it('order.onSuccess 는 claimApiBase 도 세운다 (수정 진입은 initLocal 이 서지 않아 미리보기가 한 번도 호출되지 않았다)', () => {
    // 회귀(2026-09-11 브라우저 실측, #599 후속): 수정 진입의 첫 주문 호출은 orders/0 → 404 라
    // initLocal 의 claimApiBase 가 서지 않고, 번호로 다시 부른 성공 응답에는 initLocal 이 재적용되지
    // 않는다. 그래서 수정 화면은 사유·재발송지를 바꿔도 claims/preview 를 부르지 않았고(D14 사문),
    // 주문금액 비교도 "계산됩니다" 자리표시에 머물렀다.
    const order = apply.data_sources.find((s: any) => s.id === 'order');
    const setState = order.onSuccess[0].actions[0];
    expect(setState.params.claimApiBase).toBe("{{'/api/modules/sirsoft-ecommerce/user/orders/' + response.data.data.id}}");
    // 작성 진입(initLocal)과 같은 기준 경로여야 한다 — 두 곳이 갈리면 작성·수정의 미리보기가 다른 주소를 부른다.
    expect(order.initLocal.claimApiBase).toBe("{{'/api/modules/sirsoft-ecommerce/user/orders/' + data.id}}");
  });

  /** @effects claim_apply_claimable_datasource_waits_for_order_id */
  it('claimable.onSuccess 는 응답 페이로드를 액션 파라미터로 넘긴다', () => {
    // 회귀(2026-08-31 브라우저 실측): 핸들러가 자기 onSuccess 시점에 데이터소스 store 를 읽으면
    // 아직 비어 있어(TemplateApp 상태 갱신이 refetch 해소 뒤) 응답 200 인데 화면만 빈 신청서로
    // 남았다. 오류도 로그도 남지 않는다 — 그래서 페이로드를 응답 컨텍스트에서 직접 넘긴다.
    const claimable = apply.data_sources.find((s: any) => s.id === 'claimable');
    const init = claimable.onSuccess[0];

    expect(init.handler).toBe('sirsoft-ecommerce.initClaimApply');
    expect(init.params.claimable).toBe('{{response.data.data}}');

    // route / query 는 데이터소스 onSuccess 컨텍스트에 없다 — 넘기는 형태만 남으면 살아 있는
    // 배선처럼 보이므로 두지 않는다. 유형과 미리 선택 상품은 핸들러가 주소에서 읽는다.
    expect(init.params.claimType).toBeUndefined();
    expect(init.params.preselected).toBeUndefined();
  });

  /** @effects claim_apply_shell_initlocal_holds_only_static_contract */
  it('레이아웃 initLocal 은 셸이 정하는 정적 계약값만 갖는다 (핸들러가 쓰는 키를 두면 재적용이 되돌린다)', () => {
    // 회귀(2026-08-31 브라우저 실측): initLocal 은 마운트 뒤 한 번 더 적용되며 핸들러가 쓴 값을
    // 초기값으로 되돌린다. 첫 진입에서는 순서가 맞아 보이지만 SPA 뒤로가기로 되돌아오면 재적용이
    // 뒤에 도착해, 응답 200 · 오류 0 인 채로 빈 신청서만 남는다.
    //
    // 지키는 것은 개수가 아니라 성질이다 — 여기 있어도 되는 것은 **회원/비회원에 따라 셸이
    // 정하고 그 뒤로 바뀌지 않는 값**뿐이다. S13 이 더한 `claimEditApiBase`(수정 PATCH 주소의
    // 앞부분)도 그 성질이며, 회원과 비회원의 라우트 모양이 달라 핸들러가 조립할 수 없다.
    expect(Object.keys(apply.initLocal).sort()).toEqual([
      'claimDetailPathBase',
      'claimEditApiBase',
      'claimListPath',
      'imageDeleteBase',
      'isGuest',
    ]);
    expect(apply.initLocal.isGuest).toBe(false);
    expect(apply.initLocal.imageDeleteBase).toContain('/user/claims/images');
    expect(apply.initLocal.claimEditApiBase).toContain('/user/claims');
    // 접수·저장 뒤에 갈 곳도 같은 성질이다 — 회원/비회원에 따라 셸이 정하고 그 뒤로 바뀌지
    // 않는다. 공유 partial(_apply_summary.json)이 주소를 리터럴로 들면 비회원이 회원 전용
    // 주소로 보내진다(S14).
    expect(apply.initLocal.claimListPath).toBe('/mypage/claims');
    expect(apply.initLocal.claimDetailPathBase).toBe('/mypage/claims');

    // claimApiBase 는 주문 ID 가 필요하므로 order 데이터소스의 initLocal 이 소유한다.
    const order = apply.data_sources.find((s: any) => s.id === 'order');
    expect(order.initLocal.claimApiBase).toContain('/user/orders/');
  });

  /** @effects claim_apply_unmount_clears_estimate_timers */
  it('화면을 떠날 때 미리보기 타이머와 세대 번호를 정리한다', () => {
    const withUnmount = flatten(apply.slots.content).find((n) => n.lifecycle?.onUnmount);
    expect(withUnmount).toBeTruthy();
    expect(withUnmount.lifecycle.onUnmount[0].handler).toBe('sirsoft-ecommerce.clearClaimApplyTimers');
  });

  /** @effects claim_apply_shell_mounts_four_partials_and_the_change_modal */
  it('4개 partial 과 교환상품 변경 모달을 싣는다', () => {
    const partials = flatten(apply).map((n) => n.partial).filter(Boolean);
    expect(partials).toEqual(
      expect.arrayContaining([
        'partials/mypage/claims/_apply_items.json',
        'partials/mypage/claims/_apply_redelivery.json',
        'partials/mypage/claims/_apply_reason.json',
        'partials/mypage/claims/_apply_summary.json',
        'partials/mypage/claims/_modal_change_product.json',
      ])
    );
  });
});

describe('_apply_items.json — 신청 상품 (D29)', () => {
  const nodes = flattenChildren(items);
  const itemLoop = nodes.find((n) => n.iteration?.item_var === 'claimItem');

  /** @effects claim_apply_change_product_button_is_per_item */
  it('상품 반복은 _local.claimItems 를 순회하고 index_var 를 갖는다', () => {
    expect(itemLoop?.iteration?.source).toBe('{{_local.claimItems ?? []}}');
    expect(itemLoop?.iteration?.index_var).toBe('claimIdx');
  });

  /** @effects claim_apply_has_no_global_exchange_mode_card */
  it("신청서 전체에 걸린 '교환 방식' 라디오 그룹이 없다", () => {
    // 전체 라디오로 접으면 상품마다 다른 교환 대상을 표현할 수 없다(D29).
    const radios = flatten(items).filter((n) => n.props?.type === 'radio');
    expect(radios).toHaveLength(0);
  });

  /** @effects claim_apply_change_product_button_is_per_item */
  it('[교환상품 변경] 버튼은 상품 반복 안에 있고 그 행의 index 를 넘긴다', () => {
    // flatten 은 조상·자손을 모두 훑으므로 같은 액션 객체가 여러 번 잡힌다 — 참조로 중복을 접는다.
    const openActions = [...new Set(
      flatten(itemLoop).filter((n) => n.handler === 'sirsoft-ecommerce.openClaimChangeProduct')
    )];

    // 반복 밖에는 하나도 없어야 한다 — 신청서 전체에 걸린 버튼이면 첫 상품만 바뀐다(D29).
    const outsideLoop = [...new Set(
      flatten(items).filter((n) => n.handler === 'sirsoft-ecommerce.openClaimChangeProduct')
    )];

    expect(openActions).toHaveLength(1);
    expect(outsideLoop).toHaveLength(1);
    expect(openActions[0].params.index).toBe('{{claimIdx}}');
  });

  /** @effects user_claim_apply_exchange_renders_per_item_change_button */
  it('교환 대상 블록은 교환 유형에서만 그린다', () => {
    const block = nodes.find((n) => typeof n.if === 'string' && n.if.includes("_local.claimType === 'exchange'"));
    expect(block).toBeTruthy();
  });

  /** @effects claim_apply_quantity_clamped_to_available */
  it('수량 입력은 잔여 수량을 상한으로 갖고 핸들러에 index 를 넘긴다', () => {
    const quantity = flatten(items).find((n) => n.props?.type === 'number');
    expect(quantity.props.max).toBe('{{claimItem.available_quantity ?? 1}}');

    const action = quantity.actions[0];
    expect(action.handler).toBe('sirsoft-ecommerce.updateClaimQuantity');
    expect(action.params.index).toBe('{{claimIdx}}');
  });

  /** @effects claim_apply_amounts_use_server_formatted_strings */
  it('단가는 서버가 준 표기 문자열을 그대로 쓴다 (화면이 통화 기호를 붙이지 않는다)', () => {
    const texts = flatten(items).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain('{{claimItem.unit_price_formatted ?? \'\'}}');
    expect(texts.some((t) => t.includes('toLocaleString'))).toBe(false);
  });

  /** @effects claim_apply_secondary_currency_absorbs_all_server_shapes */
  it('단가에도 보조통화를 병기한다 (§5-4 — 형제 금액 줄과 같은 규약)', () => {
    const loop = flatten(items).find((n) => n.iteration?.item_var === 'unitPriceCurrency');
    expect(loop?.iteration?.source).toBe('{{claimItem.mc_unit_price ?? []}}');

    const texts = flattenChildren(loop).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain("{{unitPriceCurrency.formatted ?? ''}}");
  });

  /** @effects claim_apply_item_validation_error_maps_to_its_row */
  it('제출 거절의 항목별 오류를 그 상품 줄에 그린다 (§6-3 P13)', () => {
    // 회귀(2026-08-31 3차 계획 검증): 서버가 `items.{순번}.{필드}` 로 보낸 오류가
    // `claimValidationErrors` 에 담기기만 하고 어디에도 그려지지 않아, 요약 카드에는
    // message 만 남고 어느 상품이 문제인지 화면에 나타나지 않았다.
    const node = flatten(items).find(
      (n) => typeof n.text === 'string' && n.text.includes('claimItem.validation_error')
    );

    expect(node, '항목별 오류를 그리는 자리가 없다').toBeDefined();
    expect(node?.if).toBe("{{(claimItem.validation_error ?? '') !== ''}}");
    expect(node?.props?.className).toContain('text-red-600');
    expect(node?.props?.className).toContain('dark:text-red-400');
  });
});

describe('_apply_reason.json — 사유', () => {
  /** @effects claim_apply_reason_catalog_is_type_scoped */
  it('사유 목록은 유형별 카탈로그에서 온다', () => {
    const option = flatten(reason).find((n) => n.name === 'Option' && n.iteration);
    expect(option.iteration.source).toBe(
      '{{((_local.claimableSnapshot ?? claimable?.data)?.reasons ?? {})[_local.claimType] ?? []}}'
    );
  });

  /** @effects claim_apply_reason_label_uses_localized_name */
  it('사유 라벨은 localized_name 이다 (name 은 다국어 맵이라 객체가 찍힌다)', () => {
    const option = flatten(reason).find((n) => n.name === 'Option' && n.iteration);
    expect(option.text).toBe('{{claimReason.localized_name}}');
  });

  /** @effects claim_image_limits_come_from_operator_settings */
  it('첨부 상한은 운영자 설정에서 읽는다 (리터럴 금지)', () => {
    const uploader = flatten(reason).find((n) => n.name === 'FileUploader');
    expect(String(uploader.props.maxFiles)).toContain('claimableSnapshot ?? claimable?.data)?.settings?.max_images');
    const hint = flatten(reason).find((n) => n.name === 'P' && String(n.text).includes('claim.apply.images_hint'));
    expect(String(hint.text)).toContain('image_max_size_kb');
  });

  /**
   * 용량 상한도 컴포지트에 넘긴다. (T2-D2 · #599 3차 실측)
   *
   * 넘기지 않던 동안에는 용량을 넘은 파일이 컴포지트 목록에 `big.jpg 8.52 MB` ·
   * `1/5개 첨부됨` 으로 **첨부된 것처럼 남았다** — 핸들러가 걸러 실제로 올라가지는 않지만,
   * 토스트가 5초 뒤 사라지면 화면과 실제가 갈린다.
   *
   * 종전 판단은 「넘기면 컴포지트가 조용히 걸러 아무 일도 없어 보인다」였는데, 실은 컴포지트가
   * `onUploadError` 를 부르고 있었고 **이 화면이 그 이벤트를 받지 않았을 뿐**이다. 그래서
   * 상한을 넘기고 그 이벤트를 함께 받는다.
   *
   * 값은 **같은 설정값**(`image_max_size_kb`)에서 나온다 — 컴포지트가 MB 를 받으므로 서버가 같은 상한을 MB 로도 내려 준다 — 화면이
   * 나눈다. 리터럴을 쓰면 운영자가 상한을 바꿨을 때 안내 문구와 거절선이 갈린다.
   *
   * @effects claim_image_limits_come_from_operator_settings
   */
  it('용량 상한을 컴포지트에 넘기고 거절 사유를 받는다 (T2-D2)', () => {
    const uploader = flatten(reason).find((n) => n.name === 'FileUploader');

    expect(String(uploader.props.maxSize)).toContain('image_max_size_mb');

    const onError = (uploader.actions ?? []).find((a: any) => a.event === 'onUploadError');
    expect(onError).toBeTruthy();
    expect(JSON.stringify(onError)).toContain('toast');
  });

  /** @effects claim_image_upload_defers_to_submit_and_keeps_uploaded_on_retry */
  it('첨부는 change 시점에 올리지 않고 대기열에만 담는다', () => {
    const uploader = flatten(reason).find((n) => n.name === 'FileUploader');
    expect(uploader.props.autoUpload).toBe(false);
    expect(uploader.actions[0].handler).toBe('sirsoft-ecommerce.setClaimImageFiles');
  });
});

describe('_apply_redelivery.json — 재발송지 (D14)', () => {
  /** @effects claim_apply_redelivery_maps_order_address_fields */
  it('교환에서만 그린다', () => {
    expect(redelivery.if).toBe("{{_local.claimType === 'exchange'}}");
  });

  /** @effects claim_apply_redelivery_maps_order_address_fields */
  it('국가는 바꿀 수 없음을 안내한다', () => {
    const texts = flatten(redelivery).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts.some((t) => t.includes('claim.apply.redelivery_country_locked'))).toBe(true);
  });

  /** @effects claim_apply_estimate_applies_latest_generation_only */
  it('적용은 미리보기를 다시 부르는 핸들러를 탄다 (도서산간 재판정)', () => {
    const apply = flatten(redelivery).filter((n) => n.handler === 'sirsoft-ecommerce.applyClaimRedelivery');
    expect(apply.length).toBeGreaterThan(0);
  });
});

describe('_apply_summary.json — 요약 + 청구 2축 (D6·P13)', () => {
  /** @effects claim_apply_preview_rejection_stays_on_screen */
  it('미리보기 거절 사유가 요약 자리에 남는다 (P13)', () => {
    const block = flatten(summary).find(
      (n) => typeof n.if === 'string' && n.if.includes('_local.claimEstimateError')
    );
    expect(block).toBeTruthy();

    const texts = flattenChildren(block).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain("{{_local.claimEstimateError ?? ''}}");
  });

  /** @effects claim_apply_submit_requires_successful_preview */
  it('제출 버튼은 미리보기 성공을 조건으로 한다', () => {
    const submit = flatten(summary).find(
      (n) => n.name === 'Button' && String(n.props?.disabled ?? '').includes('claimEstimateError')
    );
    expect(submit).toBeTruthy();

    const disabled = String(submit.props.disabled);
    expect(disabled).toContain('!_local.claimEstimate');
    expect(disabled).toContain('_local.claimEstimateLoading === true');
    expect(disabled).toContain('!_local.claimReason');
    expect(submit.actions[0].handler).toBe('sirsoft-ecommerce.submitClaim');

    // 접수 뒤 돌아갈 곳은 신청내역 목록이다. S12 시점에는 그 라우트가 없어(뒤 세션 소유)
    // 접수는 성공했는데 404 가 열렸고, 그동안은 주문상세로 돌아갔다 — S13 이 목록을 세우며
    // 원래 목적지로 되돌렸다. 유형 서브탭을 함께 실어야 방금 낸 신청서가 보인다(유형마다
    // 서버가 다른 테이블을 읽는다).
    const successPath = String(submit.actions[0].params.successPath);
    // 목적지의 앞부분은 셸이 준다(S14) — partial 이 회원 주소를 리터럴로 들면 비회원이
    // 회원 전용 주소로 보내진다. 유형 서브탭은 여전히 이 자리에서 붙인다.
    expect(successPath).toContain('_local.claimListPath');
    expect(successPath).toContain("'?type='");
    expect(successPath).not.toContain('/mypage/');
    expect(successPath).not.toContain('/mypage/orders/');
    // 접수 결과는 **언제나 환불신청서**다(#599 후속 D2) — 분기로 탭을 가르지 않는다.
    // 정본은 서버 응답의 신청서 유형이며, 핸들러(`resolveClaimSuccessPath`)가 그 값으로
    // 이 주소의 `type` 을 덮어쓴다. 예전에는 주문 **전체** 기준 값으로 추측해, 분기가 섞인
    // 주문에서 접수한 탭과 이동한 탭이 갈렸다(F8).
    expect(successPath).not.toContain("'cancel'");
    expect(successPath).toContain('_local.claimType');
  });

  /** @effects claim_apply_charge_axes_are_two_and_operator_sourced */
  it('청구는 2축이고 두 축 모두 운영자 허용 목록에서 나온다', () => {
    const loops = flatten(summary).filter((n) => n.iteration?.source);
    const chargeAxis = loops.find((n) => n.iteration.item_var === 'chargeMethod');
    const paymentAxis = loops.find((n) => n.iteration.item_var === 'chargePaymentMethod');

    expect(chargeAxis.iteration.source).toBe(
      '{{(_local.claimableSnapshot ?? claimable?.data)?.settings?.allowed_charge_methods ?? []}}'
    );
    expect(paymentAxis.iteration.source).toBe(
      '{{(_local.claimableSnapshot ?? claimable?.data)?.settings?.charge_payment_methods ?? []}}'
    );
  });

  /** @effects claim_apply_charge_axes_hidden_for_refund */
  it('취소·환불 유형에서는 두 축 모두 미노출이다', () => {
    const axisBlocks = flatten(summary).filter(
      (n) => typeof n.if === 'string' && n.if.includes('allowed_charge_methods') || (typeof n.if === 'string' && n.if.includes('charge_payment_methods'))
    );
    expect(axisBlocks.length).toBe(2);
    for (const block of axisBlocks) {
      expect(block.if).toContain("_local.claimType !== 'refund'");
    }
  });

  /** @effects claim_apply_deduct_from_refund_gated_by_refund_presence */
  it("'환불금에서 차감' 은 환불이 발생할 때만 고를 수 있고, 고르면 축2 가 비활성이 된다", () => {
    const radios = flatten(summary).filter((n) => n.props?.type === 'radio');
    const chargeRadio = radios.find((n) => n.props.name === 'claimChargeMethod');
    const paymentRadio = radios.find((n) => n.props.name === 'claimPaymentMethod');

    expect(String(chargeRadio.props.disabled)).toContain('deduct_from_refund');
    // 교환의 환불 여부는 adjustment.refund_total 이 아니라 저가 교환의 차액 환불로 판정한다 —
    // 교환 adjustment 는 대상 상품을 되돌렸다 얹는 형태라 차액 0원에서도 refund_total 이 0 이 아니다.
    expect(String(chargeRadio.props.disabled)).toContain('refund_product_amount');
    expect(String(chargeRadio.props.disabled)).toContain('refund_total');
    expect(String(paymentRadio.props.disabled)).toContain("_local.claimChargeMethod === 'deduct_from_refund'");
  });

  /** @effects claim_apply_refund_row_hidden_for_exchange */
  it('환불 예정액 행은 교환에서 그리지 않는다 (교환에서 돈이 오가는 축은 옵션 차액과 총 청구액뿐)', () => {
    // 회귀(2026-08-31 브라우저 실측): 차액 0원 교환에서 adjustment.refund_total 이 15,000원으로
    // 내려와 "환불 예정액 15,000원" 이 그려졌다. 구매자에게 돌려줄 돈이 아닌데 그렇게 읽힌다.
    const row = flatten(summary).find(
      (n) => typeof n.if === 'string' && n.if.includes('adjustment?.refund_total')
    );
    expect(row.if).toContain("_local.claimType !== 'exchange'");
  });

  /** @effects claim_apply_charge_axis_check_reads_user_selection */
  it('체크 표시는 사용자의 선택 상태에서 읽는다 (서버 응답이 아니다)', () => {
    const radios = flatten(summary).filter((n) => n.props?.type === 'radio');
    for (const radio of radios) {
      expect(String(radio.props.checked)).toContain('_local.claim');
    }
  });

  /** @effects claim_apply_claimable_reads_prefer_the_snapshot */
  it('claimable 을 읽는 자리는 전부 스냅샷을 1순위로 둔다 (store 단독 읽기 0)', () => {
    // 회귀(2026-08-31 3차 검증 실측): `?order_id` 없이 들어온 진입(주소 직접 입력·북마크)은
    // claimable 첫 호출이 404 로 끝나고 체인이 다시 부르는데, 그 재조회 결과는 응답
    // 컨텍스트로 **핸들러에만** 닿고 데이터소스 store 에는 나타나지 않는다. 그래서 store 를
    // 읽는 자리만 비고 — 상품 줄은 스냅샷에서 나와 그려지므로 화면은 정상으로 보이는데
    // 사유 카탈로그·첨부 상한·청구 2축이 비어 제출이 영영 막혔다.
    //
    // 한 자리를 고치는 것으로는 이 축이 닫히지 않는다(같은 표현식이 3파일에 흩어져 있다).
    // 모집단은 claimable 을 읽는 자리 전체이며, 그 전부가 스냅샷을 1순위로 둬야 한다.
    const sources = [
      ['apply.json', JSON.stringify(apply)],
      ['_apply_reason.json', JSON.stringify(reason)],
      ['_apply_summary.json', JSON.stringify(summary)],
      ['_apply_items.json', JSON.stringify(items)],
      ['_apply_redelivery.json', JSON.stringify(redelivery)],
      ['_modal_change_product.json', JSON.stringify(changeProduct)],
    ] as const;

    for (const [name, raw] of sources) {
      // `claimable?.data` 앞에는 반드시 스냅샷 폴백이 선다.
      const bare = raw.split('claimable?.data').length - 1;
      const guarded = raw.split('(_local.claimableSnapshot ?? claimable?.data)').length - 1;

      expect(bare, `${name}: claimable store 를 스냅샷 없이 읽는 자리가 있다`).toBe(guarded);
    }
  });

  /** @effects claim_apply_summary_amount_rows_all_show_secondary_currency */
  it('요약의 금액 줄에는 보조통화를 병기한다 (§6-3 · §5-4)', () => {
    // 보조통화 값은 서버가 주문 통화 스냅샷의 동결 환율로 낸 것을 핸들러가 그 통화로 포맷한
    // 결과다 — 화면이 환산하면 예고와 실제 청구의 근거가 갈린다.
    //
    // 회귀(2026-08-31 3차 계획 검증): 미리보기 응답의 다통화 값은 `{통화코드: 숫자}` 라
    // 주문 리소스의 `{amount, formatted}` 와 형태가 다르다. 화면이 `[1].formatted` 로 읽고
    // 있어 오류 없이 **빈 줄**이 렌더됐고, 바인딩 문자열의 존재만 보던 이 테스트가 그것을
    // 통과시켰다. 이제 세 줄 모두 핸들러가 편 배열(`claimEstimateFormatted.mc_*`)을 읽고
    // item_var 의 `formatted` 필드를 그린다 — 핸들러 출력과 맞물리는 경로만 통과한다.
    // S12: 환불 예정액 줄은 청구 방식에 따라 **차감 전/후** 두 배열 중 하나를 읽는다(§6-3 반품
    // 요약 3줄). 그래서 이 축의 단언은 정확한 문자열이 아니라 규약을 잰다 — ⓐ 핸들러가 편
    // 배열(`claimEstimateFormatted.mc_*`)만 읽고 ⓑ 서버 원형(`{통화코드: 숫자}`)을 직접 읽지
    // 않으며 ⓒ item_var 의 `formatted` 를 그린다. 문자열 고정으로 되돌리면 줄이 늘 때마다
    // 이 테스트가 규약이 아니라 형태를 막게 된다.
    const cases: Array<[string, string[]]> = [
      ['totalChargeCurrency', ['mc_total_charge_amount']],
      ['shippingFeeCurrency', ['mc_shipping_fee']],
      ['refundTotalCurrency', ['mc_refund_total', 'mc_refund_before_deduction']],
      ['refundFinalCurrency', ['mc_refund_total']],
    ];

    for (const [itemVar, keys] of cases) {
      const loop = flatten(summary).find((n) => n.iteration?.item_var === itemVar);
      expect(loop, `${itemVar} 반복이 없다`).toBeDefined();

      const source = String(loop?.iteration?.source ?? '');

      for (const key of keys) {
        expect(source, `${itemVar} 가 ${key} 를 읽지 않는다`).toContain(`_local.claimEstimateFormatted?.${key}`);
      }

      // 서버 원형을 직접 읽으면 오류 없이 빈 줄이 된다 (3차 C1 의 형태).
      expect(source).not.toContain('refund_formatted?.mc');
      expect(source).not.toContain('claimEstimate?.mc_');

      const texts = flattenChildren(loop).map((n) => n.text).filter((t): t is string => typeof t === 'string');
      expect(texts).toContain(`{{${itemVar}.formatted ?? ''}}`);
    }
  });

  /** @effects claim_apply_total_charge_row_hidden_for_refund */
  it('총 청구액 줄은 취소·환불과 「환불금에서 차감」에 서지 않는다 (§6-3)', () => {
    // 회귀(2026-08-31 3차 계획 검증): 형제 줄(청구 2축·환불 예정액)에는 유형 가드가
    // 있는데 이 줄만 없어 취소 신청서에 "총 청구액 0원" 이 섰다. 서버가 그 유형에 총액
    // 필드를 주지 않아 포맷터가 0 으로 떨어진 값이다.
    //
    // 두 번째 조건은 2026-09-21 세션B 실측(S-B-D6 · 운영 결정 「감추기」)이 더했다.
    // `deduct_from_refund` 는 회수비를 환불액에서 이미 뺐으므로 따로 낼 돈이 없는데,
    // 바로 위 「회수 배송비 차감 −3,000원」 아래에 「총 청구액 3,000원」이 서면 같은 돈을
    // 두 번 내는 것으로 읽힌다.
    //
    // 조건 문자열을 통째로 비교하면 새 조건이 **덧붙기만 해도** 통과한다 — 두 축이
    // 각각 살아 있는지를 따로 확인한다.
    const loop = flatten(summary).find((n) => n.iteration?.item_var === 'totalChargeCurrency');
    const row = flatten(summary).find((n) =>
      flattenChildren(n).some((c) => c === loop) && typeof n.if === 'string' && n.if.includes('claimType')
    );

    const guard = String(row?.if);

    // ① 유형 축 — 취소·환불에는 서지 않는다.
    expect(guard).toContain("_local.claimType !== 'refund'");

    // ② 청구방식 축 — 「환불금에서 차감」에는 서지 않는다. 신청 중에는 서버 미리보기가
    // 아직 방식을 돌려주지 않을 수 있어 화면 선택값(`claimChargeMethod`)을 폴백으로 읽는다.
    expect(guard).toContain("!== 'deduct_from_refund'");
    expect(guard).toContain('_local.claimEstimate?.charge_method');
    expect(guard).toContain('_local.claimChargeMethod');

    // 두 축은 함께 만족해야 한다 — OR 로 이어지면 한쪽만 맞아도 줄이 서서 가드가 무너진다.
    expect(guard).toMatch(/&amp;&amp;|&&/);
    expect(guard).not.toMatch(/\|\|/);
  });

  /** @effects claim_apply_amounts_use_server_formatted_strings */
  it('금액은 서버 표기 문자열만 쓴다 (화면이 통화 단위를 이어 붙이지 않는다)', () => {
    const texts = flatten(summary).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts.some((t) => t.includes('toLocaleString'))).toBe(false);
    expect(texts.some((t) => /원'|원"/.test(t))).toBe(false);
  });

  /** @effects claim_apply_shipping_fee_basis_shown_for_policy_mode */
  it('배송비에는 산출 근거가 붙는다 — 편도 단가 × 왕복 배수 (§6-3)', () => {
    // 합계만 보이면 구매자는 청구액이 어떻게 나왔는지 확인할 방법이 없다. 근거는 서버가
    // 배송정책 그룹마다 내려주므로 화면은 옮기기만 한다 — 그룹이 여럿이면 줄도 여럿이다.
    const loop = flatten(summary).find((n) => n.iteration?.item_var === 'shippingGroup');
    expect(loop?.iteration?.source).toBe("{{_local.claimEstimateFormatted?.shipping_groups ?? []}}");

    const texts = flattenChildren(loop).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain(
      '$t:claim.apply.shipping_fee_basis|unit={{shippingGroup.base_fee ?? \'\'}}|times={{shippingGroup.multiplier ?? 1}}'
    );

    // 도서산간은 있을 때만 — 0원에 "추가 0원" 을 붙이면 없는 청구가 있는 것처럼 읽힌다.
    const extra = flattenChildren(loop).find(
      (n) => typeof n.text === 'string' && n.text.includes('shipping_fee_extra_basis')
    );
    expect(String(extra.if)).toContain('shippingGroup.has_extra_fee === true');

    // 취소·환불은 회수 배송이 없으므로 근거 줄도 서지 않는다.
    const block = flatten(summary).find(
      (n) => typeof n.if === 'string' && n.if.includes('shipping_groups')
    );
    expect(block.if).toContain("_local.claimType !== 'refund'");
  });

  /** @effects claim_apply_option_price_difference_broken_down_per_item */
  it('옵션 차액은 상품별로 나뉜다 — 상품이 둘 이상일 때 (§6-3·D29)', () => {
    // 교환 대상은 상품마다 다르므로(D29) 합계만 보이면 어느 상품이 얼마를 보탰는지 알 수 없다.
    const loop = flatten(summary).find((n) => n.iteration?.item_var === 'summaryItem');
    expect(loop?.iteration?.source).toBe('{{(_local.claimItems ?? []).filter(i => i.selected === true)}}');

    const block = flatten(summary).find(
      (n) => typeof n.if === 'string' && n.if.includes('summaryItem') === false
        && typeof n.if === 'string' && n.if.includes("i.selected === true).length > 1")
    );
    expect(block.if).toContain("_local.claimType === 'exchange'");

    // 차액 표기는 상품 행과 같은 출처(서버 미리보기가 상품마다 돌려준 값)를 쓴다.
    const texts = flattenChildren(loop).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts.some((t) => t.includes('summaryItem.price_difference_formatted'))).toBe(true);
    expect(texts.some((t) => t.includes('summaryItem.new_option_label'))).toBe(true);
    expect(texts.some((t) => t.includes('toLocaleString'))).toBe(false);
  });
});

describe('_modal_change_product.json — 교환상품 변경 모달 (D29)', () => {
  /** @effects claim_apply_change_product_modal_id_uses_the_claim_prefix */
  it('모달 id 는 claim_ 접두를 지킨다', () => {
    expect(changeProduct.id).toBe('modal_claim_change_product');
  });

  /** @effects claim_apply_change_product_modal_reads_page_local_state */
  it('상태는 페이지 로컬(_local)을 그대로 본다 ($parent._local 은 이 모달 컨텍스트에 없다)', () => {
    // 회귀(2026-08-31 브라우저 실측): $parent._local 로 쓰면 조건이 전부 거짓이 되어 제목과
    // 푸터만 남은 빈 모달이 열린다 — 오류도 경고도 없다. 레이아웃 modals 배열에 등록된 모달의
    // _local 은 페이지 로컬이다.
    expect(JSON.stringify(changeProduct)).not.toContain('$parent');

    const bindings = flatten(changeProduct)
      .flatMap((n) => [n.if, n.text, ...Object.values(n.props ?? {}), ...Object.values(n.params ?? {})])
      .filter((v): v is string => typeof v === 'string');

    expect(bindings.filter((b) => b.includes('_local.changeProduct')).length).toBeGreaterThan(0);
  });

  /**
   * 고른 조합을 지금 살 수 없으면 **그 이유를 말한다**. (T2-D10 · #599 3차 실측 L-4)
   *
   * 옵션 찾기(`findMatchingOption`)는 판매중지 옵션을 건너뛴다. 그래서 블랙/M 이 판매중지면
   * 두 그룹을 다 골라도 찾은 옵션이 없고, 종전에는 가격 줄도 안내도 없이 [적용]만 잠겼다 —
   * 같은 화면의 재고 0 은 「선택한 옵션의 재고가 없습니다.」라고 말하는데 이 경우만 침묵했다.
   *
   * 조건식을 직접 평가해 세 경우를 가른다. 모달 루트는 열린 상태에서만 그려져 렌더 하네스로는
   * 이 분기를 열 수 없기 때문이다.
   *
   * @effects claim_apply_change_product_explains_an_unavailable_combination
   */
  it('모든 그룹을 골랐는데 살 수 있는 옵션이 없으면 이유를 말한다 (T2-D10)', () => {
    const notice = flatten(changeProduct).find(
      (n) => typeof n.if === 'string'
        && JSON.stringify(n.children ?? []).includes('claim.change_product.unavailable'),
    );
    expect(notice).toBeTruthy();

    const expr = String(notice.if).replace(/^\{\{|\}\}$/g, '');
    // eslint-disable-next-line no-new-func
    const evaluate = new Function('_local', `return (${expr});`) as (l: any) => boolean;
    const groups = [{ name_localized: '색상' }, { name_localized: '사이즈' }];
    const base = { changeProductMode: 'change', changeProductProduct: { option_groups: groups } };

    // 두 그룹을 다 골랐는데 찾은 옵션이 없다 → 판매중지 조합 → 안내한다.
    expect(evaluate({ ...base, changeProductSelections: { 색상: '블랙', 사이즈: 'M' }, changeProductMatched: null })).toBe(true);
    // 아직 한 그룹만 골랐다 → 고르는 중이지 불가가 아니다 → 안내하지 않는다.
    expect(evaluate({ ...base, changeProductSelections: { 색상: '블랙' }, changeProductMatched: null })).toBe(false);
    // 살 수 있는 옵션을 찾았다 → 안내하지 않는다(재고 문구는 요약 카드가 따로 말한다).
    expect(evaluate({ ...base, changeProductSelections: { 색상: '블랙', 사이즈: 'L' }, changeProductMatched: { id: 7 } })).toBe(false);
    // 동일상품 교환 모드에서는 옵션을 고르지 않으므로 안내하지 않는다.
    expect(evaluate({ ...base, changeProductMode: 'same', changeProductSelections: {}, changeProductMatched: null })).toBe(false);
  });

  /** @effects claim_apply_change_product_apply_locked_without_a_valid_option */
  it('적용 버튼은 옵션변경인데 옵션이 없거나 품절이면 잠긴다', () => {
    const applyButton = flatten(changeProduct).find(
      (n) => n.name === 'Button' && n.actions?.[0]?.handler === 'sirsoft-ecommerce.applyClaimChangeProduct'
    );
    expect(String(applyButton.props.disabled)).toContain('changeProductMatched');
    expect(String(applyButton.props.disabled)).toContain('stock_quantity');
  });
});

describe('mypage/orders/show.json — 클레임 신청 진입', () => {
  /** @effects order_detail_claim_entry_buttons_gated_by_claimable_types */
  it('클레임 신청 버튼 3종이 있고 각각 유형이 열린 상품에서만 활성이다', () => {
    const buttons = flatten(orderShow).filter(
      (n) => n.name === 'Button' && String(n.props?.disabled ?? '').includes('claimable_types')
    );
    expect(buttons).toHaveLength(3);

    const types = buttons.map((b) =>
      String(b.actions[0].params.path).replace(/^.*\/claims\/apply\//, '')
    );
    expect(types.sort()).toEqual(['exchange', 'refund', 'return']);

    for (const button of buttons) {
      // 체크한 상품이 없으면 누를 수 없다.
      expect(String(button.props.disabled)).toContain('_local.selectedItemIds');
      // 목록 클러스터 안의 이동이므로 목록 상태를 물려준다.
      expect(button.actions[0].params.mergeQuery).toBe(true);
      // 체크한 상품을 작성 화면으로 넘긴다.
      expect(button.actions[0].params.query.options).toBe("{{(_local.selectedItemIds ?? []).join(',')}}");
    }
  });

  /** @effects claim_apply_claimable_datasource_waits_for_order_id */
  it('claimable 데이터소스를 싣고 주문 응답 뒤에 부른다', () => {
    const claimable = orderShow.data_sources.find((s: any) => s.id === 'claimable');
    expect(claimable.auto_fetch).toBe(false);
    expect(claimable.endpoint).toContain('{{_local.claimOrderId}}');

    const order = orderShow.data_sources.find((s: any) => s.id === 'order');
    expect(order.onSuccess[0].actions[1].params.dataSourceId).toBe('claimable');
  });

  /**
   * @scenario claim_type=exchange, entry=detail
   * @effects order_detail_old_cancel_button_removed
   */
  it('옛 주문 취소 모달은 더 이상 싣지 않는다 (#599 후속 — 취소는 클레임 진입이 받는다)', () => {
    const modals: any[] = orderShow.modals ?? [];
    expect(modals.some((m) => typeof m.partial === 'string' && m.partial.includes('_modal_cancel.json'))).toBe(false);
    expect(orderShow.initLocal.useClaimActions).toBeUndefined();
  });
});

describe('_apply_summary.json / _apply_comparison.json — 주문금액 비교 섹션 구조 (#599 후속)', () => {
  const summary = load('partials/mypage/claims/_apply_summary.json');
  const comparison = load('partials/mypage/claims/_apply_comparison.json');

  /**
   * @scenario claim_type=exchange, price_delta=higher, charge_method=pay_on_request
   * @effects claim_apply_comparison_partial_mounts_inside_the_summary_card
   */
  it('요약 카드는 금액 요약 뒤·분기 안내 앞에 비교 partial 을 싣고, 카드 안에서 스크롤하지 않는다', () => {
    const children: any[] = summary.children;
    const mountIndex = children.findIndex((n) => n.partial === 'partials/mypage/claims/_apply_comparison.json');
    const noticeIndex = children.findIndex((n) => typeof n.comment === 'string' && n.comment.startsWith('취소·환불 분기 안내'));
    const amountsIndex = children.findIndex((n) => typeof n.comment === 'string' && n.comment.startsWith('금액 요약'));

    expect(mountIndex).toBeGreaterThan(amountsIndex);
    expect(mountIndex).toBe(noticeIndex - 1);
    // 요약은 비교표까지 전부 펼쳐 보여야 한다 — 카드 안 스크롤(max-h + overflow-y) 금지 (제품 결정 2026-09-11).
    expect(summary.props.className).not.toContain('max-h-');
    expect(summary.props.className).not.toContain('overflow-y-auto');
  });

  /**
   * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
   * @effects claim_apply_comparison_default_open_follows_breakpoint
   */
  it('토글은 aria-expanded/aria-controls 를 바인딩하고 본문은 접힘 상태에서 렌더하지 않는다 (P6)', () => {
    const nodes = flatten(comparison);
    const toggle = nodes.find((n) => n.name === 'Button' && n.actions?.[0]?.handler === 'sirsoft-ecommerce.toggleClaimComparison');
    expect(toggle.props['aria-expanded']).toBe('{{_local.claimComparisonOpen === true}}');
    expect(toggle.props['aria-controls']).toBe('claim-comparison-body');

    const body = nodes.find((n) => n.props?.id === 'claim-comparison-body');
    expect(body.if).toContain('_local.claimComparisonOpen === true');
    // 16행 — 14 금액행 + 쿠폰 + 교환 치환. `_global.isPortable` 죽은 참조는 쓰지 않는다.
    const rows = nodes.filter((n) => typeof n.props?.['data-row'] === 'string');
    expect(rows.map((n) => n.props['data-row'])).toEqual([
      'item_count', 'total_list_price_amount', 'subtotal_amount', 'total_product_coupon_discount_amount',
      'total_order_coupon_discount_amount', 'total_code_discount_amount', 'shipping_discount_amount',
      'base_shipping_amount', 'extra_shipping_amount', 'total_points_used_amount', 'total_tax_amount',
      'total_vat_amount', 'total_paid_amount', 'total_earned_points_amount', 'coupons', 'substituted',
    ]);
    // 주석은 그 죽은 참조를 설명하느라 이름을 인용한다 — 바인딩 식만 검사한다.
    const withoutComments = JSON.stringify(comparison, (key, value) => (key === 'comment' || key.startsWith('_comment') ? undefined : value));
    expect(withoutComments).not.toContain('_global.isPortable');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   * @effects claim_apply_cancel_blocked_locks_submit_inline, claim_apply_refund_priority_reestimates
   */
  it('제출 버튼은 환불 불가(cancel_blocked)에 잠기고 우선순위 라디오는 미리보기를 다시 부른다', () => {
    const nodes = flatten(summary);
    const submit = nodes.find((n) => n.name === 'Button' && n.actions?.[0]?.handler === 'sirsoft-ecommerce.submitClaim');
    expect(submit.props.disabled).toContain('_local.claimEstimate?.adjustment?.cancel_blocked === true');

    const radios = nodes.filter((n) => n.name === 'Input' && n.props?.name === 'claimRefundPriority');
    expect(radios.map((n) => n.props.value)).toEqual(['pg_first', 'points_first']);
    for (const radio of radios) {
      expect(radio.actions[0].handler).toBe('sirsoft-ecommerce.changeClaimRefundPriority');
      expect(radio.actions[0].params.claimApiBase).toBe("{{_local.claimApiBase ?? ''}}");
    }
    const priorityBlock = nodes.find((n) => n.props?.['data-testid'] === 'claim-refund-priority');
    // 마일리지를 쓰지 않은 주문에서는 뜻이 없다 — 포인트 사용액으로 가드한다.
    expect(priorityBlock.if).toContain('total_points_used_amount ?? 0) > 0');
  });
});

describe('_items.json — 공유 partial (회원/비회원)', () => {
  /**
   * @scenario claim_type=exchange, entry=detail
   * @effects order_detail_old_cancel_button_removed
   */
  it('옛 [주문 취소] 버튼은 남아 있지 않다 — initUserCancelItems 핸들러 참조 0 (#599 후속)', () => {
    const raw = JSON.stringify(orderItems);
    expect(raw).not.toContain('initUserCancelItems');
    expect(raw).not.toContain('useClaimActions');
    expect(raw).not.toContain('cancel_modal.');
    // 선택 축(체크박스)은 살아 있다 — 클레임 신청 버튼이 체크한 상품을 넘긴다.
    expect(raw).toContain('sirsoft-ecommerce.toggleItemSelection');
    expect(raw).toContain('sirsoft-ecommerce.toggleSelectAllItems');
  });

  /**
   * @scenario exchange_mode=same, preview_result=ok
   *
   * @effects order_detail_claim_entry_buttons_gated_by_claimable_types
   */
  it('체크박스는 신청 가능 여부로 **잠기고**, 칸은 언제나 선다 (#599 후속 U-3)', () => {
    // 종전 계약은 「신청 가능한 유형이 있는 상품에서만 **보인다**」 였다. 그러면 같은 목록 안에서
    // 그 줄만 상품 이미지가 왼쪽으로 밀려 줄 머리가 어긋나고, 신청 버튼이 왜 안 켜지는지도
    // 화면에 드러나지 않는다 — 2026-09-21 요구사항이 「칸은 표시하되 disabled 로 간격을 맞춰라,
    // 모든 클레임 공통」 으로 뒤집었다.
    //
    // 판정 축(`claimable_types`)은 그대로다. 바뀐 것은 그 축이 **가시성**이 아니라 **잠금**을
    // 정한다는 점뿐이다. 옛 축(`abilities?.can_cancel`)으로 되돌아가지 않는 것도 함께 잠근다 —
    // 그 시절 미결제 주문에서는 체크박스만 서고 누를 버튼이 없었다.
    const checkbox = flatten(orderItems).find(
      (n) => n?.props?.type === 'checkbox' && typeof n?.props?.disabled === 'string'
    );

    expect(checkbox).toBeTruthy();
    expect(checkbox.props.disabled).toContain('claimable?.data?.options');
    expect(checkbox.props.disabled).toContain('claimable_types');
    expect(checkbox.props.disabled).not.toContain('can_cancel');

    // 칸을 감추는 조건이 어디에도 남아 있지 않아야 한다.
    const hider = flatten(orderItems).find(
      (n) => typeof n.if === 'string' && n.if.includes('claimable_types') && n.if.includes('item.id')
    );

    expect(hider).toBeUndefined();
  });

  /** @effects order_items_partial_does_not_decide_authentication */
  it('공유 partial 은 로그인 여부를 스스로 판단하지 않는다', () => {
    expect(JSON.stringify(orderItems)).not.toContain('_global.currentUser');
  });
});

describe('_payment.json — 클레임 추가결제 합계 행 (D20)', () => {
  const row = flatten(orderPayment).find(
    (n) => typeof n.if === 'string' && n.if.includes('claim_additional_paid_total')
  );

  /** @effects order_detail_claim_additional_paid_row_present */
  it('행이 존재하고 0원이면 그리지 않는다', () => {
    expect(row).toBeTruthy();
    expect(row.if).toBe('{{(order.data.claim_additional_paid_total ?? 0) > 0}}');
  });

  /** @effects order_detail_claim_additional_paid_row_present */
  it('표기는 서버가 준 문자열을 그대로 쓴다', () => {
    const texts = flattenChildren(row).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain("+{{order.data.claim_additional_paid_total_formatted ?? ''}}");
  });

  /** @effects order_detail_claim_additional_paid_row_shows_secondary_currency */
  it('보조통화를 함께 적는다 — 형제 금액 줄과 같은 규약 (§5-4)', () => {
    // 이 줄만 기준통화 단독이면, 보조통화를 쓰는 구매자가 같은 카드 안에서 한 줄만
    // 자기 통화로 읽지 못한다. 서버는 이 금액의 보조통화 값을 이미 내려준다.
    const loop = flattenChildren(row).find((n) => n.iteration?.item_var === 'claimPaidCurrency');
    expect(loop?.iteration?.source).toContain('order.data.mc_claim_additional_paid_total');
    // 기준통화는 위 줄이 이미 적었으므로 보조 목록에서 뺀다.
    expect(loop?.iteration?.source).toContain('order.data.base_currency');

    const texts = flattenChildren(loop).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain("{{claimPaidCurrency[1]?.formatted ?? ''}}");
  });

  /** @effects order_payment_extension_slot_stays_at_the_end */
  it('확장 슬롯은 여전히 결제 정보 목록의 말단에 있다 (금액 줄이 슬롯 뒤로 밀리지 않는다)', () => {
    // 이 축이 재는 것은 "새로 더한 금액 줄이 확장 슬롯 뒤로 가지 않았는가" 다 — 뒤로 가면
    // 확장이 주입한 카드와 금액 줄의 순서가 뒤집혀 결제 정보가 두 덩이로 갈라진다.
    // 2026-09-01 슬롯이 하나 늘었다(mypage_order_payment_provider_slot — PG 제공 블록이
    // 레이아웃 이름이 아니라 이 카드를 따라가게 하려고 신설). 그래서 "마지막 하나" 가 아니라
    // "말단 연속 구간" 을 잰다.
    const infoList = (orderPayment.children as any[]).find((c) =>
      (c.props?.className ?? '').includes('space-y-3')
    );
    const children = infoList.children as any[];

    const trailingSlots: string[] = [];
    for (let i = children.length - 1; i >= 0; i--) {
      if (children[i].type !== 'extension_point') break;
      trailingSlots.unshift(children[i].name);
    }

    expect(trailingSlots).toEqual([
      'mypage_order_cash_receipt_slot',
      'mypage_order_payment_provider_slot',
    ]);

    // 확장 슬롯은 말단에만 있다 — 중간에 끼면 금액 줄 사이가 갈라진다.
    const slotCount = children.filter((c: any) => c.type === 'extension_point').length;
    expect(slotCount).toBe(trailingSlots.length);
  });
});
