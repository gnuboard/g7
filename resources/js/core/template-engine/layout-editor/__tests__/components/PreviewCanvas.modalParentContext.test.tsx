/**
 * PreviewCanvas — 창 편집 모드의 창 노드가 받는 `$parent` 회귀 테스트.
 *
 * 런타임 openModal 은 창을 연 레이아웃의 맥락 `{_local, _global, _computed}` 를 레이아웃 맥락 스택에 쌓아
 * 창의 `$parent` 로 넘긴다(ActionDispatcher 의 openModal 처리). 편집기는 창 편집 때 창 노드를 호스트
 * 컴포넌트 뒤 루트 형제로 덧붙여 그리므로 같은 맥락을 직접 넘겨야 하는데, 처음 연결은 `_computed` 를
 * 빼고 넘겨 `{{$parent._computed.*}}` 를 읽는 창(게시판 일괄 적용 창의 게시판 이름 등)이 편집기에서만
 * 비어 보였다.
 *
 * 순수 함수 테스트(`modalEditParentContext.test.ts`)는 PreviewCanvas 가 그 결과를 실제로 창 노드에
 * 넘기는지 보지 못하므로, 여기서는 실제 PreviewCanvas 를 창 편집 모드로 그리고 DynamicRenderer 가
 * 받은 prop 을 붙잡아 확인한다.
 *
 * @scenario scope_kind=modal, patch_branch=local
 * @effects modal_edit_preview_parent_carries_host_computed, modal_edit_preview_host_node_gets_no_parent
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup } from '@testing-library/react';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { PreviewCanvas } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

const captured = vi.hoisted(() => ({ props: [] as Array<Record<string, any>> }));

vi.mock('../../../DynamicRenderer', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>();
    const Capture = (props: Record<string, any>) => {
        captured.props.push(props);
        return null;
    };
    return { ...actual, default: Capture };
});

/** 마운트 시 창 편집 모드로 들어가는 테스트 헬퍼 */
function EnterModalEdit(): null {
    const { dispatch } = useLayoutEditor();
    React.useEffect(() => {
        dispatch({ type: 'ENTER_MODAL_EDIT', modalId: 'bulk_apply_modal', hostLayout: 'admin_board_settings' } as any);
    }, [dispatch]);
    return null;
}

describe('PreviewCanvas — 창 편집 모드 창 노드의 $parent', () => {
    let originalG7Core: any;

    beforeEach(() => {
        captured.props = [];
        ComponentRegistry.resetInstance();
        originalG7Core = (window as any).G7Core;
        (window as any).G7Core = { t: vi.fn((key: string) => key) };
        (window as any).G7Config = { trustedScriptHosts: [] };
        (global as any).fetch = vi.fn(async (url: string) => {
            if (url.includes('/editor-assets')) {
                return {
                    ok: true,
                    json: async () => ({ data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true } }),
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
            return { ok: false, status: 404, json: async () => ({}) };
        });
        (window as any).SirsoftBasic = {};
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        (window as any).G7Core = originalG7Core;
        delete (window as any).G7Config;
        delete (window as any).SirsoftBasic;
        ComponentRegistry.resetInstance();
    });

    /** 호스트 노드 + 끝에 덧붙인 창 노드로 된 창 편집 문서(useLayoutDocument 의 모양)로 캔버스를 그린다. */
    const renderModalEditCanvas = () => {
        const engine = new TranslationEngine();
        const hostRaw = {
            components: [{ id: 'host_root', type: 'basic', name: 'Div', props: {} }],
            computed: {
                boardLabel: "{{ 'host-computed' }}",
                isReadOnly: '{{ false }}',
            },
        };
        const modalNode = { id: 'bulk_apply_modal', type: 'composite', name: 'Modal', props: {} };
        const docValue = {
            document: {
                layoutName: 'admin_board_settings',
                raw: { ...hostRaw, components: [...hostRaw.components, modalNode] },
                lockVersion: 0,
                modalContext: {
                    modalId: 'bulk_apply_modal',
                    modalIndex: 0,
                    hostRaw,
                    originalModalNode: modalNode,
                    editIndexPath: [1],
                },
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
                        EditorModalProvider,
                        null,
                        React.createElement(EnterModalEdit),
                        React.createElement(LayoutDocumentProvider, { value: docValue }, React.createElement(PreviewCanvas)),
                    ),
                ),
            ),
        );
    };

    const latestPropsFor = (id: string): Record<string, any> | undefined =>
        [...captured.props].reverse().find((p) => p.componentDef?.id === id);

    // @scenario scope_kind=modal, patch_branch=local
    // @effects modal_edit_preview_parent_carries_host_computed
    it('창 노드의 $parent 에 호스트 레이아웃의 _computed 값이 실린다(런타임 openModal 과 같은 모양)', async () => {
        renderModalEditCanvas();

        await waitFor(() => {
            const modalProps = latestPropsFor('bulk_apply_modal');
            expect(modalProps?.parentDataContext).toBeDefined();
            expect(modalProps!.parentDataContext._computed).toEqual(
                expect.objectContaining({ boardLabel: 'host-computed', isReadOnly: false }),
            );
        });

        const parent = latestPropsFor('bulk_apply_modal')!.parentDataContext;
        expect(Object.keys(parent).sort()).toEqual(['_computed', '_global', '_local']);
    });

    // @scenario scope_kind=modal, patch_branch=local
    // @effects modal_edit_preview_host_node_gets_no_parent
    it('호스트 노드에는 $parent 를 넘기지 않는다', async () => {
        renderModalEditCanvas();

        await waitFor(() => {
            expect(latestPropsFor('bulk_apply_modal')?.parentDataContext).toBeDefined();
        });
        expect(latestPropsFor('host_root')?.parentDataContext).toBeUndefined();
    });
});
