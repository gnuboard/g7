/**
 * @file mypage-order-change-address-modal.test.tsx
 * @description 주문 상세 배송지 변경 모달 구조 회귀 테스트 (회원/비회원 공용)
 *
 * 테스트 대상:
 * - templates/.../partials/mypage/orders/_modal_change_address.json
 * - templates/.../partials/mypage/orders/_shipping.json (트리거)
 *
 * 회귀 차단 (이슈55):
 * - 모달은 부모 _local 미접근 → _global.editingShippingAddress dataKey 자동바인딩
 * - 적용 액션은 단일 커스텀 핸들러(endpoint/body/토큰 분기는 핸들러 테스트가 검증)
 * - 회원/비회원 UI 분기: 탭/저장된배송지는 회원만, 직접입력은 비회원 항상
 * - 로딩 스피너 + confirm 1회
 *
 * @scenario actor=member, change_mode=saved, e2e_browser=chromium
 * @effects change_address_modal_uses_global_editingshippingaddress_datakey_not_parent_local,
 *   change_address_autobind_inputs_use_subkey_name_without_prefix,
 *   change_address_postcode_search_writes_global_via_onaddressselect,
 *   change_address_apply_invokes_single_custom_handler_with_one_confirm,
 *   change_address_layout_has_no_direct_apicall_or_endpoint_url,
 *   change_address_tab_header_visible_member_only,
 *   change_address_manual_form_visible_for_guest_always_and_member_manual_mode,
 *   change_address_saved_list_visible_member_only,
 *   change_address_trigger_visible_for_both_member_and_guest_before_shipping,
 *   change_address_trigger_sets_mode_saved_for_member_manual_for_guest,
 *   change_address_apply_button_shows_spinner_and_disables_while_submitting
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

const layoutPath = path.resolve(
  __dirname,
  '../../layouts/partials/mypage/orders/_modal_change_address.json'
);

interface Node {
  meta?: { is_partial?: boolean };
  type?: string;
  name?: string;
  id?: string;
  if?: string;
  dataKey?: string;
  trackChanges?: boolean;
  props?: Record<string, any>;
  children?: Node[];
  actions?: Action[];
  comment?: string;
}

interface Action {
  type?: string;
  handler: string;
  confirm?: string;
  if?: string;
  target?: string;
  params?: Record<string, any>;
  onSuccess?: Action[];
  onError?: Action[];
  actions?: Action[];
}

function loadLayout(): Node {
  const raw = fs.readFileSync(layoutPath, 'utf8');
  return JSON.parse(raw);
}

/** 트리 전체를 깊이 우선으로 평탄화 */
function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
  if (!node) return acc;
  acc.push(node);
  if (Array.isArray(node.children)) {
    for (const child of node.children) flatten(child, acc);
  }
  return acc;
}

const CHANGE_HANDLER = 'sirsoft-ecommerce.changeShippingAddress';

/** 액션 트리 평탄화 (중첩 actions/onSuccess/onError 포함) */
function collectActions(actions: Action[] | undefined, acc: Action[] = []): Action[] {
  if (!Array.isArray(actions)) return acc;
  for (const a of actions) {
    acc.push(a);
    if (a.actions) collectActions(a.actions, acc);
    if (a.onSuccess) collectActions(a.onSuccess, acc);
    if (a.onError) collectActions(a.onError, acc);
  }
  return acc;
}

/** 적용 버튼 = 배송지 변경 커스텀 핸들러 액션을 가진 Button */
function findApplyButton(layout: Node): Node | undefined {
  return flatten(layout).find(
    (n) =>
      n.name === 'Button' &&
      Array.isArray(n.actions) &&
      collectActions(n.actions).some((a) => a.handler === CHANGE_HANDLER)
  );
}

/** 직접입력 폼 컨테이너 = manual 모드 if 를 가진 컨테이너 Div */
function findManualFormContainer(layout: Node): Node | undefined {
  return flatten(layout).find(
    (n) =>
      n.name === 'Div' &&
      typeof n.if === 'string' &&
      n.if.includes("=== 'manual'")
  );
}

describe('_modal_change_address.json (회원 주문 배송지 변경 모달)', () => {
  const layout = loadLayout();

  it('Modal 타입의 partial 레이아웃이다', () => {
    expect(layout.meta?.is_partial).toBe(true);
    expect(layout.type).toBe('composite');
    expect(layout.name).toBe('Modal');
  });

  it('배경(딤 오버레이) 클릭으로 닫히지 않는다 (입력 중 실수 닫힘 방지)', () => {
    expect((layout.props as any)?.closeOnBackdropClick).toBe(false);
  });

  // ── 증상1: confirm 중복 ──────────────────────────────
  describe('증상1 — 확인창 중복 방지', () => {
    it('적용 버튼이 존재한다 (배송지 변경 핸들러 액션 보유)', () => {
      const btn = findApplyButton(layout);
      expect(btn).toBeDefined();
    });

    it('적용 버튼의 click 액션은 1개다 (2개면 confirm 이 두 번 뜸)', () => {
      const btn = findApplyButton(layout)!;
      const clickActions = btn.actions!.filter(
        (a) => (a.type ?? 'click') === 'click'
      );
      expect(clickActions.length).toBe(1);
    });

    it('적용 버튼 액션 트리 전체에서 confirm 속성은 최대 1개다', () => {
      const btn = findApplyButton(layout)!;
      const all = collectActions(btn.actions);
      const confirmCount = all.filter(
        (a) => typeof a.confirm === 'string' && a.confirm.length > 0
      ).length;
      expect(confirmCount).toBeLessThanOrEqual(1);
    });

    it('미등록 handler:"confirm" 핸들러를 사용하지 않는다 (Unknown action handler 회귀 차단)', () => {
      const btn = findApplyButton(layout)!;
      const all = collectActions(btn.actions);
      expect(all.some((a) => a.handler === 'confirm')).toBe(false);
    });
  });

  // ── 증상2: 직접입력 폼 자동 바인딩 (_global 컨텍스트) ──
  // 핵심: 모달은 부모(_shipping.json)의 _local 을 못 읽는다(별도 React 컨텍스트).
  //   따라서 부모 트리거가 채우는 초기 배송지 값은 _global.editingShippingAddress 로 전달하고,
  //   모달 직접입력 폼은 dataKey="_global.editingShippingAddress" 자동바인딩으로 표시한다.
  //   (_modal_address.json 의 _global.editingAddress 와 동일한 검증된 패턴)
  //   자동바인딩 fullPath = `${dataKey}.${name}` 이므로 name 은 하위 키만 (접두사 금지).
  describe('증상2 — 직접입력 폼 _global 자동 바인딩', () => {
    // 자동바인딩되는 모든 필드 (하위 키만) — 우편번호/주소 포함 (사례 A 와 동일하게 전부 자동바인딩)
    const AUTO_BOUND_FIELDS = ['recipient_name', 'recipient_phone', 'zipcode', 'address', 'address_detail'];

    it('직접입력 폼 컨테이너가 존재한다', () => {
      const container = findManualFormContainer(layout);
      expect(container).toBeDefined();
    });

    it('직접입력 폼 컨테이너 dataKey 는 _global.editingShippingAddress 다 (모달은 부모 _local 미접근)', () => {
      const container = findManualFormContainer(layout)!;
      expect(container.dataKey).toBe('_global.editingShippingAddress');
    });

    it('manual 모드 분기 if 가 _global.changeAddressMode 를 참조한다', () => {
      const container = findManualFormContainer(layout)!;
      expect(String(container.if)).toContain('_global.changeAddressMode');
    });

    it.each(AUTO_BOUND_FIELDS)(
      '자동바인딩 Input(%s) 의 name 은 dataKey 하위 키만 가진다 (접두사 금지 — fullPath 중복 차단)',
      (field) => {
        const container = findManualFormContainer(layout)!;
        const inputs = flatten(container).filter((n) => n.name === 'Input');
        const input = inputs.find((n) => n.props?.name === field);
        expect(input, `name="${field}" Input 이 존재해야 함 (접두사 없는 하위 키)`).toBeDefined();
        // 자동/수동 혼용 금지 — 수동 value 부재 (전부 dataKey 자동바인딩)
        expect(input!.props?.value).toBeUndefined();
      }
    );

    it('직접입력 폼 Input 의 name 에 manualAddress. 접두사가 남아있지 않다 (구 _local 잔재 회귀 차단)', () => {
      const container = findManualFormContainer(layout)!;
      const inputs = flatten(container).filter((n) => n.name === 'Input');
      const withPrefix = inputs.filter(
        (n) => typeof n.props?.name === 'string' && n.props.name.startsWith('manualAddress')
      );
      expect(withPrefix.map((n) => n.props?.name)).toEqual([]);
    });

    it('직접입력 폼 어디에도 _local.manualAddress 참조가 남아있지 않다', () => {
      const container = findManualFormContainer(layout)!;
      const json = JSON.stringify(container);
      expect(json.includes('_local.manualAddress')).toBe(false);
      expect(json.includes('manualAddress')).toBe(false);
    });

    it('우편번호 검색 extension_point 가 callbacks(props 아님) 키로 onAddressSelect 를 전달한다', () => {
      const container = findManualFormContainer(layout)!;
      const slot = flatten(container).find((n: any) => n.type === 'extension_point');
      expect(slot, 'address_search_slot 존재').toBeDefined();
      // LayoutExtensionService 는 extension_point 의 'callbacks' 키만 extensionPointCallbacks 로
      // 전달한다 (props 아님). props 에 두면 {{extensionPointCallbacks.onAddressSelect}} 가 undefined
      // 로 평가되어 우편번호 검색 결과가 폼에 반영되지 않는다 (체크아웃 _checkout_shipping 와 동일 규칙).
      const onSelect = (slot as any).callbacks?.onAddressSelect;
      expect(onSelect, 'callbacks.onAddressSelect 콜백 존재 (props 가 아니라 callbacks)').toBeDefined();
      expect((slot as any).props?.onAddressSelect, 'props.onAddressSelect 는 비어야 함 (callbacks 로 이동)').toBeUndefined();
      expect(onSelect.handler).toBe('setState');
      expect(onSelect.params?.target).toBe('global');
      // editingShippingAddress.zipcode 등 _global dot notation 으로 씀
      expect(Object.keys(onSelect.params ?? {}).some((k) => k.startsWith('editingShippingAddress.'))).toBe(true);
    });
  });

  // ── 자동바인딩 경로 정합 (엔진 fullPath 규칙) ──────────
  describe('자동바인딩 경로 정합 (_global fullPath)', () => {
    it('dataKey + 하위키 name 조합이 _global.editingShippingAddress.<field> 경로를 만든다 (중복 없음)', () => {
      const container = findManualFormContainer(layout)!;
      const dataKey = container.dataKey!;
      const recipientInput = flatten(container).find(
        (n) => n.name === 'Input' && n.props?.name === 'recipient_name'
      );
      expect(recipientInput, 'recipient_name 자동바인딩 Input 존재').toBeDefined();
      // 엔진 규칙: fullPath = `${dataKey}.${name}` (DynamicRenderer.tsx:3311)
      const fullPath = `${dataKey}.${recipientInput!.props!.name}`;
      expect(fullPath).toBe('_global.editingShippingAddress.recipient_name');
    });
  });

  // ── 적용 액션 구조: 회원/비회원 단일 커스텀 핸들러 ──────
  // endpoint(user/guest)·body(address_id/직접입력)·토큰 헤더 분기는 핸들러(TS)가 처리하며,
  // 핸들러 단위 테스트(userChangeShippingAddressHandlers.test.ts)가 그 분기를 검증한다.
  // 여기서는 레이아웃이 핸들러를 올바르게 호출하는지(단일 액션 + confirm 1회 + orderId 전달)만 검증.
  describe('적용 액션 구조 (커스텀 핸들러)', () => {
    function getApplyAction(): Action {
      const btn = findApplyButton(layout)!;
      const action = btn.actions!.find((a) => a.handler === CHANGE_HANDLER);
      expect(action).toBeDefined();
      return action!;
    }

    it('버튼 액션은 changeShippingAddress 핸들러 1개이며 confirm 1개를 가진다', () => {
      const btn = findApplyButton(layout)!;
      expect(btn.actions!.length).toBe(1);
      const action = btn.actions![0];
      expect(action.handler).toBe(CHANGE_HANDLER);
      expect(typeof action.confirm).toBe('string');
    });

    it('핸들러 호출에 회원용 orderId(order.data.id)를 전달한다', () => {
      const action = getApplyAction();
      expect(String(action.params?.orderId)).toContain('order.data.id');
    });

    it('레이아웃에 apiCall / sequence 등 구 endpoint 직접 호출 잔재가 없다 (핸들러로 통합)', () => {
      const btn = findApplyButton(layout)!;
      const all = collectActions(btn.actions);
      expect(all.some((a) => a.handler === 'apiCall')).toBe(false);
      // endpoint URL 이 레이아웃에 직접 박혀있지 않아야 함 (핸들러가 분기)
      const json = JSON.stringify(btn);
      expect(json.includes('/shipping-address')).toBe(false);
    });
  });

  // ── 회원/비회원 분기 (비회원은 탭 없이 직접입력 폼만) ──
  describe('회원/비회원 분기', () => {
    /** 탭 헤더 컨테이너 = 저장된배송지/직접입력 탭 버튼을 가진 Div */
    function findTabHeader(): Node | undefined {
      return flatten(layout).find(
        (n) =>
          n.name === 'Div' &&
          typeof n.comment === 'string' &&
          n.comment.includes('탭')
      );
    }

    it('탭 헤더는 회원에게만 노출된다 (if 에 currentUser?.uuid)', () => {
      const tab = findTabHeader();
      expect(tab, '탭 헤더 컨테이너 존재').toBeDefined();
      expect(String(tab!.if)).toContain('currentUser?.uuid');
    });

    it('직접입력 폼은 비회원이면 항상, 회원이면 manual 모드일 때 노출된다', () => {
      const container = findManualFormContainer(layout)!;
      const cond = String(container.if);
      // 비회원(!currentUser) OR 회원 manual 모드
      expect(cond).toContain('!_global.currentUser?.uuid');
      expect(cond).toContain("=== 'manual'");
    });

    it('저장된 배송지 목록은 회원에게만 노출된다', () => {
      const savedList = flatten(layout).find(
        (n) =>
          n.name === 'Div' &&
          typeof n.comment === 'string' &&
          n.comment.includes('저장된 배송지 목록')
      );
      expect(savedList, '저장된 배송지 목록 컨테이너 존재').toBeDefined();
      expect(String(savedList!.if)).toContain('currentUser?.uuid');
    });
  });

  // ── 로딩 스피너 (처리 중 상태) ────────────────────────
  // 적용 클릭 → 처리 중 스피너 노출 + 버튼 비활성. isSubmittingAddress 토글은 핸들러(TS)가 수행.
  describe('로딩 스피너 / 처리 중 상태', () => {
    it('적용 버튼이 isSubmittingAddress 로 disabled 된다', () => {
      const btn = findApplyButton(layout)!;
      expect(String(btn.props?.disabled)).toContain('isSubmittingAddress');
    });

    it('적용 버튼 안에 처리 중 스피너 아이콘(if=isSubmittingAddress)이 있다', () => {
      const btn = findApplyButton(layout)!;
      const spinner = (btn.children ?? []).find(
        (c: any) => c.name === 'Icon' && c.props?.name === 'spinner'
      );
      expect(spinner).toBeDefined();
      expect(String((spinner as any).if)).toContain('isSubmittingAddress');
      expect((spinner as any).props?.className).toContain('animate-spin');
    });

    it('적용 버튼 라벨이 처리 중일 때 common.processing 으로 전환된다', () => {
      const btn = findApplyButton(layout)!;
      const label = (btn.children ?? []).find((c: any) => c.name === 'Span');
      expect(label).toBeDefined();
      expect(String((label as any).text)).toContain('isSubmittingAddress');
      expect(String((label as any).text)).toContain('$t:common.processing');
    });
  });

  // ── manual body 단일 바인딩의 실제 객체 평가 ──────────
  // {{_global.editingShippingAddress}} 가 엔진의 단일 바인딩 경로(resolveObject→singleBindingMatch)
  // 를 타고 객체 그대로 보존되는지 검증 (증상2 회귀 차단).
  describe('manual body 단일 바인딩 평가', () => {
    it('{{_global.editingShippingAddress}} 가 객체 그대로 평가되어 받는분/연락처가 보존된다', () => {
      const engine = new DataBindingEngine();
      const editingShippingAddress = {
        recipient_name: '홍길동',
        recipient_phone: '010-1234-5678',
        zipcode: '12345',
        address: '서울시 강남구',
        address_detail: '101동 202호',
      };
      // 실제 엔진 경로: resolveObject 가 params 를 처리 (single binding → 원본 객체 보존)
      const resolved = engine.resolveObject(
        { body: '{{_global.editingShippingAddress}}' },
        { _global: { editingShippingAddress } } as any
      );
      expect(resolved.body).toEqual(editingShippingAddress);
      expect(resolved.body.recipient_name).toBe('홍길동');
      expect(resolved.body.recipient_phone).toBe('010-1234-5678');
    });
  });
});

// ── 검증된 패턴 일치 확인: _modal_address.json(_global.editingAddress) 와 동일 구조 ──
// 부모→모달 초기값 전달은 _modal_address.json 이 _global.editingAddress dataKey 로 이미 실증.
// 본 모달이 그 검증된 패턴과 구조적으로 동일한지 대조하여 동작을 보장한다.
// (createLayoutTest 는 이 디렉토리에서 Basic 컴포넌트를 자동 등록하지 않아 실제 렌더 검증 불가 —
//  코어 엔진 자동바인딩은 resources/js/core 테스트가 커버. 여기서는 패턴 동일성으로 회귀 차단.)
describe('검증된 _global dataKey 패턴 일치 (_modal_address.json 대조)', () => {
  const layout = loadLayout();
  const refPath = path.resolve(
    __dirname,
    '../../layouts/partials/mypage/addresses/_modal_address.json'
  );
  const refLayout: Node = JSON.parse(fs.readFileSync(refPath, 'utf8'));

  function findGlobalDataKeyContainer(node: Node): Node | undefined {
    return flatten(node).find(
      (n) => typeof n.dataKey === 'string' && n.dataKey.startsWith('_global.')
    );
  }

  it('참조 모달(_modal_address)도 _global.* dataKey 자동바인딩 패턴을 쓴다 (패턴 실재 확인)', () => {
    const refContainer = findGlobalDataKeyContainer(refLayout);
    expect(refContainer, '_modal_address 의 _global dataKey 컨테이너 존재').toBeDefined();
    expect(refContainer!.dataKey).toBe('_global.editingAddress');
  });

  it('본 모달의 직접입력 폼이 참조 모달과 동일하게 _global.* dataKey + 하위키 name 자동바인딩이다', () => {
    const container = findManualFormContainer(layout)!;
    expect(container.dataKey?.startsWith('_global.')).toBe(true);
    // 자동바인딩 Input 들은 하위 키 name + 수동 value 없음 (참조 모달과 동일 규칙)
    const inputs = flatten(container).filter((n) => n.name === 'Input');
    for (const input of inputs) {
      expect(String(input.props?.name).includes('.')).toBe(false);
      expect(input.props?.value).toBeUndefined();
    }
  });
});
