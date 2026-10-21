// e2e:allow 실물 DynamicRenderer·ActionDispatcher·G7Core.state.setLocal 로 「입력 → 취소 → 다시 열기」 를 실행해 잰다 — 브라우저 축은 재실측(이커머스 통화 추가 줄)이 덮는다
/**
 * 자동바인딩 칸의 setLocal 덮어쓰기 기록이 명시적 초기화를 이기던 결함 (7.1.1 출시 전 실측)
 *
 * 자동바인딩 칸은 입력을 `G7Core.state.setLocal(patch, { render: false })` 로 저장소 B 에 쓴다. setLocal 은 쓴 경로를
 * `__g7SetLocalOverrideKeys` 에 누적하고, 이 기록은 `dataContext._local` 이 바뀌는 렌더에서만 지워진다 — render:false 라
 * 그 렌더가 오지 않는다. 이후 단추의 명시적 setState 마다 `handleLocalSetState` 가 그 기록을 `effectivePrev` 에 다시 얹어,
 * 같은 sequence 의 `form.newCurrency = null` 뒤 객체 setState 에서 앞 입력이 되살아났다(deep 병합 `name: {}` 로는 못 지움).
 * 명시적 setState 가 쓰는 경로는 그 기록에서 지운다 — 사용자가 고른 초기화가 앞 입력을 이긴다.
 *
 * @effects explicit_set_state_beats_set_local_override, untouched_override_paths_kept
 */
// @scenario write_path=explicit_set_state, overridden=same_path
// @scenario write_path=explicit_set_state, overridden=other_path

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { fireEvent, screen, act } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from './utils/layoutTestUtils';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';

async function flush(ms = 400) {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, ms));
  });
}

/** 하네스 G7Core 에 실물 setLocal 을 꽂고, 저장소 B 는 TemplateApp 계약(최상위 얕은 병합)대로 둔다 */
function installRealSetLocal(local: Record<string, any>) {
  let storeB: Record<string, any> = { _local: JSON.parse(JSON.stringify(local)) };
  (window as any).__templateApp = {
    getGlobalState: () => storeB,
    setGlobalState: (updates: Record<string, any>) => {
      storeB = { ...storeB, ...updates };
    },
  };
  const harnessG7Core = (window as any).G7Core;
  initializeG7CoreGlobals({
    getState: () => ({}),
    transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
    responsiveManager: {},
    webSocketManager: { subscribe: () => 's', unsubscribe: () => {}, leaveChannel: () => {}, disconnect: () => {}, isInitialized: () => true, getSubscriptionCount: () => 0 },
  } as any);
  const realSetLocal = (window as any).G7Core.state.setLocal;
  (window as any).G7Core = harnessG7Core;
  (window as any).G7Core.state.setLocal = realSetLocal;

  return () => storeB;
}

const layout = {
  version: '1.0.0',
  layout_name: 'test/set-local-override-explicit-reset',
  components: [
    {
      id: 'form_root',
      type: 'basic',
      name: 'Div',
      dataKey: 'form',
      children: [
        {
          id: 'add',
          type: 'basic',
          name: 'Button',
          props: { 'data-testid': 'add' },
          text: 'add',
          actions: [
            {
              type: 'click',
              handler: 'sequence',
              actions: [
                { handler: 'setState', params: { target: 'local', 'form.newCurrency': null } },
                { handler: 'setState', params: { target: 'local', isAdding: true, 'form.newCurrency': { code: '', name: {} } } },
              ],
            },
          ],
        },
        {
          id: 'row',
          type: 'basic',
          name: 'Div',
          if: '{{_local.isAdding}}',
          children: [
            { id: 'name_ko', type: 'basic', name: 'Input', props: { name: 'newCurrency.name.ko', 'data-testid': 'name-ko' } },
            { id: 'memo', type: 'basic', name: 'Input', props: { name: 'memo', 'data-testid': 'memo' } },
            {
              id: 'cancel',
              type: 'basic',
              name: 'Button',
              props: { 'data-testid': 'cancel' },
              text: 'cancel',
              actions: [{ type: 'click', handler: 'setState', params: { target: 'local', isAdding: false } }],
            },
          ],
        },
      ],
    },
  ],
};

describe('자동바인딩 setLocal 덮어쓰기 기록 vs 명시적 초기화', () => {
  afterEach(() => {
    delete (window as any).__templateApp;
    delete (window as any).__g7SetLocalOverrideKeys;
    delete (window as any).__g7ForcedLocalFields;
    delete (window as any).__g7PendingLocalState;
  });

  // @scenario write_path=explicit_set_state, overridden=same_path
  // @scenario write_path=explicit_set_state, overridden=other_path
  // @effects explicit_set_state_beats_set_local_override, untouched_override_paths_kept
  it('입력 → 취소 → 다시 열기 하면 초기화한 칸은 비고, 초기화하지 않은 칸의 입력은 남는다', async () => {
    const local = { form: { memo: '' } };
    const utils = createLayoutTest(layout as any, {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
      locale: 'ko',
      initialState: { _global: { _local: local }, _local: local },
    });
    await utils.render();
    (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });
    installRealSetLocal(local);

    fireEvent.click(screen.getByTestId('add'));
    await flush();
    fireEvent.change(screen.getByTestId('name-ko'), { target: { value: '원화' } });
    fireEvent.change(screen.getByTestId('memo'), { target: { value: '메모' } });
    await flush();
    expect((screen.getByTestId('name-ko') as HTMLInputElement).value).toBe('원화');

    fireEvent.click(screen.getByTestId('cancel'));
    await flush();
    expect(screen.queryByTestId('name-ko')).toBeNull();

    fireEvent.click(screen.getByTestId('add'));
    await flush();

    // 종전: 덮어쓰기 기록이 null 초기화 뒤 객체 setState 에서 다시 얹혀 「원화」 가 되살아났다
    expect((screen.getByTestId('name-ko') as HTMLInputElement).value, '초기화한 칸').toBe('');
    // 명시적 setState 가 쓰지 않은 경로(memo)의 setLocal 기록·값은 그대로다
    expect((screen.getByTestId('memo') as HTMLInputElement).value, '초기화하지 않은 칸').toBe('메모');
    utils.cleanup();
  });
});
