/**
 * @file adminEcommerceSettingsClaim.test.ts
 * @description 쇼핑몰 설정 클레임 탭 — 구조 계약 (S10 · D23/D26)
 *
 * 이 탭이 지키는 것은 "무엇이 그려지는가" 가 아니라 **어떤 설정 키가 화면에 통로를 갖는가** 다.
 * 세 축을 고정한다:
 *
 *  1. `defaults.json` 의 `claim.*` 스칼라 축 전부가 화면에 편집 통로를 갖는지. 하나라도 빠지면
 *     그 설정은 소비 쪽만 있고 운영자가 값을 바꿀 수단이 영구히 없는 상태로 남는다 —
 *     화면에도 로그에도 드러나지 않는다.
 *  2. 사유 카탈로그 3종(교환/반품/환불)이 **각자의 상태 키**를 읽고 쓰는지. 정본을 복제한
 *     파일이라 한 곳이라도 `refund_reasons` 가 남으면 세 표가 같은 목록을 편집하게 되고,
 *     저장하면 교환 사유가 반품 사유로 덮어써진다.
 *  3. 사유명이 `MultilingualInput`(행은 `compact`) 인지 (D23 — 단일 Input 금지).
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';

import { describe, expect, it } from 'vitest';
import { callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import defaults from '../../../../config/settings/defaults.json';
import claimPolicy from '../../../layouts/admin/partials/admin_ecommerce_settings/_claim_policy_section.json';
import exchangeCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_exchange_reason_cards.json';
import exchangeSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_exchange_reason_section.json';
import refundSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_refund_reason_section.json';
import refundCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_refund_reason_cards.json';
import returnCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_return_reason_cards.json';
import returnSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_return_reason_section.json';
import shippingCarrierCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_carrier_cards.json';
import shippingTypeCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_type_cards.json';
import shippingCarrierSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_carrier_section.json';
import shippingTypeSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_type_section.json';
import tabClaim from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_claim.json';
import tabLanguageCurrency from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_language_currency.json';
import tabMileage from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage.json';
import tabShipping from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json';
import disableIntlModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_disable_international_shipping_modal.json';
import settingsShell from '../../../layouts/admin/admin_ecommerce_settings.json';
import unsavedTabModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_unsaved_tab_switch.json';
import langKo from '../../../lang/partial/ko/admin/settings.json';
import langEn from '../../../lang/partial/en/admin/settings.json';

/** 사유 카탈로그 3종은 DB 관리 대상이라 defaults.json 에 없다 — 스칼라 축만 화면 통로를 요구한다 */
const SCALAR_KEYS = Object.keys((defaults as any).defaults.claim);

const policyText = JSON.stringify(claimPolicy);

/**
 * 레이아웃 트리에서 조건에 맞는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param match 판정 함수
 * @returns 찾은 노드 (없으면 undefined)
 */
function findNode(node: any, match: (n: any) => boolean): any {
    if (node && typeof node === 'object') {
        if (!Array.isArray(node) && match(node)) return node;
        for (const value of Array.isArray(node) ? node : Object.values(node)) {
            const hit = findNode(value, match);
            if (hit) return hit;
        }
    }

    return undefined;
}

/**
 * 조건에 맞는 노드를 **전부** 모읍니다 (findNode 는 첫 하나만 돌려준다).
 *
 * @param node 탐색 시작 노드
 * @param match 판정 함수
 * @return 조건에 맞는 노드 배열
 */
function findNodes(node: any, match: (n: any) => boolean): any[] {
    const hits: any[] = [];

    if (node && typeof node === 'object') {
        if (!Array.isArray(node) && match(node)) hits.push(node);
        for (const value of Array.isArray(node) ? node : Object.values(node)) {
            hits.push(...findNodes(value, match));
        }
    }

    return hits;
}

/**
 * @scenario reason_type=exchange
 *
 * @effects settings_claim_tab_composes_policy_and_three_catalogs,
 *   settings_claim_reason_catalog_reads_and_writes_own_type,
 *   settings_claim_reason_name_is_multilingual
 */
describe('설정 클레임 탭 — 구성', () => {
  /**
   * @scenario reason_type=exchange
   * @effects settings_claim_tab_composes_policy_and_three_catalogs
   */
  it('클레임 탭은 정책 → 사유 하위 탭 → 교환/반품/환불 사유 패널을 이 순서로 싣는다', () => {
    const children = (tabClaim as any).children ?? [];

    expect(children[0].partial).toBe('_claim_policy_section.json');
    expect(children[1].id).toBe('claim_reason_sub_tabs');
    // 패널마다 자기 사유 조각 하나만 싣는다 — 한 패널에 둘이 들어가면 하위 탭을 골라도 두 표가 함께 보인다.
    expect(children.slice(2).map((c: any) => [c.id, (c.children ?? []).map((k: any) => k.partial)])).toEqual([
      ['claim_exchange_reason_panel', ['_exchange_reason_section.json']],
      ['claim_return_reason_panel', ['_return_reason_section.json']],
      ['claim_refund_reason_panel', ['_refund_reason_section.json']],
    ]);
  });

  /**
   * @effects settings_claim_reason_sub_tab_follows_url
   */
  it('사유 하위 탭은 고른 값(_local)이 먼저, 진입·새로고침 때는 URL 쿼리 sub_tab 이 정하고, 모르는 값·부재는 교환으로 떨어진다', () => {
    const children = (tabClaim as any).children ?? [];
    const tabs = children[1];

    expect(tabs.props.tabs.map((t: any) => t.id)).toEqual(['exchange', 'return', 'refund']);
    expect(tabs.props.activeTabId).toContain('query.sub_tab');
    expect(tabs.props.activeTabId).toContain('_local.claimReasonTab');

    // 패널 표시 조건과 활성 탭 식이 같은 판정을 해야 탭 강조와 보이는 표가 어긋나지 않는다.
    const visibleFor = (subTab: unknown, picked: unknown = undefined) => {
      const query = { sub_tab: subTab };
      const _local = { claimReasonTab: picked };
      return children.slice(2).filter((panel: any) => {
        const expr = String(panel.if).replace(/^\{\{|\}\}$/g, '');
        // eslint-disable-next-line no-new-func
        return new Function('query', '_local', `return (${expr});`)(query, _local);
      }).map((panel: any) => panel.id);
    };
    const activeFor = (subTab: unknown, picked: unknown = undefined) => {
      const expr = String(tabs.props.activeTabId).replace(/^\{\{|\}\}$/g, '');
      // eslint-disable-next-line no-new-func
      return new Function('query', '_local', `return (${expr});`)({ sub_tab: subTab }, { claimReasonTab: picked });
    };

    expect([visibleFor(undefined), activeFor(undefined)]).toEqual([['claim_exchange_reason_panel'], 'exchange']);
    expect([visibleFor('return'), activeFor('return')]).toEqual([['claim_return_reason_panel'], 'return']);
    expect([visibleFor('refund'), activeFor('refund')]).toEqual([['claim_refund_reason_panel'], 'refund']);
    expect([visibleFor('bogus'), activeFor('bogus')]).toEqual([['claim_exchange_reason_panel'], 'exchange']);
    // 탭을 고르면 URL 은 replace-url 로만 바뀌어 query 가 갱신되지 않는다 — 고른 값이 query 보다 먼저다.
    expect([visibleFor('return', 'refund'), activeFor('return', 'refund')]).toEqual([['claim_refund_reason_panel'], 'refund']);
    expect([visibleFor('refund', 'exchange'), activeFor('refund', 'exchange')]).toEqual([['claim_exchange_reason_panel'], 'exchange']);
    // 상위 탭 전환이 null 로 비우면 다시 URL 을 따른다.
    expect([visibleFor('return', null), activeFor('return', null)]).toEqual([['claim_return_reason_panel'], 'return']);
  });

  /**
   * @effects settings_claim_reason_sub_tab_follows_url
   */
  it('하위 탭 전환은 열린 사유 추가 폼 3종을 비우고, 설정을 다시 받지 않는 replaceUrl 로 tab=claim 을 지킨 채 sub_tab 만 URL 에 남긴다', () => {
    const tabs = ((tabClaim as any).children ?? [])[1];
    const seq = tabs.actions.find((a: any) => a.event === 'onTabChange');
    const [clear, nav] = seq.actions;

    // 페이지 [저장] 가드가 이 플래그를 하위 탭과 무관하게 읽는다 — 남기면 안 보이는 폼 때문에 저장이 막힌다.
    expect(clear.handler).toBe('setState');
    for (const key of ['isAddingExchangeReason', 'isAddingReturnReason', 'isAddingRefundReason']) {
      expect(clear.params[key]).toBe(false);
    }
    for (const key of ['exchangeReasonForm', 'returnReasonForm', 'refundReasonForm', 'exchangeReasonFormErrors', 'returnReasonFormErrors', 'refundReasonFormErrors']) {
      expect(clear.params[key]).toBeNull();
    }

    expect(clear.params.claimReasonTab).toBe('{{$args[0]}}');
    // navigate 는 라우트를 다시 풀어 설정을 다시 받고 initLocal 로 폼을 덮는다 — 하위 탭을 옮기는 순간 저장 전 편집이 사라진다.
    expect(nav.handler).toBe('replaceUrl');
    expect(nav.params).toMatchObject({ mergeQuery: true, query: { tab: 'claim', sub_tab: '{{$args[0]}}' } });
  });

  /**
   * @effects settings_claim_reason_sub_tab_follows_url
   */
  it('상위 탭 전환은 고른 하위 탭을 비워, 다시 돌아오면 주소(sub_tab 없음)와 화면(교환)이 어긋나지 않는다', () => {
    // 탭 전환은 「저장하지 않은 변경」 확인 창(S5-D41) 뒤로 갈렸다 — 정리는 변경이 없을 때의 갈래(if 없는 else)가 하고, 창 [이동] 은 같은 목록을 부모 폼에 한다(adminEcommerceSettingsUnsavedTabChange 가 키 일치를 단언)
    const onTabChange = findNode(settingsShell, (n) => n?.id === 'tab_navigation').actions.find((a: any) => a.event === 'onTabChange');
    // 고친 것이 없으면 바로 옮기는 갈래(conditions 의 else), 고친 것이 있으면 확인 창의 「저장하지 않고 이동」
    // (페이지 상태는 `$parent._local`) — 두 길 모두 하위 탭을 비워야 한다(7.1.0 조치 G2 확인 창)
    const directSwitch = onTabChange.conditions[1].then;
    const direct = directSwitch.actions.find((a: any) => a.handler === 'setState' && a.params?.target === 'local');
    expect(direct.params).toHaveProperty('claimReasonTab', null);
    const leave = findNode(unsavedTabModal, (n) => n?.props?.['data-testid'] === 'ecommerce-settings-unsaved-tab-leave');
    const confirmed = leave.actions[0].actions.find((a: any) => a.handler === 'setState' && a.params?.target === '$parent._local');
    expect(confirmed.params).toHaveProperty('claimReasonTab', null);
  });

  /**
   * @effects settings_claim_tab_renders_only_on_its_own_tab
   */
  it('탭은 claim 탭에서만 렌더된다', () => {
    expect((tabClaim as any).if).toContain("=== 'claim'");
  });
});

/**
 * @scenario reason_type=refund
 *
 * @effects settings_claim_policy_exposes_every_scalar_axis,
 *   settings_claim_policy_limits_come_from_server,
 *   settings_claim_complete_defaults_declared_as_modal_seed
 */
describe('클레임 정책 — defaults 의 스칼라 축 전부가 편집 통로를 갖는다', () => {
  /**
   * `complete_defaults` 는 하위 키를 갖는 축이라 아래 전용 루프가 따로 본다.
   * `auto_approve_refund_payment_methods_decided` 는 운영자가 편집하는 값이 아니라 **서버 전용 결정
   * 이력**이다 — 운영자가 한 번 뺀 즉시 환불 수단이 다음 조회의 자동 합류로 되살아나지 않도록 남기는
   * 키다(#599 §4-A A-2 · P17). 이력은 전체 저장과 단건 저장이 **같은 저장 경로**
   * (`EcommerceSettingsAdminService::saveSettings` / `updateSetting`)에서 서버가 기록하고, 관리자 조회
   * 응답에서는 빠지며(`getSettingsForAdmin`), 저장 요청에 실으면 `prohibited` 로 거절된다
   * (`StoreEcommerceSettingsRequest`). 화면 편집 통로가 없는 것이 설계이므로 「스칼라 축 전부가 편집
   * 통로를 갖는다」 검사에서 뺀다.
   */
  const SERVER_RECORDED_KEYS = ['auto_approve_refund_payment_methods_decided'];
  const POLICY_SCALAR_KEYS = SCALAR_KEYS.filter(
    (k) => k !== 'complete_defaults' && !SERVER_RECORDED_KEYS.includes(k),
  );

  // 파생이 비면 `it.each([])` 는 케이스를 **0개 등록하고 초록**이다 — 오류도 경고도 없다.
  // 같은 describe 의 형제 파생(`COMPLETE_DEFAULT_KEYS`)은 이미 하한을 갖는데 이쪽만 없어
  // 한 블록 안에서 강도가 갈려 있었다. `defaults.json` 경로·구조가 바뀌면 여기서 붉어진다.
  /**
   * @effects settings_claim_derivations_are_not_empty
   */
  it('스칼라 축 파생이 비어 있지 않다 (파생 자체의 건전성)', () => {
    expect(
      POLICY_SCALAR_KEYS.length,
      'defaults.json 의 claim 스칼라 축을 읽지 못했다 — 아래 루프가 통째로 공전한다',
    ).toBeGreaterThanOrEqual(12);
  });

  /**
   * @effects settings_claim_policy_exposes_every_scalar_axis
   */
  it.each(POLICY_SCALAR_KEYS)(
    'claim.%s 를 읽고 쓰는 노드가 있다',
    (key) => {
      expect(policyText).toContain(`claim?.${key}`);
      expect(policyText).toMatch(new RegExp(`claim\\.${key}`));
    },
  );

  // 하위 키를 손으로 적으면 세 번째 완료 기본값이 `defaults.json` 에 생겼을 때 이 검사가
  // 조용히 통과한다 — 화면 통로가 없어도, 서버 `required_array_keys` 에 없어 `validated()`
  // 에서 탈락해도 붉어지지 않는다. 파일 머리말 축 ①("스칼라 축 **전부**")이 이 축에서만
  // 예외를 만든 자리이므로 하위 키도 `defaults.json` 에서 파생시킨다.
  const COMPLETE_DEFAULT_KEYS = Object.keys((defaults as any).defaults.claim.complete_defaults ?? {});

  /**
   * @effects settings_claim_derivations_are_not_empty
   */
  it('완료 처리 기본값 파생이 비어 있지 않다 (파생 자체의 건전성)', () => {
    expect(COMPLETE_DEFAULT_KEYS.length, 'defaults.json 의 claim.complete_defaults 를 읽지 못했다').toBeGreaterThanOrEqual(2);
  });

  /**
   * @effects settings_claim_policy_exposes_every_scalar_axis
   */
  it.each(COMPLETE_DEFAULT_KEYS)('완료 처리 기본값 %s 도 통로를 갖는다 (D26)', (key) => {
    // 형제 스칼라 루프는 read(`claim?.X`) 와 write(`claim.X`) 를 **둘 다** 본다. 여기만 write
    // 하나면 세 번째 기본값이 생겼을 때(=이 파생이 존재하는 유일한 이유) 그 키의 값 표시
    // 통로가 없어도 초록이다 — 운영자가 끈 값이 모달에 켜진 채로 뜬다.
    expect(policyText, `완료 기본값 ${key} 의 값을 읽는 통로가 없다`).toContain(`claim?.complete_defaults?.${key}`);
    expect(policyText, `완료 기본값 ${key} 를 쓰는 통로가 없다`).toContain(`claim.complete_defaults.${key}`);
  });

  /**
   * @effects settings_claim_complete_defaults_declared_as_modal_seed
   */
  it('완료 처리 기본값은 "모달 초기 선택값" 임을 화면이 밝힌다 (D26)', () => {
    expect(policyText).toContain('claim.policy.complete_defaults.notice');
  });

  /**
   * @effects settings_claim_policy_limits_come_from_server
   */
  it('숫자 입력의 경계값은 서버가 내려준 _meta.limits 를 읽는다 (리터럴 재기입 금지)', () => {
    for (const limit of [
      'claim_request_period_days_min',
      'claim_request_period_days_max',
      'claim_max_images_min',
      'claim_max_images_max',
      'claim_image_max_size_kb_min',
      'claim_image_max_size_kb_max',
    ]) {
      expect(policyText).toContain(`_meta?.limits?.${limit}`);
    }
  });

  /**
   * @effects settings_claim_inputs_all_locked_for_read_only_operator
   */
  it('읽기 전용 운영자에게는 모든 입력이 잠긴다', () => {
    // 문자열의 `"disabled":` 개수와 `isReadOnly` 개수를 견주면, disabled 를 아예 안 붙인
    // 입력을 새로 넣어도 두 수가 여전히 같아 통과한다 — 그 필드만 열린 채 남는다.
    // 그래서 노드를 모아 **각 입력마다** 잠금 표현식을 확인한다.
    // 이름 화이트리스트로 모집단을 좁히면, 목록 밖의 입력형(Textarea·Checkbox·RadioGroup 등)이
    // `disabled` 없이 들어와도 `found` 에 담기지 않아 잠금 검사 밖에 남는다 — 이 케이스가
    // 닫았다고 선언한 탈출구("disabled 를 아예 안 붙인 입력을 새로 넣어도 통과")가 개수 축에서
    // 이름 축으로 옮겨 그대로 남는 자리다. 저장소가 아는 입력형 전집합을 함께 고정한다.
    const INPUT_NAMES = [
      'Input', 'Select', 'TagInput', 'Toggle', 'MultilingualInput',
      'Textarea', 'Checkbox', 'RadioGroup', 'ChipCheckbox', 'NumberInput', 'DatePicker',
    ];
    const found: Array<{ name: string; id: string; disabled: unknown }> = [];

    const walk = (node: unknown): void => {
      if (Array.isArray(node)) {
        node.forEach(walk);

        return;
      }

      if (!node || typeof node !== 'object') return;

      const n = node as Record<string, any>;

      if (typeof n.name === 'string' && INPUT_NAMES.includes(n.name)) {
        found.push({ name: n.name, id: n.props?.id ?? '(id 없음)', disabled: n.props?.disabled });
      }

      Object.values(n).forEach(walk);
    };

    // 정책 카드만 보면 사유 3섹션(표) + 모바일 카드 3종의 입력 36개가 검사 밖에 남는다 — 7 partial 전부를 훑는다.
    [claimPolicy, exchangeSection, returnSection, refundSection, exchangeCards, returnCards, refundCards].forEach(walk);

    // 하한(>14)이면 partial 3개가 통째로 사라져도 통과한다 — 정확값으로 고정한다.
    // 52 = 종전 50 + 자동승인 대상 상태 MultiSelect 1(#599 후속 D8)
    //      + 환불 자동 승인 결제수단 MultiSelect 1(#599 §4-A A-2 · P17 · `591770d69`)
    //      + 선교환 허용 Toggle 1 · 회수 기한(일) 숫자 입력 1(#599 §3-3 = 54).
    expect(found.length, '7 partial 의 입력형 노드 수가 바뀌었다 — 입력을 더했거나 뺐으면 이 수를 함께 고친다').toBe(54);

    const open = found.filter(
      (f) => typeof f.disabled !== 'string' || !f.disabled.includes('_computed.isReadOnly')
    );

    expect(
      open.map((f) => `${f.name}#${f.id}`),
      '읽기 전용 잠금이 걸리지 않은 입력'
    ).toEqual([]);
  });

    /**
     * @effects settings_claim_charge_payment_method_options_derive_from_order_settings, settings_charge_payment_method_options_are_not_literal_builtins
     */
    it('추가결제 수단 선택지는 주문 설정의 결제수단에서 파생한다 (리터럴 금지)', () => {
        // 이 축의 서버 허용 어휘는 기본 결제수단 + 결제 플러그인이 등록한 확장 ID 이다.
        // 화면이 기본 7종을 박아 두면 서버는 받아들이는데 운영자는 그 수단을 고를 수 없고,
        // API 로 심어 둔 확장 ID 도 이 탭을 한 번 저장하면 선택지 밖이라 사라진다.
        const text = JSON.stringify(claimPolicy);
        const node = findNode(claimPolicy, (n: any) => n?.props?.name === 'claim.charge_payment_methods');

        expect(node).toBeDefined();
        expect(Array.isArray(node.props.options), '선택지가 정적 배열로 박혔 있다').toBe(false);
        expect(node.props.options).toContain('order_settings?.payment_methods');
        // 공급이 끊긴 수단은 앞으로 쓸 수단을 고르는 자리에서 뺀다.
        expect(node.props.options).toContain('_orphaned');
        // 부정 단언은 통과가 기본값이라 바늘 하나로는 강도를 재기 어렵다. `options` 가 표현식
        // 문자열이면 `"value":` 자체가 없어 무엇을 넣어도 통과하므로, 위 `Array.isArray(...)`
        // false 단언이 실질 방어선이다. 그 위에 기본 결제수단 어휘를 **전수**로 훑어 어느
        // 하나라도 리터럴로 박히면 걸리게 한다 — vbank 하나만 보면 card·point 하드코딩은 샌다.
        const flattened = text.replace(/\s/g, '');

        for (const builtin of ['card', 'vbank', 'dbank', 'point', 'phone', 'kakaopay', 'naverpay']) {
            expect(flattened, `기본 결제수단 \`${builtin}\` 이 선택지에 리터럴로 박혔다`)
                .not.toContain(`"value":"${builtin}"`);
        }
    });

    /**
     * @effects settings_claim_charge_payment_method_options_do_not_filter_by_order_catalog_active
     */
    it('추가결제 수단 선택지는 주문 카탈로그의 is_active 로 거르지 않는다', () => {
        // 클레임 추가결제 수단은 주문 결제수단과 **다른 키**(`claim.charge_payment_methods`)이고,
        // 서버도 `PaymentMethodResolver::allValidIds()` 로만 검증해 활성 여부를 보지 않는다.
        // 화면만 `is_active` 로 거르면 두 가지가 동시에 깨진다:
        //   ① 이미 저장된 비활성 수단이 선택지 모집단 밖이라 라벨을 못 찾아 **원문 키**로 찍힌다
        //      (2026-09-22 실측: 기본값 `point` 가 「포인트결제」 대신 `point` 로 노출).
        //   ② 운영자가 그 태그를 ✕ 로 한 번 지우면 선택지에도 없어 **이 화면에서 다시 고를 수 없다**
        //      — 마일리지 추가결제 경로가 화면에서 사라지고, 주문설정 탭을 먼저 켜라는 안내도 없다.
        // 같은 파일의 「환불 자동 승인 결제수단」이 `is_active` 로 거르지 않는 것과 같은 이유다.
        const node = findNode(claimPolicy, (n: any) => n?.props?.name === 'claim.charge_payment_methods');

        expect(node).toBeDefined();
        expect(String(node.props.options), '선택지가 주문 카탈로그의 is_active 로 걸러진다')
            .not.toContain('is_active');
    });
});

/**
 * @scenario reason_type=return
 *
 * @effects settings_claim_reason_catalog_reads_and_writes_own_type,
 *   settings_claim_reason_name_is_multilingual
 */
describe('사유 카탈로그 3종 — 각자의 상태 키를 읽고 쓴다', () => {
  const cases: Array<[string, unknown, string, string[]]> = [
    ['교환', exchangeSection, 'exchange', ['return_reasons', 'refund_reasons']],
    ['교환(모바일 카드)', exchangeCards, 'exchange', ['return_reasons', 'refund_reasons']],
    ['반품', returnSection, 'return', ['exchange_reasons', 'refund_reasons']],
    ['반품(모바일 카드)', returnCards, 'return', ['exchange_reasons', 'refund_reasons']],
    ['환불', refundSection, 'refund', ['exchange_reasons', 'return_reasons']],
    ['환불(모바일 카드)', refundCards, 'refund', ['exchange_reasons', 'return_reasons']],
  ];

  /**
   * @effects settings_claim_reason_catalog_reads_and_writes_own_type
   */
  it.each(cases)('%s 사유는 claim.%s_reasons 만 읽고 쓴다', (_label, layout, type, forbidden) => {
    const text = JSON.stringify(layout);

    expect(text).toContain(`claim?.${type}_reasons`);
    expect(text).toContain(`claim.${type}_reasons`);

    for (const other of forbidden as string[]) {
      expect(text).not.toContain(other);
    }
  });

  /**
   * @effects settings_claim_reason_catalog_reads_and_writes_own_type
   */
  it.each(cases)('%s 사유의 다국어 키도 그 유형 것을 쓴다', (_label, layout, type) => {
    const text = JSON.stringify(layout);

    expect(text).toContain(`settings.claim.${type}_reasons.`);
  });

  /**
   * @scenario reason_type=return
   * @effects settings_claim_reason_name_is_multilingual
   */
  it.each([
    ['교환', exchangeSection],
    ['반품', returnSection],
    ['환불', refundSection],
  ])('%s 사유명은 MultilingualInput 이고 목록 행은 compact 다 (D23)', (_label, layout) => {
    const text = JSON.stringify(layout);

    expect(text.includes('"MultilingualInput"')).toBe(true);
    expect(text.includes('"layout":"compact"')).toBe(true);
  });

  /**
   * @effects settings_claim_reason_name_requires_current_locale
   */
  it.each([
    ['교환', exchangeSection, 'exchangeReasonForm', 'claim.exchange_reasons'],
    ['반품', returnSection, 'returnReasonForm', 'claim.return_reasons'],
    ['환불', refundSection, 'refundReasonForm', 'claim.refund_reasons'],
  ])('%s 사유명 필수 판정은 사이트 기본 언어다 — 보는 언어·ko 고정 금지 (D23 → B3)', (_label, layout, form, section) => {
    // 필수 언어는 서버 규칙과 같은 사이트 기본 언어(`_global.appConfig.siteLocale`)다(제품 결정 2026-10-08, 후속 B 세션 B3).
    // 화면이 보는 언어나 `ko` 고정으로 판정하면 서버(사이트 기본 언어 필수)와 규칙이 갈려, `*` 붙은 칸을
    // 채워도 저장 버튼에서 막히거나 422 를 받는다. 게이트·오류 플래그·행 오류 바인딩 세 곳 모두 같은 축을 읽는다.
    const text = JSON.stringify(layout);

    expect(text.includes(`_local.${form}?.name?.ko`), 'ko 고정 게이트가 남아 있다').toBe(false);
    expect(text.includes(`_local.${form}?.name?.[(_global.appConfig?.siteLocale ?? $locale ?? 'ko')]`)).toBe(true);
    expect(text.includes(`_local.${form}?.name?.[($locale ?? 'ko')]`), '보는 언어 게이트가 남아 있다').toBe(false);
    expect(text.includes(`'${section}.' + reason._idx + '.name.ko'`), '행 오류 바인딩이 ko 고정이다').toBe(false);
    expect(text.includes(`'${section}.' + reason._idx + '.name.' + (_global.appConfig?.siteLocale ?? $locale ?? 'ko')`)).toBe(true);
  });

  /**
   * @scenario reason_type=refund
   * @effects settings_claim_reason_name_is_multilingual
   */
  it.each([
    ['교환', exchangeCards, 'claim.exchange_reasons'],
    ['반품', returnCards, 'claim.return_reasons'],
    ['환불', refundCards, 'claim.refund_reasons'],
  ])('%s 모바일 카드의 사유명에도 서버 오류 통로가 있다', (_label, layout, section) => {
    // portable 뷰포트에서 사유명 422 가 나면 카드 어디에도 표시가 없었다 — 형제 배송사 카드와 같은 형태로 잇는다.
    const input = findNode(layout, (n) => n.name === 'MultilingualInput');

    expect(input).toBeDefined();
    // 서버 422 키는 name.{사이트 기본 언어}(후속 B 세션 B3)
    expect(String(input.props?.error ?? '')).toContain(`'${section}.' + reason._idx + '.name.' + (_global.appConfig?.siteLocale ?? $locale ?? 'ko')`);
  });

});

/**
 * @scenario reason_type=exchange
 *
 * @effects settings_claim_reason_name_is_multilingual,
 *   settings_claim_reason_code_format_checked_before_add
 */
describe('모바일 카드 오류 바인딩 — 반복 변수 이름이 선언과 같다', () => {
  // 카드의 `error` 바인딩이 선언되지 않은 반복 변수를 읽으면 표현식은 조용히 빈 문자열이 되고,
  // 모바일에서만 어느 카드가 422 인지 표시되지 않는다 (10차 배송유형 카드 `$type` 오타).
  const cases: Array<[string, unknown]> = [
    ['교환', exchangeCards],
    ['반품', returnCards],
    ['환불', refundCards],
    ['배송사', shippingCarrierCards],
    ['배송유형', shippingTypeCards],
  ];

  /**
   * @effects settings_card_error_binding_uses_the_declared_item_var
   */
  it.each(cases)('%s 카드의 error 바인딩은 iteration.item_var 를 읽는다', (_label, cards) => {
    const iterated = findNode(cards, (n) => typeof n?.iteration?.item_var === 'string');
    expect(iterated, '반복 노드가 없다').toBeTruthy();
    const itemVar: string = iterated.iteration.item_var;

    const errorNodes: any[] = [];
    const walk = (n: any) => {
      if (!n || typeof n !== 'object') return;
      if (typeof n.props?.error === 'string' && n.props.error.includes('_local.errors')) errorNodes.push(n);
      Object.values(n).forEach(walk);
    };
    walk(iterated);

    expect(errorNodes.length, '카드에 오류 바인딩이 없다').toBeGreaterThan(0);
    for (const node of errorNodes) {
      // 반복 변수가 `type` 이면 `$type._idx` 는 미선언 이름이다 — 선언된 이름 + `._idx` 만 허용한다.
      expect(node.props.error).toContain(`${itemVar}._idx`);
      const foreign = node.props.error.match(/\$?[A-Za-z_]+\._idx/g) ?? [];
      expect(foreign.every((ref: string) => ref === `${itemVar}._idx`), `선언되지 않은 반복 변수: ${foreign.join(', ')}`).toBe(true);
    }
  });
});

/**
 * @scenario reason_type=return
 *
 * @effects settings_claim_reason_code_format_checked_before_add
 */
describe('사유 추가 폼 — 코드 형식을 서버와 같은 규칙으로 미리 검사한다', () => {
  // 서버 규칙(`ClaimReasonCodeFormat`)은 `^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$` 다. 화면이 비어 있음만
  // 보면 대문자·하이픈 코드가 행으로 들어가고, 저장 422 는 행 번호 없이 상단 배너로만 뜨며 코드 칸은
  // 읽기 전용이라 그 자리에서 고칠 수 없다.
  const cases: Array<[string, unknown, string]> = [
    ['교환', exchangeSection, 'exchange'],
    ['반품', returnSection, 'return'],
    ['환불', refundSection, 'refund'],
  ];

  /**
   * @effects settings_claim_reason_code_format_checked_before_add
   */
  it.each(cases)('%s 추가 버튼의 게이트가 형식 4조건(첫 글자·허용 문자·연속 밑줄·끝 밑줄) + 길이 50 + 기존 코드 중복을 본다', (_label, section, type) => {
    const gate = findNode(section, (n) => typeof n?.if === 'string' && n.if.includes(`_local.${type}ReasonForm?.code`) && n.if.includes('charAt(0)'));
    expect(gate, '형식 게이트가 없다').toBeTruthy();
    expect(gate.if).toContain(".split('').every(");
    expect(gate.if).toContain(".includes('__')");
    expect(gate.if).toContain("charAt(");
    expect(gate.if).toContain("!== '_'");
    // 12차: 서버 `max:50` 과 유형 내 유일성도 미리 본다 — 중복 코드는 서버 422 가 행을 지목하지
    // 않고 코드 칸은 읽기 전용이라, 사전검사가 없으면 삭제·재추가 말고 탈출구가 없었다.
    expect(gate.if).toContain('.length <= 50');
    expect(gate.if).toContain(`(_local.form?.claim?.${type}_reasons ?? []).some(`);
    expect(gate.if).toContain('.toLowerCase()');

    const errors = findNode(section, (n) => typeof n?.params?.[`${type}ReasonFormErrors`]?.code === 'string' && n.params[`${type}ReasonFormErrors`].code.includes("'format'"));
    expect(errors, '형식 위반을 구분하는 오류 플래그가 없다').toBeTruthy();
    expect(errors.params[`${type}ReasonFormErrors`].code).toContain("'length'");
    expect(errors.params[`${type}ReasonFormErrors`].code).toContain("'duplicate'");

    const text = findNode(section, (n) => typeof n?.text === 'string' && n.text.includes(`${type}_reasons.validation.code_format`));
    expect(text, '형식 안내 문구가 없다').toBeTruthy();
    expect(text.text).toContain(`${type}_reasons.validation.code_required`);
    expect(text.text).toContain(`${type}_reasons.validation.code_length`);
    expect(text.text).toContain(`${type}_reasons.validation.code_duplicate`);
  });
});

/**
 * @scenario reason_type=exchange
 *
 * @effects settings_claim_reason_row_code_error_is_pointed_at_the_row
 */
describe('사유 행 — 서버가 code 를 거절하면 그 행이 지목된다', () => {
  // 코드 칸은 읽기 전용(사용 중 잠금·형식 면제)이라 서버 422(`claim.{type}_reasons.N.code` — 중복·
  // 맞바꾸기·형식)를 받은 운영자가 어느 행인지 알 길이 이 통로뿐이다. 형제 배송사 표는 처음부터
  // 행 지목 통로(입력 `input-error` + Span)를 갖고 있었고, 사유 3종만 없었다.
  const cases: Array<[string, unknown, unknown, string]> = [
    ['교환', exchangeSection, exchangeCards, 'exchange'],
    ['반품', returnSection, returnCards, 'return'],
    ['환불', refundSection, refundCards, 'refund'],
  ];

  /**
   * @effects settings_claim_reason_row_code_error_is_pointed_at_the_row
   */
  it.each(cases)('%s 표와 모바일 카드 모두 행의 code 오류를 그 행에 그린다', (_label, section, cards, type) => {
    const key = `'claim.${type}_reasons.' + reason._idx + '.code'`;

    for (const [name, root] of [['표', section], ['카드', cards]] as Array<[string, unknown]>) {
      const errorText = findNode(root, (n) => typeof n?.text === 'string' && n.text.includes(key) && n.text.includes('?.[0]'));
      expect(errorText, `${name}에 행 code 오류 문구가 없다`).toBeTruthy();
      expect(errorText.if).toContain(key);

      const codeCell = findNode(root, (n) => n?.text === '{{reason.code}}' && typeof n?.props?.className === 'string' && n.props.className.includes(key));
      expect(codeCell, `${name}의 코드 칸이 오류 상태를 색으로 드러내지 않는다`).toBeTruthy();
      expect(codeCell.props.className).toContain('dark:text-red-400');
    }
  });
});

/**
 * @scenario reason_type=exchange
 *
 * @effects settings_save_is_blocked_while_an_inline_add_form_is_open
 */
describe('페이지 [저장] — 추가 폼을 연 채 저장하면 막고 경고한다', () => {
  // 통화 2축은 원래 막고 있었다. 사유 3종·배송사·배송유형·배송가능국가의 추가 폼은 같은 형태
  // (버퍼 `*Form`/`newCountry` 를 확정 버튼으로만 `form.*` 에 옮긴다)인데 가드 밖이면, 폼을 연 채
  // 저장하면 입력이 조용히 버려지고 성공 토스트가 뜬다.
  //
  // 이 목록을 손으로 적으면 안 된다 — 13차 검증이 잡은 것이 바로 그것이다. S10 이 가드를 세울 때
  // 이미 존재하던 `isAddingCountry` 를 세지 않았고, 이 테스트도 같은 다섯 개를 그대로 옮겨 적어
  // 누락을 증명하지 못했다(가드와 테스트가 같은 열거를 공유하면 테스트는 가드의 사본일 뿐이다).
  // 그래서 **설정 화면이 실제로 켜는 플래그 전집합을 파셜에서 파생**시키고, 가드가 그 전부를
  // 덮는지를 본다. 새 인라인 폼이 생기면 이 테스트가 먼저 붉어진다.
  //
  // 14차 보강 — 파생 원본이 **손으로 적은 파셜 목록**이면 그 목록 밖에 새 인라인 폼이 생겼을 때
  // 여전히 누락을 증명하지 못한다(13차가 닫은 것은 "가드의 열거를 옮겨 적는 것" 이고, 목록 층위로
  // 옮겨간 같은 사본이 남아 있었다). 설정 파셜 **디렉터리 전체**를 읽어 파생 원본 자체를 닫는다.
  const PARTIALS_DIR = path.resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_settings');
  const partialSources = fs
    .readdirSync(PARTIALS_DIR)
    .filter((name) => name.endsWith('.json'))
    .map((name) => fs.readFileSync(path.join(PARTIALS_DIR, name), 'utf-8'));

  const flags = Array.from(
    new Set(
      [...partialSources, JSON.stringify(settingsShell)]
        .flatMap((source) => source.match(/"(isAdding[A-Za-z]+)":\s*true/g) ?? [])
        .map((hit) => hit.replace(/^"/, '').replace(/":\s*true$/, ''))
    )
  ).sort();

  /**
   * @effects settings_claim_derivations_are_not_empty
   */
  it('설정 화면이 켜는 인라인 추가 플래그가 하나라도 파생되어야 한다 (파생 자체의 건전성)', () => {
    expect(partialSources.length, '설정 파셜 디렉터리를 읽지 못했다 — 파생 원본이 비면 아래 검사가 전부 공전한다').toBeGreaterThanOrEqual(8);
    expect(flags.length).toBeGreaterThanOrEqual(8);
    expect(flags).toContain('isAddingCountry');
  });

  // 18차 — 탭 전환 정리의 **범위**가 각 폼의 [취소] 와 같은지 본다.
  //
  // 17차가 탭 전환 정리를 넣으며 주석에 "각 폼의 '취소' 가 비우는 것과 같은 범위를 비운다:
  // 플래그 + 입력 버퍼 + 그 폼의 오류" 라고 적었는데, 실제로는 8폼 중 통화 2폼이 **플래그만**
  // 비우고 입력 버퍼(`form.newCurrency`/`form.newMileageCurrency`)를 남겼다. 그 버퍼는 저장 추적
  // dataKey(`form`) 안에 있으므로, 탭을 옮겼다 돌아와 다시 [추가] 를 누르면 이전 입력이 안내 없이
  // 되살아난다. 플래그만 보는 위 검사들은 전부 초록이었다 — 범위를 보는 축이 없었기 때문이다.
  //
  // 기대 집합도 손으로 적지 않는다. 각 폼의 확정/취소 액션이 `isAdding*: false` 와 **같은 params
  // 객체**에서 `null` 로 비우는 키가 곧 그 폼의 버퍼다. 새 인라인 폼이 생기면 그 폼의 버퍼가
  // 자동으로 기대 집합에 들어온다.
  /**
   * @effects settings_tab_switch_clears_the_same_scope_as_each_form_cancel
   */
  it('탭 전환 정리가 각 폼의 [취소] 와 같은 범위를 비운다 (버퍼 파생)', () => {
    const buffers = new Map<string, Set<string>>();

    const collectBuffers = (node: any): void => {
      if (Array.isArray(node)) {
        node.forEach(collectBuffers);

        return;
      }

      if (!node || typeof node !== 'object') {
        return;
      }

      for (const key of Object.keys(node)) {
        if (/^isAdding[A-Za-z]+$/.test(key) && node[key] === false) {
          const nulled = Object.keys(node).filter((k) => k !== key && node[k] === null);

          if (!buffers.has(key)) {
            buffers.set(key, new Set());
          }

          nulled.forEach((k) => buffers.get(key)!.add(k));
        }
      }

      Object.values(node).forEach(collectBuffers);
    };

    // 파생 원본은 파셜 디렉터리뿐이다 — 셸에는 정리 액션 자신이 있어 포함하면 자기 사본이 된다.
    partialSources.map((raw) => JSON.parse(raw)).forEach(collectBuffers);

    expect(buffers.size, '버퍼를 가진 인라인 폼을 하나도 파생하지 못했다').toBeGreaterThanOrEqual(8);

    const tabChangeParams = findNode(
      settingsShell,
      (n) =>
        n?.handler === 'setState' &&
        n?.params &&
        Object.keys(n.params).some((k) => /^isAdding[A-Za-z]+$/.test(k)) &&
        Object.keys(n.params).filter((k) => /^isAdding[A-Za-z]+$/.test(k)).length >= 8
    )?.params;

    expect(tabChangeParams, '탭 전환 정리 액션을 찾지 못했다').toBeTruthy();

    const cleared = new Set(Object.keys(tabChangeParams));
    const missing: string[] = [];

    for (const [flag, keys] of buffers) {
      for (const key of keys) {
        if (!cleared.has(key)) {
          missing.push(`${flag} -> ${key}`);
        }
      }
    }

    expect(missing, `탭 전환이 이 폼들의 입력 버퍼를 남긴다: ${missing.join(', ')}`).toEqual([]);
  });

  const save = findNode(settingsShell, (n) => n?.props?.['data-testid'] === 'ecommerce-settings-save' || n?.props?.testId === 'ecommerce-settings-save');
  const actions: any[] = callbacksOf(save?.actions?.[0], 'actions');

  // 경고 토스트는 둘이다 — 통화 2축은 `save_pending_currency`, 나머지 인라인 폼은 `save_pending_form`.
  // 어느 쪽이 맡는지는 문구 선택의 문제이므로, 계약은 **둘을 합쳐 파생 집합 전부를 덮는가** 다.
  /**
   * @effects settings_save_is_blocked_while_an_inline_add_form_is_open
   */
  it('인라인 폼 경고 토스트(2종)가 합쳐서 파생된 플래그 전부를 본다', () => {
    const toasts = actions.filter(
      (a) => a.handler === 'toast'
        && /save_pending_(form|currency)/.test(String(a.params?.message))
    );
    expect(toasts.length, '인라인 폼 경고 토스트가 없다').toBeGreaterThanOrEqual(2);

    const covered = toasts.map((t) => String(t.if)).join(' ');
    for (const flag of flags) expect(covered, `${flag} 를 경고하는 토스트가 없다`).toContain(`_local.${flag} === true`);
  });

  // 13차가 가드에 `isAddingCountry` 를 더했지만 그 가드가 띄우는 **문구**는 종전 그대로
  // "사유·배송사·배송유형" 세 가지를 고정 나열했다 — 배송가능국가 폼만 연 운영자는 열려 있지도
  // 않은 폼을 확정하라는 안내를 받았고(ko/en/ja 전부), 무엇을 닫아야 하는지 화면에서 알 수 없었다.
  // 가드가 보는 플래그와 문구가 지목할 수 있는 폼이 같은 집합이어야 한다.
  /**
   * @scenario reason_type=exchange
   *
   * @effects settings_save_pending_form_labels_match_dictionary_keys
   */
  it('경고 문구는 지금 열려 있는 폼만 지목한다 — 가드가 보는 플래그마다 대응 라벨이 있다', () => {
    const formToast = actions.find(
      (a) => a.handler === 'toast' && /save_pending_form/.test(String(a.params?.message))
    );
    expect(formToast, 'save_pending_form 토스트가 없다').toBeDefined();

    const message = String(formToast.params.message);

    // 고정 나열로 되돌리면(= 표현식이 아닌 `$t:` 리터럴 하나) 이 단언이 붉어진다.
    expect(message, '문구가 열린 폼을 지목하지 않고 고정 나열로 되돌아갔다').toContain(':forms');
    expect(message).toContain('save_pending_form_targets.');

    // 이 토스트가 보는 플래그 전부가 라벨 키와 짝지어져 있어야 한다. 짝이 없으면 그 폼만
    // 열었을 때 지목 목록이 비어 "추가 중인  폼을" 이 된다.
    const guarded = Array.from(String(formToast.if).matchAll(/_local\.(isAdding[A-Za-z]+) === true/g)).map((m) => m[1]);
    expect(guarded.length, '토스트가 보는 플래그를 읽지 못했다').toBeGreaterThanOrEqual(6);

    for (const flag of guarded) {
      expect(
        message,
        `${flag} 가 켜졌을 때 문구가 지목할 라벨이 없다`
      ).toContain(`[_local.${flag}, '`);
    }

    // 라벨 키는 세 언어 모두에 실재해야 한다 — 없으면 지목 자리에 키 문자열이 그대로 나간다.
    expect(message, '라벨 키를 파생하는 표현이 없다').toContain("save_pending_form_targets.' + pair[1]");

    // 라벨 키는 `save_pending_form_targets.` + **런타임 문자열 결합**이라 i18n 정적 검사가
    // 해석하지 못한다. 개수 비교(`>= referenced`)로는 레이아웃의 토큰을 하나 개명하거나
    // 사전 키만 개명해도 초록이고, 그때 운영자 화면에는 키 원문이 그대로 뜬다 —
    // 14차가 M1 로 닫은 형태("계약의 전제가 무보호")가 그 조치 자신 안에서 재현된 자리다.
    // 개수가 아니라 **집합 동일성**을 본다.
    const tokens = Array.from(message.matchAll(/\[_local\.isAdding[A-Za-z]+,\s*'([a-z_]+)'\]/g)).map((m) => m[1]).sort();
    expect(tokens.length, '문구에서 라벨 토큰을 읽지 못했다').toBe(guarded.length);

    // ja 팩도 모집단이다 — `_bundled`↔활성 diff 는 팩 안의 키 소실을 보지 못한다.
    const langJa = JSON.parse(
      fs.readFileSync(
        path.resolve(__dirname, '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/settings.json'),
        'utf-8',
      ),
    );

    for (const [name, lang] of [['ko', langKo], ['en', langEn], ['ja', langJa]] as [string, any][]) {
      expect(String(lang.save_pending_form), `${name}: 문구에 :forms 치환 자리가 없다`).toContain(':forms');
      expect(
        Object.keys(lang.save_pending_form_targets ?? {}).sort(),
        `${name}: 라벨 사전의 키 집합이 레이아웃 토큰과 다르다 — 화면에 키 원문이 나간다`,
      ).toEqual(tokens);

      // 이 문구는 저장이 막힌 운영자가 읽는 **유일한 탈출 안내**다. 종전에는 형제
      // `save_pending_currency` 의 문면을 그대로 복제해 "확정(체크 버튼)" 이라고 말했는데,
      // 이 가드가 덮는 폼들의 확정 컨트롤은 전부 `…추가` 텍스트 버튼이고 체크 아이콘은 하나도
      // 없다. 하필 그 폼들에 있는 유일한 체크박스는 '고객 선택 가능' 입력칸이라, 안내를 따른
      // 운영자는 엉뚱한 칸을 켠다. 통화 폼만 실제로 체크 버튼을 갖는다.
      expect(
        String(lang.save_pending_form),
        `${name}: 안내가 '체크 버튼' 을 지목한다 — 이 가드가 덮는 폼에는 체크 버튼이 없다(통화 폼과 혼동)`,
      ).not.toMatch(/체크 버튼|check button|チェックボタン/i);
    }
  });

  // 안내가 지목하는 컨트롤이 실제 확정 버튼과 같은 말인지 본다. 위 케이스는 **라벨 토큰과
  // 사전 키의 집합**을 고정하지만, 문장이 인용하는 컨트롤 이름이 화면 버튼과 같은지는 그
  // 단언의 축이 아니다 — 15차 ⓕ 와 같은 형태(안내가 라디오 라벨을 다른 말로 인용)가
  // 이 문구에서는 ko·en·ja 3언어 × 6폼으로 남아 있었다.
  /**
   * @effects settings_save_pending_wording_matches_the_real_confirm_button
   */
  it('저장 차단 안내가 지목하는 컨트롤이 실제 확정 버튼과 같은 말이다', () => {
    const langJa = JSON.parse(
      fs.readFileSync(
        path.resolve(__dirname, '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/settings.json'),
        'utf-8',
      ),
    );

    // 확정 버튼 라벨은 폼마다 다르다(`사유 추가`·`유형 추가`·`배송사 추가`·`추가`). 공통분모는
    // "추가" 이므로 안내도 그 말을 쓴다 — 특정 폼의 라벨을 통째로 인용하면 나머지 5폼에서 틀린다.
    const wording: [string, any, RegExp][] = [
      ['ko', langKo, /추가 버튼/],
      ['en', langEn, /Add button/],
      ['ja', langJa, /追加ボタン/],
    ];

    for (const [name, lang, needle] of wording) {
      const message = String(lang.save_pending_form);

      // 실제 확정 버튼 라벨 전건이 안내가 쓰는 말을 포함하는지 — 하나라도 어긋나면 그 폼의
      // 운영자에게는 안내가 가리키는 컨트롤이 화면에 없다.
      const confirmLabels = [
        lang.claim?.exchange_reasons?.add_button,
        lang.claim?.return_reasons?.add_button,
        lang.claim?.refund_reasons?.add_button,
        lang.shipping?.types?.add_button,
        lang.shipping?.carriers?.add_button,
      ].map(String);

      expect(message, `${name}: 안내가 확정 버튼을 지목하지 않는다`).toMatch(needle);

      const token = needle.source.replace(/\\/g, '');
      for (const label of confirmLabels) {
        expect(
          label.includes(token.replace(' button', '').replace('ボタン', '').replace(' 버튼', '')),
          `${name}: 확정 버튼 라벨 "${label}" 이 안내가 인용하는 말과 다르다`,
        ).toBe(true);
      }
    }

    // 통화 폼은 실제로 체크 버튼을 가지므로 그 문구는 바꾸지 않는다 — 두 문장이 같은 말을
    // 하게 되면 다음 라운드가 "통일" 을 명목으로 정확한 쪽을 틀린 쪽에 맞출 수 있다.
    expect(String(langKo.save_pending_currency), '통화 폼 안내는 체크 버튼을 지목해야 한다').toContain('체크 버튼');
  });

  // 가드가 플래그를 **보는 것**만으로는 부족하다 — 그 플래그를 **끄는 액션이 화면에서 도달
  // 가능해야** 한다. 배송가능국가 추가 폼은 자기 플래그 외에 `international_shipping_enabled`
  // 를 게이트로 하나 더 갖는 유일한 폼이라(형제 7종은 자기 플래그 하나뿐), 폼을 연 채 해외배송을
  // 끄면 폼과 [추가] 버튼이 함께 사라지고 플래그를 끄는 유일한 액션(폼 안의 [확정]/[취소])도
  // 함께 사라진다 → 이후 어느 탭에서도 [저장]이 영구히 막힌다(새로고침 외 탈출구가 없고,
  // 새로고침은 다른 탭 편집까지 버린다). 폼이 도달 불가가 되는 지점에서 표시도 함께 내려야 한다.
  /**
   * @effects settings_unreachable_inline_form_never_locks_save_forever
   */
  it('해외배송을 끄는 액션이 배송가능국가 추가 표시를 함께 내린다 (도달 불가 폼이 저장을 잠그지 않게)', () => {
    const shippingText = JSON.stringify(tabShipping);

    // 전제 — 이 폼만 자기 플래그 외의 조건을 게이트로 갖는다. 그 2중 게이트가 사라지면
    // 이 검사의 근거도 사라지므로 함께 고정한다.
    expect(
      shippingText,
      '배송가능국가 폼의 2중 게이트가 사라졌다 — 이 검사의 전제를 다시 확인할 것'
    ).toContain('_local.isAddingCountry && (_local.form?.shipping?.international_shipping_enabled');

    const offBranches = findNodes(
      tabShipping,
      (n: any) => n?.handler === 'setState'
        && n?.params?.['form.shipping.international_shipping_enabled'] === false
    );

    expect(offBranches.length, '해외배송을 끄는 setState 를 찾지 못했다').toBeGreaterThanOrEqual(2);

    for (const branch of offBranches) {
      expect(
        branch.params.isAddingCountry,
        '해외배송을 끄면서 배송가능국가 추가 표시를 내리지 않는 분기가 있다 — 그 폼은 도달 불가가 되는데 저장 가드는 계속 막는다'
      ).toBe(false);
      expect(branch.params.newCountry, '버퍼(newCountry)가 남으면 다시 켰을 때 옛 입력이 되살아난다').toBeNull();
    }
  });

  // 위 조치는 폼을 내리는 것이 옳지만, 확인 모달을 여는 분기에서는 운영자가 [취소]로 되돌아올
  // 수 있다 — 그 경로는 "끄지 않겠다" 를 고른 것인데 입력 중이던 국가가 이미 버려져 있고
  // 안내도 없다. 확인창을 띄우는 분기만 버퍼를 보관하고 [취소]가 되살린다.
  /**
   * @effects settings_disable_intl_modal_stashes_and_restores_the_country_buffer
   */
  it('확인 모달을 여는 분기는 입력 버퍼를 보관하고 [취소]가 되살린다', () => {
    const stashing = findNodes(
      tabShipping,
      (n: any) => n?.handler === 'setState'
        && n?.params?.['form.shipping.international_shipping_enabled'] === false
        && n?.params?.disableIntlCountryBuffer !== undefined
    );
    expect(stashing.length, '버퍼를 보관하는 분기가 없다 — [취소] 가 되살릴 값이 없다').toBe(1);

    const modalText = JSON.stringify(disableIntlModal);
    expect(modalText, '[취소] 가 추가 폼 표시를 되살리지 않는다').toContain('disableIntlWasAddingCountry');
    expect(modalText, '[취소] 가 입력 버퍼를 되살리지 않는다').toContain('"newCountry":"{{$parent._local.disableIntlCountryBuffer}}"');

    // 되살린 뒤에도 버퍼가 남으면 다음 토글에서 옛 입력이 되살아난다 — 양 버튼 모두 비운다.
    //
    // 개수(`=== 2`)로 견주면 **한 버튼이 두 번 비워도** 통과한다. 계약은 "[취소]·[확인]
    // 각각이 비운다" 이므로 버튼 단위로 확인한다 — 15차 M2 가 닫은 "개수 → 집합" 형태가
    // 같은 파일의 다른 조치 안에 남아 있던 자리다.
    const buttons = findNodes(disableIntlModal, (n: any) => n?.name === 'Button');
    const labelled = buttons.map((b: any) => ({
      label: String((b.children ?? []).map((c: any) => c?.text).find(Boolean) ?? '').split('.').pop(),
      clears: JSON.stringify(b.actions ?? []).includes('"disableIntlCountryBuffer":null'),
    }));

    expect(
      labelled.map((b) => b.label).sort(),
      '모달의 버튼 집합이 바뀌었다 — 버퍼를 비워야 할 대상이 달라졌는지 확인할 것',
    ).toEqual(['cancel', 'confirm']);

    for (const button of labelled) {
      expect(button.clears, `[${button.label}] 이 버퍼를 비우지 않는다 — 다음 토글에서 옛 입력이 되살아난다`).toBe(true);
    }
  });

  /**
   * @effects settings_save_execution_is_gated_by_every_inline_form_flag
   */
  it('저장 실행(setState·apiCall)은 같은 플래그가 하나라도 켜져 있으면 나가지 않는다', () => {
    const gated = actions.filter((a) => (a.handler === 'setState' || a.handler === 'apiCall') && typeof a.if === 'string' && a.if.startsWith('{{!('));
    expect(gated.length).toBeGreaterThanOrEqual(2);
    for (const a of gated) for (const flag of flags) expect(a.if).toContain(`_local.${flag} === true`);
  });

  // 가드가 보는 폼이 **확정 가능해야** 저장이 다시 열린다. 확정 게이트는 `name[현재 로케일]` 을
  // 요구하는데(10차 B1 이 `name.ko` 고정에서 옮겼다), 시드가 ko/en 리터럴이면 그 밖의 로케일
  // 운영자에게는 그 언어의 입력칸이 없다 — `MultilingualInput` 은 표시할 로케일을 value 의 키에서
  // 뽑고, 언어 추가 버튼은 **현재 로케일 필드**에만 붙이므로(`localeCode === actualDefaultLocale`)
  // 시드에 현재 로케일이 없으면 버튼조차 나오지 않는다. 확정도 못 하고, 그동안 [저장]은
  // 위 가드에 막힌다 — 안내가 지시하는 동작이 수행 불가능해진다.
  //
  // 목록을 손으로 적지 않는다(13차 B2·14차 L5 규율) — 파셜 디렉터리에서 이름 맵을 시드하는
  // 지점 전부를 파생시켜 검사한다. 새 인라인 폼이 생겨도 이 검사가 먼저 붉어진다.
  /**
   * @scenario reason_type=exchange
   *
   * @effects settings_inline_form_name_seed_derives_from_active_locales
   */
  describe('인라인 추가 폼의 이름 시드 — 활성 언어 전체에서 파생한다', () => {
    // 모집단은 **문자열 표현식 시드**가 아니라 "추가 표시를 켜는 setState" 자체다.
    //
    // 종전 파생은 `"…Form": "{{…name:…}}"` 처럼 표현식 문자열만 매칭했다. 그러면 한 폼을
    // 객체 리터럴로 되돌렸을 때 그 시드가 모집단에서 **통째로 빠져** 아래 per-item 검사가
    // 그것을 한 번도 보지 않고, 남는 방어선은 개수 하한 하나뿐이었다 — 오늘은 실측 6과
    // 하한 6이 우연히 같아 붉어지지만 인라인 폼이 하나 늘면(7) 되돌림이 초록이 된다.
    // 시드는 언제나 `isAdding*: true` 와 **같은 params 객체**에 있으므로 그 구조를 앵커로
    // 삼으면 시드의 모양이 바뀌어도 모집단에서 빠지지 않는다.
    const collectSeeds = (node: any, out: { flag: string; key: string; value: any }[] = []) => {
      if (Array.isArray(node)) {
        node.forEach((child) => collectSeeds(child, out));

        return out;
      }

      if (!node || typeof node !== 'object') return out;

      const flag = Object.keys(node).find((k) => /^isAdding[A-Za-z]+$/.test(k) && node[k] === true);

      if (flag) {
        for (const key of Object.keys(node)) {
          if (/(Form|newCountry)$/.test(key)) out.push({ flag, key, value: node[key] });
        }
      }

      Object.values(node).forEach((child) => collectSeeds(child, out));

      return out;
    };

    // 이름 맵을 싣는 시드만 본다 — 모양과 무관하게 판정한다(문자열이면 `name:` 표현,
    // 객체면 `name` 키). 되돌린 객체 리터럴도 여기서 잡히므로 모집단을 벗어나지 못한다.
    const seedsAll = partialSources.flatMap((source) => collectSeeds(JSON.parse(source)));
    const seeds = seedsAll.filter(({ value }) =>
      (typeof value === 'string' && /name\s*:/.test(value))
      || (value && typeof value === 'object' && 'name' in value)
    );

    /**
     * @effects settings_claim_derivations_are_not_empty
     */
    it('이름을 시드하는 지점이 파생된다 (파생 자체의 건전성)', () => {
      expect(
        seeds.length,
        '이름 맵을 시드하는 인라인 폼을 하나도 찾지 못했다 — 파생 원본이 비면 아래 검사가 공전한다',
      ).toBeGreaterThanOrEqual(6);

      // 파생이 `flags`(추가 표시 플래그 전집합)와 같은 축을 보는지 교차 확인한다. 두 파생이
      // 서로를 보지 않으면 한쪽이 조용히 좁아져도 드러나지 않는다.
      const seededFlags = Array.from(new Set(seeds.map((s) => s.flag)));
      for (const flag of seededFlags) {
        expect(flags, `${flag} 가 추가 표시 플래그 전집합에 없다 — 두 파생이 다른 축을 본다`).toContain(flag);
      }
    });

    /**
     * @effects settings_inline_form_name_seed_derives_from_active_locales
     */
    it.each(seeds.map((s) => [`${s.flag}/${s.key}`, s]))('%s 의 이름 시드가 $locales 에서 파생된다', (_label, seed: any) => {
      expect(
        typeof seed.value,
        `${seed.key} 의 시드가 표현식이 아니다 — 객체 리터럴은 로케일을 고정하므로 그 밖의 로케일 운영자가 폼을 확정할 수 없다`,
      ).toBe('string');
      expect(seed.value, '이름 시드가 활성 언어 목록($locales)에서 파생되지 않는다').toContain('$locales');
      expect(seed.value, "이름 시드가 로케일을 리터럴로 열거한다 — 그 밖의 로케일 운영자는 폼을 확정할 수 없다").not.toMatch(/name:\s*\{/);
    });

    // 시드와 게이트는 같은 로케일 축을 보아야 한다. 한쪽만 바뀐 것이 이 결함의 형태였다.
    /**
     * @effects settings_claim_reason_name_required_locale_is_current_locale
     */
    it('확정 게이트는 사이트 기본 언어의 이름을 요구한다 (서버 규칙과 같은 축)', () => {
      const gates = partialSources.flatMap((source) => source.match(/name\?\.\[\(_global\.appConfig\?\.siteLocale \?\? \$locale \?\? 'ko'\)\]/g) ?? []);
      expect(gates.length, '확정 게이트가 사이트 기본 언어를 읽지 않는다 — 서버 422 를 화면이 막지 못한다').toBeGreaterThanOrEqual(5);
      const viewerLocaleGates = partialSources.flatMap((source) => source.match(/name\?\.\[\(\$locale \?\? 'ko'\)\]/g) ?? []);
      expect(viewerLocaleGates, '확정 게이트가 보는 언어로 되돌아갔다').toEqual([]);
      const koFixed = partialSources.flatMap((source) => source.match(/Form\?\.name\?\.ko/g) ?? []);
      expect(koFixed, '확정 게이트가 ko 고정으로 되돌아갔다').toEqual([]);
    });

    // 읽는 쪽의 모집단은 게이트만이 아니다. 저장 실패 시 서버가 돌려주는 422 키는
    // `...name.{사이트 기본 언어}` 라, 오류 조회 키가 ko 고정이면 비-ko 관리자에게는 필드
    // 오류가 **표시되지 않는다**(토스트만 뜬다). 두 소비처가 같은 축을 보는지 함께 고정한다.
    /**
     * @effects settings_server_field_error_keys_follow_the_current_locale
     */
    it('서버 422 필드 오류 조회 키도 사이트 기본 언어를 쓴다', () => {
      const errorKeys = partialSources.flatMap(
        (source) => source.match(/\.name\.' \+ \(_global\.appConfig\?\.siteLocale \?\? \$locale \?\? 'ko'\)/g) ?? []
      );
      expect(
        errorKeys.length,
        '오류 조회 키가 사이트 기본 언어를 읽지 않는다 — 서버 422 키(name.{사이트 기본 언어})와 어긋나 어느 칸이 문제인지 알 수 없다',
      ).toBeGreaterThanOrEqual(5);

      const koFixedErrorKeys = partialSources.flatMap(
        (source) => source.match(/\.name\.ko'\]/g) ?? []
      );
      expect(koFixedErrorKeys, '오류 조회 키가 ko 고정으로 되돌아갔다').toEqual([]);
    });
  });
});

/**
 * 안내 문구가 지목하는 것이 화면에 실재하는지 본다.
 *
 * 16차가 닫은 형태(안내가 없는 '체크 버튼' 을 지목)의 형제 축이다. 이름 입력칸의
 * placeholder 는 `MultilingualInput` 이 `inline` 렌더에서 뒤에 `(로케일명)` 을 덧붙인다
 * (`MultilingualInput.tsx` 의 `localizedPlaceholder`). 그래서 placeholder 값 자체가
 * 언어명이면 ko 관리자는 `한국어 (English)`, ja 관리자는 `韓国語 (日本語)` 를 읽는다.
 * `compact` 렌더(표 행 편집기)는 로케일명을 붙이지 않으므로 일본어 칸 위에 그냥
 * `韓国語` 만 남아, 안내를 따른 운영자가 그 칸에 한국어를 적는다.
 *
 * 키 이름이 `name_ko_placeholder` 라 "한국어 칸의 안내" 로 읽히지만, 실제로는 한 폼의
 * 모든 언어 칸이 공유하는 단일 prop 이다 — 그래서 어떤 언어명도 값이 될 수 없다.
 */
describe('설정 클레임/배송 탭 — 이름 입력칸 안내가 언어명을 지목하지 않는다', () => {
  const SETTINGS_LANGS: Array<[string, string]> = [
    ['ko', path.resolve(__dirname, '../../../lang/partial/ko/admin/settings.json')],
    ['en', path.resolve(__dirname, '../../../lang/partial/en/admin/settings.json')],
    [
      'ja',
      path.resolve(
        __dirname,
        '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/settings.json',
      ),
    ],
  ];

  // 이 화면이 실제로 쓰는 폼들 — 손으로 적지 않고 ko 사전(SSoT)에서 파생한다. 19차 이전에는
  // 이 주석 바로 아래가 3원소 리터럴이라, 네 번째 사유 카탈로그가 생기면 그 폼의 안내는
  // 검사 모집단 밖에 서서 언어명 placeholder 가 그대로 통과했다.
  // 기준을 ko 하나로 두는 이유: en/ja 에서 그 폼 블록이 통째로 빠진 경우도 아래
  // `typeof value === 'string'` 단언이 붉어지게 하기 위해서다(언어별로 파생하면 빠진 언어는
  // 모집단도 함께 줄어 조용히 통과한다).
  const OWNERS = Object.keys((langKo as any).claim ?? {}).filter((key) => key.endsWith('_reasons'));

  /**
   * @effects settings_claim_derivations_are_not_empty
   */
  it('사유 카탈로그 폼 파생이 비어 있지 않다 (파생 자체의 건전성)', () => {
    expect(
      OWNERS.length,
      'ko 사전에서 사유 카탈로그 폼(*_reasons)을 읽지 못했다 — 아래 검사가 공전한다',
    ).toBeGreaterThanOrEqual(3);
  });

  // 언어명은 사이트가 노출하는 로케일 표시명 전집합에서 온다. 한 언어만 검사하면
  // ja 만 고치고 ko/en 이 남거나 그 반대가 된다.
  const LOCALE_WORDS = /한국어|영어|일본어|Korean|English|Japanese|韓国語|英語|日本語/;

  /**
   * @effects settings_name_placeholder_never_names_a_language
   */
  it.each(SETTINGS_LANGS)('%s: 사유 카탈로그 3종의 이름 안내가 언어명이 아니다', (loc, file) => {
    const lang = JSON.parse(fs.readFileSync(file, 'utf-8'));

    const found = OWNERS.map((owner) => [owner, lang.claim?.[owner]?.form?.name_ko_placeholder]) as Array<
      [string, unknown]
    >;

    for (const [owner, value] of found) {
      expect(typeof value, `${loc}/${owner}: 이름 안내 키가 없다 — 모집단이 비면 이 검사가 공전한다`).toBe(
        'string',
      );
      expect(
        String(value),
        `${loc}/${owner}: 안내가 언어명이다 — 이 prop 은 모든 언어 칸이 공유하고 inline 렌더는 뒤에 '(로케일명)' 을 덧붙이므로, 운영자가 엉뚱한 언어를 적는다`,
      ).not.toMatch(LOCALE_WORDS);
    }
  });

  /**
   * @effects settings_name_placeholder_never_names_a_language
   */
  it.each(SETTINGS_LANGS)('%s: 같은 화면의 배송사·배송유형 안내도 같은 규칙을 지킨다', (loc, file) => {
    const lang = JSON.parse(fs.readFileSync(file, 'utf-8'));

    for (const owner of ['carriers', 'types'] as const) {
      const value = lang.shipping?.[owner]?.form?.name_ko_placeholder;
      expect(typeof value, `${loc}/${owner}: 이름 안내 키가 없다`).toBe('string');
      expect(
        String(value),
        `${loc}/${owner}: 안내가 언어명이다 — 형제 폼만 고치면 같은 화면에서 규칙이 갈린다`,
      ).not.toMatch(LOCALE_WORDS);
    }
  });
});
