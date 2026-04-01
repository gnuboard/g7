/**
 * DynamicRenderer TranslationEngine 통합 테스트
 *
 * Task 19.3: DynamicRenderer에 TranslationEngine 통합
 *
 * 테스트 항목:
 * 1. $t:key 형식의 텍스트가 올바르게 번역되는지 확인
 * 2. props 내의 번역 키도 처리되는지 검증
 * 3. 중첩된 컴포넌트에서도 translations가 전달되어 번역이 작동하는지 테스트
 * 4. 번역 키가 없는 일반 텍스트는 그대로 렌더링되는지 확인
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import * as TransitionContextModule from '../TransitionContext';

// 테스트용 컴포넌트
const TestButton: React.FC<{
  text?: string;
  children?: React.ReactNode;
  'aria-label'?: string;
}> = ({
  text,
  children,
  'aria-label': ariaLabel,
}) => <button aria-label={ariaLabel}>{children || text}</button>;

const TestCard: React.FC<{ title?: string; children?: React.ReactNode }> = ({
  title,
  children,
}) => (
  <div data-testid="card">
    {title && <h2>{title}</h2>}
    {children}
  </div>
);

describe('DynamicRenderer - TranslationEngine 통합', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    // ComponentRegistry 설정 (싱글톤 사용)
    registry = ComponentRegistry.getInstance();

    // 비공개 메서드 우회하여 컴포넌트 직접 등록
    (registry as any).registry = {
      Button: {
        component: TestButton,
        metadata: { name: 'Button', type: 'basic' },
      },
      Card: {
        component: TestCard,
        metadata: { name: 'Card', type: 'composite' },
      },
    };

    // 나머지 엔진 초기화
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({
      navigate: vi.fn(),
    });

    // Translation Context 설정
    translationContext = {
      templateId: 'test-template',
      locale: 'ko',
    };

    // 테스트용 번역 데이터 로드
    const mockTranslations = {
      common: {
        save: '저장',
        cancel: '취소',
        delete: '삭제',
      },
      dashboard: {
        title: '대시보드',
        subtitle: '시스템 현황',
      },
      user: {
        welcome: '{name}님 환영합니다',
      },
    };

    // TranslationEngine에 번역 데이터 직접 주입 (비공개 메서드 우회)
    (translationEngine as any).translations.set(
      'test-template:ko',
      mockTranslations
    );
  });

  describe('1. $t:key 형식의 텍스트 번역', () => {
    it('text 속성의 $t:key가 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'button-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '$t:common.save',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('저장');
    });

    it('번역 키가 존재하지 않으면 키 자체를 반환해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'button-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '$t:nonexistent.key',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('nonexistent.key');
    });
  });

  describe('2. Props 내 번역 키 처리', () => {
    it('props의 $t:key가 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'card-1',
        type: 'composite',
        name: 'Card',
        props: {
          title: '$t:dashboard.title',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('heading')).toHaveTextContent('대시보드');
    });

    it('여러 props에서 동시에 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'button-3',
        type: 'basic',
        name: 'Button',
        props: {
          text: '$t:common.delete',
          'aria-label': '$t:common.delete',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const button = screen.getByRole('button');
      expect(button).toHaveTextContent('삭제');
      expect(button).toHaveAttribute('aria-label', '삭제');
    });
  });

  describe('3. 중첩된 컴포넌트에서 translations 전달', () => {
    it('자식 컴포넌트에도 번역이 적용되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'card-2',
        type: 'composite',
        name: 'Card',
        props: {
          title: '$t:dashboard.title',
        },
        children: [
          {
            id: 'button-4',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '$t:common.save',
          },
          {
            id: 'button-5',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '$t:common.cancel',
          },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('heading')).toHaveTextContent('대시보드');
      expect(screen.getAllByRole('button')[0]).toHaveTextContent('저장');
      expect(screen.getAllByRole('button')[1]).toHaveTextContent('취소');
    });

    it('깊이 중첩된 컴포넌트에도 번역이 적용되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'card-3',
        type: 'composite',
        name: 'Card',
        children: [
          {
            id: 'card-4',
            type: 'composite',
            name: 'Card',
            props: {
              title: '$t:dashboard.subtitle',
            },
            children: [
              {
                id: 'button-6',
                type: 'basic',
                name: 'Button',
                props: {},
                text: '$t:common.delete',
              },
            ],
          },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('heading')).toHaveTextContent('시스템 현황');
      expect(screen.getByRole('button')).toHaveTextContent('삭제');
    });
  });

  describe('4. 일반 텍스트 렌더링 (번역 키 없음)', () => {
    it('$t: 접두사가 없는 텍스트는 그대로 렌더링되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'button-7',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '일반 텍스트',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('일반 텍스트');
    });

    it('번역 키와 일반 텍스트가 혼용된 경우', () => {
      const componentDef: ComponentDefinition = {
        id: 'card-5',
        type: 'composite',
        name: 'Card',
        props: {
          title: '$t:dashboard.title',
        },
        children: [
          {
            id: 'button-8',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '$t:common.save',
          },
          {
            id: 'button-9',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '사용자 정의 버튼',
          },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('heading')).toHaveTextContent('대시보드');
      expect(screen.getAllByRole('button')[0]).toHaveTextContent('저장');
      expect(screen.getAllByRole('button')[1]).toHaveTextContent(
        '사용자 정의 버튼'
      );
    });
  });

  describe('5. 번역 + 데이터 바인딩 조합 (보너스)', () => {
    it('번역 텍스트 내 파라미터가 데이터 컨텍스트로 치환되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'button-10',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '$t:user.welcome|name={{userName}}',
      };

      const dataContext = {
        userName: '홍길동',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={dataContext}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('홍길동님 환영합니다');
    });
  });

  describe('6. Lifecycle 핸들러 (onMount/onUnmount)', () => {
    it('onMount 액션이 컴포넌트 마운트 시 실행되어야 함', async () => {
      // 커스텀 핸들러 mock
      const mockOnMount = vi.fn();
      actionDispatcher.registerHandler('testOnMount', mockOnMount);

      const componentDef: ComponentDefinition = {
        id: 'lifecycle-test-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Mount Test',
        lifecycle: {
          onMount: [
            {
              type: 'click',
              handler: 'testOnMount',
              target: 'test-value',
            },
          ],
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ testData: 'hello' }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 버튼이 렌더링되었는지 확인
      expect(screen.getByRole('button')).toHaveTextContent('Mount Test');

      // onMount 핸들러가 호출되었는지 확인
      expect(mockOnMount).toHaveBeenCalled();
    });

    it('여러 onMount 액션이 순차적으로 실행되어야 함', async () => {
      const executionOrder: string[] = [];

      const mockHandler1 = vi.fn(() => {
        executionOrder.push('handler1');
      });
      const mockHandler2 = vi.fn(() => {
        executionOrder.push('handler2');
      });

      actionDispatcher.registerHandler('mountHandler1', mockHandler1);
      actionDispatcher.registerHandler('mountHandler2', mockHandler2);

      const componentDef: ComponentDefinition = {
        id: 'lifecycle-test-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Multiple Mount Test',
        lifecycle: {
          onMount: [
            { type: 'click', handler: 'mountHandler1' },
            { type: 'click', handler: 'mountHandler2' },
          ],
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(mockHandler1).toHaveBeenCalled();
      expect(mockHandler2).toHaveBeenCalled();
      expect(executionOrder).toEqual(['handler1', 'handler2']);
    });

    it('onMount 액션에서 데이터 컨텍스트에 접근할 수 있어야 함', async () => {
      let receivedContext: any = null;

      const mockHandler = vi.fn((_action, context) => {
        receivedContext = context;
      });
      actionDispatcher.registerHandler('contextHandler', mockHandler);

      const componentDef: ComponentDefinition = {
        id: 'lifecycle-test-3',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Context Test',
        lifecycle: {
          onMount: [
            {
              type: 'click',
              handler: 'contextHandler',
              target: '{{testValue}}',
            },
          ],
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ testValue: 'context-data' }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(mockHandler).toHaveBeenCalled();
      // context.data에 testValue가 포함되어 있어야 함
      expect(receivedContext?.data?.testValue).toBe('context-data');
    });

    it('onUnmount 액션이 컴포넌트 언마운트 시 실행되어야 함', async () => {
      const mockOnUnmount = vi.fn();
      actionDispatcher.registerHandler('testOnUnmount', mockOnUnmount);

      const componentDef: ComponentDefinition = {
        id: 'lifecycle-test-4',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Unmount Test',
        lifecycle: {
          onUnmount: [
            {
              type: 'click',
              handler: 'testOnUnmount',
            },
          ],
        },
      };

      const { unmount } = render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 마운트 시점에는 onUnmount가 호출되지 않음
      expect(mockOnUnmount).not.toHaveBeenCalled();

      // 컴포넌트 언마운트
      unmount();

      // 언마운트 후 onUnmount 핸들러가 호출되었는지 확인
      expect(mockOnUnmount).toHaveBeenCalled();
    });

    it('lifecycle이 없는 컴포넌트는 정상적으로 렌더링되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'no-lifecycle',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'No Lifecycle',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('No Lifecycle');
    });
  });

  describe('7. text 속성 복합 바인딩', () => {
    it('text 속성에서 {{}} 바인딩이 처리되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'text-binding-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '{{userName}}님 환영합니다',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ userName: '홍길동' }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('홍길동님 환영합니다');
    });

    it('text 속성에서 복잡한 표현식 (||)이 처리되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'text-binding-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '{{_global.message || "기본 메시지"}}',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ _global: {} }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('기본 메시지');
    });

    it('text 속성에서 표현식 결과가 $t:인 경우 번역되어야 함 (따옴표 없이)', () => {
      const componentDef: ComponentDefinition = {
        id: 'text-binding-3',
        type: 'basic',
        name: 'Button',
        props: {},
        // 따옴표 없이 $t: 사용 - DataBindingEngine에서 자동 변환
        text: '{{_global.errorMessage || $t:common.save}}',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ _global: {} }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('저장');
    });

    it('text 속성에서 _global 값이 있으면 해당 값이 표시되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'text-binding-4',
        type: 'basic',
        name: 'Button',
        props: {},
        // 따옴표 없이 $t: 사용
        text: '{{_global.errorMessage || $t:common.save}}',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ _global: { errorMessage: '서버 오류가 발생했습니다.' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('서버 오류가 발생했습니다.');
    });
  });

  describe('8. className 복잡한 표현식 처리', () => {
    // 테스트용 Div 컴포넌트 등록
    beforeEach(() => {
      const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({
        className,
        children,
      }) => <div data-testid="test-div" className={className}>{children}</div>;

      (registry as any).registry.Div = {
        component: TestDiv,
        metadata: { name: 'Div', type: 'basic' },
      };
    });

    it('className에서 삼항 연산자 표현식이 평가되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'className-test-1',
        type: 'basic',
        name: 'Div',
        props: {
          className: "{{row.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}}",
        },
        text: 'Status Badge',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ row: { status: 'active' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const div = screen.getByTestId('test-div');
      expect(div).toHaveClass('bg-green-100', 'text-green-800');
    });

    it('className에서 중첩된 삼항 연산자가 평가되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'className-test-2',
        type: 'basic',
        name: 'Div',
        props: {
          className: "{{row.status_variant === 'success' ? 'bg-green-100' : row.status_variant === 'danger' ? 'bg-red-100' : 'bg-gray-100'}}",
        },
        text: 'Nested Ternary',
      };

      // success 상태 테스트
      const { rerender } = render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ row: { status_variant: 'success' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );
      expect(screen.getByTestId('test-div')).toHaveClass('bg-green-100');

      // danger 상태 테스트
      rerender(
        <DynamicRenderer
          componentDef={{ ...componentDef, id: 'className-test-2-danger' }}
          dataContext={{ row: { status_variant: 'danger' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );
      expect(screen.getByTestId('test-div')).toHaveClass('bg-red-100');

      // 기본 상태 테스트
      rerender(
        <DynamicRenderer
          componentDef={{ ...componentDef, id: 'className-test-2-default' }}
          dataContext={{ row: { status_variant: 'other' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );
      expect(screen.getByTestId('test-div')).toHaveClass('bg-gray-100');
    });

    it('className에서 논리 연산자(||)가 평가되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'className-test-3',
        type: 'basic',
        name: 'Div',
        props: {
          className: "{{row.customClass || 'default-class'}}",
        },
        text: 'Logical OR',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ row: {} }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('test-div')).toHaveClass('default-class');
    });

    it('className에서 문자열 리터럴 내 특수문자가 처리되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'className-test-4',
        type: 'basic',
        name: 'Div',
        props: {
          className: "{{row.active ? 'px-2.5 py-0.5 rounded-full' : 'px-1 py-1'}}",
        },
        text: 'Special Characters',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ row: { active: true } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const div = screen.getByTestId('test-div');
      expect(div).toHaveClass('px-2.5', 'py-0.5', 'rounded-full');
    });
  });

  describe('9. blur_until_loaded 속성', () => {
    // 테스트용 Div 컴포넌트 등록
    beforeEach(() => {
      const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({
        className,
        children,
      }) => <div data-testid="blur-test-div" className={className}>{children}</div>;

      (registry as any).registry.Div = {
        component: TestDiv,
        metadata: { name: 'Div', type: 'basic' },
      };
    });

    it('blur_until_loaded가 true이고 isTransitioning이 true일 때 blur 래퍼가 적용되어야 함', () => {
      // useTransitionState 모킹 - isTransitioning: true
      vi.spyOn(TransitionContextModule, 'useTransitionState').mockReturnValue({
        isTransitioning: true,
      });

      const componentDef: ComponentDefinition = {
        id: 'blur-test-1',
        type: 'basic',
        name: 'Div',
        blur_until_loaded: true,
        props: {
          className: 'content-area',
        },
        text: 'Loading Content',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // blur 래퍼가 적용되었는지 확인
      const blurWrapper = screen.getByTestId('blur-test-div').parentElement;
      expect(blurWrapper).toHaveClass('opacity-50', 'blur-sm', 'pointer-events-none');
    });

    it('blur_until_loaded가 true이고 isTransitioning이 false일 때 blur 래퍼가 적용되지 않아야 함', () => {
      // useTransitionState 모킹 - isTransitioning: false
      vi.spyOn(TransitionContextModule, 'useTransitionState').mockReturnValue({
        isTransitioning: false,
      });

      const componentDef: ComponentDefinition = {
        id: 'blur-test-2',
        type: 'basic',
        name: 'Div',
        blur_until_loaded: true,
        props: {
          className: 'content-area',
        },
        text: 'Loaded Content',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // blur 래퍼가 적용되지 않았는지 확인
      const div = screen.getByTestId('blur-test-div');
      const parent = div.parentElement;
      // 부모가 blur 클래스를 가지지 않아야 함
      expect(parent).not.toHaveClass('blur-sm');
    });

    it('blur_until_loaded가 없으면 isTransitioning 상태와 관계없이 blur가 적용되지 않아야 함', () => {
      // useTransitionState 모킹 - isTransitioning: true
      vi.spyOn(TransitionContextModule, 'useTransitionState').mockReturnValue({
        isTransitioning: true,
      });

      const componentDef: ComponentDefinition = {
        id: 'blur-test-3',
        type: 'basic',
        name: 'Div',
        // blur_until_loaded 속성 없음
        props: {
          className: 'content-area',
        },
        text: 'No Blur Content',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // blur 래퍼가 적용되지 않았는지 확인
      const div = screen.getByTestId('blur-test-div');
      const parent = div.parentElement;
      expect(parent).not.toHaveClass('blur-sm');
    });

    it('blur_until_loaded가 false이면 blur가 적용되지 않아야 함', () => {
      // useTransitionState 모킹 - isTransitioning: true
      vi.spyOn(TransitionContextModule, 'useTransitionState').mockReturnValue({
        isTransitioning: true,
      });

      const componentDef: ComponentDefinition = {
        id: 'blur-test-4',
        type: 'basic',
        name: 'Div',
        blur_until_loaded: false,
        props: {
          className: 'content-area',
        },
        text: 'Explicit No Blur',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // blur 래퍼가 적용되지 않았는지 확인
      const div = screen.getByTestId('blur-test-div');
      const parent = div.parentElement;
      expect(parent).not.toHaveClass('blur-sm');
    });

    afterEach(() => {
      vi.restoreAllMocks();
    });
  });

  // 8. Responsive 오버라이드 테스트는 별도 파일 (DynamicRenderer.responsive.test.tsx)로 분리
  // vi.mock이 파일 최상위에서 호출되어야 하므로 별도 파일에서 테스트

  describe('10. onComponentEvent 구독 패턴', () => {
    // G7Core.componentEvent 모킹
    let mockOn: ReturnType<typeof vi.fn>;
    let mockEmit: ReturnType<typeof vi.fn>;
    let mockOff: ReturnType<typeof vi.fn>;
    let subscribers: Map<string, Array<(data?: any) => any>>;

    beforeEach(() => {
      // 구독자 저장소 초기화
      subscribers = new Map();

      // on 함수 모킹 - 구독자를 저장하고 unsubscribe 함수 반환
      mockOn = vi.fn((eventName: string, callback: (data?: any) => any) => {
        if (!subscribers.has(eventName)) {
          subscribers.set(eventName, []);
        }
        subscribers.get(eventName)!.push(callback);

        // unsubscribe 함수 반환
        return () => {
          const callbacks = subscribers.get(eventName);
          if (callbacks) {
            const index = callbacks.indexOf(callback);
            if (index > -1) {
              callbacks.splice(index, 1);
            }
          }
        };
      });

      // emit 함수 모킹 - 모든 구독자에게 이벤트 전달
      mockEmit = vi.fn(async (eventName: string, data?: any) => {
        const callbacks = subscribers.get(eventName) || [];
        const results: any[] = [];
        for (const callback of callbacks) {
          const result = await callback(data);
          results.push(result);
        }
        return results;
      });

      // off 함수 모킹
      mockOff = vi.fn((eventName: string) => {
        subscribers.delete(eventName);
      });

      // window.G7Core 설정
      (window as any).G7Core = {
        componentEvent: {
          on: mockOn,
          emit: mockEmit,
          off: mockOff,
        },
      };
    });

    afterEach(() => {
      delete (window as any).G7Core;
      subscribers.clear();
    });

    it('onComponentEvent가 있는 컴포넌트가 마운트되면 이벤트가 구독되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'event-subscribe-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Subscribe Test',
        onComponentEvent: [
          {
            event: 'upload:site_logo',
            handler: 'refetchDataSource',
            params: { id: 'site_settings' },
          },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 이벤트 구독이 호출되었는지 확인
      expect(mockOn).toHaveBeenCalledWith('upload:site_logo', expect.any(Function));
    });

    it('여러 이벤트가 정의된 경우 모두 구독되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'multi-event-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Multi Event Test',
        onComponentEvent: [
          { event: 'event:one', handler: 'handler1' },
          { event: 'event:two', handler: 'handler2' },
          { event: 'event:three', handler: 'handler3' },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 모든 이벤트가 구독되었는지 확인
      expect(mockOn).toHaveBeenCalledTimes(3);
      expect(mockOn).toHaveBeenCalledWith('event:one', expect.any(Function));
      expect(mockOn).toHaveBeenCalledWith('event:two', expect.any(Function));
      expect(mockOn).toHaveBeenCalledWith('event:three', expect.any(Function));
    });

    it('이벤트가 발생하면 핸들러가 실행되어야 함', async () => {
      const mockHandler = vi.fn();
      actionDispatcher.registerHandler('testEventHandler', mockHandler);

      const componentDef: ComponentDefinition = {
        id: 'event-handler-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Handler Test',
        onComponentEvent: [
          {
            event: 'test:event',
            handler: 'testEventHandler',
            params: { value: 'test-param' },
          },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 이벤트 발생
      await mockEmit('test:event', { customData: 'hello' });

      // 핸들러가 호출되었는지 확인
      expect(mockHandler).toHaveBeenCalled();
    });

    it('이벤트 데이터가 _eventData 컨텍스트로 핸들러에 전달되어야 함', async () => {
      let receivedContext: any = null;

      const mockHandler = vi.fn((_action, context) => {
        receivedContext = context;
      });
      actionDispatcher.registerHandler('contextEventHandler', mockHandler);

      const componentDef: ComponentDefinition = {
        id: 'event-context-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Context Test',
        onComponentEvent: [
          {
            event: 'data:event',
            handler: 'contextEventHandler',
          },
        ],
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ existingData: 'value' }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 이벤트 발생 (데이터 포함)
      await mockEmit('data:event', { eventPayload: 'test-payload' });

      // _eventData가 컨텍스트에 포함되어 있어야 함
      expect(mockHandler).toHaveBeenCalled();
      expect(receivedContext?.data?._eventData).toEqual({ eventPayload: 'test-payload' });
    });

    it('컴포넌트 언마운트 시 이벤트 구독이 해제되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'event-unsubscribe-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Unsubscribe Test',
        onComponentEvent: [
          { event: 'cleanup:event', handler: 'cleanupHandler' },
        ],
      };

      const { unmount } = render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 마운트 시 구독자가 1개 있어야 함
      expect(subscribers.get('cleanup:event')?.length).toBe(1);

      // 언마운트
      unmount();

      // 언마운트 후 구독자가 제거되어야 함
      expect(subscribers.get('cleanup:event')?.length).toBe(0);
    });

    it('onComponentEvent가 없는 컴포넌트는 정상적으로 렌더링되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'no-event-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'No Event',
        // onComponentEvent 없음
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button')).toHaveTextContent('No Event');
      // 이벤트 구독이 호출되지 않아야 함
      expect(mockOn).not.toHaveBeenCalled();
    });

    it('event 이름이 없는 정의는 무시되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'empty-event-test',
        type: 'basic',
        name: 'Button',
        props: {},
        text: 'Empty Event',
        onComponentEvent: [
          { event: '', handler: 'someHandler' },
          { event: 'valid:event', handler: 'validHandler' },
        ] as any,
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 빈 이벤트 이름은 무시되고 유효한 것만 구독
      expect(mockOn).toHaveBeenCalledTimes(1);
      expect(mockOn).toHaveBeenCalledWith('valid:event', expect.any(Function));
    });
  });

  /**
   * Regression Tests
   * @see .claude/docs/frontend/troubleshooting-state-closure.md
   */
  describe('Regression Tests - Stale Closure Prevention', () => {
    /**
     * [TS-CLOSURE-1] 체크박스 되돌림 방지
     * 버그: PermissionTree에서 체크박스 클릭 시 상태가 변경되었다가 즉시 원래대로 되돌아감
     * 원인: componentContext가 이전 상태를 캡처하여 새 상태를 덮어씌움
     * 해결: getter 함수 패턴 적용 (parentComponentContext)
     */
    describe('[TS-CLOSURE-1] componentContext getter 함수 패턴', () => {
      it('DynamicRenderer가 렌더링될 때 componentContext가 올바른 구조를 가져야 함', () => {
        // 컴포넌트 정의 - 상태를 사용하는 간단한 컴포넌트
        const componentDef: ComponentDefinition = {
          id: 'closure-test-1',
          type: 'basic',
          name: 'Button',
          props: {
            text: '테스트 버튼',
          },
        };

        // 렌더링 - componentContext는 내부적으로 생성됨
        const { container } = render(
          <DynamicRenderer
            componentDef={componentDef}
            dataContext={{ _local: { testValue: 'initial' } }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 컴포넌트가 정상 렌더링되어야 함
        expect(container.querySelector('button')).toBeTruthy();
      });
    });

    /**
     * [TS-CLOSURE-2] stateRef 패턴 - expandChildren 내부 _local 상태
     * 버그: DataGrid의 expandChildren 내부 체크박스 클릭 시 UI가 업데이트되지 않음
     * 원인: useCallback으로 메모이제이션된 함수가 이전 state 값을 참조
     * 해결: stateRef.current를 사용하여 항상 최신 상태 참조
     *
     * NOTE: 캐시 무효화 테스트는 DataBindingEngine.test.ts에서 담당합니다.
     * 여기서는 DynamicRenderer의 _local 바인딩 기본 기능만 테스트합니다.
     *
     * @see troubleshooting-cache.md 사례 8: SPA 네비게이션 후 _localInit 캐시 미무효화
     * @see DataBindingEngine.test.ts [TS-CACHE-8] 캐시 무효화 테스트
     */
    describe('[TS-CLOSURE-2] stateRef를 통한 최신 _local 상태 참조', () => {
      it('캐시 무효화 후 새로운 _local 상태가 반영되어야 함', () => {
        // 첫 번째 렌더링
        const componentDef1: ComponentDefinition = {
          id: 'stateref-cache-invalidation-1',
          type: 'basic',
          name: 'Button',
          props: {
            text: '{{_local.buttonText}}',
          },
        };

        const { container: container1 } = render(
          <DynamicRenderer
            componentDef={componentDef1}
            dataContext={{ _local: { buttonText: '첫 번째 값' } }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        expect(container1.querySelector('button')?.textContent).toBe('첫 번째 값');

        // 캐시 무효화 (실제 SPA에서는 _localInit 처리 시 호출됨)
        bindingEngine.invalidateCacheByKeys(['_local']);

        // 새로운 _local 값으로 새 컴포넌트 렌더링
        const componentDef2: ComponentDefinition = {
          id: 'stateref-cache-invalidation-2',
          type: 'basic',
          name: 'Button',
          props: {
            text: '{{_local.buttonText}}',
          },
        };

        const { container: container2 } = render(
          <DynamicRenderer
            componentDef={componentDef2}
            dataContext={{ _local: { buttonText: '두 번째 값' } }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 캐시 무효화 후 새로운 값이 반영되어야 함
        expect(container2.querySelector('button')?.textContent).toBe('두 번째 값');
      });

      it('동일한 컴포넌트 ID에서 _local 바인딩이 정상 해석되어야 함', () => {
        const componentDef: ComponentDefinition = {
          id: 'stateref-binding-test',
          type: 'basic',
          name: 'Button',
          props: {
            text: '{{_local.count}}개',
          },
        };

        // _local 상태가 올바르게 바인딩되는지 확인
        const { container } = render(
          <DynamicRenderer
            componentDef={componentDef}
            dataContext={{ _local: { count: 5 } }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        expect(container.querySelector('button')?.textContent).toBe('5개');
      });
    });

    /**
     * [TS-CLOSURE-3] computedRef 패턴 - expandChildren 내부 _computed 상태
     * 버그: expandChildren에서 _computed 기반 표현식이 항상 빈 배열 반환
     * 원인: 캐싱된 globalState._computed가 재계산되지 않은 이전 값 참조
     * 해결: computedRef.current를 사용하여 항상 최신 computed 값 참조
     */
    describe('[TS-CLOSURE-3] computedRef를 통한 최신 _computed 상태 참조', () => {
      it('dataContext._computed가 변경되어도 리렌더링이 발생해야 함', () => {
        const componentDef: ComponentDefinition = {
          id: 'computedref-test',
          type: 'basic',
          name: 'Button',
          props: {
            text: '{{_computed.computedText ?? "기본값"}}',
          },
        };

        // 초기 렌더링 (computed 값 없음)
        const { rerender, container } = render(
          <DynamicRenderer
            componentDef={componentDef}
            dataContext={{ _local: {}, _computed: {} }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 기본값 확인
        expect(container.querySelector('button')?.textContent).toBe('기본값');

        // _computed 상태 변경으로 리렌더링
        rerender(
          <DynamicRenderer
            componentDef={componentDef}
            dataContext={{
              _local: {},
              _computed: { computedText: '계산된 텍스트' },
            }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 변경된 computed 값 확인
        expect(container.querySelector('button')?.textContent).toBe('계산된 텍스트');
      });
    });

    /**
     * 상태 변경 시 자식 컴포넌트에도 최신 상태가 전달되어야 함
     * 중첩된 컴포넌트에서도 stale closure 문제가 발생하지 않아야 함
     *
     * NOTE: 캐시 무효화 테스트는 DataBindingEngine.test.ts에서 담당합니다.
     * 여기서는 DynamicRenderer의 중첩 컴포넌트 바인딩 기본 기능만 테스트합니다.
     */
    describe('중첩 컴포넌트에서의 상태 전파', () => {
      it('캐시 무효화 후 중첩 컴포넌트도 새로운 _local 상태가 반영되어야 함', () => {
        // 첫 번째 렌더링 (중첩 컴포넌트)
        const componentDef1: ComponentDefinition = {
          id: 'nested-cache-1',
          type: 'composite',
          name: 'Card',
          props: {
            title: '{{_local.cardTitle}}',
          },
          children: [
            {
              id: 'nested-button-cache-1',
              type: 'basic',
              name: 'Button',
              props: {
                text: '{{_local.buttonText}}',
              },
            },
          ],
        };

        const { container: container1 } = render(
          <DynamicRenderer
            componentDef={componentDef1}
            dataContext={{
              _local: { cardTitle: '초기 제목', buttonText: '초기 버튼' },
            }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 초기 값 확인
        expect(container1.querySelector('h2')?.textContent).toBe('초기 제목');
        expect(container1.querySelector('button')?.textContent).toBe('초기 버튼');

        // 캐시 무효화 (실제 SPA에서는 _localInit 처리 시 호출됨)
        bindingEngine.invalidateCacheByKeys(['_local']);

        // 두 번째 렌더링 (중첩 컴포넌트)
        const componentDef2: ComponentDefinition = {
          id: 'nested-cache-2',
          type: 'composite',
          name: 'Card',
          props: {
            title: '{{_local.cardTitle}}',
          },
          children: [
            {
              id: 'nested-button-cache-2',
              type: 'basic',
              name: 'Button',
              props: {
                text: '{{_local.buttonText}}',
              },
            },
          ],
        };

        const { container: container2 } = render(
          <DynamicRenderer
            componentDef={componentDef2}
            dataContext={{
              _local: { cardTitle: '변경된 제목', buttonText: '변경된 버튼' },
            }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 캐시 무효화 후 자식 컴포넌트에도 최신 상태가 전달되어야 함
        expect(container2.querySelector('h2')?.textContent).toBe('변경된 제목');
        expect(container2.querySelector('button')?.textContent).toBe('변경된 버튼');
      });

      it('동일 컴포넌트에서 초기 _local 값이 정상 바인딩되어야 함', () => {
        const componentDef: ComponentDefinition = {
          id: 'nested-initial-binding-test',
          type: 'composite',
          name: 'Card',
          props: {
            title: '{{_local.cardTitle}}',
          },
          children: [
            {
              id: 'nested-initial-button',
              type: 'basic',
              name: 'Button',
              props: {
                text: '{{_local.buttonText}}',
              },
            },
          ],
        };

        const { container } = render(
          <DynamicRenderer
            componentDef={componentDef}
            dataContext={{
              _local: { cardTitle: '카드 제목', buttonText: '버튼 텍스트' },
            }}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
          />
        );

        // 중첩 컴포넌트에서 _local 값이 정상 바인딩되는지 확인
        expect(container.querySelector('h2')?.textContent).toBe('카드 제목');
        expect(container.querySelector('button')?.textContent).toBe('버튼 텍스트');
      });
    });
  });

  describe('13. text 속성에서 파이프 연산자 처리', () => {
    // 테스트용 Span 컴포넌트 등록
    const TestSpan: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
      <span data-testid="test-span">{children}</span>
    );

    beforeEach(() => {
      (registry as any).registry.Span = {
        component: TestSpan,
        metadata: { name: 'Span', type: 'basic' },
      };
    });

    it('text에서 date 파이프가 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-date',
        type: 'basic',
        name: 'Span',
        text: "{{item.created_at | date('YYYY-MM-DD')}}",
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { created_at: '2024-06-15T10:30:00Z' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('test-span').textContent).toBe('2024-06-15');
    });

    it('text에서 datetime 파이프가 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-datetime',
        type: 'basic',
        name: 'Span',
        text: "{{item.updated_at | datetime('YYYY-MM-DD HH:mm:ss')}}",
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { updated_at: '2024-06-15T10:30:45Z' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // UTC 시간이므로 로컬 타임존에 따라 다를 수 있음 - 포맷만 확인
      const text = screen.getByTestId('test-span').textContent ?? '';
      expect(text).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    });

    it('text에서 nullish coalescing과 파이프 조합이 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-nullish',
        type: 'basic',
        name: 'Span',
        text: "{{(item.updated_at ?? item.created_at) | date('YYYY-MM-DD')}}",
      };

      // updated_at이 null인 경우 created_at 사용
      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { created_at: '2024-01-15T10:00:00Z', updated_at: null } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('test-span').textContent).toBe('2024-01-15');
    });

    it('text에서 optional chaining과 파이프 조합이 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-optional',
        type: 'basic',
        name: 'Span',
        text: "{{item?.created_at | date('YYYY-MM-DD')}}",
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { created_at: '2024-03-20T08:15:00Z' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('test-span').textContent).toBe('2024-03-20');
    });

    it('text에서 number 파이프가 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-number',
        type: 'basic',
        name: 'Span',
        text: '{{item.price | number}}',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { price: 1234567 } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 천단위 구분자가 적용되어야 함
      expect(screen.getByTestId('test-span').textContent).toBe('1,234,567');
    });

    it('text에서 truncate 파이프가 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-truncate',
        type: 'basic',
        name: 'Span',
        text: "{{item.description | truncate(10, '...')}}",
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { description: 'This is a very long description text' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('test-span').textContent).toBe('This is a ...');
    });

    it('text에서 파이프 체인이 정상 동작해야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'pipe-test-chain',
        type: 'basic',
        name: 'Span',
        text: '{{item.name | uppercase | truncate(5)}}',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ item: { name: 'hello world' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('test-span').textContent).toBe('HELLO...');
    });
  });

  describe('JSON 구조 문자열 내 $t: 토큰 보존', () => {
    it('JSON 객체 문자열 내부의 $t: 토큰이 번역되지 않아야 함', () => {
      // CodeEditor value prop 시나리오: 레이아웃 JSON 문자열에 $t: 토큰 포함
      const jsonContent = JSON.stringify({
        text: '$t:common.save',
        title: '$t:dashboard.title',
      });

      const componentDef: ComponentDefinition = {
        id: 'editor-1',
        type: 'basic',
        name: 'Button',
        props: {
          text: jsonContent,
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // JSON 문자열 내부의 $t: 토큰은 번역되지 않고 원본 그대로 유지
      const button = screen.getByRole('button');
      expect(button.textContent).toContain('$t:common.save');
      expect(button.textContent).toContain('$t:dashboard.title');
      // 번역된 값이 아님을 확인
      expect(button.textContent).not.toContain('저장');
      expect(button.textContent).not.toContain('대시보드');
    });

    it('JSON 배열 문자열 내부의 $t: 토큰이 번역되지 않아야 함', () => {
      const jsonContent = JSON.stringify([
        { label: '$t:common.save' },
        { label: '$t:common.cancel' },
      ]);

      const componentDef: ComponentDefinition = {
        id: 'editor-2',
        type: 'basic',
        name: 'Button',
        props: {
          text: jsonContent,
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const button = screen.getByRole('button');
      expect(button.textContent).toContain('$t:common.save');
      expect(button.textContent).toContain('$t:common.cancel');
    });

    it('일반 $t: 토큰은 여전히 정상 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'btn-normal',
        type: 'basic',
        name: 'Button',
        props: {
          text: '$t:common.save',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button').textContent).toBe('저장');
    });

    it('접두사가 있는 $t: 토큰도 정상 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'btn-prefix',
        type: 'basic',
        name: 'Button',
        props: {
          text: '27$t:common.save',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button').textContent).toBe('27저장');
    });
  });

  describe('raw: 바인딩 번역 면제 (engine-v1.27.0)', () => {
    it('{{raw:path}} 바인딩 결과의 $t: 토큰이 번역되지 않아야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'raw-1',
        type: 'basic',
        name: 'Button',
        props: {
          text: '{{raw:post.title}}',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ post: { title: '$t:admin.dashboard' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // raw 마커가 제거되고 원본 텍스트가 그대로 표시
      expect(screen.getByRole('button').textContent).toBe('$t:admin.dashboard');
    });

    it('raw: 없는 바인딩의 $t: 토큰은 정상 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'raw-2',
        type: 'basic',
        name: 'Button',
        props: {
          text: '$t:common.save',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 번역된 값으로 표시
      expect(screen.getByRole('button').textContent).toBe('저장');
    });

    it('혼합 보간 — raw 마커 영역은 보호되고 외부 $t:는 번역되어야 함', () => {
      const componentDef: ComponentDefinition = {
        id: 'raw-3',
        type: 'basic',
        name: 'Button',
        props: {
          text: '{{raw:post.title}} - $t:common.save',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ post: { title: 'Hello $t:admin.dashboard' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const text = screen.getByRole('button').textContent;
      // raw 영역: $t:admin.dashboard가 번역되지 않고 보존
      expect(text).toContain('Hello $t:admin.dashboard');
      // 외부 영역: $t:common.save는 번역됨
      expect(text).toContain('저장');
    });

    it('$t: 패턴이 없는 raw 바인딩은 정상 표시', () => {
      const componentDef: ComponentDefinition = {
        id: 'raw-4',
        type: 'basic',
        name: 'Button',
        props: {
          text: '{{raw:post.title}}',
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ post: { title: '일반 제목 텍스트' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByRole('button').textContent).toBe('일반 제목 텍스트');
    });
  });
});
