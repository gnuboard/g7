/**
 * canvas-click-action-intercept — 편집 모드 클릭 액션 가로채기 회귀
 *
 * 검증 매트릭스:
 *  - 일반 사이트 렌더(isEditMode=false): navigate / openModal / apiCall 핸들러가 정상 호출
 *  - 편집 모드(isEditMode=true): 같은 액션 정의를 가진 컴포넌트의 onClick 이 핸들러를 발화하지 않음
 *  - 편집 모드 + A.href: 클릭 시 e.preventDefault 가 호출되어 브라우저 네비게이션 차단
 *
 * 본 테스트는 DynamicRenderer 의 편집 모드 분기(`bindComponentActions` 에 actions=undefined
 * 전달 + onClick 내 preventDefault) 가 회귀하지 않도록 1차 코드 경로 가드.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';

function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  const TestButton = (props: any) =>
    React.createElement('button', { ...props, 'data-testid': 'btn' }, 'click me');
  const TestAnchor = (props: any) =>
    React.createElement('a', { ...props, 'data-testid': 'link' }, 'go');

  // Use internal mutation pattern from DynamicRenderer.test.tsx (registerComponent is private).
  (registry as any).registry.Button = {
    component: TestButton,
    metadata: { name: 'Button', type: 'basic' },
  };
  (registry as any).registry.A = {
    component: TestAnchor,
    metadata: { name: 'A', type: 'basic' },
  };
  return registry;
}

function renderButton({
  isEditMode,
  dispatcher,
}: {
  isEditMode: boolean;
  dispatcher: ActionDispatcher;
}) {
  const componentDef = {
    name: 'Button',
    type: 'basic',
    actions: [
      { event: 'onClick', handler: 'navigate', params: { path: '/posts' } },
    ],
  };
  return render(
    React.createElement(DynamicRenderer, {
      componentDef,
      dataContext: {},
      registry: makeRegistry(),
      bindingEngine: new DataBindingEngine(),
      translationEngine: new TranslationEngine(),
      actionDispatcher: dispatcher,
      isEditMode,
      isRootRenderer: true,
      componentPath: '0',
    } as any)
  );
}

describe('일반 사이트 렌더 — navigate 액션이 정상 발동', () => {
  it('isEditMode=false 면 클릭 시 navigate 핸들러가 호출', () => {
    const navigateMock = vi.fn();
    const dispatcher = new ActionDispatcher({ navigate: navigateMock });
    const { getByTestId } = renderButton({ isEditMode: false, dispatcher });
    fireEvent.click(getByTestId('btn'));
    expect(navigateMock).toHaveBeenCalledTimes(1);
    // ActionDispatcher 의 navigate 핸들러는 path 문자열을 직접 첫 인자로 받는다.
    expect(navigateMock.mock.calls[0][0]).toBe('/posts');
  });
});

describe('편집 모드 — 액션 가로채기', () => {
  it('isEditMode=true 면 클릭해도 navigate 가 호출되지 않음', () => {
    const navigateMock = vi.fn();
    const dispatcher = new ActionDispatcher({ navigate: navigateMock });
    dispatcher.setPreviewMode(true);
    const { getByTestId } = renderButton({ isEditMode: true, dispatcher });
    fireEvent.click(getByTestId('btn'));
    expect(navigateMock).not.toHaveBeenCalled();
  });
});

describe('편집 모드 — <a href> preventDefault', () => {
  it('A 컴포넌트의 href 클릭이 e.preventDefault 로 차단', () => {
    const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    dispatcher.setPreviewMode(true);
    const aDef = { name: 'A', type: 'basic', props: { href: '/board' } };
    const { getByTestId } = render(
      React.createElement(DynamicRenderer, {
        componentDef: aDef,
        dataContext: {},
        registry: makeRegistry(),
        bindingEngine: new DataBindingEngine(),
        translationEngine: new TranslationEngine(),
        actionDispatcher: dispatcher,
        isEditMode: true,
        isRootRenderer: true,
        componentPath: '0',
      } as any)
    );
    const linkEl = getByTestId('link') as HTMLAnchorElement;
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    linkEl.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });
});
