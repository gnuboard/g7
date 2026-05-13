/**
 * extensionPointProps/extensionPointCallbacks 테스트
 *
 * engine-v1.28.0: props/callbacks 분리
 * - extensionPointProps: resolveObject()로 재귀 표현식 평가
 * - extensionPointCallbacks: 평가 없이 그대로 전달
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestSpan: React.FC<{
  value?: any;
  items?: any;
  mapping?: any;
  callback?: any;
  'data-testid'?: string;
  children?: React.ReactNode;
}> = ({ value, items, mapping, callback, 'data-testid': testId, children }) => (
  <span data-testid={testId || 'test-span'}>
    {value !== undefined ? String(value) : ''}
    {items !== undefined && <span data-testid="items">{JSON.stringify(items)}</span>}
    {mapping !== undefined && <span data-testid="mapping">{JSON.stringify(mapping)}</span>}
    {callback !== undefined && <span data-testid="callback">{typeof callback === 'object' ? JSON.stringify(callback) : String(callback)}</span>}
    {children}
  </span>
);

describe('extensionPointProps/extensionPointCallbacks', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Span: {
        component: TestSpan,
        metadata: { name: 'Span', type: 'basic' },
      },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  describe('extensionPointProps (표현식 평가)', () => {
    it('단순 표현식 문자열이 평가되어야 한다', () => {
      const componentDef: ComponentDefinition = {
        id: 'test-1',
        type: 'basic',
        name: 'Span',
        props: { 'data-testid': 'result', value: '{{extensionPointProps.productId}}' },
        extensionPointProps: { productId: '{{route.id}}' },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ route: { id: '42' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('result').textContent).toBe('42');
    });

    it('복합 표현식이 평가되어야 한다', () => {
      const componentDef: ComponentDefinition = {
        id: 'test-2',
        type: 'basic',
        name: 'Span',
        props: { 'data-testid': 'result', value: '{{extensionPointProps.currency}}' },
        extensionPointProps: { currency: "{{_global.currency ?? 'KRW'}}" },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ _global: { currency: 'USD' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByTestId('result').textContent).toBe('USD');
    });

    it('정적 문자열은 그대로 전달되어야 한다', () => {
      const componentDef: ComponentDefinition = {
        id: 'test-3',
        type: 'basic',
        name: 'Span',
        props: { 'data-testid': 'result', value: '{{extensionPointProps.shopName}}' },
        extensionPointProps: { shopName: 'My Shop' },
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

      expect(screen.getByTestId('result').textContent).toBe('My Shop');
    });

    it('배열은 그대로 전달되어야 한다', () => {
      const componentDef: ComponentDefinition = {
        id: 'test-4',
        type: 'basic',
        name: 'Span',
        props: { 'data-testid': 'result', items: '{{extensionPointProps.fields}}' },
        extensionPointProps: { fields: ['zipcode', 'address'] },
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

      expect(screen.getByTestId('items').textContent).toBe('["zipcode","address"]');
    });

    it('객체 내부의 표현식도 재귀적으로 평가되어야 한다', () => {
      const componentDef: ComponentDefinition = {
        id: 'test-5',
        type: 'basic',
        name: 'Span',
        props: { 'data-testid': 'result', mapping: '{{extensionPointProps.targetFields}}' },
        extensionPointProps: {
          targetFields: {
            zipcode: '{{_local.formPrefix}}.zipcode',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ _local: { formPrefix: 'shipping' } }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const mapping = JSON.parse(screen.getByTestId('mapping').textContent || '{}');
      expect(mapping.zipcode).toBe('shipping.zipcode');
    });
  });

  describe('extensionPointCallbacks (평가 없이 전달)', () => {
    it('액션 객체가 평가 없이 그대로 전달되어야 한다', () => {
      const actionObj = {
        handler: 'setState',
        params: { target: 'local', 'form.zipcode': '{{$event.zipcode}}' },
      };

      const componentDef: ComponentDefinition = {
        id: 'test-6',
        type: 'basic',
        name: 'Span',
        props: { 'data-testid': 'result', callback: '{{extensionPointCallbacks.onAddressSelect}}' },
        extensionPointCallbacks: { onAddressSelect: actionObj },
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

      const result = JSON.parse(screen.getByTestId('callback').textContent || '{}');
      // $event 표현식이 평가되지 않고 원본 그대로 보존되어야 함
      expect(result.handler).toBe('setState');
      expect(result.params['form.zipcode']).toBe('{{$event.zipcode}}');
    });
  });
});
