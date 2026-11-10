// e2e:allow 실물 DynamicRenderer · TranslationEngine 로 컴포넌트가 엔진 렌더러를 직접 그릴 때의 번역 문맥을 잰다 — 브라우저 축은 두 템플릿 Modal 고정 하단 실측(총괄 엔진 회귀 단계)이 덮는다
/**
 * @file DynamicRenderer.inheritRenderEnvTranslation.test.tsx
 * @description 번역 문맥 없이 그린 렌더러가 부모 렌더러의 렌더 환경에서 번역 문맥을 이어받는다 (engine-v1.94.0, #869)
 *
 * 컴포넌트(Modal 고정 하단 같은 서드파티 꼴)가 정의를 엔진 렌더러로 직접 그리면서 `translationContext` 를 넘기지 않으면, 엔진은
 * `parentComponentContext.renderEnvRef` 에 담긴 부모의 번역 문맥을 쓴다(DynamicRenderer.tsx 「번역 문맥이 빠진 렌더러」). 두 번들 템플릿
 * Modal 은 지금 번역 문맥을 직접 넘겨 이 줄을 밟지 않으므로, 넘기지 않는 컴포넌트로 따로 잠근다. 이어받지 못하면 기본값(templateId 빈 값)으로
 * 풀려 그 템플릿의 문구가 아니라 키가 보인다.
 *
 * @scenario-manifest tests/scenarios/render-environment-translation-inherit.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer from '../DynamicRenderer';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { createMockComponentRegistryWithBasics } from './utils/layoutTestUtils';

/**
 * 받은 `__componentContext` 로 고정 정의를 엔진 렌더러로 직접 그리는 합성 컴포넌트 — 번역 문맥은 일부러 넘기지 않는다.
 * 정의는 props 로 받지 않으므로 부모가 `$t:` 를 미리 풀지 않는다.
 */
const SelfRenderingHost: React.FC<any> = ({ __componentContext }) => {
    const env = __componentContext?.renderEnvRef?.current;

    return React.createElement(DynamicRenderer, {
        componentDef: { id: 'inner', type: 'basic', name: 'Span', props: { 'data-testid': 'inner' }, text: '$t:core.test.hello' } as any,
        dataContext: __componentContext?.parentDataContext ?? {},
        parentComponentContext: __componentContext,
        registry: env?.componentRegistry,
        bindingEngine: env?.bindingEngine,
        translationEngine: env?.translationEngine,
        actionDispatcher: env?.actionDispatcher,
        isRootRenderer: false,
    } as any);
};

describe('번역 문맥이 빠진 렌더러는 부모 렌더 환경의 번역 문맥을 이어받는다 (engine-v1.94.0)', () => {
    /**
     * @scenario renderer=component_draws_engine_renderer_without_translation_prop
     * @effects inner_renderer_uses_parent_translation_context
     */
    it('컴포넌트가 번역 문맥을 넘기지 않고 그린 자식 렌더러도 부모 템플릿의 문구로 번역한다', () => {
        const registry = createMockComponentRegistryWithBasics() as any;
        registry.register('composite', 'SelfRenderingHost', SelfRenderingHost);
        const translationEngine = new TranslationEngine();
        (translationEngine as any).translations.set('sirsoft-basic:ko', { core: { test: { hello: '안녕하세요' } } });

        render(React.createElement(DynamicRenderer, {
            componentDef: { id: 'host', type: 'composite', name: 'SelfRenderingHost' } as any,
            dataContext: { _global: {}, _local: {} },
            translationContext: { templateId: 'sirsoft-basic', locale: 'ko' },
            registry,
            bindingEngine: new DataBindingEngine(),
            translationEngine,
            actionDispatcher: new ActionDispatcher({}),
            isRootRenderer: true,
        } as any));

        expect(screen.getByTestId('inner').textContent).toBe('안녕하세요');
    });
});
