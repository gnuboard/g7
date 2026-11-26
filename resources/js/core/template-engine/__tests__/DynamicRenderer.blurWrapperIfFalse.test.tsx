/**
 * @file DynamicRenderer.blurWrapperIfFalse.test.tsx
 * @description `if` 가 거짓인 노드는 `blur_until_loaded` 래퍼도 그리지 않는다 (engine-v1.99.2)
 *
 * 종전에는 `if` 가 거짓이라 내용이 null 이어도 흐림 래퍼 `<div>` 가 빈 채로 남았다. 양 끝 정렬(`flex-between`) 행에서
 * 이 빈 div 가 세 번째 자식이 되어 값이 가운데로 밀렸다(환경설정 › 정보 탭 메모리·디스크, 목록 건수가 추정치일 때 건수 문구).

 * @scenario-manifest tests/scenarios/blur-wrapper-if-false.yaml
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

describe('[case:components-misc-blur-wrapper-empty-when-if-false] 흐림 래퍼는 그릴 내용이 있을 때만 생긴다 (engine-v1.99.2)', () => {
    let registry: ComponentRegistry;
    let bindingEngine: DataBindingEngine;
    let translationEngine: TranslationEngine;
    let actionDispatcher: ActionDispatcher;
    let translationContext: TranslationContext;

    beforeEach(() => {
        const Div: React.FC<any> = ({ children, text, ...rest }) => <div {...rest}>{children ?? text}</div>;
        const Span: React.FC<any> = ({ children, text, ...rest }) => <span {...rest}>{children ?? text}</span>;
        registry = ComponentRegistry.getInstance();
        (registry as any).registry = {
            Div: { component: Div, metadata: { name: 'Div', type: 'basic' } },
            Span: { component: Span, metadata: { name: 'Span', type: 'basic' } },
        };
        bindingEngine = new DataBindingEngine();
        translationEngine = new TranslationEngine();
        actionDispatcher = new ActionDispatcher({ navigate: vi.fn() } as any);
        translationContext = { templateId: 'test-template', locale: 'ko' };
        transitionManager.setPending(false);
    });

    /** 환경설정 정보 탭의 메모리 행과 같은 모양 — 라벨 + 값 3분기(마지막만 흐림) */
    const row = (): ComponentDefinition => ({
        id: 'row', type: 'basic', name: 'Div', props: { 'data-testid': 'row', className: 'flex-between' },
        children: [
            { id: 'label', type: 'basic', name: 'Span', text: '메모리' },
            { id: 'value', type: 'basic', name: 'Span', if: '{{info?.data?.pct != null}}', text: '{{info?.data?.pct}}%' },
            { id: 'na', type: 'basic', name: 'Span', if: '{{info?.data != null && info?.data?.pct == null}}', text: '측정 불가' },
            { id: 'loading', type: 'basic', name: 'Span', if: '{{info?.data == null}}', blur_until_loaded: { enabled: true, data_sources: 'info' } as any, text: '-' },
        ],
    });

    const mount = (componentDef: ComponentDefinition, dataContext: Record<string, any>) => render(
        <TransitionProvider>
            <DynamicRenderer
                componentDef={componentDef}
                dataContext={dataContext}
                translationContext={translationContext}
                registry={registry}
                bindingEngine={bindingEngine}
                translationEngine={translationEngine}
                actionDispatcher={actionDispatcher}
            />
        </TransitionProvider>
    );

    /**
     * @scenario case=received_if_false_row
     * @effects blur_wrapper_absent_when_if_false
     */
    it('데이터를 받아 흐림 분기의 if 가 거짓이면 행 자식은 라벨·값 둘뿐이다 (빈 div 없음)', () => {
        mount(row(), { info: { data: { pct: 77 } } });
        const el = screen.getByTestId('row');
        expect(el.children).toHaveLength(2);
        expect(el.lastElementChild?.tagName).toBe('SPAN');
        expect(el.lastElementChild).toHaveTextContent('77%');
    });

    /**
     * @scenario case=pending_if_true_row
     * @effects blur_wrapper_kept_when_rendered
     */
    it('받기 전(if 참)에는 흐림 분기를 래퍼째 그리고 전환 중이면 흐림이 선다', () => {
        mount(row(), {});
        const el = screen.getByTestId('row');
        expect(el.children).toHaveLength(2);
        const wrapper = el.lastElementChild as HTMLElement;
        expect(wrapper.tagName).toBe('DIV');
        expect(wrapper.querySelector('span')).toHaveTextContent('-');

        act(() => { transitionManager.setPending(true); });
        try {
            expect((screen.getByTestId('row').lastElementChild as HTMLElement)).toHaveClass('blur-sm');
        } finally {
            act(() => { transitionManager.setPending(false); });
        }
    });

    /**
     * if 가 없는 흐림 노드는 종전대로 래퍼를 가진다.
     *
     * @scenario case=blur_without_if
     * @effects blur_wrapper_kept_when_rendered
     */
    it('if 없는 흐림 노드는 종전대로 래퍼 div 안에 그린다', () => {
        mount({ id: 'b', type: 'basic', name: 'Span', blur_until_loaded: true, props: { 'data-testid': 'b' }, text: 'x' }, {});
        expect(screen.getByTestId('b').parentElement?.tagName).toBe('DIV');
    });
});
