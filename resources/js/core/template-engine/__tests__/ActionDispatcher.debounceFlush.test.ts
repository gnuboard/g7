/**
 * ActionDispatcher debounce flush 메커니즘 테스트
 *
 * 비디바운스 액션 실행 전 대기 중인 debounce 액션을 즉시 실행(flush)하여
 * state가 최신 상태가 되도록 보장하는 기능을 검증합니다.
 *
 * 버그 시나리오: MultilingualInput(debounce: 300)에 값 입력 후 300ms 이내에
 * 비디바운스 버튼(옵션 일괄 생성)을 클릭하면, 디바운스된 setState가 아직
 * 실행되지 않아 핸들러가 빈 값을 읽는 문제.
 *
 * @since engine-v1.20.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

// AuthManager mock
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1, name: 'Test User' }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

// ApiClient mock
const mockGetToken = vi.fn();
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: mockGetToken,
  })),
}));

describe('ActionDispatcher - debounce flush', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();
    mockNavigate = vi.fn();
    mockGetToken.mockReset();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    dispatcher.clearDebounceTimers();
    vi.useRealTimers();
    Logger.getInstance().setDebug(false);
  });

  // 헬퍼: mock change 이벤트 생성
  const createMockChangeEvent = (value?: string) => ({
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
    type: 'change',
    target: {
      value: value ?? '',
      name: 'testInput',
      tagName: 'INPUT',
      type: 'text',
    },
  } as unknown as Event);

  // 헬퍼: mock click 이벤트 생성
  const createClickEvent = () => ({
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
    type: 'click',
    target: null,
  } as unknown as Event);

  describe('flushPendingDebounceTimers', () => {
    it('대기 중인 debounce 액션이 없으면 아무것도 실행하지 않아야 함', () => {
      expect(() => dispatcher.flushPendingDebounceTimers()).not.toThrow();
    });

    it('대기 중인 trailing debounce 액션을 즉시 실행해야 함', () => {
      const executedHandlers: string[] = [];

      dispatcher.registerHandler('trackableHandler', () => {
        executedHandlers.push('trackableHandler');
      });

      // bindActionsToProps를 통해 debounce가 적용되는 액션 바인딩
      // componentId = props.name || props.id || 'unknown'
      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'trackableHandler',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // debounce 트리거 → 타이머 대기 상태
      boundProps.onChange(createMockChangeEvent('test-value'));

      // 아직 실행되지 않았어야 함
      expect(executedHandlers).toHaveLength(0);

      // flush 호출 → 즉시 실행
      dispatcher.flushPendingDebounceTimers();

      expect(executedHandlers).toHaveLength(1);
      expect(executedHandlers[0]).toBe('trackableHandler');
    });

    it('flush 후 타이머가 정리되어 중복 실행되지 않아야 함', () => {
      const executionCount = { value: 0 };

      dispatcher.registerHandler('countHandler', () => {
        executionCount.value++;
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'countHandler',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);
      boundProps.onChange(createMockChangeEvent('value'));

      // flush로 즉시 실행
      dispatcher.flushPendingDebounceTimers();
      expect(executionCount.value).toBe(1);

      // 원래 타이머 시간이 지나도 다시 실행되지 않아야 함
      vi.advanceTimersByTime(500);
      expect(executionCount.value).toBe(1);
    });

    it('여러 개의 대기 중인 debounce 액션을 모두 flush해야 함', () => {
      const executed: string[] = [];

      dispatcher.registerHandler('handlerA', () => {
        executed.push('A');
      });
      dispatcher.registerHandler('handlerB', () => {
        executed.push('B');
      });

      // 서로 다른 componentId(props.id)로 별도 바인딩 (다른 debounce key 생성)
      const propsA = {
        id: 'comp-A',
        actions: [
          { type: 'change' as const, handler: 'handlerA', debounce: 300 },
        ],
      };
      const propsB = {
        id: 'comp-B',
        actions: [
          { type: 'change' as const, handler: 'handlerB', debounce: 500 },
        ],
      };

      const boundA = dispatcher.bindActionsToProps(propsA);
      const boundB = dispatcher.bindActionsToProps(propsB);

      // 두 컴포넌트 모두 debounce 트리거
      boundA.onChange(createMockChangeEvent('valueA'));
      boundB.onChange(createMockChangeEvent('valueB'));

      expect(executed).toHaveLength(0);

      // flush → 모두 즉시 실행
      dispatcher.flushPendingDebounceTimers();

      expect(executed).toHaveLength(2);
      expect(executed).toContain('A');
      expect(executed).toContain('B');
    });

    it('leading+trailing 모드에서 trailing flush가 정상 동작해야 함', () => {
      const executionCount = { value: 0 };

      dispatcher.registerHandler('leadingTrailingHandler', () => {
        executionCount.value++;
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'leadingTrailingHandler',
            debounce: { delay: 300, leading: true, trailing: true },
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 첫 호출 → leading 즉시 실행
      boundProps.onChange(createMockChangeEvent('value1'));
      expect(executionCount.value).toBe(1);

      // 두 번째 호출 → trailing 대기 (leading 마커가 있어 즉시 실행 안됨)
      boundProps.onChange(createMockChangeEvent('value2'));
      expect(executionCount.value).toBe(1);

      // flush → trailing 실행
      dispatcher.flushPendingDebounceTimers();
      expect(executionCount.value).toBe(2);
    });
  });

  describe('bindActionsToProps에서 자동 flush', () => {
    it('비디바운스 액션 실행 시 대기 중인 debounce 액션이 먼저 flush되어야 함', () => {
      /**
       * 핵심 시나리오:
       * 1. debounce 액션 (onChange, debounce: 300) → 핸들러 대기
       * 2. 비디바운스 액션 (onClick) → 클릭 시 최신 state 필요
       * → onClick 실행 전에 대기 중인 핸들러가 먼저 실행되어야 함
       */
      const executionOrder: string[] = [];

      dispatcher.registerHandler('debouncedSetState', () => {
        executionOrder.push('debouncedSetState');
      });
      dispatcher.registerHandler('immediateAction', () => {
        executionOrder.push('immediateAction');
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'debouncedSetState',
            debounce: 300,
          },
          {
            type: 'click' as const,
            handler: 'immediateAction',
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 1단계: debounce 액션 트리거 (onChange)
      boundProps.onChange(createMockChangeEvent('new-value'));
      expect(executionOrder).toHaveLength(0);

      // 2단계: 비디바운스 액션 트리거 (onClick)
      boundProps.onClick(createClickEvent());

      // debouncedSetState가 immediateAction보다 먼저 실행되어야 함
      expect(executionOrder).toHaveLength(2);
      expect(executionOrder[0]).toBe('debouncedSetState');
      expect(executionOrder[1]).toBe('immediateAction');
    });

    it('대기 중인 debounce 액션이 없으면 비디바운스 액션만 실행해야 함', () => {
      const executionOrder: string[] = [];

      dispatcher.registerHandler('immediateAction', () => {
        executionOrder.push('immediateAction');
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'click' as const,
            handler: 'immediateAction',
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);
      boundProps.onClick(createClickEvent());

      expect(executionOrder).toHaveLength(1);
      expect(executionOrder[0]).toBe('immediateAction');
    });

    it('debounce 타이머 만료 후에는 flush 대상이 없어야 함', () => {
      const executionCount = { value: 0 };

      dispatcher.registerHandler('debouncedHandler', () => {
        executionCount.value++;
      });
      dispatcher.registerHandler('clickHandler', () => {
        // flush 후 추가 실행 없음 검증
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'debouncedHandler',
            debounce: 300,
          },
          {
            type: 'click' as const,
            handler: 'clickHandler',
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // debounce 트리거
      boundProps.onChange(createMockChangeEvent('value'));
      expect(executionCount.value).toBe(0);

      // 타이머 만료 → 자연스럽게 실행됨
      vi.advanceTimersByTime(300);
      expect(executionCount.value).toBe(1);

      // 이후 클릭 → flush 대상 없음 → 중복 실행 없음
      boundProps.onClick(createClickEvent());
      expect(executionCount.value).toBe(1);
    });
  });

  describe('clearDebounceTimers와 flusher 정리', () => {
    it('clearDebounceTimers 호출 시 flusher도 함께 정리되어야 함', () => {
      const executionCount = { value: 0 };

      dispatcher.registerHandler('testHandler', () => {
        executionCount.value++;
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'testHandler',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);
      boundProps.onChange(createMockChangeEvent('value'));

      // clear 호출 → 타이머와 flusher 모두 정리
      dispatcher.clearDebounceTimers();

      // flush 호출해도 실행 대상 없음
      dispatcher.flushPendingDebounceTimers();
      expect(executionCount.value).toBe(0);

      // 타이머 경과해도 실행 안됨
      vi.advanceTimersByTime(500);
      expect(executionCount.value).toBe(0);
    });

    it('componentId 기반 clearDebounceTimers가 해당 flusher만 정리해야 함', () => {
      const executed: string[] = [];

      dispatcher.registerHandler('handlerA', () => {
        executed.push('A');
      });
      dispatcher.registerHandler('handlerB', () => {
        executed.push('B');
      });

      // 서로 다른 componentId(props.id)로 debounce 액션 바인딩
      const propsA = {
        id: 'comp-A',
        actions: [
          { type: 'change' as const, handler: 'handlerA', debounce: 300 },
        ],
      };
      const propsB = {
        id: 'comp-B',
        actions: [
          { type: 'change' as const, handler: 'handlerB', debounce: 300 },
        ],
      };

      const boundA = dispatcher.bindActionsToProps(propsA);
      const boundB = dispatcher.bindActionsToProps(propsB);

      // 두 컴포넌트 모두 debounce 트리거
      boundA.onChange(createMockChangeEvent('valueA'));
      boundB.onChange(createMockChangeEvent('valueB'));

      // comp-A만 clear
      dispatcher.clearDebounceTimers('comp-A');

      // flush → comp-B만 실행되어야 함
      dispatcher.flushPendingDebounceTimers();
      expect(executed).toHaveLength(1);
      expect(executed[0]).toBe('B');
    });
  });

  describe('debounce 연속 입력 시 최신 값으로 flush', () => {
    it('여러 번 입력 후 flush 시 마지막 값으로 실행되어야 함', () => {
      const capturedValues: string[] = [];

      dispatcher.registerHandler('captureHandler', (action, context) => {
        // executeDebouncedAction에서 contextWithEvent.$event로 전달됨
        // createHandler 내부에서 ActionContext.data.$event로 매핑됨
        const value = context?.data?.$event?.target?.value ?? 'no-value';
        capturedValues.push(value);
      });

      const props = {
        id: 'test-comp',
        actions: [
          {
            type: 'change' as const,
            handler: 'captureHandler',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 연속 입력 시뮬레이션
      boundProps.onChange(createMockChangeEvent('a'));
      vi.advanceTimersByTime(100);
      boundProps.onChange(createMockChangeEvent('ab'));
      vi.advanceTimersByTime(100);
      boundProps.onChange(createMockChangeEvent('abc'));

      // 아직 실행되지 않음
      expect(capturedValues).toHaveLength(0);

      // flush → 마지막 값 'abc'로 실행
      dispatcher.flushPendingDebounceTimers();
      expect(capturedValues).toHaveLength(1);
      expect(capturedValues[0]).toBe('abc');
    });
  });

  describe('_changedKeys 프로토콜: 객체 값 디바운스 병합', () => {
    // 헬퍼: _changedKeys가 포함된 커스텀 이벤트 생성 (MultilingualInput 패턴)
    const createMultilingualEvent = (value: Record<string, string>, changedKeys: string[]) => ({
      preventDefault: vi.fn(),
      stopPropagation: vi.fn(),
      type: 'change',
      target: {
        value,
        name: 'multilingualInput',
      },
      _changedKeys: changedKeys,
    } as unknown as Event);

    it('_changedKeys가 있으면 디바운스 중 이전 로케일 변경분이 유지됨', () => {
      const capturedValues: Record<string, string>[] = [];

      dispatcher.registerHandler('captureMultilingual', (action, context) => {
        const value = context?.data?.$event?.target?.value;
        if (value && typeof value === 'object') {
          capturedValues.push({ ...value });
        }
      });

      const props = {
        id: 'ml-input',
        actions: [
          {
            type: 'change' as const,
            handler: 'captureMultilingual',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 1. KO 입력: value={ko:"빨강", en:"blue"}, _changedKeys=["ko"]
      boundProps.onChange(createMultilingualEvent(
        { ko: '빨강', en: 'blue' },
        ['ko']
      ));

      // 2. 100ms 후 EN 입력: value={ko:"파랑", en:"red"}, _changedKeys=["en"]
      // (stale closure로 ko가 "파랑"으로 되돌아가는 값이 오지만, _changedKeys로 en만 병합)
      vi.advanceTimersByTime(100);
      boundProps.onChange(createMultilingualEvent(
        { ko: '파랑', en: 'red' },
        ['en']
      ));

      // 타이머 완료
      vi.advanceTimersByTime(300);

      expect(capturedValues).toHaveLength(1);
      // 핵심: ko는 첫 이벤트의 "빨강"이 유지되고, en은 두 번째 이벤트의 "red"로 병합
      expect(capturedValues[0]).toEqual({ ko: '빨강', en: 'red' });
    });

    it('_changedKeys가 없으면 기존 동작 유지 (마지막 값 사용)', () => {
      const capturedValues: string[] = [];

      dispatcher.registerHandler('captureNormal', (action, context) => {
        const value = context?.data?.$event?.target?.value ?? 'no-value';
        capturedValues.push(value);
      });

      const props = {
        id: 'normal-input',
        actions: [
          {
            type: 'change' as const,
            handler: 'captureNormal',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 일반 문자열 입력 (DOM 이벤트 — _changedKeys 없음)
      boundProps.onChange(createMockChangeEvent('abc'));
      vi.advanceTimersByTime(100);
      boundProps.onChange(createMockChangeEvent('abcd'));

      vi.advanceTimersByTime(300);

      expect(capturedValues).toHaveLength(1);
      expect(capturedValues[0]).toBe('abcd'); // 마지막 값
    });

    it('_changedKeys 디바운스 완료 후 누적값이 정리됨', () => {
      const capturedValues: Record<string, string>[] = [];

      dispatcher.registerHandler('captureCleanup', (action, context) => {
        const value = context?.data?.$event?.target?.value;
        if (value && typeof value === 'object') {
          capturedValues.push({ ...value });
        }
      });

      const props = {
        id: 'cleanup-test',
        actions: [
          {
            type: 'change' as const,
            handler: 'captureCleanup',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 첫 번째 디바운스 사이클
      boundProps.onChange(createMultilingualEvent({ ko: '값1', en: '' }, ['ko']));
      vi.advanceTimersByTime(300);
      expect(capturedValues).toHaveLength(1);
      expect(capturedValues[0].ko).toBe('값1');

      // 두 번째 디바운스 사이클 — 이전 누적값이 정리되어 영향 없음
      boundProps.onChange(createMultilingualEvent({ ko: '', en: '값2' }, ['en']));
      vi.advanceTimersByTime(300);
      expect(capturedValues).toHaveLength(2);
      // 이전 사이클의 ko:"값1"이 누적되지 않고, 새 사이클의 값 그대로
      expect(capturedValues[1]).toEqual({ ko: '', en: '값2' });
    });

    it('flush 시에도 _changedKeys 병합이 적용됨', () => {
      const capturedValues: Record<string, string>[] = [];

      dispatcher.registerHandler('captureFlush', (action, context) => {
        const value = context?.data?.$event?.target?.value;
        if (value && typeof value === 'object') {
          capturedValues.push({ ...value });
        }
      });

      const props = {
        id: 'flush-ml',
        actions: [
          {
            type: 'change' as const,
            handler: 'captureFlush',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      boundProps.onChange(createMultilingualEvent({ ko: '첫값', en: '' }, ['ko']));
      vi.advanceTimersByTime(50);
      boundProps.onChange(createMultilingualEvent({ ko: '', en: '둘째' }, ['en']));

      // flush로 즉시 실행
      dispatcher.flushPendingDebounceTimers();

      expect(capturedValues).toHaveLength(1);
      expect(capturedValues[0]).toEqual({ ko: '첫값', en: '둘째' });
    });
  });

  describe('_changedKeys 프로토콜: 커스텀 컴포넌트 이벤트 (plain object)', () => {
    // 실제 MultilingualInput이 emit하는 형태: preventDefault 없는 plain object
    const createPlainCustomEvent = (value: Record<string, string>, changedKeys: string[]) => ({
      target: {
        value,
        name: 'multilingualInput',
      },
      _changedKeys: changedKeys,
    });

    it('plain object 이벤트에서도 _changedKeys가 보존되어 디바운스 병합됨', () => {
      const capturedValues: Record<string, string>[] = [];

      dispatcher.registerHandler('capturePlainML', (action, context) => {
        const value = context?.data?.$event?.target?.value;
        if (value && typeof value === 'object') {
          capturedValues.push({ ...value });
        }
      });

      const props = {
        id: 'plain-ml-input',
        actions: [
          {
            type: 'change' as const,
            handler: 'capturePlainML',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 1. KO 입력 (plain object event — MultilingualInput 실제 패턴)
      boundProps.onChange(createPlainCustomEvent(
        { ko: '빨강', en: 'blue' },
        ['ko']
      ));

      // 2. 100ms 후 EN 입력 (stale closure로 ko가 "파랑"으로 되돌아감)
      vi.advanceTimersByTime(100);
      boundProps.onChange(createPlainCustomEvent(
        { ko: '파랑', en: 'red' },
        ['en']
      ));

      vi.advanceTimersByTime(300);

      expect(capturedValues).toHaveLength(1);
      // 핵심: ko는 첫 이벤트의 "빨강"이 유지, en은 두 번째의 "red"로 병합
      expect(capturedValues[0]).toEqual({ ko: '빨강', en: 'red' });
    });

    it('plain object 이벤트 flush 시에도 _changedKeys 병합 적용', () => {
      const capturedValues: Record<string, string>[] = [];

      dispatcher.registerHandler('capturePlainFlush', (action, context) => {
        const value = context?.data?.$event?.target?.value;
        if (value && typeof value === 'object') {
          capturedValues.push({ ...value });
        }
      });

      const props = {
        id: 'plain-flush-ml',
        actions: [
          {
            type: 'change' as const,
            handler: 'capturePlainFlush',
            debounce: 300,
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      boundProps.onChange(createPlainCustomEvent({ ko: '첫값', en: '' }, ['ko']));
      vi.advanceTimersByTime(50);
      boundProps.onChange(createPlainCustomEvent({ ko: '', en: '둘째' }, ['en']));

      dispatcher.flushPendingDebounceTimers();

      expect(capturedValues).toHaveLength(1);
      expect(capturedValues[0]).toEqual({ ko: '첫값', en: '둘째' });
    });
  });

  describe('실제 시나리오: 옵션 입력 후 즉시 버튼 클릭', () => {
    it('debounce된 핸들러가 비디바운스 핸들러보다 먼저 실행되어 최신 state를 보장해야 함', () => {
      /**
       * 실제 버그 재현 시나리오:
       * 1. MultilingualInput onChange (debounce: 300) → updateOptionInputHandler (setState)
       * 2. 사용자가 300ms 이내에 "옵션 일괄 생성" 클릭 → generateOptionsHandler
       * 3. generateOptionsHandler가 getLocal()로 읽은 값이 빈 값 → 토스트 에러
       *
       * 수정 후:
       * 2단계에서 onClick 실행 전에 flush가 먼저 발생하여
       * 디바운스된 핸들러가 먼저 실행됨 → state에 최신 값 저장
       */
      let localState: Record<string, any> = {};

      // debounced 핸들러: state에 값 저장 시뮬레이션
      dispatcher.registerHandler('updateOptionInput', (action, context) => {
        const value = context?.data?.$event?.target?.value ?? '';
        localState['optionName'] = value;
      });

      // non-debounced 핸들러: state에서 값 읽기
      let readValue: string | undefined;
      dispatcher.registerHandler('generateOptions', () => {
        readValue = localState['optionName'];
      });

      const props = {
        id: 'option-input',
        actions: [
          {
            type: 'change' as const,
            handler: 'updateOptionInput',
            debounce: 300,
          },
          {
            type: 'click' as const,
            handler: 'generateOptions',
          },
        ],
      };

      const boundProps = dispatcher.bindActionsToProps(props);

      // 1. 옵션명 입력 (debounce 대기 중)
      boundProps.onChange(createMockChangeEvent('색상'));
      expect(localState['optionName']).toBeUndefined();

      // 2. 300ms 이내에 "옵션 일괄 생성" 클릭
      vi.advanceTimersByTime(100); // 100ms만 경과
      boundProps.onClick(createClickEvent());

      // 3. flush로 인해 updateOptionInput이 먼저 실행되어 state에 '색상'이 저장됨
      expect(localState['optionName']).toBe('색상');

      // 4. generateOptions가 최신 state를 읽을 수 있음
      expect(readValue).toBe('색상');
    });
  });
});
