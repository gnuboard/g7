// e2e:allow 렌더 횟수(루트급 렌더 주기)는 화면 동작이 아니라 비용이라 E2E 로 잡지 않는다 — 브라우저 축은 7.1.1 출시 전 엔진 검사의 route 치환 성능 비교(관리자 목록 이동 1회 표시까지 커밋 19/14/15/14)가 덮는다
/**
 * @file DynamicRenderer.transitionSubscription.test.tsx
 * @description 전환 상태 변화에 흐림 판정이 있는 렌더러만 다시 그린다 (engine-v1.82.0)
 *
 * 화면 이동을 시작하면 전환 상태를 곧바로 켠다(v1.82.0). 이때 흐림 판정이 없는 렌더러까지 전환 문맥을 읽으면 떠나는 화면
 * 전체가 한 번 더 그려진다(관리자 목록 이동 1회의 루트급 커밋 19 → 34). 흐림 판정이 있는 렌더러만 다시 그리고 흐림은 선다.
 *
 * @scenario-manifest tests/scenarios/transition-subscription-scope.yaml
 *
 * @effects only_blur_renderers_rerender, blur_still_applied
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { TransitionProvider } from '../TransitionContext';
import { transitionManager } from '../TransitionManager';

describe('[case:state-global-route-supersede-and-refresh] 전환 상태 변화에 흐림 판정이 있는 렌더러만 다시 그린다 (engine-v1.82.0)', () => {
    let registry: ComponentRegistry;
    let bindingEngine: DataBindingEngine;
    let translationEngine: TranslationEngine;
    let actionDispatcher: ActionDispatcher;
    let translationContext: TranslationContext;
    let renders: Record<string, number>;

    beforeEach(() => {
        renders = {};
        const CountDiv: React.FC<{ 'data-testid'?: string; children?: React.ReactNode }> = ({ children, ...rest }) => {
            const id = rest['data-testid'] ?? 'none';
            renders[id] = (renders[id] ?? 0) + 1;

            return <div data-testid={id}>{children}</div>;
        };
        registry = ComponentRegistry.getInstance();
        (registry as any).registry = { Div: { component: CountDiv, metadata: { name: 'Div', type: 'basic' } } };
        bindingEngine = new DataBindingEngine();
        translationEngine = new TranslationEngine();
        actionDispatcher = new ActionDispatcher({ navigate: vi.fn() } as any);
        translationContext = { templateId: 'test-template', locale: 'ko' };
        transitionManager.setPending(false);
    });

    /**
     * 전환 상태를 켜면 흐림 칸은 흐림이 서고, 흐림 없는 렌더러(루트·형제)는 다시 그려지지 않는다.
     *
     * @scenario case=transition_on_blur_and_plain_siblings
     * @effects only_blur_renderers_rerender, blur_still_applied
     */
    it('전환 상태를 켜면 흐림 칸만 다시 그려지고 흐림 없는 칸은 그대로다', () => {
        const componentDef: ComponentDefinition = {
            id: 'tr-root', type: 'basic', name: 'Div', props: { 'data-testid': 'tr-root' },
            children: [
                { id: 'tr-blur', type: 'basic', name: 'Div', blur_until_loaded: true, props: { 'data-testid': 'tr-blur' }, text: 'blur' },
                { id: 'tr-plain', type: 'basic', name: 'Div', props: { 'data-testid': 'tr-plain' }, text: 'plain' },
            ],
        };
        render(
            <TransitionProvider>
                <DynamicRenderer
                    componentDef={componentDef}
                    dataContext={{}}
                    translationContext={translationContext}
                    registry={registry}
                    bindingEngine={bindingEngine}
                    translationEngine={translationEngine}
                    actionDispatcher={actionDispatcher}
                />
            </TransitionProvider>
        );
        const before = { ...renders };
        // 루트급 렌더러는 그릴 때마다 렌더 주기를 연다 — 실측(관리자 목록 이동)과 같은 지표
        const cycleSpy = vi.spyOn(bindingEngine, 'startRenderCycle');

        act(() => { transitionManager.setPending(true); });

        try {
            expect(screen.getByTestId('tr-blur').parentElement).toHaveClass('blur-sm');
            expect(cycleSpy).not.toHaveBeenCalled();
            expect(renders['tr-plain']).toBe(before['tr-plain']);
            expect(renders['tr-root']).toBe(before['tr-root']);
        } finally {
            cycleSpy.mockRestore();
            act(() => { transitionManager.setPending(false); });
        }
    });
});
