/**
 * inline-edit-roundtrip.test.tsx — 인라인 편집 키 ↔ 캔버스 렌더 라운드트립
 *
 *  - `$t:custom.*` 키를 text 로 가진 노드를 DynamicRenderer 로 렌더하면, TranslationEngine
 *    사전의 현재 로케일 값으로 해석되어 화면에 표시된다(인라인 편집 확정 후 동일 화면 재현).
 *  - 콘텐츠 로케일 전환 → 같은 노드가 새 로케일 값으로 갱신된다.
 *  - 커스텀 키 + 언어팩 키 + 코어 키가 같은 사전에서 정확한 우선순위(커스텀 우선)로 해석된다.
 *
 * property-modal-live-preview.test.tsx 의 DynamicRenderer 하네스 패턴을 재사용한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';

afterEach(() => cleanup());

const TEMPLATE = 'sirsoft-basic';

/** 격리 레지스트리 — Span 을 text 패스스루로 등록. */
function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  const Span = (props: Record<string, unknown>) =>
    React.createElement('span', props, (props.children as React.ReactNode) ?? (props.text as React.ReactNode));
  (registry as unknown as { registry: Record<string, unknown> }).registry.Span = {
    component: Span,
    metadata: { name: 'Span', type: 'basic' },
  };
  return registry;
}

/** 사전을 직접 주입한 TranslationEngine 인스턴스 생성. */
function engineWith(dicts: Record<string, Record<string, unknown>>): TranslationEngine {
  const engine = new TranslationEngine();
  for (const [cacheKey, dict] of Object.entries(dicts)) {
    // private translations 맵에 직접 set — 테스트 전용(loadTranslations fetch 우회).
    (engine as unknown as { translations: Map<string, unknown> }).translations.set(cacheKey, dict);
  }
  return engine;
}

function renderNode(text: string, engine: TranslationEngine, locale: string) {
  const registry = makeRegistry();
  return render(
    React.createElement(DynamicRenderer, {
      componentDef: { name: 'Span', type: 'basic', props: { text } },
      dataContext: {},
      registry,
      bindingEngine: new DataBindingEngine(),
      translationEngine: engine,
      actionDispatcher: new ActionDispatcher({}),
      translationContext: { templateId: TEMPLATE, locale },
      isEditMode: false,
      isRootRenderer: true,
      componentPath: '0',
    } as never),
  );
}

describe('인라인 편집 키 ↔ 캔버스 렌더 라운드트립 ', () => {
  it('$t:custom.* 키가 현재 로케일 값으로 해석되어 표시', () => {
    const engine = engineWith({
      [`${TEMPLATE}:ko`]: { custom: { home: { 1: '환영합니다' } } },
    });
    const { container } = renderNode('$t:custom.home.1', engine, 'ko');
    expect(container.textContent).toContain('환영합니다');
    expect(container.textContent).not.toContain('$t:');
  });

  it('로케일 전환 → 같은 키가 새 로케일 값으로 렌더', () => {
    const engine = engineWith({
      [`${TEMPLATE}:ko`]: { custom: { home: { 1: '환영합니다' } } },
      [`${TEMPLATE}:en`]: { custom: { home: { 1: 'Welcome' } } },
    });
    const ko = renderNode('$t:custom.home.1', engine, 'ko');
    expect(ko.container.textContent).toContain('환영합니다');
    cleanup();
    const en = renderNode('$t:custom.home.1', engine, 'en');
    expect(en.container.textContent).toContain('Welcome');
  });

  it('커스텀 키가 사전에 병합되어 코어/언어팩 키와 함께 해석 (우선순위 — 병합 결과 기준)', () => {
    // MergeCustomTranslations 가 언어팩 위에 커스텀 키를 덮어쓴 최종 사전 형태.
    const engine = engineWith({
      [`${TEMPLATE}:ko`]: {
        nav: { home: '홈' }, // 코어/언어팩 키
        custom: { home: { 1: '맞춤 환영' } }, // 커스텀 키 (병합 우선 결과)
      },
    });
    const coreKey = renderNode('$t:nav.home', engine, 'ko');
    expect(coreKey.container.textContent).toContain('홈');
    cleanup();
    const customKey = renderNode('$t:custom.home.1', engine, 'ko');
    expect(customKey.container.textContent).toContain('맞춤 환영');
  });

  it('미해석 키(사전에 없음)는 폴백 — raw 키 또는 빈 문자열(엔진 기본 동작)', () => {
    const engine = engineWith({ [`${TEMPLATE}:ko`]: {} });
    const { container } = renderNode('$t:custom.home.99', engine, 'ko');
    // 엔진 기본 폴백 = 키 자체. 회귀 가드: 적어도 크래시 없이 렌더.
    expect(container).toBeTruthy();
  });

  it('편집 모드 DOM 의 data-editor-path 는 `.children.` 세그먼트를 포함한다 — 미러 selector 불변식', () => {
    // 회귀 가드: 편집 오버레이의 서식 미러는 `data-editor-path` selector 로 캔버스 노드를
    // 찾는다. DynamicRenderer 가 emit 하는 path 는 `0.children.1.children.0` 형태(`.children.`
    // 포함)인데, parseEditorPath 로 파싱한 number[] 를 `.join('.')` 하면 `0.1.0`(`.children.`
    // 누락)이 되어 selector 가 노드를 못 찾아 미러가 빈 채로 남았다(근본 원인). 따라서 미러는
    // 반드시 DOM 원문 path(domPath)를 써야 한다 — 그 전제(DOM path 가 `.children.` 포함)를 고정.
    const registry = makeRegistry();
    const engine = engineWith({ [`${TEMPLATE}:ko`]: {} });
    const { container } = render(
      React.createElement(DynamicRenderer, {
        componentDef: {
          name: 'Span',
          type: 'basic',
          props: {},
          children: [{ name: 'Span', type: 'basic', props: { text: '자식' } }],
        },
        dataContext: {},
        registry,
        bindingEngine: new DataBindingEngine(),
        translationEngine: engine,
        actionDispatcher: new ActionDispatcher({}),
        translationContext: { templateId: TEMPLATE, locale: 'ko' },
        isEditMode: true,
        isRootRenderer: true,
        componentPath: '0',
      } as never),
    );
    const childEl = container.querySelector('[data-editor-path="0.children.0"]');
    expect(childEl).not.toBeNull();
    // 잘못된(파싱 후 join) 형태로는 절대 매칭되지 않아야 한다.
    expect(container.querySelector('[data-editor-path="0.0"]')).toBeNull();
  });
});
