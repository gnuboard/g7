/**
 * footer-editor-path — 실물 DynamicRenderer 로 그린 Modal `footer` 단추가 편집기 경로를 받고, 그 경로로
 * 편집기가 footer 노드를 고르는지 잠근다 (#869 B1, #141).
 *
 * 결함: 확인 창 단추를 `props.footer` 로 옮긴 뒤 편집기 캔버스에서 단추를 고를 수 없었다. footer 를 그리는
 * 컴포넌트(템플릿 Modal)가 componentPath 를 넘기지 않아 `data-editor-path` 가 붙지 않았고, 엔진이 노드 경로를
 * 컴포넌트에 알려 줄 길도 없었다.
 *
 * 고침: DynamicRenderer 가 `__componentContext.componentPath` 에 노드 경로를 싣고, Modal 은 footer 단추마다
 * `${componentPath}.props.footer.${i}` 를 넘긴다. 편집기는 이 표기를 `{propSlot:'footer'}` 세그먼트로 읽는다.
 *
 * 여기서는 템플릿 Modal 과 같은 방식으로 footer 를 그리는 대역 composite 를 실물 렌더러에 등록한다(템플릿
 * Modal 자체의 계약은 각 템플릿의 Modal 시험이 잠근다).
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, cleanup, renderHook, act } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';
import { parseEditorPath, useElementSelection } from '../../hooks/useElementSelection';
import { findNodeByPath, type EditorNode } from '../../utils/layoutTreeUtils';
import { resolveEditorTargetElement } from '../../utils/resolveEditorTarget';

/** 렌더 중 Modal 이 받은 componentContext 기록 */
const seenContexts: any[] = [];

function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  (registry as any).registry.Div = {
    component: (props: any) => React.createElement('div', props),
    metadata: { name: 'Div', type: 'basic' },
  };
  (registry as any).registry.Button = {
    component: (props: any) => React.createElement('button', { type: 'button', ...props }),
    metadata: { name: 'Button', type: 'basic' },
  };
  (registry as any).registry.Span = {
    component: (props: any) => React.createElement('span', props),
    metadata: { name: 'Span', type: 'basic' },
  };
  // 템플릿 Modal 과 같은 footer 렌더 — 렌더 환경과 노드 경로를 componentContext 에서 받는다.
  (registry as any).registry.Modal = {
    component: ({ footer, children, __componentContext, __dataContext, __translationContext, editorAttrs }: any) => {
      seenContexts.push(__componentContext);
      const env = __componentContext?.renderEnvRef?.current ?? {};
      const ownerPath = __componentContext?.componentPath;
      return React.createElement(
        'div',
        { className: 'modal', ...(editorAttrs ?? {}) },
        React.createElement('div', { className: 'modal-body' }, children),
        React.createElement(
          'div',
          { className: 'modal-footer-bar' },
          (footer ?? []).map((def: any, index: number) =>
            React.createElement(DynamicRenderer as any, {
              key: `footer-${index}`,
              componentDef: def,
              dataContext: __dataContext ?? __componentContext?.parentDataContext ?? {},
              parentComponentContext: __componentContext,
              translationContext: __translationContext,
              registry: env.componentRegistry,
              bindingEngine: env.bindingEngine,
              translationEngine: env.translationEngine,
              actionDispatcher: env.actionDispatcher,
              isRootRenderer: false,
              isEditMode: env.isEditMode,
              ...(ownerPath ? { componentPath: `${ownerPath}.props.footer.${index}` } : {}),
            }),
          ),
        ),
      );
    },
    metadata: { name: 'Modal', type: 'composite' },
  };
  return registry;
}

/** 루트 Div > [본문 Span, Modal(footer: 취소, 삭제(안에 Span))] */
function layout(): EditorNode {
  return {
    name: 'Div',
    type: 'basic',
    props: {},
    children: [
      { name: 'Span', type: 'basic', text: '머리' },
      {
        name: 'Modal',
        type: 'composite',
        props: {
          footer: [
            { name: 'Button', type: 'basic', text: '취소', props: { 'data-tag': 'cancel' } },
            {
              name: 'Button',
              type: 'basic',
              props: { 'data-tag': 'delete' },
              children: [{ name: 'Span', type: 'basic', text: '삭제', props: { 'data-tag': 'delete-label' } }],
            },
          ],
        },
        children: [
          { name: 'Span', type: 'basic', text: '본문', props: { 'data-tag': 'body' } },
          // footer 의 「삭제」와 같은 구조(단추 안 글자 노드)를 본문 children 에도 둔다 — 선택 규칙 대조용
          {
            name: 'Button',
            type: 'basic',
            props: { 'data-tag': 'body-delete' },
            children: [{ name: 'Span', type: 'basic', text: '삭제', props: { 'data-tag': 'body-delete-label' } }],
          },
        ],
      },
    ],
  } as EditorNode;
}

function renderLayout(isEditMode: boolean) {
  return render(
    React.createElement(DynamicRenderer as any, {
      componentDef: layout(),
      dataContext: {},
      registry: makeRegistry(),
      bindingEngine: new DataBindingEngine(),
      translationEngine: new TranslationEngine(),
      actionDispatcher: new ActionDispatcher({}),
      isEditMode,
      isRootRenderer: true,
      componentPath: '0',
      onComponentSelect: () => {},
      onComponentHover: () => {},
    }),
  );
}

afterEach(() => {
  cleanup();
  seenContexts.length = 0;
});

describe('[case:wysiwyg-12] footer 단추 — 실물 렌더러의 편집기 경로', () => {
  /** @effects editor_footer_node_selectable */
  it('엔진이 Modal 에 노드 경로를 싣고, footer 단추와 그 자식이 `0.children.1.props.footer.{i}` 경로를 받는다', () => {
    const { container } = renderLayout(true);
    expect(seenContexts.some((c) => c?.componentPath === '0.children.1')).toBe(true);
    const cancel = container.querySelector('[data-tag="cancel"]') as HTMLElement;
    const del = container.querySelector('[data-tag="delete"]') as HTMLElement;
    const label = container.querySelector('[data-tag="delete-label"]') as HTMLElement;
    expect(cancel.getAttribute('data-editor-path')).toBe('0.children.1.props.footer.0');
    expect(del.getAttribute('data-editor-path')).toBe('0.children.1.props.footer.1');
    expect(label.getAttribute('data-editor-path')).toBe('0.children.1.props.footer.1.children.0');
    // 본문 children 경로는 종전 그대로
    const body = container.querySelector('[data-tag="body"]') as HTMLElement;
    expect(body.getAttribute('data-editor-path')).toBe('0.children.1.children.0');
  });

  /** @effects editor_footer_node_selectable */
  it('캔버스에서 그 단추를 클릭한 경로로 편집기가 footer 노드를 고른다', () => {
    const { container } = renderLayout(true);
    const domPath = (container.querySelector('[data-tag="delete-label"]') as HTMLElement).getAttribute('data-editor-path')!;
    const root: EditorNode = { children: [layout()] };
    expect(findNodeByPath(root, parseEditorPath(domPath))?.text).toBe('삭제');

    const { result } = renderHook(() => useElementSelection({ rootNode: root, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', { dataset: { editorPath: '0.children.1.props.footer.0' } as DOMStringMap });
    });
    expect(result.current.selectedNode?.text).toBe('취소');
  });

  /** @effects editor_footer_node_selectable */
  it('단추 안 글자를 클릭하면 footer 도 본문 children 과 같게 가장 깊은 노드(글자)를 잡고, 부모 단추는 한 단계 위 경로다', () => {
    const { container } = renderLayout(true);
    const frame = container.firstElementChild as HTMLElement;
    const footerLabel = container.querySelector('[data-tag="delete-label"]') as HTMLElement;
    const bodyLabel = container.querySelector('[data-tag="body-delete-label"]') as HTMLElement;
    // 같은 규칙(1차 closest) — 둘 다 글자 노드가 잡힌다.
    expect(resolveEditorTargetElement(footerLabel, frame)).toBe(footerLabel);
    expect(resolveEditorTargetElement(bodyLabel, frame)).toBe(bodyLabel);
    // 단추 자체(글자 밖 여백) 클릭은 둘 다 단추.
    const footerButton = container.querySelector('[data-tag="delete"]') as HTMLElement;
    const bodyButton = container.querySelector('[data-tag="body-delete"]') as HTMLElement;
    expect(resolveEditorTargetElement(footerButton, frame)).toBe(footerButton);
    expect(resolveEditorTargetElement(bodyButton, frame)).toBe(bodyButton);
    // 부모 칩(「↑부모」)이 쓰는 한 단계 위 경로 — 글자 경로에서 마지막 `.children.N` 을 떼면 단추 경로.
    const up = (p: string) => p.replace(/\.children\.\d+$/, '');
    expect(up(footerLabel.getAttribute('data-editor-path')!)).toBe(footerButton.getAttribute('data-editor-path'));
    expect(up(bodyLabel.getAttribute('data-editor-path')!)).toBe(bodyButton.getAttribute('data-editor-path'));
  });

  /** @effects editor_footer_node_selectable */
  it('편집 모드가 아니면 footer 단추에 편집 표식이 없다(일반 화면 무변화)', () => {
    const { container } = renderLayout(false);
    const cancel = container.querySelector('[data-tag="cancel"]') as HTMLElement;
    expect(cancel).not.toBeNull();
    expect(cancel.hasAttribute('data-editor-path')).toBe(false);
  });
});
