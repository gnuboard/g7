// e2e:allow 실물 DynamicRenderer 로 `_global` 자동바인딩 칸의 빠른 연속 입력을 잰다 — 브라우저 축은 재실측(배송지 창 받는 분 12ms 입력)이 덮는다
/**
 * `_global` 자동바인딩 칸에서 빠르게 치면 글자가 하나씩 빠지던 결함 (7.1.1 출시 전 실측 7.1.0 부터)
 *
 * `data-key="_global.*"` 폼의 칸은 입력을 `_globalSetState`(TemplateApp.setGlobalState)로 쓴다. 이 함수는 상태만 바로 바꾸고
 * 다시 그리기는 동적 import 뒤 `startTransition` 으로 미룬다. 사용자 이벤트 안에 바로 반영되는 React 갱신이 없으니 React 는
 * 이벤트 끝에 제어 입력을 직전 렌더 값으로 되돌리고, 다음 키가 미뤄진 렌더보다 먼저 오면 옛 값에 새 글자가 붙어 직전 글자가
 * 빠진다(배송지 창 「U1 & 받는이 ab cd」 → 「U1 & 받는 ab cd」, 12ms 에서 약 12%). `_local` 칸은 상태 소유 루트의 React 상태를
 * 바로 갱신해 되돌림이 없다.
 *
 * 그 칸만 표시용 대기 값으로 바로 다시 그리고(상태가 그 값이 되면 확정, 그사이 상태가 다른 값으로 바뀌면 버림) 상태 쓰기는
 * 그대로 둔다.
 *
 * @effects global_autobinding_fast_typing_keeps_every_char, external_reset_beats_pending_display_value
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, fireEvent, act, screen } from '@testing-library/react';
import DynamicRenderer, { type ComponentDefinition } from '../DynamicRenderer';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { createMockComponentRegistryWithBasics } from './utils/layoutTestUtils';

const TEXT = 'U1 & 받는이 ab cd';
const KEY_INTERVAL_MS = 12;
/** TemplateApp.setGlobalState 의 미뤄진 다시 그리기 — 키 간격보다 늦게 끝나는 무거운 화면을 결정적으로 만든다 */
const DEFERRED_RENDER_MS = 30;

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * sirsoft-basic 기본 Input 과 같은 값 처리 — 입력은 로컬 값으로 바로 보이고, value prop 이 바뀌면 로컬 값을 그 값으로 맞춘다.
 * 미뤄진 렌더가 중간 값(앞 키까지만 반영된 전역 상태)을 가져오면 이 동기화가 입력을 그 값으로 되돌린다 — 브라우저 실측의
 * 실제 손실 경로(제어 입력 복원이 아니라 늦은 중간 값 동기화).
 */
const LocalValueInput: React.FC<any> = ({ value, onChange, ...rest }) => {
  const [localValue, setLocalValue] = React.useState<string>(value ?? '');
  React.useEffect(() => {
    if (value !== undefined) setLocalValue(value as string);
  }, [value]);
  return React.createElement('input', {
    ...rest,
    value: localValue,
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
      setLocalValue(e.target.value);
      onChange?.(e);
    },
  });
};

/** `_global` 상태와 TemplateApp 계약대로의 `_globalSetState`(상태 즉시, 다시 그리기는 미룸)를 갖춘 렌더 하네스 */
function mountForm(
  dataKey: '_global.editingAddress' | 'editingAddress',
  debounce?: number,
  mode: 'autobinding' | 'controlled' = 'autobinding',
  options: { localValueInput?: boolean; upperCaseAction?: boolean } = {},
) {
  const registry = createMockComponentRegistryWithBasics() as any;
  if (options.localValueInput) {
    registry.register('basic', 'Input', LocalValueInput);
  }
  const bindingEngine = new DataBindingEngine();
  const translationEngine = new TranslationEngine();
  const actionDispatcher = new ActionDispatcher({});

  let globalState: Record<string, any> = { editingAddress: { recipient_name: '' } };
  let localState: Record<string, any> = { editingAddress: { recipient_name: '' } };
  let rerender: ((snapshot?: Record<string, any>) => void) | null = null;
  let pendingTimer: ReturnType<typeof setTimeout> | null = null;

  const scheduleRender = () => {
    // TemplateApp: import('./template-engine').then(updateTemplateData({ _global: {...this.globalState} }) → startTransition(root.render))
    // — 데이터는 then 시점에 떠 두고, 커밋은 그보다 늦다(무거운 화면). 렌더마다 따로 예약한다.
    void Promise.resolve().then(() => {
      const snapshot = globalState;
      pendingTimer = setTimeout(() => {
        pendingTimer = null;
        rerender?.(snapshot);
      }, DEFERRED_RENDER_MS);
    });
  };

  const setGlobal = (updates: any) => {
    const next = typeof updates === 'function' ? updates(globalState) : updates;
    globalState = { ...globalState, ...next };
    scheduleRender();
  };
  actionDispatcher.setGlobalStateUpdater((updates: any) => setGlobal(updates));
  // 쓰기 정본 — 운영의 TemplateApp.getGlobalState 와 같이 다시 그리기와 무관하게 즉시 최신 값을 돌려준다
  (window as any).__templateApp = { getGlobalState: () => ({ ...globalState }) };
  (window as any).G7Core = {
    state: {
      get: () => ({ ...globalState }),
      set: setGlobal,
      getLocal: () => localState,
      setLocal: (updates: any) => {
        localState = { ...localState, ...updates };
      },
    },
  };

  const controlledChildren = [
    {
      id: 'reason',
      type: 'basic',
      name: 'Input',
      props: { 'data-testid': 'recipient', value: "{{_global.editingAddress?.recipient_name ?? ''}}" },
      actions: [
        {
          type: 'change',
          handler: 'setState',
          params: { target: 'global', 'editingAddress.recipient_name': '{{$event.target.value}}' },
        },
      ],
    },
    {
      id: 'reset',
      type: 'basic',
      name: 'Button',
      props: { 'data-testid': 'reset' },
      text: 'reset',
      actions: [{ type: 'click', handler: 'setState', params: { target: 'global', 'editingAddress.recipient_name': '' } }],
    },
  ];

  const componentDef: ComponentDefinition = mode === 'controlled' ? {
    id: 'root',
    type: 'layout',
    name: 'Fragment',
    children: [{ id: 'report_box', type: 'basic', name: 'Div', children: controlledChildren } as any],
  } as any : {
    id: 'root',
    type: 'layout',
    name: 'Fragment',
    children: [
      {
        id: 'address_form',
        type: 'basic',
        name: 'Div',
        dataKey,
        ...(debounce ? { debounce } : {}),
        children: [
          {
            id: 'recipient', type: 'basic', name: 'Input', props: { name: 'recipient_name', 'data-testid': 'recipient' },
            // 같은 경로에 가공한 값을 다시 쓰는 change 동작(자동바인딩 쓰기 뒤에 돈다)
            ...(options.upperCaseAction ? { actions: [{ type: 'change', handler: 'setState', params: { target: 'global', 'editingAddress.recipient_name': '{{$event.target.value.toUpperCase()}}' } }] } : {}),
          },
          {
            id: 'reset',
            type: 'basic',
            name: 'Button',
            props: { 'data-testid': 'reset' },
            text: 'reset',
            actions: [
              {
                type: 'click',
                handler: 'setState',
                params: dataKey.startsWith('_global')
                  ? { target: 'global', 'editingAddress.recipient_name': '' }
                  : { target: 'local', 'editingAddress.recipient_name': '' },
              },
            ],
          },
        ],
      } as any,
    ],
  } as any;

  const element = (globalSnapshot: Record<string, any> = globalState) =>
    React.createElement(DynamicRenderer, {
      componentDef,
      dataContext: { _global: globalSnapshot, _local: localState, _globalSetState: setGlobal },
      translationContext: { templateId: 't', locale: 'ko' },
      registry,
      bindingEngine,
      translationEngine,
      actionDispatcher,
    });

  const utils = render(element());
  rerender = (snapshot?: Record<string, any>) => utils.rerender(element(snapshot ?? globalState));

  return {
    input: () => screen.getByTestId('recipient') as HTMLInputElement,
    global: () => globalState,
    local: () => localState,
  };
}

/** 실제 키 입력처럼 지금 DOM 값에 한 글자를 붙여 change 를 낸다(되돌려졌으면 되돌려진 값에 붙는다) */
async function typeFast(input: () => HTMLInputElement, text: string) {
  for (const ch of text) {
    await act(async () => {
      fireEvent.change(input(), { target: { value: input().value + ch } });
    });
    await act(async () => {
      await sleep(KEY_INTERVAL_MS);
    });
  }
  await act(async () => {
    await sleep(DEFERRED_RENDER_MS * 3);
  });
}

describe('[case:state-setstate-48] `_global` 자동바인딩 칸의 빠른 연속 입력', () => {
  beforeEach(() => {
    (window as any).__g7PendingLocalState = undefined;
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__templateApp;
    (window as any).__g7PendingLocalState = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7ForcedLocalFields = undefined;
  });

  // @scenario store=global, typing=fast_12ms
  // @effects global_autobinding_fast_typing_keeps_every_char
  it('12ms 간격으로 쳐도 글자가 빠지지 않는다(다시 그리기가 키 간격보다 늦을 때)', async () => {
    const form = mountForm('_global.editingAddress');
    await typeFast(form.input, TEXT);

    // 종전: 「U1 & 받는 ab cd」처럼 직전 글자가 빠졌다
    expect(form.input().value).toBe(TEXT);
    expect(form.global().editingAddress.recipient_name).toBe(TEXT);
  });

  // @scenario store=local, typing=fast_12ms
  // @effects global_autobinding_fast_typing_keeps_every_char
  it('대조군 — `_local` 칸은 종전부터 빠지지 않는다', async () => {
    const form = mountForm('editingAddress');
    await typeFast(form.input, TEXT);

    expect(form.input().value).toBe(TEXT);
  });

  // @scenario store=global, typing=external_reset
  // @effects external_reset_beats_pending_display_value
  it('입력 중 단추로 그 경로를 비우면 대기 값이 아니라 빈 값이 보인다', async () => {
    const form = mountForm('_global.editingAddress');
    await typeFast(form.input, 'abc');
    expect(form.input().value).toBe('abc');

    // 마지막 키 직후(다시 그리기 전) 외부 초기화
    await act(async () => {
      fireEvent.change(form.input(), { target: { value: form.input().value + 'd' } });
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId('reset'));
    });
    await act(async () => {
      await sleep(DEFERRED_RENDER_MS * 3);
    });

    expect(form.global().editingAddress.recipient_name).toBe('');
    expect(form.input().value).toBe('');
  });
  // @scenario store=global, typing=external_reset_to_baseline
  // @effects external_reset_beats_pending_display_value
  it('빈 칸에서 친 직후(다시 그리기 전) 입력 전 값(빈 값)으로 되돌리면 대기 값이 남지 않는다', async () => {
    const form = mountForm('_global.editingAddress');
    // 다시 그리기가 오기 전에 두 글자와 초기화가 모두 일어난다 — 상태가 입력 전 값(baseline)과 같아진다
    await act(async () => {
      fireEvent.change(form.input(), { target: { value: 'a' } });
      fireEvent.change(form.input(), { target: { value: 'ab' } });
      fireEvent.click(screen.getByTestId('reset'));
    });
    await act(async () => {
      await sleep(DEFERRED_RENDER_MS * 4);
    });

    expect(form.global().editingAddress.recipient_name).toBe('');
    // 종전: 상태가 baseline 과 같아 「미뤄진 렌더가 아직 안 옴」 으로 보고 'ab' 를 시한 없이 계속 보였다(화면 ≠ 상태)
    expect(form.input().value).toBe('');
  });

  // @scenario store=global, typing=fast_12ms
  // @effects global_autobinding_fast_typing_keeps_every_char
  it('같은 칸에 가공한 값을 다시 쓰는 change 동작(대문자)이 붙어도 정본이 가공 값이면 대기 값을 바깥 초기화로 버리지 않고, 끝에 화면 = 상태다', async () => {
    // 정본 비교를 「정본 ≠ 대기 값」 으로만 하면 가공 값마다 대기 값을 버려 손실 모양이 바뀐다(실측 'ACEGH').
    // 「정본이 입력 전 값·이 칸이 쓴 중간 값으로 돌아감」 일 때만 버려 engine-v1.80.1 과 같은 결과(실측 'ABDFH' — 가공 동작이 붙은
    // 칸의 손실은 수정 범위 밖의 종전 한계, 결과 파일 「못 고친 것」)를 낸다. 여기서는 그 갈래가 화면 ≠ 상태로 남지 않음을 잠근다.
    const form = mountForm('_global.editingAddress', undefined, 'autobinding', { upperCaseAction: true });
    await typeFast(form.input, 'abcdefgh');

    const state = form.global().editingAddress.recipient_name as string;
    expect(state).toBe(state.toUpperCase());
    expect(form.input().value).toBe(state);
  });

  // @scenario store=global, typing=external_reset_to_baseline
  // @effects external_reset_beats_pending_display_value
  it('제어 입력(갈래 D)은 같은 경우 대기 값 시한(1초) 안에 상태 값으로 돌아간다 — 동작이 디바운스·비동기·값 가공일 수 있어 정본 비교를 하지 않는다', async () => {
    const form = mountForm('_global.editingAddress', undefined, 'controlled');
    await act(async () => {
      fireEvent.change(form.input(), { target: { value: 'a' } });
      fireEvent.change(form.input(), { target: { value: 'ab' } });
      fireEvent.click(screen.getByTestId('reset'));
    });
    await act(async () => {
      await sleep(1200);
    });

    expect(form.global().editingAddress.recipient_name).toBe('');
    expect(form.input().value).toBe('');
  });

  // @scenario store=global, typing=debounced
  // @effects global_autobinding_fast_typing_keeps_every_char
  it('디바운스가 있는 `_global` 칸도 빠지지 않고, 상태는 디바운스 뒤 전체 값이 된다(디바운스 대기 값과 분리)', async () => {
    const form = mountForm('_global.editingAddress', 80);
    await typeFast(form.input, TEXT);
    await act(async () => {
      await sleep(200);
    });

    expect(form.input().value).toBe(TEXT);
    expect(form.global().editingAddress.recipient_name).toBe(TEXT);
  });
  // @scenario store=global, typing=fast_12ms
  // @effects global_autobinding_fast_typing_keeps_every_char
  it('`value="{{_global…}}"` + 전역 setState 제어 입력(신고 창 꼴)도 12ms 간격에서 글자가 빠지지 않는다', async () => {
    const form = mountForm('_global.editingAddress', undefined, 'controlled');
    await typeFast(form.input, TEXT);

    expect(form.input().value).toBe(TEXT);
    expect(form.global().editingAddress.recipient_name).toBe(TEXT);
  });

  // @scenario store=global, typing=external_reset
  // @effects external_reset_beats_pending_display_value
  it('제어 입력도 입력 중 외부 초기화가 대기 값을 이긴다', async () => {
    const form = mountForm('_global.editingAddress', undefined, 'controlled');
    await typeFast(form.input, 'abc');
    await act(async () => {
      fireEvent.change(form.input(), { target: { value: form.input().value + 'd' } });
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId('reset'));
    });
    await act(async () => {
      await sleep(DEFERRED_RENDER_MS * 3);
    });

    expect(form.global().editingAddress.recipient_name).toBe('');
    expect(form.input().value).toBe('');
  });
  // @scenario store=global, typing=fast_12ms
  // @effects global_autobinding_fast_typing_keeps_every_char
  it.each(['autobinding', 'controlled'] as const)(
    '로컬 값을 가진 Input(sirsoft-basic 꼴)도 늦게 온 중간 값에 되돌려지지 않는다 — %s',
    async (mode) => {
      const form = mountForm('_global.editingAddress', undefined, mode, { localValueInput: true });
      await typeFast(form.input, TEXT);

      expect(form.input().value).toBe(TEXT);
      expect(form.global().editingAddress.recipient_name).toBe(TEXT);
    },
  );
});
