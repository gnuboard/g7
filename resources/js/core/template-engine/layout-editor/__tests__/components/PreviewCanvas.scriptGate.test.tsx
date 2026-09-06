/**
 * PreviewCanvas — 편집 캔버스 스크립트 주입 출처 게이트 회귀 테스트.
 *
 * 프리뷰는 런타임 렌더의 미리보기다. 런타임(TemplateApp)이 거부하는 `scripts[].src` 를
 * 캔버스가 관리자 document.head 에 그대로 주입하면, **편집 중에만** 임의 원격 코드가
 * 관리자 세션에서 실행된다. 저장은 422 로 막히므로 저장 전 미리보기 단계가 유일한
 * 실행 창이고, 오류도 로그도 남지 않는다.
 *
 * 판정·결과는 런타임과 같아야 한다 — same-origin 경로/선언된 신뢰 호스트만 주입,
 * 그 밖은 skip + 경고.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup } from '@testing-library/react';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { PreviewCanvas } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

describe('PreviewCanvas — scripts[] 출처 게이트', () => {
    let originalG7Core: any;
    let layoutScripts: Array<{ src: string; id?: string }> = [];
    let warnSpy: ReturnType<typeof vi.spyOn>;

    const injectedScriptSrcs = (): string[] =>
        Array.from(window.document.querySelectorAll('script[data-g7le-canvas-script]')).map(
            el => el.getAttribute('data-g7le-canvas-script') ?? ''
        );

    beforeEach(() => {
        ComponentRegistry.resetInstance();
        originalG7Core = (window as any).G7Core;
        (window as any).G7Core = { t: vi.fn((key: string) => key) };
        (window as any).G7Config = { trustedScriptHosts: [] };

        warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        // 주입된 태그가 영원히 pending 되지 않도록 즉시 load 를 발화시킨다
        vi.spyOn(window.document.head, 'appendChild').mockImplementation(((node: any) => {
            if (node.tagName === 'SCRIPT') {
                window.document.body.appendChild(node);
                queueMicrotask(() => node.dispatchEvent(new Event('load')));
                return node;
            }
            return HTMLHeadElement.prototype.appendChild.call(window.document.head, node);
        }) as any);

        (global as any).fetch = vi.fn(async (url: string) => {
            if (url.includes('/editor-assets')) {
                return {
                    ok: true,
                    json: async () => ({
                        data: {
                            identifier: 'sirsoft-basic',
                            js: [],
                            css: [],
                            manifest_present: true,
                        },
                    }),
                };
            }
            if (url.includes('/components.json')) {
                return {
                    ok: true,
                    json: async () => ({
                        version: '1.0.0',
                        templateId: 'sirsoft-basic',
                        components: { basic: [], composite: [], layout: [] },
                    }),
                };
            }
            if (url.includes('/lang/')) {
                return { ok: true, json: async () => ({}) };
            }
            if (url.includes('/api/layouts/')) {
                return {
                    ok: true,
                    json: async () => ({ data: { components: [], scripts: layoutScripts } }),
                };
            }
            return { ok: false, status: 404, json: async () => ({}) };
        });

        (window as any).SirsoftBasic = {};
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        window.document
            .querySelectorAll('script[data-g7le-canvas-script]')
            .forEach(el => el.remove());
        (window as any).G7Core = originalG7Core;
        delete (window as any).G7Config;
        delete (window as any).SirsoftBasic;
        ComponentRegistry.resetInstance();
        layoutScripts = [];
    });

    /**
     * 문서 컨텍스트를 직접 주입해 캔버스를 렌더한다.
     *
     * 라우트 선택 → routes fetch → 문서 fetch 전체를 태우지 않고도, 캔버스가 실제로
     * `document.raw.scripts` 를 읽어 주입하는 그 경로를 그대로 지난다
     * (LayoutDocumentProvider 가 단일 진입점이다).
     */
    const renderCanvas = () => {
        const engine = new TranslationEngine();
        const docValue = {
            document: {
                layoutName: 'main',
                raw: { components: [], scripts: layoutScripts },
                lockVersion: 0,
            },
            isLoading: false,
            error: null,
        } as any;

        return render(
            React.createElement(
                TranslationProvider,
                {
                    translationEngine: engine,
                    translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
                },
                React.createElement(
                    LayoutEditorProvider,
                    { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko' },
                    React.createElement(
                        LayoutDocumentProvider,
                        { value: docValue },
                        React.createElement(PreviewCanvas)
                    )
                )
            )
        );
    };

    it('미신뢰 외부 src 는 주입하지 않고 경고만 남긴다', async () => {
        layoutScripts = [{ src: 'https://cdn.evil.com/x.js', id: 'evil' }];

        renderCanvas();

        await waitFor(() => {
            expect(warnSpy).toHaveBeenCalledWith(
                expect.stringContaining('Blocked untrusted script src')
            );
        });

        expect(injectedScriptSrcs()).not.toContain('https://cdn.evil.com/x.js');
    });

    it('authority 우회 형태도 주입하지 않는다', async () => {
        layoutScripts = [{ src: '/\\/evil.com/x.js', id: 'bypass' }];

        renderCanvas();

        await waitFor(() => {
            expect(warnSpy).toHaveBeenCalledWith(
                expect.stringContaining('Blocked untrusted script src')
            );
        });

        expect(injectedScriptSrcs()).not.toContain('/\\/evil.com/x.js');
    });

    it('same-origin 경로는 그대로 주입된다 (과차단 없음)', async () => {
        layoutScripts = [{ src: '/api/templates/assets/sirsoft-basic/x.js', id: 'ok' }];

        renderCanvas();

        await waitFor(() => {
            expect(injectedScriptSrcs()).toContain('/api/templates/assets/sirsoft-basic/x.js');
        });
    });

    it('선언된 신뢰 호스트는 주입된다', async () => {
        (window as any).G7Config = { trustedScriptHosts: ['cdn.ckeditor.com'] };
        layoutScripts = [{ src: 'https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.js', id: 'ck' }];

        renderCanvas();

        await waitFor(() => {
            expect(injectedScriptSrcs()).toContain(
                'https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.js'
            );
        });
    });
});
