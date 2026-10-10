/**
 * @file shippingPolicyFormCreateModeReadOnly.test.tsx
 * @description 배송정책 등록 화면도 등록 권한이 없으면 입력칸까지 잠긴다 (제품 결정 2026-10-10, 상품 폼과 같은 기준)
 *
 * 「배송정책 등록」 권한이 없는 운영자가 주소로 배송정책 등록(복사 진입 포함) 화면에 들어오면 입력칸과 [저장]이
 * 모두 열려 있고 저장할 때서야 서버가 403 을 내던 결함(7.1.0 통합 브라우저 실측)의 회귀 테스트다. 폼 최상위
 * 계산값 `_computed.isReadOnly` 가 수정 진입이면 정책 응답의 can_update, 등록·복사 진입이면 목록 컬렉션
 * abilities 의 can_create(`policy_abilities`)를 보고, 구획의 입력·버튼과 [저장]이 그 값 하나를 따른다.
 * 권한 응답이 아직 없을 때(로딩 중·실패)는 잠긴 쪽으로 시작한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import shell from '../../../layouts/admin/admin_ecommerce_shipping_policy_form.json';
import chargeSettings from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.json';
import countryBasic from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_country_basic_fields.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 첫 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @returns 찾은 노드
 */
function find(node: unknown, predicate: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = find(child, predicate);
      if (found) return found;
    }

    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  if (predicate(node as Node)) return node as Node;

  for (const value of Object.values(node as Node)) {
    const found = find(value, predicate);
    if (found) return found;
  }

  return undefined;
}

/**
 * 노드를 꼭 찾는다(없으면 테스트가 레이아웃 변경을 알린다).
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param label 실패 메시지용 이름
 * @returns 찾은 노드
 */
function must(node: unknown, predicate: (n: Node) => boolean, label: string): Node {
  const found = find(node, predicate);
  if (!found) throw new Error(`노드를 찾지 못했습니다: ${label}`);

  return found;
}

/** 값 식에 특정 칸 이름이 들어간 컨트롤을 찾는 조건 */
const valueOf = (name: string, field: string) => (n: Node) =>
  n.name === name && String(n.props?.value ?? '').includes(field);

/**
 * 프로브로 그릴 대표 컨트롤. 렌더 결과에서 찾을 수 있게 data-testid 를 붙인 사본을 쓴다.
 */
const PROBES: Array<{ testid: string; node: Node }> = [
  { testid: 'probe-shipping-method', node: must(countryBasic, valueOf('Select', 'shipping_method'), 'shipping_method select') },
  { testid: 'probe-charge-policy', node: must(chargeSettings, valueOf('Select', 'charge_policy'), 'charge_policy select') },
  { testid: 'probe-base-fee', node: must(chargeSettings, valueOf('Input', 'base_fee'), 'base_fee input') },
  { testid: 'probe-free-threshold', node: must(chargeSettings, valueOf('Input', 'free_threshold'), 'free_threshold input') },
  { testid: 'probe-save', node: must(shell, (n) => n.id === 'footer_save_button', 'footer_save_button') },
];

/**
 * 대표 컨트롤만 담은 프로브 레이아웃. 데이터 소스와 computed 는 셸에서 그대로 가져온다 —
 * 사본을 두면 셸이 바뀌어도 이 테스트만 옛 계약으로 통과한다.
 *
 * @param sources 포함할 데이터 소스 id
 * @returns 레이아웃 JSON
 */
function probe(sources: string[]): Node {
  const components = PROBES.map(({ testid, node }) => {
    const { children: _children, actions: _actions, ...rest } = node;

    return { ...rest, id: testid, props: { ...(node.props ?? {}), 'data-testid': testid } };
  });

  return {
    version: '1.0.0',
    layout_name: 'test/shipping-policy-form-create-mode-readonly',
    data_sources: ((shell as Node).data_sources ?? []).filter((d: Node) => sources.includes(d.id)),
    computed: (shell as Node).computed,
    components,
  };
}

/**
 * 프로브 컨트롤의 disabled 상태를 읽는다.
 *
 * @param testid 프로브 testid
 * @returns disabled 여부
 */
function disabledOf(testid: string): boolean {
  const el = document.querySelector(`[data-testid="${testid}"]`) as HTMLInputElement | null;
  if (!el) throw new Error(`렌더되지 않았습니다: ${testid}`);

  return el.disabled;
}

/**
 * 프로브를 그린다.
 *
 * @param options 진입 모드와 응답
 * @returns 정리 함수
 */
async function renderProbe(options: {
  id?: string;
  canCreate?: boolean | 'missing' | 'error';
  canUpdate?: boolean;
}): Promise<() => void> {
  const editMode = !!options.id;
  const layout = probe(editMode ? ['policy', 'policy_abilities'] : ['policy_abilities']);
  const utils = createLayoutTest(layout as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    routeParams: editMode ? { id: options.id as string } : {},
    initialData: { _computedDefinitions: (shell as Node).computed },
    initialState: { _local: { form: { country_settings: [] }, errors: null } },
  });

  if (options.canCreate === 'error') {
    utils.mockApiError('policy_abilities', 500, 'server error');
  } else {
    const abilities = options.canCreate === 'missing' ? undefined : { can_create: options.canCreate };
    utils.mockApi('policy_abilities', { response: { data: { data: [], ...(abilities ? { abilities } : {}) } } });
  }
  if (editMode) {
    utils.mockApi('policy', {
      response: { data: { id: Number(options.id), country_settings: [], abilities: { can_update: options.canUpdate } } },
    });
  }

  await utils.render();

  return () => utils.cleanup();
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});

describe('배송정책 등록 모드도 등록 권한이 없으면 입력칸까지 잠근다', () => {
  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_shipping_policy_form_create_mode_inputs_locked
   */
  it('폼 최상위 계산값 isReadOnly 가 수정 모드면 can_update, 등록 모드면 policy_abilities 의 can_create 를 본다', () => {
    const expr = String((shell as Node).computed?.isReadOnly ?? '');

    expect(expr).toContain('route.id');
    expect(expr).toContain('policy?.data?.abilities?.can_update !== true');
    expect(expr).toContain('policy_abilities?.data?.abilities?.can_create !== true');
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_shipping_policy_form_create_mode_inputs_locked
   */
  it('[저장]이 수정 모드 전용 식이 아니라 폼 읽기 전용 계산값을 따른다', () => {
    const save = must(shell, (n) => n.id === 'footer_save_button', 'footer_save_button');

    expect(String(save.props?.disabled ?? '')).toContain('_computed.isReadOnly');
    expect(String(save.props?.disabled ?? '')).not.toContain('route.id && policy?.data?.abilities?.can_update');
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_shipping_policy_form_create_mode_inputs_locked
   */
  it('등록 모드 + can_create=false 면 대표 입력과 [저장]이 모두 잠긴다', async () => {
    const cleanup = await renderProbe({ canCreate: false });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(true);
    }

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=granted
   * @effects admin_shipping_policy_form_create_mode_inputs_open
   */
  it('등록 모드 + can_create=true 면 입력칸과 [저장]이 열린다', async () => {
    const cleanup = await renderProbe({ canCreate: true });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(false);
    }

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_shipping_policy_form_create_mode_fail_closed
   */
  it.each(['missing', 'error'] as const)('등록 모드에서 권한 응답이 없으면(%s) 잠긴 쪽으로 시작한다', async (state) => {
    const cleanup = await renderProbe({ canCreate: state });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(true);
    }

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=granted
   * @effects admin_shipping_policy_form_edit_mode_follows_update
   */
  it('수정 모드는 종전대로 can_update 만 본다 — 등록 권한이 없어도 수정 권한이 있으면 열린다', async () => {
    const cleanup = await renderProbe({ id: '7', canUpdate: true, canCreate: false });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(false);
    }

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_shipping_policy_form_edit_mode_follows_update
   */
  it('수정 모드에서 수정 권한이 확인되지 않으면 등록 권한이 있어도 잠긴다', async () => {
    const cleanup = await renderProbe({ id: '7', canUpdate: undefined, canCreate: true });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(true);
    }

    cleanup();
  });
});
