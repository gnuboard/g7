/**
 * 슬롯 마커(data-editor-slot) 테스트
 *
 * 공통(base) 레이아웃의 슬롯 노드(`slot: "content"` 등)를 편집기 오버레이/CSS 가
 * 시각화할 수 있도록, 편집 모드에서만 `data-editor-slot` DOM 속성을 부여한다.
 * 운영 렌더(isEditMode=false)에는 속성이 없어야 한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestDiv: React.FC<Record<string, unknown>> = (props) => {
  const { children, ...rest } = props as { children?: React.ReactNode };
  return <div {...(rest as Record<string, never>)}>{children}</div>;
};

describe('data-editor-slot 마커', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const slotDef: ComponentDefinition = {
    id: 'main_content',
    type: 'basic',
    name: 'Div',
    slot: 'content',
    props: { 'data-testid': 'slot-node' },
    children: [],
  } as unknown as ComponentDefinition;

  it('편집 모드 — 슬롯 노드에 data-editor-slot="<슬롯명>" 이 부여된다', () => {
    const { container } = render(
      <DynamicRenderer
        componentDef={slotDef}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
        isEditMode
      />,
    );
    expect(container.querySelector('[data-editor-slot="content"]')).not.toBeNull();
  });

  it('운영 렌더(isEditMode=false) — data-editor-slot 미부여 (운영 영향 0)', () => {
    const { container } = render(
      <DynamicRenderer
        componentDef={slotDef}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />,
    );
    expect(container.querySelector('[data-editor-slot]')).toBeNull();
  });

  it('편집 모드 — base 편집 표시 마커(__editorSlotName)로도 부여된다 (PreviewCanvas slot 치환 경로)', () => {
    const markerDef: ComponentDefinition = {
      id: 'main_content',
      type: 'basic',
      name: 'Div',
      __editorSlotName: 'content',
      props: {},
      children: [],
    } as unknown as ComponentDefinition;
    const { container } = render(
      <DynamicRenderer
        componentDef={markerDef}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
        isEditMode
      />,
    );
    expect(container.querySelector('[data-editor-slot="content"]')).not.toBeNull();
  });

  it('편집 모드 — slot 키가 없는 노드에는 부여하지 않는다', () => {
    const plainDef: ComponentDefinition = {
      id: 'plain',
      type: 'basic',
      name: 'Div',
      props: {},
    };
    const { container } = render(
      <DynamicRenderer
        componentDef={plainDef}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
        isEditMode
      />,
    );
    expect(container.querySelector('[data-editor-slot]')).toBeNull();
  });
});
