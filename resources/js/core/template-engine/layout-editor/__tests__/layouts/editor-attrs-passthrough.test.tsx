/**
 * editor-attrs-passthrough — 편집 모드 editorAttrs 패스스루 회귀
 *
 * 배경: 편집 모드에서 DynamicRenderer 가 주입하는 `data-editor-*` 표식/선택·hover 핸들러가
 * composite/layout 컴포넌트 루트에서 유실되면 그 노드가 편집기의 드롭 슬롯/선택/드래그
 * 대상에서 누락된다(Container/Flex/카드류가 누락되어 자식을 컨테이너 밖으로
 * 옮기는 드롭존이 생성되지 않음). DynamicRenderer 가 주입분을 단일 `editorAttrs` 객체로도
 * 전달하고, 각 nesting 컴포넌트가 이를 받아 시각적 루트에 spread 하면 유실이 해소된다.
 *
 * 검증 매트릭스 (계획서):
 *  (a) 편집 모드 + editorAttrs 를 루트에 spread 하는 컴포넌트 → 루트 DOM 에 `data-editor-path` 존재
 *  (b) 비편집 모드 → DOM 에 `data-editor-*` 부재 (누출 0 회귀)
 *  (c) editorAttrs 미수신 컴포넌트(또는 basic) 에 `editorAttrs` 객체 prop 이 DOM 으로 누출되지 않음
 *  (d) 컴포넌트 도메인 prop 이 DOM 속성으로 누출되지 않음 (C안 핵심 안전성)
 *
 * 본 테스트는 코어 엔진의 editorAttrs 주입 + basic/layout DOM-safe 필터(editorAttrs 키 제외)
 * 회귀를 1차 코드 경로로 가드한다(템플릿 컴포넌트별 spread 적용은 각 템플릿 __tests__ 담당).
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';

/**
 * editorAttrs 를 명시 수신해 루트에 spread 하는 composite 컴포넌트(템플릿 컴포넌트 모사).
 * 도메인 prop(title)은 구조분해만 하고 DOM 으로 흘리지 않는다.
 */
const ReceivingCard = ({ title, editorAttrs }: any) =>
  React.createElement('div', { className: 'card', ...editorAttrs }, title);

/**
 * editorAttrs 를 받지 않는 composite 컴포넌트(미적용 상태 모사) — 도메인 prop 만 구조분해.
 * 편집기 표식이 유실되어야 함(=루트에 data-editor-path 부재).
 */
const NonReceivingCard = ({ title }: any) =>
  React.createElement('div', { className: 'card' }, title);

function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  (registry as any).registry.ReceivingCard = {
    component: ReceivingCard,
    metadata: { name: 'ReceivingCard', type: 'composite' },
  };
  (registry as any).registry.NonReceivingCard = {
    component: NonReceivingCard,
    metadata: { name: 'NonReceivingCard', type: 'composite' },
  };
  // basic 컴포넌트: {...props} 패스스루 (Div 모사)
  (registry as any).registry.Div = {
    component: (props: any) => React.createElement('div', props),
    metadata: { name: 'Div', type: 'basic' },
  };
  // layout 컴포넌트: 도메인 prop(className)만 명시 구조분해, editorAttrs 를 루트에 spread.
  //   `{...props}` 패스스루를 하지 **않으므로** 개별 data-editor-* 키는 도달하지 못하고,
  //   오직 editorAttrs 객체를 통해서만 표식을 받는다(Container/Flex/Grid 모사).
  (registry as any).registry.LayoutBox = {
    component: ({ className, children, editorAttrs }: any) =>
      React.createElement('div', { className, ...editorAttrs }, children),
    metadata: { name: 'LayoutBox', type: 'layout' },
  };
  return registry;
}

function renderNode(opts: {
  name: string;
  type: string;
  isEditMode: boolean;
  props?: Record<string, any>;
}) {
  const componentDef = {
    name: opts.name,
    type: opts.type,
    props: opts.props ?? {},
  };
  return render(
    React.createElement(DynamicRenderer, {
      componentDef,
      dataContext: {},
      registry: makeRegistry(),
      bindingEngine: new DataBindingEngine(),
      translationEngine: new TranslationEngine(),
      actionDispatcher: new ActionDispatcher({}),
      isEditMode: opts.isEditMode,
      isRootRenderer: true,
      componentPath: '0',
      // 선택/hover 콜백을 전달해야 DynamicRenderer 가 핸들러를 등록한다
      onComponentSelect: () => {},
      onComponentHover: () => {},
    } as any)
  );
}

describe('(a) 편집 모드 — editorAttrs 를 루트에 spread 하는 컴포넌트', () => {
  it('루트 DOM 에 data-editor-path 가 존재한다', () => {
    const { container } = renderNode({
      name: 'ReceivingCard',
      type: 'composite',
      isEditMode: true,
      props: { title: '회원' },
    });
    const root = container.querySelector('.card') as HTMLElement;
    expect(root).not.toBeNull();
    expect(root.getAttribute('data-editor-path')).toBe('0');
    expect(root.getAttribute('data-editor-name')).toBe('ReceivingCard');
    expect(root.getAttribute('data-editor-type')).toBe('composite');
  });
});

describe('(a-역) 편집 모드 — editorAttrs 를 받지 않는 컴포넌트는 표식 유실', () => {
  it('editorAttrs 를 spread 하지 않으면 루트에 data-editor-path 가 없다 (결함 재현 가드)', () => {
    const { container } = renderNode({
      name: 'NonReceivingCard',
      type: 'composite',
      isEditMode: true,
      props: { title: '회원' },
    });
    const root = container.querySelector('.card') as HTMLElement;
    expect(root).not.toBeNull();
    // composite 는 미명시 props 를 흘리지 않으므로 표식이 유실된다 — 이것이 본 작업이 해결한 결함
    expect(root.getAttribute('data-editor-path')).toBeNull();
  });
});

describe('(b) 비편집 모드 — data-editor-* 누출 0', () => {
  it('isEditMode=false 면 DOM 에 data-editor-* 표식이 전혀 없다', () => {
    const { container } = renderNode({
      name: 'ReceivingCard',
      type: 'composite',
      isEditMode: false,
      props: { title: '회원' },
    });
    const root = container.querySelector('.card') as HTMLElement;
    expect(root).not.toBeNull();
    expect(root.getAttribute('data-editor-path')).toBeNull();
    expect(root.getAttribute('data-editor-id')).toBeNull();
    expect(root.getAttribute('data-editor-name')).toBeNull();
    // editorAttrs 객체 자체도 DOM 속성으로 나타나지 않는다
    expect(root.getAttribute('editorattrs')).toBeNull();
  });
});

describe('(c) basic 컴포넌트 — editorAttrs 객체가 DOM 으로 누출되지 않음', () => {
  it('편집 모드에서 basic(Div)은 개별 data-editor-* 키로 표식되고, editorAttrs 객체 prop 은 DOM 에 없다', () => {
    const { container } = renderNode({
      name: 'Div',
      type: 'basic',
      isEditMode: true,
      props: {},
    });
    const root = container.querySelector('div') as HTMLElement;
    expect(root).not.toBeNull();
    // basic 은 {...props} 패스스루로 개별 data-editor-* 가 도달
    expect(root.getAttribute('data-editor-path')).toBe('0');
    // editorAttrs 객체는 basic/layout DOM-safe 필터에서 제외되어 DOM 누출 0
    expect(root.getAttribute('editorattrs')).toBeNull();
  });
});

describe('(c-2) layout 컴포넌트 — editorAttrs 객체 수신 + 루트 표식 (결함 재현 가드)', () => {
  it('편집 모드에서 layout(LayoutBox)은 editorAttrs 를 받아 루트에 data-editor-path 부착', () => {
    // 회귀 배경: DOM-safe 필터가 `editorAttrs` 키를 basic/layout 모두에서 제거해,
    // {...props} 패스스루를 안 하는 layout(Container/Flex/Grid)이 표식을 전혀 못 받았다.
    // → 그 컨테이너가 편집기 드롭존/선택에서 누락(컨테이너 바깥 이동 불가). 필터를 basic 한정으로
    //   좁혀 layout 은 editorAttrs 를 수신하도록 수정. 본 테스트가 그 회귀를 잠근다.
    const { container } = renderNode({
      name: 'LayoutBox',
      type: 'layout',
      isEditMode: true,
      props: { className: 'box' },
    });
    const root = container.querySelector('.box') as HTMLElement;
    expect(root).not.toBeNull();
    expect(root.getAttribute('data-editor-path'), 'layout 루트에 editor-path 부착').toBe('0');
    expect(root.getAttribute('data-editor-name')).toBe('LayoutBox');
    expect(root.getAttribute('data-editor-type')).toBe('layout');
    // editorAttrs 객체 자체는 DOM 속성으로 누출되지 않는다(컴포넌트가 spread 만 함)
    expect(root.getAttribute('editorattrs')).toBeNull();
  });

  it('비편집 모드에서 layout 루트에 data-editor-* 부재 (누출 0)', () => {
    const { container } = renderNode({
      name: 'LayoutBox',
      type: 'layout',
      isEditMode: false,
      props: { className: 'box' },
    });
    const root = container.querySelector('.box') as HTMLElement;
    expect(root).not.toBeNull();
    expect(root.getAttribute('data-editor-path')).toBeNull();
    expect(root.getAttribute('editorattrs')).toBeNull();
  });
});

describe('(d) 도메인 prop 누출 0', () => {
  it('편집 모드에서 컴포넌트 도메인 prop(title)이 DOM 속성으로 누출되지 않는다', () => {
    const { container } = renderNode({
      name: 'ReceivingCard',
      type: 'composite',
      isEditMode: true,
      props: { title: '회원' },
    });
    const root = container.querySelector('.card') as HTMLElement;
    expect(root).not.toBeNull();
    // title 은 컴포넌트가 구조분해해 텍스트로만 사용 — DOM 속성으로 누출 금지
    expect(root.getAttribute('title')).toBeNull();
    expect(root.textContent).toBe('회원');
  });
});
