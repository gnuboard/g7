// e2e:allow 실물 엔진(TemplateApp.handleRouteChange·renderTemplate·DynamicRenderer·ActionDispatcher·G7Core.state)에 편집기 조각(html-editor.json)과 실물 initEditor 를 올려 같은 레이아웃 경로 이동의 재시딩을 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(두 템플릿 글 수정 → 새 글 쓰기·새 글 → 다른 글 수정·뒤로/앞으로)이 덮는다
/**
 * 회귀: 글 수정 화면에서 새 글 쓰기·다른 글 수정으로 옮기면 편집기가 새 글의 본문으로 다시 선다
 *
 * 글 수정(`/board/{slug}/{id}/edit`)과 새 글 쓰기(`/board/{slug}/write`)는 **같은 레이아웃**이라 편집기 조각이 다시
 * 마운트되지 않는다(`onMount` 의 initEditor 가 다시 돌지 않음). 편집기 확장은 주소가 바뀐 것을 보고 폼의 새 값으로
 * 편집기를 다시 세운다(`externalContentSync`).
 *
 * 결함: 주소가 바뀐 뒤 새 폼 값이 오기 전(blocking 폼 데이터를 받는 동안)에 재시딩 감시가 한 번 돌면, 「폼이 아직
 * 앞 글 값인가」를 **편집기에 보이는 값**(CKEditor 가 다듬은 HTML)과만 비교했다. 저장된 본문은 평문·서버 정리 HTML 이라
 * 편집기 값과 글자가 다르다 → 앞 글 값을 「새 값」으로 오인해 앞 글 본문으로 다시 세우고 대기를 끝냈다. 정작 새 글 값
 * (`''`)이 도착하면 「입력 중이라 폼이 뒤처진 것」으로 보아 다시 세우지 않았다 → **새 글 화면 편집기에 앞 글 본문이
 * 남고**, 한 글자만 쳐도 그 본문이 새 글 폼에 들어갔다.
 *
 * 하네스: 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·
 * `ActionDispatcher`(편집기 조각의 onMount → 실물 initEditor 핸들러)·`G7Core.state`(본문 동기화의
 * `render:false`·`selfManaged`·디바운스 setLocal 공존 경로). CKEditor 본체만 대역(받은 평문을 `<p>` 로 감싸는 정리 포함).
 * API 응답은 대역이 잡아 두었다가 푼다(받는 동안 재시딩 감시가 도는 창을 만든다).
 *
 * @scenario-manifest tests/scenarios/editor-route-reseed.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import fs from 'fs';
import path from 'path';
import { waitFor } from '@testing-library/react';

/** API 대역 — 엔드포인트로 응답을 정하고, 잡아 두기를 켜면 풀 때까지 기다린다 */
const net = vi.hoisted(() => ({
    hold: false,
    pending: [] as Array<() => void>,
}));

vi.mock('@core/api/ApiClient', () => ({
    getApiClient: () => ({
        get: (url: string) => {
            const body = respond(url);
            if (!net.hold) return Promise.resolve(body);

            return new Promise((resolve) => { net.pending.push(() => resolve(body)); });
        },
        post: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
        setOnUnauthorized: vi.fn(),
    }),
}));

import { TemplateApp } from '@core/TemplateApp';
import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { AuthManager } from '@core/auth/AuthManager';
import { initEditorHandler } from '../../handlers/initEditor';
import { destroyEditorHandler } from '../../handlers/destroyEditor';
import { CKEDITOR5_CSS_ID } from '../../handlers/ckeditorAssets';

type Node = Record<string, any>;

/** 서버가 주는 글 폼 값 — 저장된 본문은 평문(본문 모드 text)이라 편집기가 다듬은 값과 글자가 다르다 */
function respond(url: string): any {
    if (url === '/test/posts/96') return { success: true, data: { id: 96, content: '앞 글 본문', content_mode: 'text', attachments: [1] } };
    if (url === '/test/posts/97') return { success: true, data: { id: 97, content: '다른 글 본문', content_mode: 'text', attachments: [] } };
    if (url === '/test/posts/new') return { success: true, data: { content: '', content_mode: 'text' } };

    return { success: true, data: {} };
}

/** 편집기 조각 — 플러그인이 설치될 때 코어 HTML 편집기 자리에 들어가는 실물 정의 */
const extension = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../extensions/html-editor.json'), 'utf-8'));

/** 게시판 글 폼 꼴 — 수정·새 글·답글이 한 레이아웃(경로만 다름), 폼은 blocking 응답의 initLocal(form) */
const POST_FORM: Node = {
    version: '1.0.0',
    layout_name: 'post_form',
    data_sources: [{
        id: 'form_data', type: 'api', endpoint: "/api/test/posts/{{route.id ?? 'new'}}",
        auth_mode: 'required', loading_strategy: 'blocking', initLocal: 'form',
    }],
    components: [{
        id: 'root_form', type: 'basic', name: 'Div', props: { 'data-testid': 'root-form' },
        children: [
            {
                id: 'content_editor', type: 'extension_point', name: 'html_editor',
                props: { name: 'content', content: "{{_local.form?.content ?? ''}}" },
                // 서버가 확장 조각을 주입할 때처럼 확장 지점 props 를 자식에게 넘긴다
                children: extension.components.map((c: Node) => ({
                    ...JSON.parse(JSON.stringify(c)),
                    extensionPointProps: { name: 'content', content: "{{_local.form?.content ?? ''}}" },
                })),
            },
            { id: 'form_id', type: 'basic', name: 'Span', props: { 'data-testid': 'form-id' }, text: "{{'id:' + (_local.form?.id ?? 'none')}}" },
        ],
    }],
};

/**
 * 글 폼 레이아웃을 확장 지점 본문 전달 방식별로 돌려준다.
 *
 * @param withContentProp 확장 지점이 본문 값을 넘기는지 — basic 글 폼은 넘기고(`content: _local.form.content`),
 *                        관리자 글 폼은 넘기지 않아 편집기가 폼에서 직접 읽는다
 * @returns 레이아웃
 */
function postForm(withContentProp: boolean, contentExpr = "{{_local.form?.content ?? ''}}"): Node {
    const pointProps: Node = withContentProp
        ? { name: 'content', content: contentExpr }
        : { name: 'content' };
    const layout = JSON.parse(JSON.stringify(POST_FORM));
    const point = layout.components[0].children[0];
    point.props = pointProps;
    point.children.forEach((c: Node) => { c.extensionPointProps = pointProps; });

    return layout;
}

const ROUTES = {
    e96: { path: '/board/b/96/edit', params: { id: '96' } },
    e97: { path: '/board/b/97/edit', params: { id: '97' } },
    write: { path: '/board/b/write', params: {} },
} satisfies Record<string, { path: string; params: Record<string, string> }>;

/** CKEditor 대역 — 평문은 `<p>` 로 감싸 돌려준다(실물 CKEditor 의 데이터 정리와 같은 꼴). setData 도 change:data 를 낸다 */
interface FakeEditor {
    getData(): string;
    setData(v: string): void;
    /** 사용자가 편집기에 친 것처럼 값을 바꾸고 change:data 를 낸다 */
    type(v: string): void;
}

const created: FakeEditor[] = [];

/** initEditor 호출 기록(주소·받은 본문) — 같은 레이아웃 이동에서 다시 불리지 않는다는 전제를 확인한다 */
const initCalls: Array<{ path: string; content: unknown }> = [];

function normalize(v: string): string {
    if (v === '') return '';

    return v.trimStart().startsWith('<') ? v : `<p>${v}</p>`;
}

/**
 * 편집기 대역 조절.
 * - failCreate: 편집기 만들기를 실패시켜 평문 입력칸으로 내려앉게 한다
 * - createGate: 편집기 만들기를 이 약속이 풀릴 때까지 미룬다(편집기가 늦게 서는 동안 화면을 옮기는 갈래)
 */
const fake: { failCreate: boolean; createGate: Promise<void> | null } = { failCreate: false, createGate: null };

/** 편집기 본체 싣기를 잡아 두었다가 실패시키는 대역 — 본체 404 로 평문 입력칸까지 몇 초 걸리는 실제 갈래 */
const asset: { reject: null | (() => void) } = { reject: null };

function installFakeCkeditor(): void {
    class ClassicEditor {
        static async create(_el: HTMLElement, config: { initialData?: string }): Promise<any> {
            if (fake.createGate) await fake.createGate;
            if (fake.failCreate) throw new Error('편집기 생성 실패(시험)');
            let data = normalize(config.initialData ?? '');
            const listeners: Array<() => void> = [];
            const fire = () => listeners.forEach((cb) => cb());
            const editor = {
                model: { document: { on: (event: string, cb: () => void) => { if (event === 'change:data') listeners.push(cb); } } },
                editing: { view: { document: { on: () => {} } } },
                ui: { view: { editable: { element: document.createElement('div') } } },
                on: () => {},
                isReadOnly: false,
                enableReadOnlyMode: () => {},
                disableReadOnlyMode: () => {},
                destroy: async () => {},
                getData: () => data,
                setData: (v: string) => { data = normalize(v); fire(); },
                type: (v: string) => { data = normalize(v); fire(); },
            };
            created.push(editor);

            return editor;
        }
    }
    (window as any).CKEDITOR_VERSION = '43.3.1';
    (window as any).CKEDITOR = { ClassicEditor };
    // 번역·CSS 는 이미 실린 것으로 둔다(시험 관심 밖)
    for (const id of ['ckeditor5-translations-ko', CKEDITOR5_CSS_ID]) {
        const el = document.createElement('link');
        el.id = id;
        document.head.appendChild(el);
    }
}

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-') || ['id', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
const text = (testid: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.textContent ?? null;
const local = (): Record<string, any> => (window as any).G7Core.state.getLocal();
const fallbackArea = (): HTMLTextAreaElement => document.querySelector('textarea[data-ckeditor5-fallback]') as HTMLTextAreaElement;

/**
 * 평문 입력칸에 사용자가 친 것처럼 값을 넣는다.
 *
 * @param value 넣을 값
 * @return void
 */
function typeIntoFallback(value: string): void {
    const area = fallbackArea();
    area.value = value;
    area.dispatchEvent(new Event('input', { bubbles: true }));
}
/** 재시딩 감시 주기(400ms)를 두 번 넘기는 시간 */
const TWO_TICKS = 900;

/**
 * 실물 엔진을 올리고 첫 화면으로 들어간다(응답은 바로).
 *
 * @param first 첫 화면
 * @returns 이동 함수(go: 응답 바로 · goHeld: 응답을 잡아 둔 채 주소부터 바꾸고 감시 주기를 넘긴 뒤 푼다)
 */
async function mount(
    first: { path: string; params: Record<string, string> },
    options: { fallback?: boolean; assetHeld?: boolean; createHeld?: boolean; admin?: boolean; contentExpr?: string } = {},
) {
    document.body.innerHTML = '<div id="app"></div>';
    fake.failCreate = options.fallback === true;
    let releaseCreate: () => void = () => {};
    fake.createGate = options.createHeld ? new Promise<void>((r) => { releaseCreate = r; }) : null;
    net.hold = false;
    net.pending = [];
    created.length = 0;
    installFakeCkeditor();
    vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
    if (options.assetHeld) {
        // 본체가 없다 — 싣기는 잡아 두었다가 풀 때 실패(404)
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        (window as any).G7Core.asset = {
            ...((window as any).G7Core.asset ?? {}),
            loadScript: () => new Promise((_resolve, rejectLoad) => {
                asset.reject = () => rejectLoad(new Error('404 (시험)'));
            }),
        };
    }
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
    };
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    initCalls.length = 0;
    dispatcher.registerHandler('sirsoft-ckeditor5.initEditor', ((action: any, context: any) => {
        initCalls.push({ path: location.pathname, content: action?.params?.content });

        return initEditorHandler(action, context);
    }) as any, { category: 'plugin', source: 'sirsoft-ckeditor5' } as any);
    dispatcher.registerHandler('sirsoft-ckeditor5.destroyEditor', destroyEditorHandler as any, { category: 'plugin', source: 'sirsoft-ckeditor5' } as any);
    const layout = postForm(options.admin !== true, options.contentExpr);
    (app as any).layoutLoader = { loadLayout: async () => JSON.parse(JSON.stringify(layout)) };

    // Router.executeNavigation 과 같은 순서: pushState → 화면 전환
    const go = async (r: { path: string; params: Record<string, string> }, afterPush?: () => void): Promise<void> => {
        window.history.pushState({}, '', r.path);
        afterPush?.();
        await (app as any).handleRouteChange({ path: r.path, layout: 'post_form', params: r.params, query: {} });
    };
    /** 폼 데이터를 받는 동안(주소는 이미 바뀜) 재시딩 감시가 두 번 돌게 한 뒤 응답을 푼다 */
    const goHeld = async (
        r: { path: string; params: Record<string, string> },
        during?: () => void,
        afterPush?: () => void,
    ): Promise<void> => {
        net.hold = true;
        const run = go(r, afterPush);
        await waitFor(() => expect(net.pending.length).toBeGreaterThan(0));
        await settle(TWO_TICKS);
        during?.();
        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        await run;
    };

    await go(first);
    if (options.assetHeld || options.createHeld) {
        // 편집기(또는 평문 입력칸)가 아직 서지 않은 채 돌려준다
        await waitFor(() => expect(local().form?.content).toBe(respond(`/test/posts/${first.params.id ?? 'new'}`).data.content));
    } else if (fake.failCreate) {
        await waitFor(() => expect(document.querySelector('textarea[data-ckeditor5-fallback]')).not.toBeNull());
    } else {
        await waitFor(() => expect(created.length).toBe(1));
    }

    return { app, go, goHeld, editor: created[0], releaseCreate: () => releaseCreate() };
}

describe('같은 레이아웃 경로 이동 — 편집기가 새 글 값으로 다시 선다 (실물 엔진 + 편집기 조각)', () => {
    afterEach(async () => {
        fake.failCreate = false;
        fake.createGate = null;
        asset.reject = null;
        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        try { await destroyEditorHandler({ params: { name: 'content' } } as any, {}); } catch { /* 정리 */ }
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        document.head.innerHTML = '';
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        window.history.pushState({}, '', '/');
    });

    /**
     * 글 수정 → 새 글 쓰기. 폼 데이터를 받는 동안 감시가 돈다(서버가 400ms 넘게 걸리는 흔한 경우).
     * 종전: 편집기에 앞 글 본문 `<p>앞 글 본문</p>` 이 남음.
     *
     * @scenario case=edit_to_write_fetch_window
     * @effects editor_shows_new_form_value, editor_not_remounted
     */
    it('글 수정 → 새 글 쓰기: 받는 동안 감시가 돌아도 편집기가 빈칸으로 선다', async () => {
        const { goHeld, editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));
        expect(local().form?.content).toBe('앞 글 본문');

        await goHeld(ROUTES.write);
        await waitFor(() => expect(text('form-id')).toBe('id:none'));
        expect(local().form?.content).toBe('');
        await settle(TWO_TICKS);

        expect(editor.getData(), '새 글 화면 편집기에 앞 글 본문이 남으면 안 된다').toBe('');
        expect(created.length, '같은 레이아웃이라 편집기를 다시 만들지 않는다(재사용 경로)').toBe(1);
    });

    /**
     * 위에 이어 한 글자를 치면 새 글 폼에는 친 글자만 들어간다. 종전: 앞 글 본문 + 친 글자가 새 글 폼에 들어감.
     *
     * @scenario case=edit_to_write_then_type
     * @effects typed_value_reaches_new_form_only
     */
    it('글 수정 → 새 글 쓰기 뒤 입력: 새 글 폼에 앞 글 본문이 섞이지 않는다', async () => {
        const { goHeld, editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));

        await goHeld(ROUTES.write);
        await settle(TWO_TICKS);
        editor.type(`${editor.getData()}<p>새</p>`);
        // 본문 동기화는 300ms 디바운스 + render:false
        await settle(500);

        expect(local().form?.content).toBe('<p>새</p>');
        expect(String(local().form?.content)).not.toContain('앞 글 본문');
    });

    /**
     * 응답이 빨라 감시가 받는 동안 돌지 않는 경우(종전에도 통과) — 같은 결과여야 한다.
     *
     * @scenario case=edit_to_write_fast_fetch
     * @effects editor_shows_new_form_value, editor_not_remounted
     */
    it('글 수정 → 새 글 쓰기: 응답이 빨라도 편집기가 빈칸으로 선다', async () => {
        const { go, editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));

        await go(ROUTES.write);
        await settle(TWO_TICKS);

        expect(editor.getData()).toBe('');
        expect(created.length).toBe(1);
    });

    /**
     * 글 A 수정 → 글 B 수정(받는 동안 감시). 종전: 편집기에 글 A 본문이 남고 글 B 저장 때 A 로 덮일 수 있었다.
     *
     * @scenario case=edit_to_other_edit
     * @effects editor_shows_new_form_value, editor_not_remounted
     */
    it('글 A 수정 → 글 B 수정: 편집기가 글 B 본문으로 선다', async () => {
        const { goHeld, editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));

        await goHeld(ROUTES.e97);
        await settle(TWO_TICKS);

        expect(editor.getData()).toBe('<p>다른 글 본문</p>');
        expect(created.length).toBe(1);
    });

    /**
     * 새 글(입력함) → 다른 글 수정(받는 동안 감시) → 뒤로(새 글 주소, 새 폼 `''`). 앞으로·뒤로 모두 그 화면 값으로 선다.
     *
     * @scenario case=write_to_edit_and_back
     * @effects editor_shows_new_form_value, typed_value_reaches_new_form_only
     */
    it('새 글(입력) → 다른 글 수정 → 뒤로: 각 화면 값으로 선다', async () => {
        const { go, goHeld, editor } = await mount(ROUTES.write);
        await waitFor(() => expect(local().form?.content).toBe(''));
        editor.type('<p>쓰던 글</p>');
        await settle(500);
        expect(local().form?.content).toBe('<p>쓰던 글</p>');

        await goHeld(ROUTES.e97);
        await settle(TWO_TICKS);
        expect(editor.getData()).toBe('<p>다른 글 본문</p>');

        // 뒤로(popstate 와 같은 전환) — 새 글 주소, 서버는 빈 폼
        await go(ROUTES.write);
        await settle(TWO_TICKS);
        expect(editor.getData()).toBe('');
        expect(local().form?.content).toBe('');
    });

    /**
     * 주소가 바뀐 바로 뒤(다음 감시 전) 편집기가 값을 내도 새 화면 폼에 쓰지 않는다. 종전: 감시 주기(400ms) 안의 입력은
     * 앞 글 본문을 실은 채 폼으로 나갔다(디바운스가 새 폼이 선 뒤 떨어지면 새 글 폼에 앞 글 본문).
     *
     * @scenario case=input_before_poll
     * @effects no_emit_before_reseed
     */
    it('주소가 바뀐 바로 뒤 감시 전 입력은 내보내지 않는다', async () => {
        const { goHeld, editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));
        const before = local().form?.content;

        await goHeld(ROUTES.write, () => {
            // 받는 동안 — 감시 전 입력이 폼으로 나갔는지 본다(본문 동기화 디바운스 300ms 를 넘겨 둔 뒤)
            expect(local().form?.content, '감시 전 입력이 앞 글 폼으로 나가면 안 된다').toBe(before);
        }, () => {
            // pushState 바로 뒤, 감시가 돌기 전 — 편집기는 아직 앞 글 본문이다
            editor.type('<p>앞 글 본문</p><p>x</p>');
        });
        await settle(TWO_TICKS);

        expect(String(local().form?.content ?? '')).not.toContain('앞 글 본문');
        expect(editor.getData()).toBe('');
    });

    /**
     * 받는 동안(감시가 돈 뒤) 편집기에 친 값도 새 화면 폼으로 내보내지 않는다. 종전: 감시가 앞 글 원문으로 다시 세우고
     * 대기를 끝내 내보내기가 열려, 앞 글 본문 + 친 글자가 새 글 폼에 들어갔다.
     *
     * @scenario case=input_during_fetch
     * @effects no_emit_before_reseed
     */
    it('받는 동안 입력은 새 화면 폼으로 내보내지 않는다', async () => {
        const { goHeld, editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));

        await goHeld(ROUTES.write, () => {
            // 받는 동안의 입력 — 편집기는 아직 앞 글 본문이다
            editor.type('<p>앞 글 본문</p><p>x</p>');
        });
        await settle(TWO_TICKS);

        expect(String(local().form?.content ?? '')).not.toContain('앞 글 본문');
        expect(editor.getData()).toBe('');
    });

    /**
     * 같은 화면에서 치는 동안에는 되돌리지 않는다(폼이 디바운스로 뒤처져도) — 자동바인딩 순서·글자 손실 회귀 가드.
     *
     * @scenario case=same_screen_typing
     * @effects typing_not_reverted
     */
    it('같은 화면 입력 중에는 되돌리지 않는다', async () => {
        const { editor } = await mount(ROUTES.e96);
        await waitFor(() => expect(editor.getData()).toBe('<p>앞 글 본문</p>'));

        for (const ch of ['가', '나', '다', '라']) {
            editor.type(`${editor.getData()}<p>${ch}</p>`);
            await settle(150);
        }
        await settle(TWO_TICKS);

        expect(editor.getData()).toBe('<p>앞 글 본문</p><p>가</p><p>나</p><p>다</p><p>라</p>');
        expect(local().form?.content).toBe('<p>앞 글 본문</p><p>가</p><p>나</p><p>다</p><p>라</p>');
    });
    /**
     * 편집기를 만들지 못해 평문 입력칸으로 내려앉은 화면도 같다. 종전: 입력칸에 재동기화가 없어 새 글 화면에 앞 글 본문이
     * 그대로 남고, 입력하면 그 본문이 새 글 폼에 들어갔다.
     *
     * @scenario case=fallback_edit_to_write
     * @effects fallback_shows_new_form_value, typed_value_reaches_new_form_only
     */
    it('평문 입력칸: 글 수정 → 새 글 쓰기에서 빈칸으로 서고 입력은 새 글 값만 보낸다', async () => {
        const { goHeld } = await mount(ROUTES.e96, { fallback: true });
        await waitFor(() => expect(fallbackArea().value).toBe('앞 글 본문'));

        await goHeld(ROUTES.write);
        await settle(TWO_TICKS);
        expect(fallbackArea().value, '새 글 화면 입력칸에 앞 글 본문이 남으면 안 된다').toBe('');

        typeIntoFallback('새');
        await settle(50);
        expect(local().form?.content).toBe('새');
        expect(local().form?.content_mode).toBe('text');
    });

    /**
     * 평문 입력칸 — 주소가 바뀐 바로 뒤(감시 전) 입력도 내보내지 않는다.
     *
     * @scenario case=fallback_input_before_poll
     * @effects no_emit_before_reseed
     */
    it('평문 입력칸: 주소가 바뀐 바로 뒤 입력은 내보내지 않는다', async () => {
        const { goHeld } = await mount(ROUTES.e96, { fallback: true });
        await waitFor(() => expect(fallbackArea().value).toBe('앞 글 본문'));

        await goHeld(ROUTES.write, () => {
            expect(local().form?.content, '감시 전 입력이 폼으로 나가면 안 된다').toBe('앞 글 본문');
        }, () => {
            typeIntoFallback('앞 글 본문x');
        });
        await settle(TWO_TICKS);

        expect(String(local().form?.content ?? '')).not.toContain('앞 글 본문');
        expect(fallbackArea().value).toBe('');
    });

    /**
     * 평문 입력칸 — 같은 화면에서 치는 동안은 되돌리지 않는다.
     *
     * @scenario case=fallback_same_screen_typing
     * @effects typing_not_reverted
     */
    it('평문 입력칸: 같은 화면 입력 중에는 되돌리지 않는다', async () => {
        await mount(ROUTES.e96, { fallback: true });
        await waitFor(() => expect(fallbackArea().value).toBe('앞 글 본문'));

        for (const ch of ['가', '나', '다']) {
            typeIntoFallback(fallbackArea().value + ch);
            await settle(150);
        }
        await settle(TWO_TICKS);

        expect(fallbackArea().value).toBe('앞 글 본문가나다');
        expect(local().form?.content).toBe('앞 글 본문가나다');
    });
    /**
     * 편집기 본체 404 로 평문 입력칸이 몇 초 뒤에 서는 동안 새 글 쓰기로 옮긴 갈래(실측 K9 모양 그대로 — basic 글 폼).
     * 입력칸은 마운트 때 받은 앞 글 본문으로 서는데 감시는 이미 바뀐 주소를 기준으로 붙어 재시딩하지 않았다.
     * 종전: 입력칸·폼 모두 「앞 글 본문 + 입력」.
     *
     * @scenario case=fallback_late_after_move
     * @effects fallback_shows_new_form_value, typed_value_reaches_new_form_only
     */
    it('평문 입력칸이 늦게 서는 동안 새 글로 옮겨도 앞 글 본문으로 서지 않는다 (basic 꼴)', async () => {
        // 브라우저에서는 조각이 마운트될 때 확장 지점 본문(`_local.form.content`)이 이미 앞 글 본문으로 풀려 넘어온다.
        // 이 하네스는 첫 렌더 문맥에 `_local` 이 아직 없어 같은 식이 빈 값으로 풀리므로, 첫 렌더에 있는 받은 값으로 같은 꼴을 만든다.
        const { go } = await mount(ROUTES.e96, { assetHeld: true, contentExpr: "{{form_data?.data?.content ?? ''}}" });
        expect(initCalls[0]?.content, '마운트 때 앞 글 본문을 받는다(브라우저와 같은 꼴)').toBe('앞 글 본문');
        await waitFor(() => expect(asset.reject).not.toBeNull());
        expect(fallbackArea(), '본체를 싣는 동안은 입력칸이 없다').toBeNull();

        await go(ROUTES.write);
        await waitFor(() => expect(text('form-id')).toBe('id:none'));
        // 이제서야 본체 실패 → 평문 입력칸
        asset.reject?.();
        await waitFor(() => expect(fallbackArea()).not.toBeNull());
        await settle(TWO_TICKS);

        expect(fallbackArea().value, '새 글 화면 입력칸에 앞 글 본문이 서면 안 된다').toBe('');
        typeIntoFallback('가');
        await settle(50);
        expect(local().form?.content).toBe('가');
    });

    /**
     * 같은 갈래를 관리자 글 폼 꼴(확장 지점이 본문 값을 넘기지 않아 편집기가 폼에서 읽음)로 — 입력칸이 서는 순간 아직 새 폼을
     * 받는 중이면 앞 글 폼 값을 읽는다.
     *
     * @scenario case=fallback_late_during_fetch_admin
     * @effects fallback_shows_new_form_value
     */
    it('평문 입력칸이 새 폼을 받는 중에 서도 새 글 값으로 다시 선다 (관리자 꼴)', async () => {
        const { goHeld } = await mount(ROUTES.e96, { assetHeld: true, admin: true });
        await waitFor(() => expect(asset.reject).not.toBeNull());

        await goHeld(ROUTES.write, () => {
            // 받는 중(폼은 아직 앞 글) — 이때 입력칸이 선다
            asset.reject?.();
        });
        await waitFor(() => expect(fallbackArea()).not.toBeNull());
        await settle(TWO_TICKS);

        expect(fallbackArea().value).toBe('');
        expect(local().form?.content).toBe('');
    });

    /**
     * 편집기(정상 경로)가 늦게 서는 동안 옮긴 갈래 — 편집기는 마운트 때 받은 앞 글 본문으로 만들어진다.
     *
     * @scenario case=editor_late_after_move
     * @effects editor_shows_new_form_value, typed_value_reaches_new_form_only
     */
    it('편집기가 늦게 서는 동안 새 글로 옮겨도 앞 글 본문으로 남지 않는다', async () => {
        const { go, releaseCreate } = await mount(ROUTES.e96, { createHeld: true });

        await go(ROUTES.write);
        await waitFor(() => expect(text('form-id')).toBe('id:none'));
        releaseCreate();
        await waitFor(() => expect(created.length).toBe(1));
        await settle(TWO_TICKS);

        const editor = created[0];
        expect(editor.getData(), '새 글 화면 편집기에 앞 글 본문이 남으면 안 된다').toBe('');
        editor.type('<p>새</p>');
        await settle(500);
        expect(local().form?.content).toBe('<p>새</p>');
    });
});
