/**
 * IsolatedStateContext 테스트
 *
 * 격리된 상태 관리 시스템의 핵심 기능을 테스트합니다.
 */

import React from 'react';
import { render, screen, act, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  IsolatedStateProvider,
  useIsolatedState,
  useIsInIsolatedScope,
  IsolatedStateContextValue,
} from '../IsolatedStateContext';

// 테스트용 컴포넌트
const TestConsumer: React.FC<{
  onContext?: (ctx: IsolatedStateContextValue | null) => void;
}> = ({ onContext }) => {
  const ctx = useIsolatedState();

  React.useEffect(() => {
    onContext?.(ctx);
  }, [ctx, onContext]);

  return (
    <div data-testid="consumer">
      {ctx ? JSON.stringify(ctx.state) : 'no context'}
    </div>
  );
};

// 상태 업데이트 테스트용 컴포넌트
const StateUpdater: React.FC<{
  path: string;
  value: any;
}> = ({ path, value }) => {
  const ctx = useIsolatedState();

  return (
    <button
      data-testid="update-btn"
      onClick={() => ctx?.setState(path, value)}
    >
      Update
    </button>
  );
};

// 상태 병합 테스트용 컴포넌트
const StateMerger: React.FC<{
  updates: Record<string, any>;
}> = ({ updates }) => {
  const ctx = useIsolatedState();

  return (
    <button
      data-testid="merge-btn"
      onClick={() => ctx?.mergeState(updates)}
    >
      Merge
    </button>
  );
};

// 상태 조회 테스트용 컴포넌트
const StateGetter: React.FC<{
  path: string;
  onGet?: (value: any) => void;
}> = ({ path, onGet }) => {
  const ctx = useIsolatedState();

  return (
    <button
      data-testid="get-btn"
      onClick={() => onGet?.(ctx?.getState(path))}
    >
      Get
    </button>
  );
};

describe('IsolatedStateContext', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // G7Core 모의 객체 설정
    (window as any).G7Core = {
      _isolatedStates: {},
      devTools: {
        isEnabled: () => false,
        emit: vi.fn(),
      },
    };
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  describe('초기화', () => {
    it('initialState로 초기 상태를 설정한다', () => {
      render(
        <IsolatedStateProvider initialState={{ count: 0 }}>
          <TestConsumer />
        </IsolatedStateProvider>
      );

      expect(screen.getByTestId('consumer')).toHaveTextContent('{"count":0}');
    });

    it('initialState 없이도 빈 객체로 초기화된다', () => {
      render(
        <IsolatedStateProvider>
          <TestConsumer />
        </IsolatedStateProvider>
      );

      expect(screen.getByTestId('consumer')).toHaveTextContent('{}');
    });

    it('중첩된 초기 상태를 올바르게 설정한다', () => {
      const initialState = {
        user: { name: 'Kim', profile: { age: 30 } },
        items: [1, 2, 3],
      };

      render(
        <IsolatedStateProvider initialState={initialState}>
          <TestConsumer />
        </IsolatedStateProvider>
      );

      expect(screen.getByTestId('consumer')).toHaveTextContent(JSON.stringify(initialState));
    });
  });

  describe('setState', () => {
    it('단일 경로 업데이트가 정상 동작한다', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ count: 0 }}>
          <TestConsumer />
          <StateUpdater path="count" value={1} />
        </IsolatedStateProvider>
      );

      expect(screen.getByTestId('consumer')).toHaveTextContent('{"count":0}');

      await user.click(screen.getByTestId('update-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"count":1}');
      });
    });

    it('중첩 경로 업데이트가 정상 동작한다', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ user: { name: 'old' } }}>
          <TestConsumer />
          <StateUpdater path="user.name" value="Kim" />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('update-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"user":{"name":"Kim"}}');
      });
    });

    it('기존 값을 유지하면서 업데이트한다', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ a: 1, b: 2 }}>
          <TestConsumer />
          <StateUpdater path="a" value={10} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('update-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"a":10,"b":2}');
      });
    });
  });

  describe('getState', () => {
    it('경로로 값을 조회한다', async () => {
      const user = userEvent.setup();
      let capturedValue: any;

      render(
        <IsolatedStateProvider initialState={{ user: { name: 'Kim' } }}>
          <StateGetter path="user.name" onGet={(v) => { capturedValue = v; }} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('get-btn'));

      expect(capturedValue).toBe('Kim');
    });

    it('존재하지 않는 경로는 undefined를 반환한다', async () => {
      const user = userEvent.setup();
      let capturedValue: any = 'initial';

      render(
        <IsolatedStateProvider initialState={{ a: 1 }}>
          <StateGetter path="nonexistent.path" onGet={(v) => { capturedValue = v; }} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('get-btn'));

      expect(capturedValue).toBeUndefined();
    });

    it('빈 경로는 전체 상태를 반환한다', async () => {
      const user = userEvent.setup();
      let capturedValue: any;
      const initialState = { a: 1, b: 2 };

      render(
        <IsolatedStateProvider initialState={initialState}>
          <StateGetter path="" onGet={(v) => { capturedValue = v; }} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('get-btn'));

      expect(capturedValue).toEqual(initialState);
    });
  });

  describe('mergeState', () => {
    it('얕은 객체를 병합한다', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ a: 1 }}>
          <TestConsumer />
          <StateMerger updates={{ b: 2 }} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('merge-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"a":1,"b":2}');
      });
    });

    it('깊은 객체를 재귀적으로 병합한다', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ user: { name: 'Kim' } }}>
          <TestConsumer />
          <StateMerger updates={{ user: { age: 20 } }} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('merge-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"user":{"name":"Kim","age":20}}');
      });
    });

    it('배열은 교체된다 (병합 아님)', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ items: [1, 2] }}>
          <TestConsumer />
          <StateMerger updates={{ items: [3] }} />
        </IsolatedStateProvider>
      );

      await user.click(screen.getByTestId('merge-btn'));

      await waitFor(() => {
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"items":[3]}');
      });
    });
  });

  describe('useIsInIsolatedScope', () => {
    const ScopeChecker: React.FC = () => {
      const isInScope = useIsInIsolatedScope();
      return <div data-testid="scope-check">{isInScope ? 'in-scope' : 'out-scope'}</div>;
    };

    it('IsolatedStateProvider 내부에서 true를 반환한다', () => {
      render(
        <IsolatedStateProvider>
          <ScopeChecker />
        </IsolatedStateProvider>
      );

      expect(screen.getByTestId('scope-check')).toHaveTextContent('in-scope');
    });

    it('IsolatedStateProvider 외부에서 false를 반환한다', () => {
      render(<ScopeChecker />);

      expect(screen.getByTestId('scope-check')).toHaveTextContent('out-scope');
    });
  });

  describe('useIsolatedState 컨텍스트 외부 동작', () => {
    it('IsolatedStateProvider 외부에서 null을 반환한다', () => {
      let capturedContext: IsolatedStateContextValue | null | undefined;

      render(
        <TestConsumer onContext={(ctx) => { capturedContext = ctx; }} />
      );

      expect(capturedContext).toBeNull();
      expect(screen.getByTestId('consumer')).toHaveTextContent('no context');
    });
  });

  describe('DevTools 연동', () => {
    it('마운트 시 G7Core._isolatedStates에 등록된다', () => {
      render(
        <IsolatedStateProvider initialState={{ count: 1 }} scopeId="test-scope">
          <div />
        </IsolatedStateProvider>
      );

      expect((window as any).G7Core._isolatedStates['test-scope']).toEqual({ count: 1 });
    });

    it('언마운트 시 G7Core._isolatedStates에서 제거된다', () => {
      const { unmount } = render(
        <IsolatedStateProvider initialState={{ count: 1 }} scopeId="test-scope">
          <div />
        </IsolatedStateProvider>
      );

      expect((window as any).G7Core._isolatedStates['test-scope']).toEqual({ count: 1 });

      unmount();

      expect((window as any).G7Core._isolatedStates['test-scope']).toBeUndefined();
    });

    it('상태 변경 시 G7Core._isolatedStates가 업데이트된다', async () => {
      const user = userEvent.setup();

      render(
        <IsolatedStateProvider initialState={{ count: 0 }} scopeId="test-scope">
          <StateUpdater path="count" value={5} />
        </IsolatedStateProvider>
      );

      expect((window as any).G7Core._isolatedStates['test-scope']).toEqual({ count: 0 });

      await user.click(screen.getByTestId('update-btn'));

      await waitFor(() => {
        expect((window as any).G7Core._isolatedStates['test-scope']).toEqual({ count: 5 });
      });
    });
  });

  describe('Stale Closure 방지', () => {
    it('stateRef를 통해 항상 최신 상태에 접근할 수 있다', async () => {
      const user = userEvent.setup();
      let capturedRef: React.MutableRefObject<Record<string, any>> | undefined;

      const RefCapture: React.FC = () => {
        const ctx = useIsolatedState();
        capturedRef = ctx?.stateRef;
        return null;
      };

      render(
        <IsolatedStateProvider initialState={{ count: 0 }}>
          <RefCapture />
          <StateUpdater path="count" value={10} />
        </IsolatedStateProvider>
      );

      // 초기 상태 확인
      expect(capturedRef?.current).toEqual({ count: 0 });

      // 상태 업데이트
      await user.click(screen.getByTestId('update-btn'));

      await waitFor(() => {
        // stateRef는 항상 최신 상태를 참조
        expect(capturedRef?.current).toEqual({ count: 10 });
      });
    });
  });

  describe('scopeId 자동 생성', () => {
    it('scopeId를 제공하지 않으면 자동 생성된다', () => {
      render(
        <IsolatedStateProvider initialState={{ count: 1 }}>
          <div />
        </IsolatedStateProvider>
      );

      const keys = Object.keys((window as any).G7Core._isolatedStates);
      expect(keys.length).toBe(1);
      expect(keys[0]).toMatch(/^isolated-\d+-[a-z0-9]+$/);
    });

    it('제공된 scopeId가 사용된다', () => {
      render(
        <IsolatedStateProvider initialState={{ count: 1 }} scopeId="custom-scope">
          <div />
        </IsolatedStateProvider>
      );

      expect((window as any).G7Core._isolatedStates['custom-scope']).toBeDefined();
    });
  });

  describe('중첩된 IsolatedStateProvider', () => {
    it('중첩된 Provider는 각각 독립적인 상태를 가진다', async () => {
      const user = userEvent.setup();

      const InnerConsumer: React.FC = () => {
        const ctx = useIsolatedState();
        return <div data-testid="inner-state">{JSON.stringify(ctx?.state)}</div>;
      };

      render(
        <IsolatedStateProvider initialState={{ outer: 'outer-value' }} scopeId="outer">
          <TestConsumer />
          <IsolatedStateProvider initialState={{ inner: 'inner-value' }} scopeId="inner">
            <InnerConsumer />
            <StateUpdater path="inner" value="updated-inner" />
          </IsolatedStateProvider>
        </IsolatedStateProvider>
      );

      // 초기 상태 확인
      expect(screen.getByTestId('consumer')).toHaveTextContent('{"outer":"outer-value"}');
      expect(screen.getByTestId('inner-state')).toHaveTextContent('{"inner":"inner-value"}');

      // 내부 상태 업데이트
      await user.click(screen.getByTestId('update-btn'));

      await waitFor(() => {
        // 외부 상태는 변경되지 않음
        expect(screen.getByTestId('consumer')).toHaveTextContent('{"outer":"outer-value"}');
        // 내부 상태만 변경됨
        expect(screen.getByTestId('inner-state')).toHaveTextContent('{"inner":"updated-inner"}');
      });
    });
  });
});

// =============================================================================
// 회귀 테스트: 모달 상태 스코프
// 문서: modal-usage.md
// =============================================================================

describe('IsolatedStateContext - 모달 상태 스코프 회귀 테스트', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = {
      _isolatedStates: {},
      devTools: {
        isEnabled: () => false,
        emit: vi.fn(),
      },
    };
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * [TS-MODAL-SCOPE-1] 모달은 호출 컴포넌트의 _local에 접근 불가
   *
   * 문제: 모달에서 _local 데이터가 undefined
   * 원인: modals 섹션 모달은 전역 컨텍스트에서 렌더링되어 호출 컴포넌트의 _local에 접근 불가
   * 해결: 모달 데이터는 반드시 _global에 저장
   */
  describe('[TS-MODAL-SCOPE-1] 모달 격리 상태 - _local 접근 불가 시나리오', () => {
    it('모달(IsolatedStateProvider)은 자신의 격리된 상태만 접근 가능해야 함', async () => {
      const user = userEvent.setup();

      // 부모 컴포넌트 상태 확인용
      const ParentStateViewer: React.FC = () => {
        const ctx = useIsolatedState();
        return <div data-testid="parent-state">{JSON.stringify(ctx?.state)}</div>;
      };

      // 모달 컴포넌트 (별도 IsolatedStateProvider)
      const ModalContent: React.FC = () => {
        const ctx = useIsolatedState();
        return (
          <div data-testid="modal-state">
            {ctx ? JSON.stringify(ctx.state) : 'no-context'}
          </div>
        );
      };

      render(
        <IsolatedStateProvider
          initialState={{ parentData: '부모 데이터', selectedItem: { id: 1 } }}
          scopeId="parent-component"
        >
          <ParentStateViewer />
          {/* 모달은 별도의 격리된 상태를 가짐 */}
          <IsolatedStateProvider
            initialState={{ modalSpecificData: '모달 전용 데이터' }}
            scopeId="modal-scope"
          >
            <ModalContent />
          </IsolatedStateProvider>
        </IsolatedStateProvider>
      );

      // 부모 상태에는 parentData가 있음
      expect(screen.getByTestId('parent-state')).toHaveTextContent('parentData');
      expect(screen.getByTestId('parent-state')).toHaveTextContent('selectedItem');

      // 모달 상태에는 modalSpecificData만 있음 (부모의 parentData, selectedItem 접근 불가)
      expect(screen.getByTestId('modal-state')).toHaveTextContent('modalSpecificData');
      expect(screen.getByTestId('modal-state')).not.toHaveTextContent('parentData');
      expect(screen.getByTestId('modal-state')).not.toHaveTextContent('selectedItem');
    });

    it('모달에서 부모 상태를 사용하려면 명시적으로 전달해야 함', async () => {
      const user = userEvent.setup();

      // 부모에서 모달에 전달할 데이터를 명시적으로 복사
      const parentData = { selectedItem: { id: 1, name: '상품A' } };
      const modalInitialState = {
        // 부모에서 _global에 저장한 데이터를 참조하는 시나리오
        targetItem: parentData.selectedItem,
        isDeleting: false,
      };

      const ModalContent: React.FC = () => {
        const ctx = useIsolatedState();
        return (
          <div data-testid="modal-state">
            {ctx?.state.targetItem?.name ?? 'no-item'}
          </div>
        );
      };

      render(
        <IsolatedStateProvider initialState={modalInitialState} scopeId="delete-modal">
          <ModalContent />
        </IsolatedStateProvider>
      );

      // 명시적으로 전달된 데이터에 접근 가능
      expect(screen.getByTestId('modal-state')).toHaveTextContent('상품A');
    });
  });

  /**
   * [TS-MODAL-SCOPE-2] 모달 닫힘 시 상태 정리
   *
   * 문제: 모달을 닫았다 다시 열면 이전 상태가 남아있음
   * 원인: IsolatedStateProvider가 언마운트되지 않거나 상태가 재사용됨
   * 해결: 언마운트 시 G7Core._isolatedStates에서 제거되어야 함
   */
  describe('[TS-MODAL-SCOPE-2] 모달 상태 정리', () => {
    it('모달(IsolatedStateProvider) 언마운트 시 상태가 완전히 제거되어야 함', async () => {
      const user = userEvent.setup();

      const ModalContent: React.FC = () => {
        const ctx = useIsolatedState();
        return (
          <div>
            <div data-testid="modal-form-data">{ctx?.state.formData?.email ?? ''}</div>
            <button
              data-testid="set-email"
              onClick={() => ctx?.setState('formData.email', 'test@test.com')}
            >
              Set Email
            </button>
          </div>
        );
      };

      // 모달 열기/닫기를 시뮬레이션하는 컴포넌트
      const ModalContainer: React.FC<{ isOpen: boolean }> = ({ isOpen }) => {
        if (!isOpen) return null;
        return (
          <IsolatedStateProvider initialState={{ formData: {} }} scopeId="form-modal">
            <ModalContent />
          </IsolatedStateProvider>
        );
      };

      const { rerender } = render(<ModalContainer isOpen={true} />);

      // 모달에서 이메일 설정
      await user.click(screen.getByTestId('set-email'));
      await waitFor(() => {
        expect(screen.getByTestId('modal-form-data')).toHaveTextContent('test@test.com');
      });

      // G7Core._isolatedStates에 등록되어 있음
      expect((window as any).G7Core._isolatedStates['form-modal']).toBeDefined();
      expect((window as any).G7Core._isolatedStates['form-modal'].formData.email).toBe('test@test.com');

      // 모달 닫기
      rerender(<ModalContainer isOpen={false} />);

      // G7Core._isolatedStates에서 제거됨
      expect((window as any).G7Core._isolatedStates['form-modal']).toBeUndefined();

      // 모달 다시 열기
      rerender(<ModalContainer isOpen={true} />);

      // 이전 상태가 남아있지 않고 초기 상태로 시작
      await waitFor(() => {
        expect(screen.getByTestId('modal-form-data')).toHaveTextContent('');
      });
    });
  });

  /**
   * [TS-MODAL-SCOPE-3] 여러 모달 동시 열림 시 상태 격리
   *
   * 문제: 여러 모달이 동시에 열릴 때 상태가 혼합됨
   * 원인: scopeId 충돌 또는 상태 공유
   * 해결: 각 모달은 고유한 scopeId로 완전히 격리된 상태를 가져야 함
   */
  describe('[TS-MODAL-SCOPE-3] 다중 모달 상태 격리', () => {
    it('여러 모달이 동시에 열려도 각각 독립적인 상태를 유지해야 함', async () => {
      const user = userEvent.setup();

      const Modal1: React.FC = () => {
        const ctx = useIsolatedState();
        return (
          <div>
            <div data-testid="modal1-value">{ctx?.state.value ?? 'empty'}</div>
            <button
              data-testid="modal1-update"
              onClick={() => ctx?.setState('value', 'modal1-data')}
            >
              Update Modal1
            </button>
          </div>
        );
      };

      const Modal2: React.FC = () => {
        const ctx = useIsolatedState();
        return (
          <div>
            <div data-testid="modal2-value">{ctx?.state.value ?? 'empty'}</div>
            <button
              data-testid="modal2-update"
              onClick={() => ctx?.setState('value', 'modal2-data')}
            >
              Update Modal2
            </button>
          </div>
        );
      };

      render(
        <>
          <IsolatedStateProvider initialState={{}} scopeId="modal-1">
            <Modal1 />
          </IsolatedStateProvider>
          <IsolatedStateProvider initialState={{}} scopeId="modal-2">
            <Modal2 />
          </IsolatedStateProvider>
        </>
      );

      // 초기 상태 확인
      expect(screen.getByTestId('modal1-value')).toHaveTextContent('empty');
      expect(screen.getByTestId('modal2-value')).toHaveTextContent('empty');

      // Modal1 상태 업데이트
      await user.click(screen.getByTestId('modal1-update'));

      await waitFor(() => {
        // Modal1만 업데이트됨
        expect(screen.getByTestId('modal1-value')).toHaveTextContent('modal1-data');
        // Modal2는 영향 없음
        expect(screen.getByTestId('modal2-value')).toHaveTextContent('empty');
      });

      // Modal2 상태 업데이트
      await user.click(screen.getByTestId('modal2-update'));

      await waitFor(() => {
        // Modal1 상태 유지
        expect(screen.getByTestId('modal1-value')).toHaveTextContent('modal1-data');
        // Modal2 업데이트됨
        expect(screen.getByTestId('modal2-value')).toHaveTextContent('modal2-data');
      });

      // G7Core._isolatedStates에서도 분리 확인
      expect((window as any).G7Core._isolatedStates['modal-1'].value).toBe('modal1-data');
      expect((window as any).G7Core._isolatedStates['modal-2'].value).toBe('modal2-data');
    });
  });
});
