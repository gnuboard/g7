/**
 * DynamicRenderer.sourceMetaFiltering.test.tsx
 *
 * 출처 메타 키 React props 필터링 안전망 검증.
 *
 * 백엔드가 `with_source_meta=1` 옵션 응답에 `__source` 메타를 부여할 수 있고,
 * 일반 사이트 렌더 응답에 누수가 발생하더라도 React props 로 DOM 에 전달되지
 * 않도록 차단되어야 한다. `__` 접두사를 가진 모든 메타 키는 Basic/Layout
 * 컴포넌트의 DOM 전달 직전에 일괄 차단된다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { vi } from 'vitest';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId, ...rest }) => (
  <div data-testid={testId || 'test-div'} className={className} {...rest}>
    {children}
  </div>
);

describe('DynamicRenderer — `__` 시작 메타 키 React props 필터링', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: {
        component: TestDiv,
        metadata: { name: 'Div', type: 'basic' },
      },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  it('Basic 컴포넌트의 DOM 에 `__source` 메타가 노출되지 않는다', () => {
    // 백엔드 with_source_meta=1 응답에 `__source` 가 포함된 노드를 시뮬레이션
    const componentDef: ComponentDefinition & { __source?: any } = {
      id: 'test-basic-meta',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'basic-target', className: 'visible-class' },
      __source: { kind: 'base', layout: '_admin_base' },
    } as any;

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

    const el = screen.getByTestId('basic-target');
    // 일반 props 는 그대로 노출
    expect(el.className).toContain('visible-class');
    // `__source` 메타는 DOM 에 노출되지 않음 (data-__source, __source 어느 형태로도)
    expect(el.outerHTML).not.toContain('__source');
    expect(el.outerHTML).not.toContain('base');
    expect(el.outerHTML).not.toContain('_admin_base');
  });

  it('`__` 접두사를 가진 임의 키는 모두 차단된다', () => {
    const componentDef: ComponentDefinition & {
      __source?: any;
      __anyOtherMeta?: any;
    } = {
      id: 'test-arbitrary',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'arbitrary-target' },
      __source: { kind: 'extension', extensionId: 42 },
      __anyOtherMeta: 'secret-internal-value',
    } as any;

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

    const el = screen.getByTestId('arbitrary-target');
    expect(el.outerHTML).not.toContain('__source');
    expect(el.outerHTML).not.toContain('__anyOtherMeta');
    expect(el.outerHTML).not.toContain('secret-internal-value');
    expect(el.outerHTML).not.toContain('extensionId');
  });

  it('일반 `data-*` 속성은 통과한다 (regression 가드)', () => {
    const componentDef: ComponentDefinition = {
      id: 'test-data-attr',
      type: 'basic',
      name: 'Div',
      props: {
        'data-testid': 'data-attr-target',
        'data-foo': 'bar',
        'aria-label': 'sample',
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

    const el = screen.getByTestId('data-attr-target');
    expect(el.getAttribute('data-foo')).toBe('bar');
    expect(el.getAttribute('aria-label')).toBe('sample');
  });
});
