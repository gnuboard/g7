/**
 * DynamicRenderer.urlSchemeSanitize.test.tsx
 *
 * 바인딩된 URL 속성의 실행 스킴 무해화를 잠근다 (KVE-2026-2348).
 *
 * `identity_challenge` 는 `<a href="{{_local.redirect_url}}">` 를 그리고 그 값은 `query.redirect_url`
 * 에서 온다. 검증 없이 렌더하면 `?redirect_url=javascript:…` 링크 클릭 한 번으로 현재 origin 에서
 * 코드가 돈다. origin 은 제한하지 않는다 — 정상 외부 링크·mailto·tel 은 그대로여야 한다.
 *
 * @since engine-v1.73.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestA: React.FC<any> = ({ children, ...rest }) => <a {...rest}>{children}</a>;
const TestImg: React.FC<any> = (props) => <img alt="" {...props} />;
const TestButton: React.FC<any> = ({ children, ...rest }) => <button {...rest}>{children}</button>;

describe('DynamicRenderer — URL 속성 실행 스킴 무해화', () => {
    let registry: ComponentRegistry;
    let bindingEngine: DataBindingEngine;
    let translationEngine: TranslationEngine;
    let actionDispatcher: ActionDispatcher;
    let translationContext: TranslationContext;

    beforeEach(() => {
        registry = ComponentRegistry.getInstance();
        (registry as any).registry = {
            A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
            Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
            Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        };

        bindingEngine = new DataBindingEngine();
        translationEngine = new TranslationEngine();
        actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
        translationContext = { templateId: 'test-template', locale: 'ko' };
    });

    const renderDef = (componentDef: ComponentDefinition, dataContext: any = {}) =>
        render(
            <DynamicRenderer
                componentDef={componentDef}
                dataContext={dataContext}
                translationContext={translationContext}
                registry={registry}
                bindingEngine={bindingEngine}
                translationEngine={translationEngine}
                actionDispatcher={actionDispatcher}
            />
        );

    const link = (id: string, href: string): ComponentDefinition =>
        ({
            id,
            type: 'basic',
            name: 'A',
            props: { 'data-testid': id, href },
            text: 'go',
        }) as any;

    /**
     * @effects dangerous_href_neutralized
     */
    it('query 에서 온 javascript:/data: href 는 # 로 무해화된다', () => {
        for (const payload of ['javascript:alert(1)', ' JaVa\tScRiPt:alert(1)', 'data:text/html,<b>x</b>']) {
            const { unmount } = renderDef(link('lnk', '{{_local.redirect_url}}'), {
                _local: { redirect_url: payload },
            });

            expect(screen.getByTestId('lnk').getAttribute('href')).toBe('#');
            unmount();
        }
    });

    /**
     * @effects external_link_preserved
     */
    it('정상 외부 링크·mailto·tel·same-origin 경로는 그대로 렌더된다 (과차단 방지)', () => {
        for (const href of ['https://sir.kr/', 'mailto:help@example.com', 'tel:0212345678', '/board/free?page=2']) {
            const { unmount } = renderDef(link('lnk', '{{_local.url}}'), { _local: { url: href } });

            expect(screen.getByTestId('lnk').getAttribute('href')).toBe(href);
            unmount();
        }
    });

    /**
     * @effects dangerous_href_neutralized, external_link_preserved
     */
    it('src 는 스크립트 스킴만 제거하고 data: 이미지는 유지한다', () => {
        const img = (): ComponentDefinition =>
            ({ id: 'img', type: 'basic', name: 'Img', props: { 'data-testid': 'img', src: '{{_local.src}}' } }) as any;

        const dataImage = 'data:image/png;base64,iVBORw0KGgo=';
        const first = renderDef(img(), { _local: { src: dataImage } });
        expect(screen.getByTestId('img').getAttribute('src')).toBe(dataImage);
        first.unmount();

        renderDef(img(), { _local: { src: 'javascript:alert(1)' } });
        expect(screen.getByTestId('img').getAttribute('src')).toBeNull();
    });

    /**
     * @effects dangerous_href_neutralized
     */
    it('formAction 의 실행 스킴은 속성째 제거된다', () => {
        renderDef(
            {
                id: 'btn',
                type: 'basic',
                name: 'Button',
                props: { 'data-testid': 'btn', formAction: '{{_local.u}}' },
                text: 'submit',
            } as any,
            { _local: { u: 'javascript:alert(1)' } }
        );

        expect(screen.getByTestId('btn').getAttribute('formaction')).toBeNull();
    });
});
