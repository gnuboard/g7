/**
 * @file adminEcommerceSettingsUnsavedTabChange.test.tsx
 * @description 쇼핑몰 환경설정 — 저장하지 않은 변경이 있을 때 탭을 옮기기 전에 확인 창 (S5-D41)
 *
 * 탭 전환은 같은 화면으로의 `navigate(replace)` 라 설정 소스(`refetchOnMount` + `initLocal`)가 다시
 * 받아 폼을 덮는다 — 저장하지 않은 값이 경고 없이 사라졌다(출시본 동일). 제품 결정 (a):
 *  1. 변경이 없으면 종전대로 바로 옮긴다.
 *  2. 변경이 있으면 옮기지 않고 확인 창을 연다 — 탭·주소는 그대로, 가려던 탭만 기억한다.
 *  3. 창 [이동] 은 기억한 탭으로 옮기고(변경 표시를 내린다), [머무르기] 는 아무것도 바꾸지 않는다.
 * 창은 자기 지역 상태를 따로 가지므로 [이동] 은 `$parent._local` 에 탭 줄 「변경 없음」 갈래와 같은 정리를
 * 한다 — 두 정리 목록이 어긋나면 S10 17차(열어 둔 인라인 폼 플래그가 남아 저장이 막힘)가 창 경로로만 되살아나므로
 * 키 일치를 단언한다. (사건 발행 + `<on listen>` 으로 한 벌만 두는 안은 엔진 onComponentEvent 가 sequence 를
 * 넘기지 않아 운영에서 아무것도 하지 않음을 이 테스트로 확인하고 버렸다.)
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import shell from '../../../layouts/admin/admin_ecommerce_settings.json';
import unsavedModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_unsaved_tab_change.json';

type Node = Record<string, any>;

const MODAL_ID = 'unsaved_tab_change_modal';

/** TabNavigation 목업 — 탭 버튼을 누르면 onTabChange(id) 를 부른다(실부품도 완전 제어형이다) */
const TestTabNavigation: React.FC<{
  tabs?: Array<{ id: string; label: string }>;
  activeTabId?: string;
  onTabChange?: (tabId: string) => void;
}> = ({ tabs, activeTabId, onTabChange }) => (
  <div data-testid="tab-navigation">
    {tabs?.map((tab) => (
      <button
        key={tab.id}
        data-testid={`tab-${tab.id}`}
        className={activeTabId === tab.id ? 'active' : ''}
        onClick={() => onTabChange?.(tab.id)}
      >
        {tab.label}
      </button>
    ))}
  </div>
);

/**
 * id 로 레이아웃 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 찾은 노드
 */
function byId(node: unknown, id: string): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = byId(child, id);
      if (found) return found;
    }

    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  if ((node as Node).id === id) return node as Node;

  for (const value of Object.values(node as Node)) {
    const found = byId(value, id);
    if (found) return found;
  }

  return undefined;
}

/**
 * 탭 이동 축만 프로브로 감싼다 — 탭 줄과 탭 본문 틀(자식 탭은 빼고). 노드는 셸에서 그대로 가져온다.
 *
 * @returns 레이아웃 JSON
 */
function probe(): Node {
  const tabNav = byId(shell, 'tab_navigation') as Node;
  const content = byId(shell, 'ecommerce_settings_tab_content') as Node;

  return {
    version: '1.0.0',
    layout_name: 'test/admin-ecommerce-settings-unsaved-tab-change',
    data_sources: [],
    components: [tabNav, { ...content, children: [] }].filter(Boolean),
  };
}

/**
 * 지금 열린 창 목록 — 이 조각의 `open-modal` 은 엔진 기본 처리기가 `_global.modalStack` 에 쌓는다.
 *
 * @param utils 테스트 유틸
 * @returns 창 id 목록
 */
function openModals(utils: ReturnType<typeof createLayoutTest>): string[] {
  return [...utils.getModalStack(), ...(utils.getState()._global.modalStack ?? [])];
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'TabNavigation', TestTabNavigation);
});

afterEach(() => {
  vi.clearAllMocks();
});

/**
 * 프로브를 렌더한다.
 *
 * @param hasChanges 저장하지 않은 변경 여부
 * @returns 테스트 유틸
 */
async function renderProbe(hasChanges: boolean) {
  const utils = createLayoutTest(probe() as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    initialState: {
      _local: { hasChanges, form: { basic_info: { shop_name: 'G7 스토어_변경' } } },
      _global: { activeEcommerceSettingsTab: 'basic_info' },
    },
  });
  await utils.render();

  return utils;
}

/**
 * 렌더 뒤 사건·상태 갱신을 한 박자 기다린다.
 *
 * @returns void
 */
async function flush(): Promise<void> {
  await new Promise((r) => setTimeout(r, 30));
}

describe('쇼핑몰 환경설정 — 저장하지 않은 변경이 있을 때 탭 이동 (S5-D41)', () => {
  /**
   * @scenario case=changes_pending
   * @effects settings_tab_change_with_changes_opens_confirm
   */
  it('확인 창 조각이 레이아웃 modals 에 걸려 있다', () => {
    const refs = ((shell as Node).modals ?? []).map((m: Node) => m.partial);

    expect(refs).toContain('partials/admin_ecommerce_settings/_modal_unsaved_tab_change.json');
    expect((unsavedModal as Node).id ?? byId(unsavedModal, MODAL_ID)?.id).toBe(MODAL_ID);
  });

  /**
   * @scenario case=no_changes
   * @effects settings_tab_change_without_changes_moves_immediately
   */
  it('변경이 없으면 창 없이 바로 그 탭으로 옮긴다', async () => {
    const utils = await renderProbe(false);

    await utils.user.click(screen.getByTestId('tab-order_settings'));
    await flush();

    expect(openModals(utils)).not.toContain(MODAL_ID);
    expect(utils.getState()._global.activeEcommerceSettingsTab).toBe('order_settings');
    expect(utils.getNavigationHistory().some((u) => u.includes('tab=order_settings'))).toBe(true);

    utils.cleanup();
  });

  /**
   * @scenario case=changes_pending
   * @effects settings_tab_change_with_changes_opens_confirm, settings_tab_change_with_changes_keeps_tab_and_values
   */
  it('변경이 있으면 옮기지 않고 확인 창을 연다 — 탭·주소·입력값은 그대로', async () => {
    const utils = await renderProbe(true);

    await utils.user.click(screen.getByTestId('tab-order_settings'));
    await flush();

    const state = utils.getState();
    expect(openModals(utils)).toContain(MODAL_ID);
    expect(state._global.activeEcommerceSettingsTab).toBe('basic_info');
    expect(state._global.pendingEcommerceSettingsTab).toBe('order_settings');
    expect(state._local.hasChanges).toBe(true);
    expect(state._local.form.basic_info.shop_name).toBe('G7 스토어_변경');
    expect(utils.getNavigationHistory().some((u) => u.includes('tab=order_settings'))).toBe(false);

    utils.cleanup();
  });

  /**
   * @scenario case=confirm_leave
   * @effects settings_tab_change_confirm_leave_moves_to_pending_tab
   */
  it('창 [이동] 은 부모 폼을 탭 줄과 같은 목록으로 정리하고 변경 표시를 내린 뒤 가려던 탭으로 옮긴다', () => {
    const actions = clickActions(unsavedModal, 'unsaved_tab_change_leave');
    const handlers = actions.map((a) => a.handler);

    expect(handlers).toEqual(['setState', 'setState', 'closeModal', 'navigate', 'setState']);

    const parentReset = actions[0].params;
    expect(parentReset.target).toBe('$parent._local');
    expect(parentReset.hasChanges).toBe(false);

    const { target: _t1, hasChanges: _h, ...modalKeys } = parentReset;
    const { target: _t2, ...tabBarKeys } = tabBarResetParams();
    expect(Object.keys(modalKeys).sort()).toEqual(Object.keys(tabBarKeys).sort());
    expect(modalKeys).toEqual(tabBarKeys);

    expect(actions[1].params).toEqual({ target: 'global', activeEcommerceSettingsTab: '{{_global.pendingEcommerceSettingsTab}}' });
    expect(actions[3].params.path).toBe('/admin/ecommerce/settings');
    expect(actions[3].params.replace).toBe(true);
    expect(actions[3].params.query).toEqual({ tab: '{{_global.pendingEcommerceSettingsTab}}' });
    expect(actions[4].params).toEqual({ target: 'global', pendingEcommerceSettingsTab: null });
  });

  /**
   * @scenario case=confirm_stay
   * @effects settings_tab_change_confirm_stay_keeps_everything
   */
  it('창 [머무르기] 는 가려던 탭만 잊고 닫는다 — 부모 폼·탭·주소는 건드리지 않는다', () => {
    const actions = clickActions(unsavedModal, 'unsaved_tab_change_stay');

    expect(actions.map((a) => a.handler)).toEqual(['setState', 'closeModal']);
    expect(actions[0].params).toEqual({ target: 'global', pendingEcommerceSettingsTab: null });
  });
});

/**
 * 버튼의 클릭 액션을 펼친다(sequence 면 그 안의 actions).
 *
 * @param root 조각
 * @param id 버튼 id
 * @returns 액션 목록
 */
function clickActions(root: unknown, id: string): Node[] {
  const click = (byId(root, id)?.actions ?? []).find((a: Node) => a.type === 'click');
  if (!click) return [];

  return click.handler === 'sequence' ? click.actions : [click];
}

/**
 * 탭 줄 「변경 없음」 갈래의 지역 상태 정리 params.
 *
 * @returns params
 */
function tabBarResetParams(): Node {
  const change = (byId(shell, 'tab_navigation')?.actions ?? []).find((a: Node) => a.event === 'onTabChange');
  const elseBranch = (change?.conditions ?? []).find((c: Node) => c.if === undefined);
  const seq = elseBranch?.then?.actions ?? [];

  return seq.find((a: Node) => a.handler === 'setState' && a.params?.target === 'local')?.params ?? {};
}
