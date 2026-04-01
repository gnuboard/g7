import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderItemChildren } from '../RenderHelpers';
import { responsiveManager } from '../../ResponsiveManager';
import { Logger } from '../../../utils/Logger';

// 테스트용 컴포넌트
const MockDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <div className={className}>{children}</div>
);

const MockSpan: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <span className={className}>{children}</span>
);

const MockButton: React.FC<{ onClick?: () => void; children?: React.ReactNode }> = ({ onClick, children }) => (
  <button onClick={onClick}>{children}</button>
);

// 컴포넌트 맵
const componentMap: Record<string, React.ComponentType<any>> = {
  Div: MockDiv,
  Span: MockSpan,
  Button: MockButton,
};

describe('RenderHelpers', () => {
  beforeEach(() => {
    // Logger 디버그 모드 활성화
    Logger.getInstance().setDebug(true);
  });

  afterEach(() => {
    // Logger 디버그 모드 비활성화
    Logger.getInstance().setDebug(false);
  });

  describe('renderItemChildren', () => {
    it('기본 컴포넌트를 렌더링한다', () => {
      const children = [
        {
          id: 'test-span',
          type: 'basic' as const,
          name: 'Span',
          text: '테스트 텍스트',
        },
      ];

      const result = renderItemChildren(children, {}, componentMap, 'test');

      expect(result).toHaveLength(1);
      expect(React.isValidElement(result[0])).toBe(true);
    });

    it('컨텍스트의 row 데이터를 바인딩한다', () => {
      const children = [
        {
          id: 'name-span',
          type: 'basic' as const,
          name: 'Span',
          text: '{{row.name}}',
        },
      ];

      const context = {
        row: { name: '홍길동', email: 'hong@example.com' },
      };

      const result = renderItemChildren(children, context, componentMap, 'test');

      expect(result).toHaveLength(1);
      const element = result[0] as React.ReactElement;
      expect(element.props.children).toBe('홍길동');
    });

    it('중첩된 자식 컴포넌트를 렌더링한다', () => {
      const children = [
        {
          id: 'container',
          type: 'basic' as const,
          name: 'Div',
          props: { className: 'container' },
          children: [
            {
              id: 'inner-span',
              type: 'basic' as const,
              name: 'Span',
              text: '{{row.value}}',
            },
          ],
        },
      ];

      const context = {
        row: { value: '내부 값' },
      };

      const result = renderItemChildren(children, context, componentMap, 'test');

      expect(result).toHaveLength(1);
      const container = result[0] as React.ReactElement;
      expect(container.props.className).toBe('container');
    });

    describe('iteration 지원', () => {
      it('iteration이 있는 컴포넌트를 반복 렌더링한다', () => {
        const children = [
          {
            id: 'role-span',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.roles',
              item_var: 'role',
            },
            text: '{{role.name}}',
          },
        ];

        const context = {
          row: {
            roles: [
              { id: 1, name: '관리자' },
              { id: 2, name: '편집자' },
              { id: 3, name: '뷰어' },
            ],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        // 3개의 역할에 대해 3개의 Span이 생성되어야 함
        expect(result).toHaveLength(3);

        const element0 = result[0] as React.ReactElement;
        const element1 = result[1] as React.ReactElement;
        const element2 = result[2] as React.ReactElement;

        expect(element0.props.children).toBe('관리자');
        expect(element1.props.children).toBe('편집자');
        expect(element2.props.children).toBe('뷰어');
      });

      it('iteration 아이템의 인덱스를 사용할 수 있다', () => {
        const children = [
          {
            id: 'indexed-span',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.items',
              item_var: 'item',
            },
            text: '{{item.name}}',
          },
        ];

        const context = {
          row: {
            items: [
              { name: '첫 번째' },
              { name: '두 번째' },
            ],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(2);

        // flatMap으로 반환되므로 각 요소가 개별적으로 배열에 포함됨
        const element0 = result[0] as React.ReactElement<{ children: string }>;
        const element1 = result[1] as React.ReactElement<{ children: string }>;

        // 각 아이템이 올바르게 렌더링되어야 함
        expect(element0.props.children).toBe('첫 번째');
        expect(element1.props.children).toBe('두 번째');
      });

      it('iteration과 props를 함께 사용할 수 있다', () => {
        const children = [
          {
            id: 'styled-span',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.tags',
              item_var: 'tag',
            },
            props: {
              className: '{{tag.color}}',
            },
            text: '{{tag.label}}',
          },
        ];

        const context = {
          row: {
            tags: [
              { label: '중요', color: 'text-red-500' },
              { label: '일반', color: 'text-gray-500' },
            ],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(2);

        const element0 = result[0] as React.ReactElement;
        const element1 = result[1] as React.ReactElement;

        expect(element0.props.className).toBe('text-red-500');
        expect(element0.props.children).toBe('중요');
        expect(element1.props.className).toBe('text-gray-500');
        expect(element1.props.children).toBe('일반');
      });

      it('빈 배열에 대해 빈 결과를 반환한다', () => {
        const children = [
          {
            id: 'empty-iter',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.items',
              item_var: 'item',
            },
            text: '{{item.name}}',
          },
        ];

        const context = {
          row: {
            items: [],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(0);
      });

      it('iteration source가 배열이 아닌 경우 빈 결과를 반환한다', () => {
        const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const children = [
          {
            id: 'invalid-iter',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.notAnArray',
              item_var: 'item',
            },
            text: '{{item}}',
          },
        ];

        const context = {
          row: {
            notAnArray: 'string value',
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(0);
        expect(consoleSpy).toHaveBeenCalled();

        consoleSpy.mockRestore();
      });

      it('iteration이 없는 컴포넌트와 있는 컴포넌트를 함께 렌더링한다', () => {
        const children = [
          {
            id: 'static-span',
            type: 'basic' as const,
            name: 'Span',
            text: '역할: ',
          },
          {
            id: 'role-span',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.roles',
              item_var: 'role',
            },
            text: '{{role.name}}',
          },
        ];

        const context = {
          row: {
            roles: [
              { name: '관리자' },
              { name: '사용자' },
            ],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        // 1개 정적 + 2개 반복 = 3개
        expect(result).toHaveLength(3);

        const element0 = result[0] as React.ReactElement;
        expect(element0.props.children).toBe('역할: ');

        const element1 = result[1] as React.ReactElement;
        const element2 = result[2] as React.ReactElement;
        expect(element1.props.children).toBe('관리자');
        expect(element2.props.children).toBe('사용자');
      });

      it('중첩된 iteration 자식 컴포넌트를 렌더링한다', () => {
        const children = [
          {
            id: 'container',
            type: 'basic' as const,
            name: 'Div',
            iteration: {
              source: 'row.groups',
              item_var: 'group',
            },
            children: [
              {
                id: 'group-name',
                type: 'basic' as const,
                name: 'Span',
                text: '{{group.name}}',
              },
            ],
          },
        ];

        const context = {
          row: {
            groups: [
              { name: '그룹 A' },
              { name: '그룹 B' },
            ],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(2);

        // 각 Div는 children으로 Span을 가짐
        const element0 = result[0] as React.ReactElement;
        const element1 = result[1] as React.ReactElement;

        expect(Array.isArray(element0.props.children)).toBe(true);
        expect(Array.isArray(element1.props.children)).toBe(true);
      });
    });

    it('존재하지 않는 컴포넌트에 대해 빈 배열을 반환한다 (필터링)', () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const children = [
        {
          id: 'unknown',
          type: 'basic' as const,
          name: 'UnknownComponent',
          text: '테스트',
        },
      ];

      const result = renderItemChildren(children, {}, componentMap, 'test');

      // 존재하지 않는 컴포넌트는 빈 배열로 반환되어 flatMap에 의해 필터링됨
      expect(result).toHaveLength(0);
      expect(consoleSpy).toHaveBeenCalled();

      consoleSpy.mockRestore();
    });

    it('빈 children 배열에 대해 빈 결과를 반환한다', () => {
      const result = renderItemChildren([], {}, componentMap, 'test');
      expect(result).toHaveLength(0);
    });

    describe('if 조건부 렌더링', () => {
      it('if 조건이 true면 컴포넌트를 렌더링한다', () => {
        const children = [
          {
            id: 'status-label',
            type: 'basic' as const,
            name: 'Span',
            if: "{{row.status == 'active'}}",
            text: '활성화',
          },
        ];

        const context = {
          row: { status: 'active' },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ children: string }>;
        expect(element.props.children).toBe('활성화');
      });

      it('if 조건이 false면 컴포넌트를 렌더링하지 않는다', () => {
        const children = [
          {
            id: 'status-label',
            type: 'basic' as const,
            name: 'Span',
            if: "{{row.status == 'active'}}",
            text: '활성화',
          },
        ];

        const context = {
          row: { status: 'inactive' },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(0);
      });

      it('여러 if 조건이 있는 컴포넌트 중 조건에 맞는 것만 렌더링한다', () => {
        const children = [
          {
            id: 'active-label',
            type: 'basic' as const,
            name: 'Span',
            if: "{{row.status == 'active'}}",
            text: '활성화',
          },
          {
            id: 'inactive-label',
            type: 'basic' as const,
            name: 'Span',
            if: "{{row.status == 'inactive'}}",
            text: '비활성화',
          },
          {
            id: 'uninstalled-label',
            type: 'basic' as const,
            name: 'Span',
            if: "{{row.status == 'uninstalled'}}",
            text: '미설치',
          },
        ];

        const context = {
          row: { status: 'inactive' },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ children: string }>;
        expect(element.props.children).toBe('비활성화');
      });

      it('if 조건이 없는 컴포넌트는 항상 렌더링한다', () => {
        const children = [
          {
            id: 'always-show',
            type: 'basic' as const,
            name: 'Span',
            text: '항상 표시',
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
      });

      it('중첩된 자식의 if 조건도 평가한다', () => {
        const children = [
          {
            id: 'container',
            type: 'basic' as const,
            name: 'Div',
            children: [
              {
                id: 'visible-child',
                type: 'basic' as const,
                name: 'Span',
                if: "{{row.show == true}}",
                text: '표시됨',
              },
              {
                id: 'hidden-child',
                type: 'basic' as const,
                name: 'Span',
                if: "{{row.show == false}}",
                text: '숨김',
              },
            ],
          },
        ];

        const context = {
          row: { show: true },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(1);
        const container = result[0] as React.ReactElement;
        // 중첩된 자식 중 조건이 맞는 것만 렌더링됨
        expect(container.props.children).toHaveLength(1);
      });

      it('iteration 내에서 if 조건을 평가한다', () => {
        const children = [
          {
            id: 'item-span',
            type: 'basic' as const,
            name: 'Span',
            iteration: {
              source: 'row.items',
              item_var: 'item',
            },
            if: "{{item.visible == true}}",
            text: '{{item.name}}',
          },
        ];

        const context = {
          row: {
            items: [
              { name: '아이템1', visible: true },
              { name: '아이템2', visible: false },
              { name: '아이템3', visible: true },
            ],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        // visible이 true인 아이템만 렌더링됨
        expect(result).toHaveLength(2);
        const element0 = result[0] as React.ReactElement;
        const element1 = result[1] as React.ReactElement;
        expect(element0.props.children).toBe('아이템1');
        expect(element1.props.children).toBe('아이템3');
      });
    });

    describe('responsive 지원', () => {
      afterEach(() => {
        // 테스트 후 데스크톱 기본값으로 복원
        responsiveManager._setWidthForTesting(1024);
      });

      it('모바일에서 responsive props 오버라이드를 적용한다', () => {
        // 모바일 너비로 설정 (0-767px)
        responsiveManager._setWidthForTesting(400);

        const children = [
          {
            id: 'responsive-div',
            type: 'basic' as const,
            name: 'Div',
            props: {
              className: 'flex items-start gap-4',
            },
            responsive: {
              mobile: {
                props: {
                  className: 'flex flex-col gap-4',
                },
              },
            },
            children: [
              {
                id: 'inner-span',
                type: 'basic' as const,
                name: 'Span',
                text: '테스트',
              },
            ],
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ className: string }>;
        expect(element.props.className).toBe('flex flex-col gap-4');
      });

      it('데스크톱에서 기본 props를 유지한다', () => {
        // 데스크톱 너비로 설정 (1024px+)
        responsiveManager._setWidthForTesting(1280);

        const children = [
          {
            id: 'responsive-div',
            type: 'basic' as const,
            name: 'Div',
            props: {
              className: 'flex items-start gap-4',
            },
            responsive: {
              mobile: {
                props: {
                  className: 'flex flex-col gap-4',
                },
              },
            },
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ className: string }>;
        expect(element.props.className).toBe('flex items-start gap-4');
      });

      it('태블릿에서 tablet responsive를 적용한다', () => {
        // 태블릿 너비로 설정 (768-1023px)
        responsiveManager._setWidthForTesting(800);

        const children = [
          {
            id: 'responsive-div',
            type: 'basic' as const,
            name: 'Div',
            props: {
              className: 'grid-cols-4',
            },
            responsive: {
              mobile: {
                props: {
                  className: 'grid-cols-1',
                },
              },
              tablet: {
                props: {
                  className: 'grid-cols-2',
                },
              },
            },
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ className: string }>;
        expect(element.props.className).toBe('grid-cols-2');
      });

      it('중첩된 컴포넌트에서도 responsive를 적용한다', () => {
        responsiveManager._setWidthForTesting(400);

        const children = [
          {
            id: 'container',
            type: 'basic' as const,
            name: 'Div',
            props: { className: 'container' },
            children: [
              {
                id: 'inner',
                type: 'basic' as const,
                name: 'Div',
                props: {
                  className: 'flex-row',
                },
                responsive: {
                  mobile: {
                    props: {
                      className: 'flex-col',
                    },
                  },
                },
              },
            ],
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
        const container = result[0] as React.ReactElement<{ children: React.ReactNode[] }>;
        const innerChildren = container.props.children as React.ReactElement[];
        expect(innerChildren[0].props.className).toBe('flex-col');
      });

      it('iteration과 responsive를 함께 사용할 수 있다', () => {
        responsiveManager._setWidthForTesting(400);

        const children = [
          {
            id: 'item',
            type: 'basic' as const,
            name: 'Div',
            iteration: {
              source: 'row.items',
              item_var: 'item',
            },
            props: {
              className: 'flex-row gap-4',
            },
            responsive: {
              mobile: {
                props: {
                  className: 'flex-col gap-2',
                },
              },
            },
            text: '{{item.name}}',
          },
        ];

        const context = {
          row: {
            items: [{ name: '아이템1' }, { name: '아이템2' }],
          },
        };

        const result = renderItemChildren(children, context, componentMap, 'test');

        expect(result).toHaveLength(2);
        const element0 = result[0] as React.ReactElement<{ className: string }>;
        const element1 = result[1] as React.ReactElement<{ className: string }>;
        expect(element0.props.className).toBe('flex-col gap-2');
        expect(element1.props.className).toBe('flex-col gap-2');
      });

      it('커스텀 범위 breakpoint를 지원한다', () => {
        responsiveManager._setWidthForTesting(500);

        const children = [
          {
            id: 'custom-responsive',
            type: 'basic' as const,
            name: 'Div',
            props: {
              className: 'default',
            },
            responsive: {
              '0-599': {
                props: {
                  className: 'small-mobile',
                },
              },
              '600-899': {
                props: {
                  className: 'large-mobile',
                },
              },
            },
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ className: string }>;
        expect(element.props.className).toBe('small-mobile');
      });

      it('responsive text 오버라이드를 적용한다', () => {
        responsiveManager._setWidthForTesting(400);

        const children = [
          {
            id: 'responsive-text',
            type: 'basic' as const,
            name: 'Span',
            text: '데스크톱 텍스트',
            responsive: {
              mobile: {
                text: '모바일 텍스트',
              },
            },
          },
        ];

        const result = renderItemChildren(children, {}, componentMap, 'test');

        expect(result).toHaveLength(1);
        const element = result[0] as React.ReactElement<{ children: string }>;
        expect(element.props.children).toBe('모바일 텍스트');
      });
    });
  });

  describe('getEffectiveContext', () => {
    // Import the function dynamically to test
    let getEffectiveContext: any;

    beforeEach(async () => {
      const module = await import('../RenderHelpers');
      getEffectiveContext = module.getEffectiveContext;
    });

    it('should return baseContext when componentContext is undefined', () => {
      const baseContext = {
        _local: { user: { name: 'test' } },
        _global: { settings: { theme: 'dark' } },
      };

      const result = getEffectiveContext(baseContext, undefined);

      expect(result).toEqual(baseContext);
    });

    it('should merge componentContext.state into _local', () => {
      const baseContext = {
        _local: { user: { name: 'test' } },
        _global: { settings: { theme: 'dark' } },
      };
      const componentContext = {
        state: { filter: 'active', page: 1 },
      };

      const result = getEffectiveContext(baseContext, componentContext);

      expect(result._local).toEqual({
        user: { name: 'test' },
        filter: 'active',
        page: 1,
      });
      expect(result._global).toEqual({ settings: { theme: 'dark' } });
    });

    it('should include _isolated from isolatedContext', () => {
      const baseContext = {
        _local: { user: { name: 'test' } },
      };
      const componentContext = {
        state: { filter: 'active' },
        isolatedContext: {
          state: { step: 1, selectedItems: [1, 2, 3] },
          setState: vi.fn(),
          getState: vi.fn(),
          mergeState: vi.fn(),
        },
      };

      const result = getEffectiveContext(baseContext, componentContext);

      expect(result._isolated).toEqual({ step: 1, selectedItems: [1, 2, 3] });
      expect(result._local).toEqual({
        user: { name: 'test' },
        filter: 'active',
      });
    });

    it('should not include _isolated when isolatedContext is null', () => {
      const baseContext = {
        _local: { user: { name: 'test' } },
      };
      const componentContext = {
        state: { filter: 'active' },
        isolatedContext: null,
      };

      const result = getEffectiveContext(baseContext, componentContext);

      expect(result._isolated).toBeUndefined();
    });

    it('should handle empty baseContext._local', () => {
      const baseContext = {
        _global: { settings: {} },
      };
      const componentContext = {
        state: { newKey: 'value' },
      };

      const result = getEffectiveContext(baseContext, componentContext);

      expect(result._local).toEqual({ newKey: 'value' });
    });

    it('should preserve all three state layers', () => {
      const baseContext = {
        _local: { localData: true },
        _global: { globalData: true },
      };
      const componentContext = {
        state: { componentData: true },
        isolatedContext: {
          state: { isolatedData: true },
          setState: vi.fn(),
          getState: vi.fn(),
          mergeState: vi.fn(),
        },
      };

      const result = getEffectiveContext(baseContext, componentContext);

      expect(result._local).toEqual({ localData: true, componentData: true });
      expect(result._global).toEqual({ globalData: true });
      expect(result._isolated).toEqual({ isolatedData: true });
    });

    describe('parentDataContext 병합 (engine-v1.19.1)', () => {
      it('parentDataContext의 데이터소스 키가 컨텍스트에 병합된다', () => {
        const baseContext = {
          _local: { filter: 'active' },
          _global: { settings: {} },
        };
        const componentContext = {
          parentDataContext: {
            order: { data: { id: 1, total_amount: 10000 } },
            active_carriers: { data: [] },
          },
        };

        const result = getEffectiveContext(baseContext, componentContext);

        expect(result.order).toEqual({ data: { id: 1, total_amount: 10000 } });
        expect(result.active_carriers).toEqual({ data: [] });
      });

      it('기존 baseContext 키와 충돌 시 baseContext가 우선한다', () => {
        const baseContext = {
          _local: { filter: 'active' },
          _global: { settings: {} },
          order: { data: { id: 999, overridden: true } },
        };
        const componentContext = {
          parentDataContext: {
            order: { data: { id: 1, original: true } },
          },
        };

        const result = getEffectiveContext(baseContext, componentContext);

        // baseContext의 order가 우선
        expect(result.order).toEqual({ data: { id: 999, overridden: true } });
      });

      it('parentDataContext와 state가 함께 병합된다', () => {
        const baseContext = {
          _local: { page: 1 },
          _global: { theme: 'dark' },
        };
        const componentContext = {
          state: { selectedId: 5 },
          parentDataContext: {
            order: { data: { id: 1 } },
          },
        };

        const result = getEffectiveContext(baseContext, componentContext);

        expect(result.order).toEqual({ data: { id: 1 } });
        expect(result._local).toEqual({ page: 1, selectedId: 5 });
        expect(result._global).toEqual({ theme: 'dark' });
      });

      it('parentDataContext가 undefined면 병합하지 않는다', () => {
        const baseContext = {
          _local: { filter: 'active' },
        };
        const componentContext = {
          state: { page: 1 },
          parentDataContext: undefined,
        };

        const result = getEffectiveContext(baseContext, componentContext);

        expect(result._local).toEqual({ filter: 'active', page: 1 });
        expect(result.order).toBeUndefined();
      });

      it('_local, _global 등 예약 키는 parentDataContext에서 병합되지 않는다', () => {
        const baseContext = {
          _local: { original: true },
          _global: { original: true },
        };
        const componentContext = {
          parentDataContext: {
            _local: { malicious: true },
            _global: { malicious: true },
            order: { data: { id: 1 } },
          },
        };

        const result = getEffectiveContext(baseContext, componentContext);

        // _local, _global은 baseContext에 이미 존재하므로 덮어쓰지 않음
        expect(result._local).toEqual({ original: true });
        expect(result._global).toEqual({ original: true });
        // 새로운 키만 병합
        expect(result.order).toEqual({ data: { id: 1 } });
      });
    });
  });
});
