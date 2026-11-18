/**
 * @file checkout-order-fields.test.ts
 * @description 주문서가 운영자 설정(주문서 항목)대로 칸을 그리고 추가 항목 값을 주문 요청에 싣는지
 *
 * 종전 주문서는 주문자 이메일, 상세 주소, 배송 메모의 사용과 필수 여부를 레이아웃에 박아 두었다.
 * 이제 주문서 응답의 `order_fields` 를 계산값 `checkoutFields` 한 곳에서 읽어 그 값대로 그린다.
 * 그 값이 없는 응답(옛 서버, 받기 전)에서는 종전 화면과 같아야 하므로 기본값을 여기서 잠근다.
 * 계산값과 식은 실제 앱과 같은 식 엔진으로 계산한다(`new Function` 은 엔진이 못 푸는 식도 통과시킨다).
 *
 * @scenario-manifest tests/scenarios/checkout-order-fields.yaml
 */

import { describe, it, expect } from 'vitest';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import checkoutLayout from '../../layouts/shop/checkout.json';
import ordererPartial from '../../layouts/partials/shop/_checkout_orderer.json';
import shippingPartial from '../../layouts/partials/shop/_checkout_shipping.json';
import summaryPartial from '../../layouts/partials/shop/_checkout_summary.json';
import extraFieldsPartial from '../../layouts/partials/shop/_checkout_extra_fields.json';
import orderCompleteLayout from '../../layouts/shop/order_complete.json';

type Node = Record<string, any>;

const engine = new DataBindingEngine();

/** 식({{ }})을 식 엔진으로 계산한다 */
function evaluate(expression: string, context: Record<string, unknown>): any {
  expect(expression.startsWith('{{') && expression.endsWith('}}')).toBe(true);
  return engine.evaluateExpression(expression.slice(2, -2), context);
}

/** 주문서 레이아웃의 계산값 전부를 엔진의 평가기로 계산한다. 풀지 못한 식이 있으면 실패다. */
function computedOf(context: Record<string, unknown>): Record<string, any> {
  const failures: string[] = [];
  const definitions = Object.fromEntries(
    Object.entries((checkoutLayout as Node).computed as Record<string, string>).filter(([name]) => name !== 'comment'),
  );
  const values = evaluateComputedDefinitions(definitions, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);
  return values;
}

/** 자식 노드(children)만 따라 내려가며 모은다 */
function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 객체 나무 전체(액션 포함)에서 조건에 맞는 첫 노드를 찾는다 */
function findDeep(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return undefined;
  if (!Array.isArray(node) && predicate(node)) return node;
  for (const value of Object.values(node)) {
    const found = findDeep(value, predicate);
    if (found !== undefined) return found;
  }
  return undefined;
}

const ordererNodes = collect(ordererPartial as Node);
const shippingNodes = collect(shippingPartial as Node);
const extraNodes = collect(extraFieldsPartial as Node);
const completeNodes = collect((orderCompleteLayout as Node).slots?.content ?? (orderCompleteLayout as Node).components);

const byId = (nodes: Node[], id: string) => nodes.find((node) => node.id === id)!;
const inputByName = (nodes: Node[], name: string) => nodes.find((node) => node.props?.name === name)!;

const guest = { currentUser: [] };
const member = { currentUser: { uuid: 'u-1', name: '홍길동', nickname: '길동이', display_name: '길동이', phone: '01012345678', email: 'hong@example.com' } };

/** 주문서 응답을 받은 화면의 문맥 */
function screen(orderFields: unknown, _global: Record<string, unknown> = guest, _local: Record<string, unknown> = {}) {
  const context: Record<string, any> = {
    checkoutData: { data: { temp_order_id: 'TMP-1', calculation: { summary: { final_amount: 1000 } }, ...(orderFields === undefined ? {} : { order_fields: orderFields }) } },
    paymentSettings: { data: {} },
    _global,
    _local,
  };
  context._computed = computedOf(context);
  return context;
}

const builtin = (over: Record<string, { enabled: boolean; required: boolean }> = {}) => ({
  orderer_email: { enabled: true, required: false },
  address_detail: { enabled: true, required: true },
  shipping_memo: { enabled: true, required: false },
  ...over,
});

const textField = { key: 'company', name: 'extra_fields.company', type: 'text', renderer: 'builtin', label: '회사명', required: true, options: [], constraints: {} };
const choiceField = { key: 'gift', name: 'extra_fields.gift', type: 'choice', renderer: 'builtin', label: '선물 포장', required: false, options: [{ value: 'yes', label: '예' }, { value: 'no', label: '아니오' }], constraints: {}, default: 'no' };
const addressField = { key: 'office', name: 'extra_fields.office', type: 'address', renderer: 'address', label: '사무실 주소', required: false, parts: { zipcode: 'extra_fields.office.zipcode', address: 'extra_fields.office.address', address_detail: 'extra_fields.office.address_detail' } };
const doorField = { key: 'door_code', name: 'extra_fields.door_code', type: 'text', renderer: 'builtin', label: '공동현관 비밀번호', required: false, help: '배송 기사에게만 전달됩니다.' };

describe('주문서 — 운영자 설정을 따르는 항목', () => {
  describe('계산값 checkoutFields 의 기본값 (설정을 바꾸지 않은 사이트의 화면은 종전과 같다)', () => {
    /**
     * @scenario case=defaults
     * @effects defaults_match_legacy_screen_without_order_fields
     */
    it('order_fields 가 없는 응답: 이메일은 사용하되 비회원만 필수, 상세 주소는 사용과 필수, 배송 메모는 사용과 선택, 추가 항목 없음', () => {
      expect(screen(undefined, guest)._computed.checkoutFields).toEqual({
        orderer_email: { enabled: true, required: true },
        address_detail: { enabled: true, required: true },
        shipping_memo: { enabled: true, required: false },
        orderer: [],
        shipping: [],
      });
      expect(screen(undefined, member)._computed.checkoutFields.orderer_email).toEqual({ enabled: true, required: false });
      // 주문서 응답을 받기 전(데이터소스 비어 있음)에도 같은 기본값이다
      const before: Record<string, any> = { checkoutData: undefined, paymentSettings: {}, _global: member, _local: {} };
      expect(computedOf(before).checkoutFields).toEqual({
        orderer_email: { enabled: true, required: false },
        address_detail: { enabled: true, required: true },
        shipping_memo: { enabled: true, required: false },
        orderer: [],
        shipping: [],
      });
    });

    /**
     * @scenario case=defaults
     * @effects defaults_match_legacy_screen_without_order_fields
     */
    it('회원 정보를 받는 중(currentUser 가 아직 없음)에는 이메일 기본값이 필수가 아니다 — 회원에게 별표가 잠깐 보이지 않는다(7.1.6 받는 중 가드)', () => {
      const loading = { currentUser: undefined };
      const ctx = screen(undefined, loading);
      expect(ctx._computed.checkoutFields.orderer_email).toEqual({ enabled: true, required: false });
      expect(evaluate(byId(ordererNodes, 'checkout_field_orderer_email_required').if, ctx)).toBe(false);
    });

    /**
     * @scenario case=defaults
     * @effects defaults_draw_same_fields_and_stars_as_before
     */
    it('기본값에서 세 칸이 모두 그려지고 별표는 종전과 같다 (이메일은 비회원만, 상세 주소는 늘, 배송 메모는 없음)', () => {
      for (const [who, emailStar] of [[guest, true], [member, false]] as const) {
        const ctx = screen(undefined, who);
        expect(evaluate(byId(ordererNodes, 'checkout_field_orderer_email').if, ctx)).toBe(true);
        expect(evaluate(byId(ordererNodes, 'checkout_field_orderer_email_required').if, ctx)).toBe(emailStar);
        expect(evaluate(byId(shippingNodes, 'checkout_field_address_detail').if, ctx)).toBe(true);
        expect(evaluate(byId(shippingNodes, 'checkout_field_address_detail_required').if, ctx)).toBe(true);
        expect(evaluate(byId(shippingNodes, 'checkout_field_shipping_memo').if, ctx)).toBe(true);
        expect(evaluate(byId(shippingNodes, 'checkout_field_shipping_memo_required').if, ctx)).toBe(false);
        // 추가 항목 묶음은 하나도 그려지지 않는다
        for (const nodes of [ordererNodes, shippingNodes]) {
          const host = nodes.find((node) => node.iteration?.item_var === 'checkoutFieldGroup')!;
          expect(evaluate(host.iteration.source, { ...ctx, $t: (key: string) => key })).toEqual([]);
        }
      }
    });

    /**
     * @scenario case=defaults
     * @effects locked_fields_stay_required
     */
    it('잠긴 항목(주문자와 수령인의 이름과 연락처, 주소)의 별표는 설정과 무관하게 늘 그린다', () => {
      const unconditionalStars = (nodes: Node[], labelKey: string) => {
        const label = nodes.find((node) => node.name === 'Label' && (node.children ?? []).some((child: Node) => child.text === labelKey))!;
        const star = (label.children as Node[]).find((child) => child.text === '*')!;
        return star !== undefined && star.if === undefined;
      };
      expect(unconditionalStars(ordererNodes, '$t:shop.checkout.orderer_name')).toBe(true);
      expect(unconditionalStars(ordererNodes, '$t:shop.checkout.orderer_phone')).toBe(true);
      expect(unconditionalStars(shippingNodes, '$t:shop.checkout.recipient_name')).toBe(true);
      expect(unconditionalStars(shippingNodes, '$t:shop.checkout.recipient_phone')).toBe(true);
      expect(unconditionalStars(shippingNodes, '$t:shop.checkout.address')).toBe(true);
      expect(unconditionalStars(shippingNodes, '$t:shop.checkout.intl_address')).toBe(true);
    });

    /**
     * @scenario case=defaults
     * @effects order_fields_kept_when_recalculation_response_omits_them
     */
    it('주문서를 처음 받을 때 order_fields 를 담아 두고, 다시 계산한 응답에 그 값이 없으면 담아 둔 것을 쓴다', () => {
      const source = ((checkoutLayout as Node).data_sources as Node[]).find((item) => item.id === 'checkoutData')!;
      const keep = (Array.isArray(source.onSuccess) ? source.onSuccess : [source.onSuccess]).find((action: Node) => action.handler === 'setState' && 'orderFieldsSeen' in (action.params ?? {}))!;
      expect(keep.params.target).toBe('local');
      const orderFields = { builtin: builtin({ address_detail: { enabled: false, required: false } }), orderer: [textField], shipping: [] };
      const response = { data: { data: { order_fields: orderFields } } };
      expect(evaluate(keep.if, { response })).toBe(true);
      expect(evaluate(keep.if, { response: { data: { data: {} } } })).toBe(false);
      expect(evaluate(keep.params.orderFieldsSeen, { response })).toEqual(orderFields);

      // 쿠폰이나 배송지를 바꾼 뒤의 응답에 order_fields 가 없어도 칸 구성이 바뀌지 않는다
      const afterRecalculation = screen(undefined, member, { orderFieldsSeen: orderFields });
      expect(afterRecalculation._computed.checkoutFields.address_detail).toEqual({ enabled: false, required: false });
      expect(afterRecalculation._computed.checkoutFields.orderer).toEqual([textField]);
      // 응답에 값이 있으면 응답이 우선이다
      const fresh = screen({ builtin: builtin(), orderer: [], shipping: [] }, member, { orderFieldsSeen: orderFields });
      expect(fresh._computed.checkoutFields.address_detail).toEqual({ enabled: true, required: true });
      expect(fresh._computed.checkoutFields.orderer).toEqual([]);
    });
  });

  describe('주문자 이메일', () => {
    const email = byId(ordererNodes, 'checkout_field_orderer_email');
    const star = byId(ordererNodes, 'checkout_field_orderer_email_required');
    const input = inputByName(ordererNodes, 'orderer_email');

    /**
     * @scenario case=orderer_email
     * @effects orderer_email_hidden_when_disabled
     */
    it('사용이 꺼지면 칸을 그리지 않는다', () => {
      const ctx = screen({ builtin: builtin({ orderer_email: { enabled: false, required: false } }), orderer: [], shipping: [] }, member);
      expect(evaluate(email.if, ctx)).toBe(false);
      expect(collect(email)).toContain(input);
    });

    /**
     * @scenario case=orderer_email
     * @effects orderer_email_required_mark_follows_setting
     */
    it('필수면 별표와 aria-required 와 required 가 붙고, 선택이면 붙지 않는다', () => {
      const required = screen({ builtin: builtin({ orderer_email: { enabled: true, required: true } }), orderer: [], shipping: [] }, member);
      const optional = screen({ builtin: builtin({ orderer_email: { enabled: true, required: false } }), orderer: [], shipping: [] }, member);
      expect(evaluate(star.if, required)).toBe(true);
      expect(evaluate(input.props['aria-required'], required)).toBe(true);
      expect(evaluate(input.props.required, required)).toBe(true);
      expect(evaluate(star.if, optional)).toBe(false);
      expect(evaluate(input.props['aria-required'], optional)).toBe(false);
      expect(evaluate(input.props.required, optional)).toBe(false);
      expect(star.props.className).toContain('text-red-600');
      expect(star.props.className).toContain('dark:text-red-400');
      expect(input.props['aria-label']).toBe('$t:shop.checkout.orderer_email');
    });
  });

  describe('상세 주소', () => {
    const detail = byId(shippingNodes, 'checkout_field_address_detail');
    const star = byId(shippingNodes, 'checkout_field_address_detail_required');
    const input = inputByName(shippingNodes, 'address_detail');

    /**
     * @scenario case=address_detail
     * @effects address_detail_hidden_when_disabled
     */
    it('사용이 꺼지면 라벨, 칸, 오류 줄을 모두 그리지 않는다', () => {
      const ctx = screen({ builtin: builtin({ address_detail: { enabled: false, required: false } }), orderer: [], shipping: [] });
      expect(evaluate(detail.if, ctx)).toBe(false);
      const inside = collect(detail);
      expect(inside).toContain(input);
      expect(inside.some((node) => node.text === '$t:shop.checkout.detail_address')).toBe(true);
      expect(inside.some((node) => String(node.if ?? '').includes("errors?.['shipping.address_detail']"))).toBe(true);
    });

    /**
     * @scenario case=address_detail
     * @effects address_detail_required_mark_follows_setting
     */
    it('선택으로 바꾸면 별표와 aria-required 와 required 가 사라진다', () => {
      const optional = screen({ builtin: builtin({ address_detail: { enabled: true, required: false } }), orderer: [], shipping: [] });
      const required = screen({ builtin: builtin(), orderer: [], shipping: [] });
      expect(evaluate(detail.if, optional)).toBe(true);
      expect(evaluate(star.if, optional)).toBe(false);
      expect(evaluate(input.props['aria-required'], optional)).toBe(false);
      expect(evaluate(input.props.required, optional)).toBe(false);
      expect(evaluate(star.if, required)).toBe(true);
      expect(evaluate(input.props['aria-required'], required)).toBe(true);
      expect(evaluate(input.props.required, required)).toBe(true);
    });
  });

  describe('배송 메모', () => {
    const memo = byId(shippingNodes, 'checkout_field_shipping_memo');
    const star = byId(shippingNodes, 'checkout_field_shipping_memo_required');
    const select = inputByName(shippingNodes, 'shipping_memo');
    const custom = inputByName(shippingNodes, 'shipping_memo_custom');
    const customWrap = shippingNodes.find((node) => (node.children ?? []).includes(custom))!;

    /**
     * @scenario case=shipping_memo
     * @effects shipping_memo_hidden_when_disabled
     */
    it('사용이 꺼지면 고르는 칸과 직접 입력칸을 모두 그리지 않는다', () => {
      const off = screen({ builtin: builtin({ shipping_memo: { enabled: false, required: false } }), orderer: [], shipping: [] }, guest, { shippingMemo: 'custom' });
      const on = screen({ builtin: builtin(), orderer: [], shipping: [] }, guest, { shippingMemo: 'custom' });
      expect(evaluate(memo.if, off)).toBe(false);
      expect(evaluate(customWrap.if, off)).toBe(false);
      expect(evaluate(memo.if, on)).toBe(true);
      expect(evaluate(customWrap.if, on)).toBe(true);
      expect(evaluate(customWrap.if, screen({ builtin: builtin(), orderer: [], shipping: [] }, guest, { shippingMemo: 'door' }))).toBe(false);
    });

    /**
     * @scenario case=shipping_memo
     * @effects shipping_memo_required_mark_and_error_follow_setting
     */
    it('필수로 바꾸면 별표와 aria-required 와 required 가 붙고, 검증 오류는 칸 아래에 그린다', () => {
      const required = screen({ builtin: builtin({ shipping_memo: { enabled: true, required: true } }), orderer: [], shipping: [] });
      expect(evaluate(star.if, required)).toBe(true);
      expect(evaluate(select.props['aria-required'], required)).toBe(true);
      expect(evaluate(select.props.required, required)).toBe(true);
      expect(select.props['aria-label']).toBe('$t:shop.checkout.shipping_memo');

      const error = byId(shippingNodes, 'checkout_field_shipping_memo_error');
      expect(collect(memo)).toContain(error);
      const failed = { _local: { errors: { shipping_memo: ['배송 메모를 선택해 주세요.'] } } };
      expect(!!evaluate(error.if, failed)).toBe(true);
      expect(evaluate(error.text, failed)).toBe('배송 메모를 선택해 주세요.');
      expect(!!evaluate(error.if, { _local: { errors: null } })).toBe(false);
      expect(error.props.className).toContain('dark:text-red-400');
    });
  });

  describe('추가 항목', () => {
    const root = extraFieldsPartial as Node;
    const row = (root.children as Node[])[0];
    const $t = (key: string) => key;

    /**
     * @scenario case=extra_fields
     * @effects extra_fields_drawn_per_section_with_stable_ids
     */
    it('주문자 구획과 배송지 구획이 자기 항목만 묶음 하나로 넘기고, 묶음과 칸과 오류 줄의 id 는 구획 이름과 항목 키를 따른다', () => {
      const ctx = { ...screen({ builtin: builtin(), orderer: [textField, choiceField], shipping: [addressField, doorField] }, member), $t };
      const ordererHost = ordererNodes.find((node) => node.iteration?.item_var === 'checkoutFieldGroup')!;
      const shippingHost = shippingNodes.find((node) => node.iteration?.item_var === 'checkoutFieldGroup')!;
      expect(evaluate(ordererHost.iteration.source, ctx)).toEqual([{ key: 'orderer', label: 'shop.checkout_fields.orderer_extra', fields: [textField, choiceField] }]);
      expect(evaluate(shippingHost.iteration.source, ctx)).toEqual([{ key: 'shipping', label: 'shop.checkout_fields.shipping_extra', fields: [addressField, doorField] }]);
      // 두 구획 모두 같은 조각을 부르고, 구획의 맨 끝에 둔다
      for (const [partial, host] of [[ordererPartial, ordererHost], [shippingPartial, shippingHost]] as const) {
        expect(JSON.stringify(host)).toContain('partials/shop/_checkout_extra_fields');
        const card = partial as Node;
        expect((card.children as Node[])[(card.children as Node[]).length - 1]).toBe(host);
      }

      expect(root.id).toBe('checkout_extra_fields_{{checkoutFieldGroup.key}}');
      expect(root.props.role).toBe('group');
      expect(evaluate(root.props['aria-label'], { checkoutFieldGroup: { label: '주문자 추가 정보' } })).toBe('주문자 추가 정보');
      expect(row.id).toBe('checkout_extra_field_{{field.key}}');
      expect(row.iteration.item_var).toBe('field');
      expect(evaluate(row.iteration.source, { checkoutFieldGroup: { fields: [textField] } })).toEqual([textField]);
      expect(byId(extraNodes, 'checkout_extra_field_error_{{field.key}}').name).toBe('Span');
    });

    /**
     * @scenario case=extra_fields
     * @effects extra_field_values_bind_under_checkout_form
     */
    it('값은 폼 자동 바인딩으로 _local.checkoutForm 아래 항목의 폼 경로(extra_fields.{키})에 담긴다', () => {
      expect(root.dataKey).toBe('checkoutForm');
      const input = extraNodes.find((node) => node.name === 'MemberFieldInput')!;
      expect(input.type).toBe('composite');
      expect(input.props.name).toBe('{{field.name}}');
      expect(input.props.field).toBe('{{field}}');
      expect(input.props.value).toBeUndefined();
      expect(actionsOf(input)).toEqual([]);
      expect(input.props.errorId).toBe('checkout_extra_field_error_{{field.key}}');
      expect(evaluate(input.props.disabled, { _local: { isSubmittingOrder: true } })).toBe(true);
      // 폼 상태의 초깃값이 있어야 첫 입력부터 같은 자리에 담긴다
      expect((checkoutLayout as Node).initLocal?.checkoutForm ?? (checkoutLayout as Node).init_local?.checkoutForm ?? (checkoutLayout as Node).state?.checkoutForm).toEqual({ extra_fields: {} });
    });

    /**
     * @scenario case=extra_fields
     * @effects extra_field_required_star_follows_contract
     */
    it('필수 별표는 항목 계약의 required 를 따르고, 예/아니오 칸은 옆 문구를 따로 정했을 때만 위 라벨을 둔다', () => {
      const label = (row.children as Node[]).find((node) => node.name === 'Label')!;
      const star = (label.children as Node[]).find((node) => node.text === '*')!;
      expect(evaluate(star.if, { field: textField })).toBe(true);
      expect(evaluate(star.if, { field: doorField })).toBe(false);
      expect(star.props.className).toContain('dark:text-red-400');
      expect(evaluate(label.if, { field: textField })).toBe(true);
      expect(evaluate(label.if, { field: { type: 'boolean', constraints: {} } })).toBe(false);
      expect(evaluate(label.if, { field: { type: 'boolean', constraints: { inline_label: '선물 포장을 원합니다' } } })).toBe(true);
      expect(label.props.className).toContain('break-words');
    });

    /**
     * @scenario case=extra_fields
     * @effects extra_field_renderer_branches_match_member_fields
     */
    it('유형 갈래는 회원 항목과 같다: 주소는 부분별 폼 경로의 세 칸과 주소 검색, 확장 유형은 확장점, 그 밖은 항목 입력 컴포넌트', () => {
      const input = extraNodes.find((node) => node.name === 'MemberFieldInput')!;
      const addressBranch = (row.children as Node[]).find((node) => node.name === 'Div' && String(node.if ?? '').includes("'address'"))!;
      const typeSlot = extraNodes.find((node) => node.type === 'extension_point' && node.name === 'member_field_input')!;
      const shown = (field: Record<string, unknown>) => ({
        input: evaluate(input.if, { field }),
        address: evaluate(addressBranch.if, { field }),
        slot: evaluate(typeSlot.if, { field }),
      });
      expect(shown({ renderer: 'builtin' })).toEqual({ input: true, address: false, slot: false });
      expect(shown({ renderer: 'unsupported' })).toEqual({ input: true, address: false, slot: false });
      expect(shown({ renderer: 'address' })).toEqual({ input: false, address: true, slot: false });
      expect(shown({ renderer: 'extension' })).toEqual({ input: false, address: false, slot: true });

      const parts = collect(addressBranch).filter((node) => node.name === 'Input');
      expect(parts.map((node) => node.props.name)).toEqual(['{{field.parts?.zipcode}}', '{{field.parts?.address}}', '{{field.parts?.address_detail}}']);
      for (const part of parts) {
        expect(part.props['aria-label']).toContain('|label={{field.label}}');
        expect(evaluate(part.props.className, { field: {} })).toContain('dark:bg-gray-700');
      }
      const search = extraNodes.find((node) => node.type === 'extension_point' && node.name === 'address_search_slot')!;
      expect(search.props.readOnlyFields).toEqual(['zipcode', 'address']);
      expect(evaluate(search.props.targetFields.zipcode, { field: addressField })).toBe('checkoutForm.extra_fields.office.zipcode');
      expect(evaluate(search.props.targetFields.address, { field: addressField })).toBe('checkoutForm.extra_fields.office.address');
      // 우편번호와 기본 주소는 검색 플러그인이 켜져 있으면 검색으로만 채운다
      expect(evaluate(parts[0].props.readOnly, { field: {}, _global: { activePlugins: ['sirsoft-daum_postcode'] } })).toBe(true);
      expect(evaluate(parts[0].props.readOnly, { field: {}, _global: {} })).toBe(false);
      expect(evaluate(parts[2].props.readOnly, { field: {}, _global: { activePlugins: ['sirsoft-daum_postcode'] } })).toBe(false);

      expect(Object.keys(typeSlot.props).sort()).toEqual(['disabled', 'error', 'field', 'formKey']);
      expect(typeSlot.props.formKey).toBe('checkoutForm');
    });

    /**
     * @scenario case=extra_fields
     * @effects extra_fields_have_dark_pairs
     */
    it('글자색, 테두리, 배경 클래스에 다크 짝이 있다', () => {
      for (const node of extraNodes) {
        for (const className of [String(node.props?.className ?? ''), String(node.responsive?.portable?.props?.className ?? '')]) {
          if (/\btext-(gray|red)-\d+/.test(className)) expect(className).toMatch(/dark:text-/);
          if (/\bbg-white\b/.test(className)) expect(className).toMatch(/dark:bg-/);
          if (/\bborder-gray-\d+/.test(className)) expect(className).toMatch(/dark:border-/);
        }
      }
    });
  });

  describe('주문 요청 본문', () => {
    const orderCall = findDeep(summaryPartial, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.includes('/user/orders') && n.params?.method === 'POST');
    const body = (ctx: Record<string, unknown>) => evaluate(orderCall.params.body, ctx);
    const filled = { shipping: { recipient_name: '홍길동', zipcode: '06236', address: '서울 강남구', address_detail: '101호' }, shippingMemo: 'door' };

    /**
     * @scenario case=request_body
     * @effects body_unchanged_without_extra_fields
     */
    it('추가 항목이 없고 설정이 기본값이면 본문의 키와 값이 종전과 같다 (extra_fields 키가 없다)', () => {
      for (const orderFields of [undefined, { builtin: builtin(), orderer: [], shipping: [] }]) {
        const sent = body(screen(orderFields, member, filled));
        expect(Object.keys(sent)).not.toContain('extra_fields');
        expect(sent.orderer).toEqual({ name: '홍길동', phone: '01012345678', email: 'hong@example.com' });
        expect(sent.shipping).toEqual(filled.shipping);
        expect(sent.shipping_memo).toBe('door');
      }
    });

    /**
     * @scenario case=request_body
     * @effects body_carries_extra_fields_by_key
     */
    it('추가 항목 값을 extra_fields 에 키별로 싣는다: 그려진 항목만, 입력하지 않은 칸은 기본값, 빈 값은 뺀다', () => {
      const orderFields = { builtin: builtin(), orderer: [textField, choiceField], shipping: [addressField, doorField] };
      const office = { zipcode: '04524', address: '서울 중구', address_detail: '3층' };
      const sent = body(screen(orderFields, member, { ...filled, checkoutForm: { extra_fields: { company: '시르소프트', office, door_code: '', removed_field: '지워진 항목의 값' } } }));
      expect(sent.extra_fields).toEqual({ company: '시르소프트', gift: 'no', office });
      // 고른 값이 기본값보다 우선한다
      const picked = body(screen(orderFields, member, { ...filled, checkoutForm: { extra_fields: { gift: 'yes' } } }));
      expect(picked.extra_fields).toEqual({ gift: 'yes' });
      // 확장이 본문에 보태는 병합 칸은 그대로 맨 뒤에서 합쳐진다
      const merged = body(screen(orderFields, member, { ...filled, checkoutForm: { extra_fields: { company: '시르소프트' } }, checkoutExtraPayload: { gift_message: '축하합니다' } }));
      expect(merged.gift_message).toBe('축하합니다');
      expect(merged.extra_fields).toEqual({ company: '시르소프트', gift: 'no' });
    });

    /**
     * @scenario case=request_body
     * @effects body_omits_disabled_builtin_fields
     */
    it('사용이 꺼진 내장 항목은 값이 상태에 남아 있어도 보내지 않는다', () => {
      const off = { enabled: false, required: false };
      const noDetail = body(screen({ builtin: builtin({ address_detail: off }), orderer: [], shipping: [] }, member, filled));
      expect(noDetail.shipping).toEqual({ recipient_name: '홍길동', zipcode: '06236', address: '서울 강남구' });
      expect(noDetail.shipping_memo).toBe('door');

      const noMemo = body(screen({ builtin: builtin({ shipping_memo: off }), orderer: [], shipping: [] }, member, filled));
      expect(Object.keys(noMemo)).not.toContain('shipping_memo');
      expect(noMemo.shipping).toEqual(filled.shipping);

      const noEmail = body(screen({ builtin: builtin({ orderer_email: off }), orderer: [], shipping: [] }, member, filled));
      expect(noEmail.orderer).toEqual({ name: '홍길동', phone: '01012345678' });
    });
  });

  describe('검증 오류 (422)', () => {
    const error = byId(extraNodes, 'checkout_extra_field_error_{{field.key}}');
    const input = extraNodes.find((node) => node.name === 'MemberFieldInput')!;

    /**
     * @scenario case=validation_errors
     * @effects order_failure_stores_errors_for_fields
     */
    it('주문 요청이 실패하면 서버의 오류 묶음을 _local.errors 에 담는다', () => {
      const orderCall = findDeep(summaryPartial, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.includes('/user/orders') && n.params?.method === 'POST');
      const store = findDeep(orderCall.onError, (n) => n.handler === 'setState' && 'errors' in (n.params ?? {}));
      const errors = { 'extra_fields.company': ['회사명을 입력해 주세요.'] };
      expect(evaluate(store.params.errors, { error: { errors } })).toEqual(errors);
      expect(evaluate(store.params.errors, { error: { response: { errors } } })).toEqual(errors);
    });

    /**
     * @scenario case=validation_errors
     * @effects extra_field_error_drawn_under_its_field
     */
    it('오류 키 extra_fields.{키} 의 문구를 그 칸 아래에 그리고 입력 컴포넌트에도 넘긴다 (다른 칸의 오류는 그리지 않는다)', () => {
      const _local = { errors: { 'extra_fields.company': ['회사명을 입력해 주세요.'], 'orderer.name': ['이름을 입력해 주세요.'] } };
      expect(evaluate(error.if, { field: textField, _local })).toBe(true);
      expect(evaluate(error.text, { field: textField, _local })).toBe('회사명을 입력해 주세요.');
      expect(evaluate(input.props.error, { field: textField, _local })).toBe('회사명을 입력해 주세요.');
      expect(evaluate(error.if, { field: doorField, _local })).toBe(false);
      expect(evaluate(input.props.error, { field: doorField, _local })).toBe('');
      expect(evaluate(error.if, { field: textField, _local: { errors: null } })).toBe(false);
      expect(error.props.className).toContain('text-red-600');
      expect(error.props.className).toContain('dark:text-red-400');
    });

    /**
     * @scenario case=validation_errors
     * @effects extra_field_error_collects_sub_paths
     */
    it('묶음 유형은 하위 경로(extra_fields.{키}.zipcode 등)의 오류도 그 칸의 오류 줄에 모은다', () => {
      const _local = { errors: { 'extra_fields.office.zipcode': ['우편번호를 입력해 주세요.'] } };
      expect(evaluate(error.if, { field: addressField, _local })).toBe(true);
      expect(evaluate(error.text, { field: addressField, _local })).toBe('우편번호를 입력해 주세요.');
      // 키가 앞부분만 같은 다른 항목(office2)의 오류는 섞이지 않는다
      const other = { errors: { 'extra_fields.office2': ['다른 칸'] } };
      expect(evaluate(error.if, { field: addressField, _local: other })).toBe(false);
    });
  });

  describe('주문자 이름의 초깃값', () => {
    /**
     * @scenario case=orderer_name
     * @effects orderer_name_defaults_to_real_name_not_display_name
     */
    it('회원의 표시 이름이 닉네임이어도 주문자 이름 칸과 주문 요청의 주문자 이름은 실명(name)이다', () => {
      const nameInput = inputByName(ordererNodes, 'orderer_name');
      expect(evaluate(nameInput.props.value, { _local: {}, _global: member })).toBe('홍길동');
      expect(nameInput.props.value).not.toContain('display_name');
      expect(nameInput.props.value).not.toContain('nickname');
      // 「주문자 정보와 동일」을 켰을 때 수령인으로 옮기는 값도 실명이다
      expect(JSON.stringify(shippingPartial)).not.toContain('display_name');
      expect(JSON.stringify(ordererPartial)).not.toContain('display_name');

      const ctx = screen(undefined, member);
      expect(ctx._computed.ordererDefaults).toEqual({ name: '홍길동', phone: '01012345678', email: 'hong@example.com' });
      expect(String((checkoutLayout as Node).computed.ordererDefaults)).not.toContain('display_name');
      // 직접 고친 이름이 있으면 그 값이 우선한다
      expect(evaluate(nameInput.props.value, { _local: { orderer: { name: '김철수' } }, _global: member })).toBe('김철수');
    });
  });

  describe('주문자 칸에 보이는 값과 보내는 값', () => {
    const orderCall = findDeep(summaryPartial, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.includes('/user/orders') && n.params?.method === 'POST');
    const inputs = [['orderer_name', 'name'], ['orderer_phone', 'phone'], ['orderer_email', 'email']] as const;
    const filled = { shipping: { recipient_name: '홍길동', zipcode: '06236', address: '서울 강남구', address_detail: '101호' } };

    /**
     * @scenario case=orderer_cleared
     * @effects orderer_initial_values_from_account
     */
    it('칸을 건드리기 전에는 계정의 이름·연락처·이메일을 칸에 채워 보이고 같은 값을 보낸다', () => {
      const ctx = screen({ builtin: builtin(), orderer: [], shipping: [] }, member, filled);
      const sent = evaluate(orderCall.params.body, ctx);
      expect(sent.orderer).toEqual({ name: '홍길동', phone: '01012345678', email: 'hong@example.com' });
      for (const [name, key] of inputs) {
        expect(evaluate(inputByName(ordererNodes, name).props.value, ctx)).toBe(sent.orderer[key]);
      }
    });

    /**
     * @scenario case=orderer_cleared
     * @effects orderer_cleared_value_sent_as_shown
     */
    it('회원이 칸을 비우면 칸에 보이는 빈 값을 그대로 보내고 계정 값으로 되채우지 않는다 (필수 설정이면 서버가 422 로 막는다)', () => {
      for (const required of [false, true]) {
        const ctx = screen({ builtin: builtin({ orderer_email: { enabled: true, required } }), orderer: [], shipping: [] }, member, { ...filled, orderer: { name: '', phone: '', email: '' } });
        const sent = evaluate(orderCall.params.body, ctx);
        expect(sent.orderer).toEqual({ name: '', phone: '', email: '' });
        for (const [name, key] of inputs) {
          expect(evaluate(inputByName(ordererNodes, name).props.value, ctx)).toBe(sent.orderer[key]);
        }
        // 본인인증 대상 이메일도 계정 이메일로 되돌아가지 않는다
        expect(evaluate(orderCall.identity_target.email, ctx)).toBe('');
        // 입금자명을 따로 적지 않았으면 비운 주문자 이름을 따른다(계정 이름이 아니다)
        expect(sent.depositor_name).toBe('');
      }
      // 이메일 하나만 비우면 이메일만 빈 값이다
      const onlyEmail = screen({ builtin: builtin(), orderer: [], shipping: [] }, member, { ...filled, orderer: { email: '' } });
      expect(evaluate(orderCall.params.body, onlyEmail).orderer).toEqual({ name: '홍길동', phone: '01012345678', email: '' });
    });
  });

  describe('주문 완료 화면', () => {
    const ordererBlock = byId(completeNodes, 'order_complete_extra_fields_orderer');
    const shippingBlock = byId(completeNodes, 'order_complete_extra_fields_shipping');
    const extraFields = [
      { key: 'company', label: '회사명', section: 'orderer', display_value: '시르소프트' },
      { key: 'door_code', label: '공동현관 비밀번호', section: 'shipping', display_value: '1234#' },
    ];

    /**
     * @scenario case=order_complete
     * @effects order_complete_lists_extra_fields_by_section
     */
    it('주문 응답의 extra_fields 를 구획별로 「라벨: 값」으로 보이고, 없으면 구획을 그리지 않는다', () => {
      const withFields = { orderData: { data: { extra_fields: extraFields } } };
      const without = { orderData: { data: {} } };
      for (const [block, section, expected] of [[ordererBlock, 'orderer', extraFields[0]], [shippingBlock, 'shipping', extraFields[1]]] as const) {
        expect(evaluate(block.if, withFields)).toBe(true);
        expect(evaluate(block.if, without)).toBe(false);
        expect(evaluate(block.if, { orderData: { data: { extra_fields: extraFields.filter((f) => f.section !== section) } } })).toBe(false);
        const line = collect(block).find((node) => node.iteration?.item_var === 'extraField')!;
        expect(line.id).toBe('order_complete_extra_field_{{extraField.key}}');
        expect(evaluate(line.iteration.source, withFields)).toEqual([expected]);
        expect(evaluate(line.iteration.source, without)).toEqual([]);
        const [label, value] = line.children as Node[];
        expect(label.text).toBe('{{extraField.label}}:');
        expect(value.text).toBe('{{extraField.display_value}}');
        for (const part of [label, value]) {
          expect(part.props.className).toMatch(/dark:text-/);
          expect(part.props.className).toContain('break-words');
        }
      }
      expect(collect(ordererBlock).some((node) => node.text === '$t:shop.checkout_fields.orderer_extra')).toBe(true);
    });
  });
});
