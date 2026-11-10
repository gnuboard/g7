/**
 * SlotContext 테스트
 *
 * 슬롯 시스템의 핵심 기능을 테스트합니다.
 */

import React from 'react';
import { render, screen, act, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  SlotProvider,
  useSlotContext,
  useSlotComponents,
  SlotContextValue,
} from '../SlotContext';

// 테스트용 컴포넌트
const TestSlotConsumer: React.FC<{
  onContext?: (ctx: SlotContextValue) => void;
}> = ({ onContext }) => {
  const ctx = useSlotContext();

  React.useEffect(() => {
    onContext?.(ctx);
  }, [ctx, onContext]);

  return <div data-testid="consumer">Consumer</div>;
};

describe('SlotContext', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // 전역 변수 클리어
    delete (window as any).__slotContextValue;
  });

  afterEach(() => {
    delete (window as any).__slotContextValue;
  });

  describe('SlotProvider', () => {
    // @scenario area=provider
    // @effects slot_provider_enables_context
    it('SlotProvider가 자식 컴포넌트를 렌더링해야 함', () => {
      render(
        <SlotProvider>
          <div data-testid="child">Child</div>
        </SlotProvider>
      );

      expect(screen.getByTestId('child')).toBeInTheDocument();
    });

    // @scenario area=provider
    // @effects slot_provider_enables_context
    it('SlotProvider가 isEnabled=true인 컨텍스트를 제공해야 함', () => {
      // 콜백 안에서 채우므로 값 상자에 담는다 — 지역 변수는 대입 뒤 null 로 좁혀진다
      const captured: { context: SlotContextValue | null } = { context: null };

      render(
        <SlotProvider>
          <TestSlotConsumer
            onContext={(ctx) => {
              captured.context = ctx;
            }}
          />
        </SlotProvider>
      );

      expect(captured.context).not.toBeNull();
      expect(captured.context?.isEnabled).toBe(true);
    });

    // @scenario area=provider
    // @effects slot_provider_enables_context
    it('SlotProvider 외부에서는 isEnabled=false인 기본 컨텍스트를 반환해야 함', () => {
      // 콜백 안에서 채우므로 값 상자에 담는다 — 지역 변수는 대입 뒤 null 로 좁혀진다
      const captured: { context: SlotContextValue | null } = { context: null };

      render(
        <TestSlotConsumer
          onContext={(ctx) => {
            captured.context = ctx;
          }}
        />
      );

      expect(captured.context).not.toBeNull();
      expect(captured.context?.isEnabled).toBe(false);
    });
  });

  describe('registerToSlot / unregisterFromSlot', () => {
    // @scenario area=register
    // @effects slot_register_unregister_tracks_components
    it('컴포넌트를 슬롯에 등록할 수 있어야 함', async () => {
      let context: SlotContextValue | null = null;

      const TestRegister: React.FC = () => {
        const ctx = useSlotContext();
        context = ctx;

        React.useEffect(() => {
          ctx.registerToSlot('test_slot', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-1',
          });
        }, [ctx]);

        return <div>Test</div>;
      };

      render(
        <SlotProvider>
          <TestRegister />
        </SlotProvider>
      );

      await waitFor(() => {
        expect(context?.getSlotComponents('test_slot')).toHaveLength(1);
      });
    });

    // @scenario area=register
    // @effects slot_register_unregister_tracks_components
    it('동일한 registrationKey로 중복 등록을 방지해야 함', async () => {
      let context: SlotContextValue | null = null;

      const TestDuplicateRegister: React.FC = () => {
        const ctx = useSlotContext();
        context = ctx;

        React.useEffect(() => {
          // 동일한 registrationKey로 2번 등록
          ctx.registerToSlot('test_slot', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-same-key',
          });

          ctx.registerToSlot('test_slot', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-same-key',
          });
        }, [ctx]);

        return <div>Test</div>;
      };

      render(
        <SlotProvider>
          <TestDuplicateRegister />
        </SlotProvider>
      );

      await waitFor(() => {
        // 동일한 키로 중복 등록해도 1개만 존재
        expect(context?.getSlotComponents('test_slot')).toHaveLength(1);
      });
    });

    // @scenario area=register
    // @effects slot_register_unregister_tracks_components
    it('컴포넌트를 슬롯에서 해제할 수 있어야 함', async () => {
      let context: SlotContextValue | null = null;

      const TestUnregister: React.FC = () => {
        const ctx = useSlotContext();
        context = ctx;

        React.useEffect(() => {
          ctx.registerToSlot('test_slot', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-1',
          });

          // 즉시 해제
          ctx.unregisterFromSlot('test_slot', 'comp1');
        }, [ctx]);

        return <div>Test</div>;
      };

      render(
        <SlotProvider>
          <TestUnregister />
        </SlotProvider>
      );

      await waitFor(() => {
        expect(context?.getSlotComponents('test_slot')).toHaveLength(0);
      });
    });
  });

  describe('getSlotComponents', () => {
    // @scenario area=ordering
    // @effects slot_components_ordered_by_order
    it('order 기준으로 정렬된 컴포넌트 목록을 반환해야 함', async () => {
      let context: SlotContextValue | null = null;

      const TestOrdering: React.FC = () => {
        const ctx = useSlotContext();
        context = ctx;

        React.useEffect(() => {
          // 순서 무작위로 등록
          ctx.registerToSlot('test_slot', 'comp3', {
            componentDef: { id: 'comp3', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 3,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp3-1',
          });

          ctx.registerToSlot('test_slot', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-1',
          });

          ctx.registerToSlot('test_slot', 'comp2', {
            componentDef: { id: 'comp2', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 2,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp2-1',
          });
        }, [ctx]);

        return <div>Test</div>;
      };

      render(
        <SlotProvider>
          <TestOrdering />
        </SlotProvider>
      );

      await waitFor(() => {
        const components = context?.getSlotComponents('test_slot') || [];
        expect(components).toHaveLength(3);
        expect(components[0].componentDef.id).toBe('comp1');
        expect(components[1].componentDef.id).toBe('comp2');
        expect(components[2].componentDef.id).toBe('comp3');
      });
    });

    // @scenario area=ordering
    // @effects slot_components_ordered_by_order
    it('존재하지 않는 슬롯에 대해 빈 배열을 반환해야 함', () => {
      // 콜백 안에서 채우므로 값 상자에 담는다 — 지역 변수는 대입 뒤 null 로 좁혀진다
      const captured: { context: SlotContextValue | null } = { context: null };

      render(
        <SlotProvider>
          <TestSlotConsumer
            onContext={(ctx) => {
              captured.context = ctx;
            }}
          />
        </SlotProvider>
      );

      expect(captured.context?.getSlotComponents('nonexistent_slot')).toEqual([]);
    });
  });

  describe('subscribeToSlot', () => {
    // @scenario area=subscribe
    // @effects slot_subscribers_notified_on_change
    it('슬롯 변경 시 구독자에게 알림을 보내야 함', async () => {
      const mockCallback = vi.fn();

      const TestSubscription: React.FC = () => {
        const ctx = useSlotContext();

        React.useEffect(() => {
          const unsubscribe = ctx.subscribeToSlot('test_slot', mockCallback);

          // 등록하여 알림 트리거
          ctx.registerToSlot('test_slot', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-1',
          });

          return () => unsubscribe();
        }, [ctx]);

        return <div>Test</div>;
      };

      render(
        <SlotProvider>
          <TestSubscription />
        </SlotProvider>
      );

      await waitFor(() => {
        expect(mockCallback).toHaveBeenCalled();
      });
    });
  });

  describe('useSlotComponents', () => {
    // @scenario area=hook_rerender
    // @effects slot_hook_rerenders_on_change
    it('슬롯 변경 시 자동으로 리렌더링해야 함', async () => {
      let context: SlotContextValue | null = null;
      let renderCount = 0;

      const SlotCounter: React.FC = () => {
        const components = useSlotComponents('test_slot');
        renderCount++;
        return <div data-testid="count">{components.length}</div>;
      };

      const Registrar: React.FC = () => {
        const ctx = useSlotContext();
        context = ctx;
        return null;
      };

      render(
        <SlotProvider>
          <SlotCounter />
          <Registrar />
        </SlotProvider>
      );

      // 초기 렌더
      expect(screen.getByTestId('count').textContent).toBe('0');

      // 컴포넌트 등록
      await act(async () => {
        context?.registerToSlot('test_slot', 'comp1', {
          componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
          dataContext: {},
          order: 1,
          parentFormContext: null,
          getParentComponentContext: () => ({ state: {}, setState: () => {} }),
          translationContext: {},
          registrationKey: 'comp1-1',
        });
      });

      await waitFor(() => {
        expect(screen.getByTestId('count').textContent).toBe('1');
      });
    });
  });

  describe('clearAllSlots', () => {
    // @scenario area=clear
    // @effects slot_clear_all_empties_slots
    it('모든 슬롯을 클리어해야 함', async () => {
      // 콜백 안에서 채우므로 값 상자에 담는다 — 지역 변수는 대입 뒤 null 로 좁혀진다
      const captured: { context: SlotContextValue | null } = { context: null };

      const TestClear: React.FC = () => {
        const ctx = useSlotContext();
        captured.context = ctx;

        React.useEffect(() => {
          // 여러 슬롯에 등록
          ctx.registerToSlot('slot_a', 'comp1', {
            componentDef: { id: 'comp1', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp1-1',
          });

          ctx.registerToSlot('slot_b', 'comp2', {
            componentDef: { id: 'comp2', type: 'basic', name: 'Div' },
            dataContext: {},
            order: 1,
            parentFormContext: null,
            getParentComponentContext: () => ({ state: {}, setState: () => {} }),
            translationContext: {},
            registrationKey: 'comp2-1',
          });
        }, [ctx]);

        return <div>Test</div>;
      };

      render(
        <SlotProvider>
          <TestClear />
        </SlotProvider>
      );

      await waitFor(() => {
        expect(captured.context?.getSlotComponents('slot_a')).toHaveLength(1);
        expect(captured.context?.getSlotComponents('slot_b')).toHaveLength(1);
      });

      // 모든 슬롯 클리어
      act(() => {
        captured.context?.clearAllSlots();
      });

      expect(captured.context?.getSlotComponents('slot_a')).toHaveLength(0);
      expect(captured.context?.getSlotComponents('slot_b')).toHaveLength(0);
    });
  });

  describe('전역 노출', () => {
    // @scenario area=global_exposure
    // @effects slot_context_exposed_on_window
    it('SlotProvider가 마운트되면 window.__slotContextValue에 값이 설정되어야 함', async () => {
      render(
        <SlotProvider>
          <div>Test</div>
        </SlotProvider>
      );

      await waitFor(() => {
        expect((window as any).__slotContextValue).not.toBeNull();
        expect((window as any).__slotContextValue.isEnabled).toBe(true);
      });
    });
  });
});
