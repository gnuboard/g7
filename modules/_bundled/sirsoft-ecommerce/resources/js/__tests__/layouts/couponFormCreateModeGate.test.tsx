/**
 * @file couponFormCreateModeGate.test.tsx
 * @description 쿠폰 등록 화면도 등록 권한이 없으면 폼을 열지 않는다 (제품 결정 2026-10-10, 수정 화면과 같은 기준)
 *
 * 「쿠폰 등록」 권한이 없는 운영자가 주소(/admin/ecommerce/promotion-coupon-create)로 들어오면 입력칸과 [저장]이
 * 모두 열려 있고 저장할 때서야 서버가 403 을 내던 결함의 회귀 테스트다. 쿠폰 폼의 수정 진입은 이미 수정 권한이
 * 없으면 화면을 403 으로 접으므로(입력칸 자체가 그려지지 않는다), 등록 진입도 같은 기준으로 접는다 — 목록 응답의
 * 컬렉션 abilities 를 받는 `coupon_abilities` 가 can_create=false 면 403 화면, 실패해도 오류 화면이다. 응답을
 * 받기 전에는 폼을 그리지 않고(blocking), [저장]은 폼 읽기 전용 계산값 `isReadOnly` 하나를 따라 응답 키가
 * 빠져도 잠긴 쪽이다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import shell from '../../../layouts/admin/admin_ecommerce_promotion_coupon_form.json';

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

/**
 * 셸의 데이터 소스 하나를 꼭 찾는다.
 *
 * @param id 데이터 소스 id
 * @returns 데이터 소스 정의
 */
function dataSource(id: string): Node {
  const found = ((shell as Node).data_sources ?? []).find((d: Node) => d.id === id);
  if (!found) throw new Error(`데이터 소스가 없습니다: ${id}`);

  return found;
}

const SAVE = must(shell, (n) => n.id === 'save_button_top', 'save_button_top');

/**
 * [저장] 하나만 담은 프로브 레이아웃. 데이터 소스와 computed 는 셸에서 그대로 가져온다.
 *
 * @param sources 포함할 데이터 소스 id
 * @returns 레이아웃 JSON
 */
function probe(sources: string[]): Node {
  const { children: _children, actions: _actions, ...rest } = SAVE;
  // 오류 화면 전환은 이 프로브의 관심사가 아니다 — 응답 데이터와 [저장] 잠금만 본다
  const sourcesOnly = ((shell as Node).data_sources ?? [])
    .filter((d: Node) => sources.includes(d.id))
    .map(({ errorHandling: _eh, errorCondition: _ec, ...d }: Node) => d);

  return {
    version: '1.0.0',
    layout_name: 'test/coupon-form-create-mode-gate',
    data_sources: sourcesOnly,
    computed: (shell as Node).computed,
    components: [{ ...rest, id: 'probe-save', props: { ...(SAVE.props ?? {}), 'data-testid': 'probe-save' } }],
  };
}

/**
 * [저장]의 disabled 상태를 읽는다.
 *
 * @returns disabled 여부
 */
function saveDisabled(): boolean {
  const el = document.querySelector('[data-testid="probe-save"]') as HTMLButtonElement | null;
  if (!el) throw new Error('렌더되지 않았습니다: probe-save');

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
  const utils = createLayoutTest(probe(editMode ? ['coupon', 'coupon_abilities'] : ['coupon_abilities']) as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    routeParams: editMode ? { id: options.id as string } : {},
    initialData: { _computedDefinitions: (shell as Node).computed },
    initialState: { _local: { form: {}, isSaving: false, errors: null } },
  });

  if (options.canCreate === 'error') {
    utils.mockApiError('coupon_abilities', 500, 'server error');
  } else {
    const abilities = options.canCreate === 'missing' ? undefined : { can_create: options.canCreate };
    utils.mockApi('coupon_abilities', { response: { data: { data: [], ...(abilities ? { abilities } : {}) } } });
  }
  if (editMode) {
    utils.mockApi('coupon', { response: { data: { id: Number(options.id), abilities: { can_update: options.canUpdate } } } });
  }

  await utils.render();

  return () => utils.cleanup();
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});

describe('쿠폰 등록 모드도 등록 권한이 없으면 폼을 열지 않는다', () => {
  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_coupon_form_create_mode_folds_to_403
   */
  it('등록 진입 전용 권한 확인 소스가 목록 API 를 부르고, can_create=false 면 403 화면으로 접는다', () => {
    const ds = dataSource('coupon_abilities');

    expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/admin/promotion-coupons');
    expect(String(ds.if ?? '')).toContain('!route.id');
    expect(ds.loading_strategy).toBe('blocking');
    expect(String(ds.errorCondition?.if ?? '')).toBe('{{response?.data?.abilities?.can_create === false}}');
    expect(ds.errorCondition?.errorCode).toBe(403);

    const handling = JSON.stringify(ds.errorHandling ?? {});
    expect(handling).toContain('"403"');
    expect(handling).toContain('"default"');
    expect(handling).toContain('showErrorPage');
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_coupon_form_create_mode_folds_to_403
   */
  it('폼 읽기 전용 계산값이 수정 모드면 can_update, 등록 모드면 coupon_abilities 의 can_create 를 보고 [저장]이 그 값을 따른다', () => {
    const expr = String((shell as Node).computed?.isReadOnly ?? '');

    expect(expr).toContain('route.id');
    expect(expr).toContain('coupon?.data?.abilities?.can_update !== true');
    expect(expr).toContain('coupon_abilities?.data?.abilities?.can_create !== true');
    expect(String(SAVE.props?.disabled ?? '')).toContain('_computed.isReadOnly');
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_coupon_form_create_mode_folds_to_403
   */
  it('등록 모드 + can_create=false 면 [저장]이 잠긴다', async () => {
    const cleanup = await renderProbe({ canCreate: false });

    expect(saveDisabled()).toBe(true);

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=granted
   * @effects admin_coupon_form_create_mode_open
   */
  it('등록 모드 + can_create=true 면 [저장]이 열린다', async () => {
    const cleanup = await renderProbe({ canCreate: true });

    expect(saveDisabled()).toBe(false);

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_coupon_form_create_mode_fail_closed
   */
  it.each(['missing', 'error'] as const)('등록 모드에서 권한 응답이 없으면(%s) [저장]이 잠긴 쪽이다', async (state) => {
    const cleanup = await renderProbe({ canCreate: state });

    expect(saveDisabled()).toBe(true);

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=granted
   * @effects admin_coupon_form_edit_mode_follows_update
   */
  it('수정 모드는 종전대로 can_update 만 본다 — 등록 권한이 없어도 수정 권한이 있으면 열린다', async () => {
    const cleanup = await renderProbe({ id: '3', canUpdate: true, canCreate: false });

    expect(saveDisabled()).toBe(false);

    cleanup();
  });

  /**
   * @scenario surface=admin, permission=missing
   * @effects admin_coupon_form_edit_mode_follows_update
   */
  it('수정 모드에서 수정 권한이 확인되지 않으면 등록 권한이 있어도 잠긴다', async () => {
    const cleanup = await renderProbe({ id: '3', canUpdate: undefined, canCreate: true });

    expect(saveDisabled()).toBe(true);

    cleanup();
  });
});
