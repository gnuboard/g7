/**
 * @file productFormCreateModeReadOnly.test.tsx
 * @description 상품 등록 화면도 등록 권한이 없으면 입력칸까지 잠긴다 (7.1.0 abilities 게이트 products#3)
 *
 * 「상품 등록」 권한이 없는 운영자가 상품 관리 > [상품 등록](복사 진입 포함)에 들어오면 [저장]만 잠기고
 * 입력칸 약 96곳은 그대로 입력되던 결함의 회귀 테스트다. 수정 모드는 이미 수정 권한이 없으면 입력칸까지
 * 잠겼으므로, 폼 최상위 계산값 `_computed.isReadOnly` 하나가 「수정 모드면 can_update, 등록 모드면 can_create」
 * 를 보게 하고 모든 입력·버튼·업로더·에디터·옵션 추가/삭제·드래그 손잡이가 그 값을 따른다.
 * 권한 응답이 아직 없을 때(로딩 중·실패)는 잠긴 쪽으로 시작한다.
 *
 * @vitest-environment jsdom
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import shell from '../../../layouts/admin/admin_ecommerce_product_form.json';
import basicInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_basic_info.json';
import salesInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_sales_info.json';
import commonInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_common_info.json';
import otherInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_other_info.json';
import identification from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_identification_codes.json';

type Node = Record<string, any>;

const PARTIAL_DIR = path.resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_product_form');

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

/**
 * 프로브로 그릴 대표 컨트롤. 렌더 결과에서 찾을 수 있게 data-testid 를 붙인 사본을 쓴다.
 */
const PROBES: Array<{ testid: string; node: Node }> = [
  { testid: 'probe-brand', node: must(basicInfo, (n) => n.name === 'Select' && n.props?.name === 'brand_id', 'brand_id select') },
  { testid: 'probe-list-price', node: must(salesInfo, (n) => n.name === 'Input' && n.props?.name === 'list_price', 'list_price input') },
  { testid: 'probe-sku', node: must(identification, (n) => n.name === 'Input' && n.props?.name === 'sku', 'sku input') },
  { testid: 'probe-min-qty', node: must(otherInfo, (n) => n.name === 'Input' && n.props?.name === 'min_purchase_qty', 'min_purchase_qty input') },
  { testid: 'probe-common-info-none', node: must(commonInfo, (n) => n.id === 'common_info_none_button', 'common_info_none_button') },
  { testid: 'probe-save', node: must(shell, (n) => n.id === 'footer_save_button', 'footer_save_button') },
];

/** 등록 모드에서만 보이는 [코드 생성] 버튼 */
const GENERATE_CODE = must(
  basicInfo,
  (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('sirsoft-ecommerce.generateProductCode'),
  '[코드 생성] 버튼',
);

/**
 * 대표 컨트롤만 담은 프로브 레이아웃. 데이터 소스와 computed 는 셸에서 그대로 가져온다 —
 * 사본을 두면 셸이 바뀌어도 이 테스트만 옛 계약으로 통과한다.
 *
 * @param sources 포함할 데이터 소스 id
 * @param extra 추가로 그릴 노드
 * @returns 레이아웃 JSON
 */
function probe(sources: string[], extra: Array<{ testid: string; node: Node }> = []): Node {
  const components = [...PROBES, ...extra].map(({ testid, node }) => {
    const { children: _children, actions: _actions, ...rest } = node;

    return { ...rest, id: testid, props: { ...(node.props ?? {}), 'data-testid': testid } };
  });

  return {
    version: '1.0.0',
    layout_name: 'test/product-form-create-mode-readonly',
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
  itemCode?: string;
  canCreate?: boolean | 'missing' | 'error';
  canUpdate?: boolean;
}): Promise<() => void> {
  const editMode = !!options.itemCode;
  const layout = probe(editMode ? ['product', 'product_abilities'] : ['product_abilities'], editMode ? [] : [{ testid: 'probe-generate-code', node: GENERATE_CODE }]);
  const utils = createLayoutTest(layout as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    routeParams: editMode ? { itemCode: options.itemCode as string } : {},
    initialData: { _computedDefinitions: (shell as Node).computed },
    initialState: { _local: { form: {}, ui: {}, errors: null } },
  });

  if (options.canCreate === 'error') {
    utils.mockApiError('product_abilities', 500, 'server error');
  } else {
    const abilities = options.canCreate === 'missing' ? undefined : { can_create: options.canCreate };
    utils.mockApi('product_abilities', { response: { data: { data: [], ...(abilities ? { abilities } : {}) } } });
  }
  if (editMode) {
    utils.mockApi('product', { response: { data: { id: 1, product_code: options.itemCode, abilities: { can_update: options.canUpdate } } } });
  }

  await utils.render();

  return () => utils.cleanup();
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});

describe('products#3 — 등록 모드도 등록 권한이 없으면 입력칸까지 잠근다', () => {
  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_product_form_create_mode_inputs_locked
   */
  it('폼 최상위 계산값 isReadOnly 가 수정 모드면 can_update, 등록 모드면 can_create 를 본다', () => {
    const expr = String((shell as Node).computed?.isReadOnly ?? '');

    expect(expr).toContain('route.itemCode');
    expect(expr).toContain('product?.data?.abilities?.can_update !== true');
    expect(expr).toContain('product_abilities?.data?.abilities?.can_create !== true');
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_product_form_create_mode_inputs_locked
   */
  it('구획 어디에도 등록 모드를 잠금에서 빼 주는 수정 모드 전용 식이 남아 있지 않다', () => {
    const files = fs.readdirSync(PARTIAL_DIR).filter((f) => f.startsWith('_partial_') && f.endsWith('.json'));
    const leftovers = files.filter((f) => {
      const text = fs.readFileSync(path.join(PARTIAL_DIR, f), 'utf-8');

      return text.includes('route.itemCode && product?.data?.abilities?.can_update')
        || text.includes('!route.itemCode || product?.data?.abilities?.can_update');
    });

    expect(leftovers).toEqual([]);
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_product_form_create_mode_inputs_locked
   */
  it('등록 모드에서만 보이는 [코드 생성] 버튼도 같은 값으로 잠긴다', () => {
    expect(String(GENERATE_CODE.if ?? '')).toContain('!route.itemCode');
    expect(String(GENERATE_CODE.props?.disabled ?? '')).toContain('_computed.isReadOnly');
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_product_form_create_mode_inputs_locked
   */
  it('등록 모드 + can_create=false 면 대표 입력·버튼·[코드 생성]·[저장]이 모두 잠긴다', async () => {
    const cleanup = await renderProbe({ canCreate: false });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(true);
    }
    expect(disabledOf('probe-generate-code')).toBe(true);

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=granted
   * @effects admin_product_form_create_mode_inputs_open
   */
  it('등록 모드 + can_create=true 면 입력칸이 열린다', async () => {
    const cleanup = await renderProbe({ canCreate: true });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(false);
    }
    expect(disabledOf('probe-generate-code')).toBe(false);

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_product_form_create_mode_fail_closed
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
   * @effects admin_product_form_edit_mode_follows_update
   */
  it('수정 모드는 종전대로 can_update 만 본다 — 등록 권한이 없어도 수정 권한이 있으면 열린다', async () => {
    const cleanup = await renderProbe({ itemCode: 'P0001', canUpdate: true, canCreate: false });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(false);
    }

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_product_form_edit_mode_follows_update
   */
  it('수정 모드에서 수정 권한이 확인되지 않으면 등록 권한이 있어도 잠긴다', async () => {
    const cleanup = await renderProbe({ itemCode: 'P0001', canUpdate: undefined, canCreate: true });

    for (const { testid } of PROBES) {
      expect(disabledOf(testid), testid).toBe(true);
    }

    cleanup();
  });
});
