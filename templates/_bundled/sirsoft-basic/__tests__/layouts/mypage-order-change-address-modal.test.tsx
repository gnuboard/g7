/**
 * @file mypage-order-change-address-modal.test.tsx
 * @description 주문 상세 배송지 변경 모달 구조 회귀 테스트 (회원/비회원 공용)
 *
 * 테스트 대상:
 * - templates/.../partials/mypage/orders/_modal_change_address.json
 * - templates/.../partials/mypage/orders/_shipping.g7.html (트리거)
 *
 * 회귀 차단 (이슈55):
 * - 모달은 부모 _local 미접근 → 값은 _global.editingShippingAddress 에 산다(7.1.1 부터 dataKey 자동바인딩 대신 칸마다 value 읽기 + change 점 경로 쓰기)
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
 *
 * @scenario-manifest tests/scenarios/checkout-address-modal-real-structure.yaml
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

  // @scenario close=none
  // @effects order_change_address_modal_contract
  it('Modal 타입의 partial 레이아웃이다', () => {
    expect(layout.meta?.is_partial).toBe(true);
    expect(layout.type).toBe('composite');
    expect(layout.name).toBe('Modal');
  });

  // @scenario close=none
  // @effects order_change_address_modal_contract
  it('배경(딤 오버레이) 클릭으로 닫히지 않는다 (입력 중 실수 닫힘 방지)', () => {
    expect((layout.props as any)?.closeOnBackdropClick).toBe(false);
  });

  // ── 증상1: confirm 중복 ──────────────────────────────
  describe('증상1 — 확인창 중복 방지', () => {
    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('적용 버튼이 존재한다 (배송지 변경 핸들러 액션 보유)', () => {
      const btn = findApplyButton(layout);
      expect(btn).toBeDefined();
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('적용 버튼의 click 액션은 1개다 (2개면 confirm 이 두 번 뜸)', () => {
      const btn = findApplyButton(layout)!;
      const clickActions = btn.actions!.filter(
        (a) => (a.type ?? 'click') === 'click'
      );
      expect(clickActions.length).toBe(1);
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('적용 버튼 액션 트리 전체에서 confirm 속성은 최대 1개다', () => {
      const btn = findApplyButton(layout)!;
      const all = collectActions(btn.actions);
      const confirmCount = all.filter(
        (a) => typeof a.confirm === 'string' && a.confirm.length > 0
      ).length;
      expect(confirmCount).toBeLessThanOrEqual(1);
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('미등록 handler:"confirm" 핸들러를 사용하지 않는다 (Unknown action handler 회귀 차단)', () => {
      const btn = findApplyButton(layout)!;
      const all = collectActions(btn.actions);
      expect(all.some((a) => a.handler === 'confirm')).toBe(false);
    });
  });

  // ── 증상2: 직접입력 폼 _global 바인딩 ──
  // 핵심: 모달은 부모(_shipping.g7.html)의 _local 을 못 읽는다(별도 React 컨텍스트).
  //   따라서 부모 트리거가 채우는 초기 배송지 값은 _global.editingShippingAddress 로 전달한다.
  //   7.1.1: 폼 자동바인딩(dataKey)은 «마지막 렌더 때의 전역 스냅샷»에 이번 칸만 얹어 통째로 쓰므로, 다음 칸 입력이
  //   미뤄진 다시 그리기보다 먼저 오면 앞 칸 값을 지웠다. 그래서 칸마다 value 를 상태에서 읽고 change 로 전역 점 경로에 쓴다
  //   (마이페이지 배송지 창과 같은 규칙, 렌더 검증은 src/__tests__/layouts/checkout-address-modal-real-structure.test.tsx).
  describe('증상2 — 직접입력 폼 _global 칸 쓰기', () => {
    // 전역 하위 키로 쓰이는 모든 필드 — 우편번호/주소 포함(우편번호·주소는 value 만 두고 주소 검색 콜백이 점 경로로 쓴다)
    const WRITTEN_FIELDS = ['recipient_name', 'recipient_phone', 'zipcode', 'address', 'address_detail'];

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('직접입력 폼 컨테이너가 존재한다', () => {
      const container = findManualFormContainer(layout);
      expect(container).toBeDefined();
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('직접입력 폼은 전역 data-key 자동바인딩을 쓰지 않는다 (7.1.1)', () => {
      const container = findManualFormContainer(layout)!;
      expect(container.dataKey).toBeUndefined();
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('manual 모드 분기 if 가 _global.changeAddressMode 를 참조한다', () => {
      const container = findManualFormContainer(layout)!;
      expect(String(container.if)).toContain('_global.changeAddressMode');
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it.each(WRITTEN_FIELDS)(
      'Input(%s) 은 _global.editingShippingAddress 의 같은 키를 value 로 읽고, 읽기 전용이 아니면 change 로 그 키에 쓴다',
      (field) => {
        const container = findManualFormContainer(layout)!;
        const inputs = flatten(container).filter((n) => n.name === 'Input');
        const input = inputs.find((n) => n.props?.name === field);
        expect(input, `name="${field}" Input 이 존재해야 함`).toBeDefined();
        expect(String(input!.props?.value)).toContain(`_global.editingShippingAddress?.${field}`);
        if (input!.props?.readOnly !== true) {
          const writes = collectActions(input!.actions as Action[]).filter((a) => a.handler === 'setState');
          expect(writes.some((a) => (a.params as any)?.target === 'global' && `editingShippingAddress.${field}` in ((a.params as any) ?? {}))).toBe(true);
        }
      }
    );

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('직접입력 폼 Input 의 name 에 manualAddress. 접두사가 남아있지 않다 (구 _local 잔재 회귀 차단)', () => {
      const container = findManualFormContainer(layout)!;
      const inputs = flatten(container).filter((n) => n.name === 'Input');
      const withPrefix = inputs.filter(
        (n) => typeof n.props?.name === 'string' && n.props.name.startsWith('manualAddress')
      );
      expect(withPrefix.map((n) => n.props?.name)).toEqual([]);
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('직접입력 폼 어디에도 _local.manualAddress 참조가 남아있지 않다', () => {
      const container = findManualFormContainer(layout)!;
      const json = JSON.stringify(container);
      expect(json.includes('_local.manualAddress')).toBe(false);
      expect(json.includes('manualAddress')).toBe(false);
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
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

  // ── 칸 쓰기 경로 정합 (_global 점 경로) ──────────
  describe('칸 쓰기 경로 정합 (_global 점 경로)', () => {
    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('change 쓰기 경로는 editingShippingAddress.<field> 다 (접두사 중복 없음)', () => {
      const container = findManualFormContainer(layout)!;
      const recipientInput = flatten(container).find(
        (n) => n.name === 'Input' && n.props?.name === 'recipient_name'
      );
      expect(recipientInput, 'recipient_name Input 존재').toBeDefined();
      const keys = collectActions(recipientInput!.actions as Action[]).flatMap((a) => Object.keys((a.params as any) ?? {}));
      expect(keys).toContain('editingShippingAddress.recipient_name');
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

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('버튼 액션은 changeShippingAddress 핸들러 1개이며 confirm 1개를 가진다', () => {
      const btn = findApplyButton(layout)!;
      expect(btn.actions!.length).toBe(1);
      const action = btn.actions![0];
      expect(action.handler).toBe(CHANGE_HANDLER);
      expect(typeof action.confirm).toBe('string');
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('핸들러 호출에 회원용 orderId(order.data.id)를 전달한다', () => {
      const action = getApplyAction();
      expect(String(action.params?.orderId)).toContain('order.data.id');
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
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

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('탭 헤더는 회원에게만 노출된다 (if 에 currentUser?.uuid)', () => {
      const tab = findTabHeader();
      expect(tab, '탭 헤더 컨테이너 존재').toBeDefined();
      expect(String(tab!.if)).toContain('currentUser?.uuid');
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('직접입력 폼은 비회원이면 항상, 회원이면 manual 모드일 때 노출된다', () => {
      const container = findManualFormContainer(layout)!;
      const cond = String(container.if);
      // 비회원(!currentUser) OR 회원 manual 모드
      expect(cond).toContain('!_global.currentUser?.uuid');
      expect(cond).toContain("=== 'manual'");
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
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
    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('적용 버튼이 isSubmittingAddress 로 disabled 된다', () => {
      const btn = findApplyButton(layout)!;
      expect(String(btn.props?.disabled)).toContain('isSubmittingAddress');
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
    it('적용 버튼 안에 처리 중 스피너 아이콘(if=isSubmittingAddress)이 있다', () => {
      const btn = findApplyButton(layout)!;
      const spinner = (btn.children ?? []).find(
        (c: any) => c.name === 'Icon' && c.props?.name === 'spinner'
      );
      expect(spinner).toBeDefined();
      expect(String((spinner as any).if)).toContain('isSubmittingAddress');
      expect((spinner as any).props?.className).toContain('animate-spin');
    });

    // @scenario close=none
    // @effects order_change_address_modal_contract
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
    // @scenario close=none
    // @effects order_change_address_modal_contract
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

// ── 같은 칸 규칙 확인: _modal_address.json(_global.editingAddress) 와 동일 구조 ──
// 7.1.1 부터 두 창 모두 전역 data-key 자동바인딩 없이 칸마다 value 읽기 + change 점 경로 쓰기다.
describe('마이페이지 배송지 창과 같은 칸 규칙 (_modal_address.json 대조)', () => {
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

  // @scenario close=none
  // @effects order_change_address_modal_contract
  it('두 창 모두 전역 data-key 자동바인딩 컨테이너가 없다', () => {
    expect(findGlobalDataKeyContainer(refLayout)).toBeUndefined();
    expect(findGlobalDataKeyContainer(layout)).toBeUndefined();
  });

  // @scenario close=none
  // @effects order_change_address_modal_contract
  it('본 모달의 직접입력 칸은 하위 키 name 과 상태 value 를 가진다(참조 모달과 같은 규칙)', () => {
    const container = findManualFormContainer(layout)!;
    const inputs = flatten(container).filter((n) => n.name === 'Input');
    expect(inputs.length).toBeGreaterThan(0);
    for (const input of inputs) {
      expect(String(input.props?.name).includes('.')).toBe(false);
      expect(String(input.props?.value)).toContain('_global.editingShippingAddress?.');
    }
  });
});

describe('저장된 배송지 카드는 키보드로 고를 수 있는 단추다 (7.1.1)', () => {
  const layout = loadLayout();
  /** 카드 = 저장된 배송지를 고르는 setState(selectedAddressId) 클릭 액션을 가진 Button */
  const cards = flatten(layout).filter(
    (n) =>
      n.name === 'Button' &&
      (n.actions ?? []).some(
        (a) => a.type === 'click' && a.handler === 'setState' && a.params?.target === 'global' && a.params?.selectedAddressId !== undefined
      )
  );

  /**
   * `{{식}}` 하나를 주어진 문맥으로 평가한다.
   *
   * @param expr 굽기 산출물의 식
   * @param scope 식이 읽는 이름들
   * @returns 평가 결과
   */
  const evalExpr = (expr: string, scope: Record<string, unknown>): unknown => {
    const m = /^\{\{([\s\S]*)\}\}$/.exec(String(expr).trim());
    expect(m, `식 하나로 된 값이 아니다: ${expr}`).not.toBeNull();
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(scope), `return (${m![1]});`)(...Object.values(scope));
  };

  // @scenario close=none
  // @effects order_change_address_modal_contract
  it('카드는 type="button" 단추 하나다(종전 클릭만 받는 Div — Tab 이 닿지 않아 「변경」 이 잠긴 채였다)', () => {
    expect(cards).toHaveLength(1);
    expect(cards[0].props?.type).toBe('button');
    // 카드를 Div 로 되돌리면 이 Button 이 사라져 위 개수 단언이 빨개진다
    expect(flatten(layout).some((n) => n.name === 'Div' && (n.actions ?? []).some((a) => a.params?.selectedAddressId !== undefined))).toBe(false);
  });

  // @scenario close=none
  // @effects order_change_address_modal_contract
  it('고른 카드만 aria-pressed 가 참이다', () => {
    const pressed = cards[0].props?.['aria-pressed'];
    expect(typeof pressed).toBe('string');
    expect(evalExpr(pressed, { _global: { selectedAddressId: 3 }, addr: { id: 3 } })).toBe(true);
    expect(evalExpr(pressed, { _global: { selectedAddressId: 3 }, addr: { id: 4 } })).toBe(false);
    expect(evalExpr(pressed, { _global: {}, addr: { id: 4 } })).toBe(false);
  });
});
