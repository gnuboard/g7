/**
 * 회귀: 편집기가 레이아웃의 읽기 전용 값 변화를 따라간다 (O-F1-2)
 *
 * 결함: `html-editor.json` 은 `onMount` 에서만 `initEditor` 를 부르고, 편집기는 그때 받은 `readOnly` 로
 * 굳었다. 권한 판정이 로드 뒤에 바뀌는 화면(페이지 작성 화면의 등록 권한 확인, 게시판 글 수정의
 * `form_meta` 도착 등)에서 편집기가 먼저 그려지면 권한 있는 관리자에게도 잠긴 편집기가 남았다
 * (7.1.0 전수 실측 영역 L A-F1 재실측 중 발견 — 최고관리자 작성 화면 contenteditable=false).
 *
 * 계약:
 *  1. 조각의 컨테이너는 같은 읽기 전용 식으로 `data-readonly="true|false"` 상태 표식을 단다.
 *     표식은 스타일이 아니라 상태라 클래스로 두지 않는다(클래스로 두면 템플릿 CSS 어휘·safelist 검사에
 *     스타일 없는 이름으로 잡힌다)
 *  2. 표식이 바뀌면 붙어 있는 편집기의 읽기 전용 잠금을 켜고 끈다(평문 폴백 textarea 도)
 *  3. 표식이 없으면(예전 조각) 초기화 값을 그대로 쓴다
 *
 * @scenario-manifest tests/scenarios/editor-readonly-follow.yaml
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import fs from 'fs';
import path from 'path';
import { initEditorHandler } from '../../handlers/initEditor';
import { destroyEditorHandler } from '../../handlers/destroyEditor';
import {
    attachReadOnlySync,
    readOnlyFromContainer,
    READONLY_ATTR,
} from '../../handlers/readOnlySync';

const extensionJson = JSON.parse(
    fs.readFileSync(path.resolve(__dirname, '../../../extensions/html-editor.json'), 'utf-8')
);

/** 편집기 대역 — CKEditor 의 읽기 전용 잠금 API 만 흉내 낸다 */
function fakeEditor() {
    const locks = new Set<string>();
    return {
        enableReadOnlyMode: vi.fn((id: string) => locks.add(id)),
        disableReadOnlyMode: vi.fn((id: string) => locks.delete(id)),
        get isReadOnly() {
            return locks.size > 0;
        },
    };
}

/** MutationObserver 콜백이 돌 때까지 기다린다 */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
    document.body.innerHTML = '';
});

describe('[case:extension_attr] 조각 컨테이너가 읽기 전용 식으로 상태 표식을 바꾼다', () => {
    /**
     * @scenario case=extension_attr
     *
     * @effects container_readonly_attr_tracks_expression
     */
    it('컨테이너 data-readonly 가 initEditor 의 readOnly 와 같은 식으로 true/false 를 고른다', () => {
        const container = extensionJson.components[0];
        const readOnlyExpr: string = container.lifecycle.onMount[0].params.readOnly;
        const marker: string = container.props?.[READONLY_ATTR] ?? '';

        expect(marker, '컨테이너 표식이 읽기 전용 값을 따라 바뀌어야 한다').toContain("'true'");
        expect(marker).toContain("'false'");
        // 같은 식을 써야 초기화 값과 이후 값이 어긋나지 않는다
        const inner = readOnlyExpr.replace(/^\{\{|\}\}$/g, '');
        expect(marker).toContain(inner);
    });

    /**
     * @scenario case=extension_attr
     *
     * @effects container_class_has_no_state_marker
     */
    it('컨테이너에 클래스를 두지 않는다 — 상태 표식도, 어느 CSS 에도 규칙이 없는 고정 이름(ckeditor5-wrapper)도 없다', () => {
        const container = extensionJson.components[0];

        // 편집기 핸들러는 컨테이너를 id 로 찾고 상태는 data-readonly 로 읽는다. 클래스는 아무도 읽지 않는다.
        expect(container.props?.className, '스타일 규칙 없는 클래스는 템플릿 CSS 어휘 검사에 걸린다').toBeUndefined();
        expect(JSON.stringify(extensionJson)).not.toMatch(/ckeditor5-(readonly|editable|wrapper)/);
    });
});

describe('[case:follow] 표식 변화에 편집기 읽기 전용이 따라간다', () => {
    /**
     * @scenario case=follow
     *
     * @effects editor_readonly_follows_container_attr
     */
    it('잠긴 채 그려진 편집기가 권한 확인 뒤(editable) 풀리고, 다시 readonly 가 되면 잠긴다', async () => {
        const container = document.createElement('div');
        container.setAttribute(READONLY_ATTR, 'true');
        document.body.appendChild(container);
        const editor = fakeEditor();

        const handle = attachReadOnlySync(container, () => [editor], true);
        expect(editor.isReadOnly).toBe(true);

        container.setAttribute(READONLY_ATTR, 'false');
        await flush();
        expect(editor.isReadOnly, '권한 확인 뒤에는 입력 가능해야 한다').toBe(false);

        container.setAttribute(READONLY_ATTR, 'true');
        await flush();
        expect(editor.isReadOnly).toBe(true);

        handle.stop();
        container.setAttribute(READONLY_ATTR, 'false');
        await flush();
        expect(editor.isReadOnly, '멈춘 뒤에는 따라가지 않는다').toBe(true);
    });

    /**
     * @scenario case=follow
     *
     * @effects editor_readonly_follows_container_attr
     */
    it('초기화 값(false)으로 만든 편집기도 이미 readonly 로 바뀐 컨테이너면 붙자마자 잠근다', () => {
        const container = document.createElement('div');
        container.setAttribute(READONLY_ATTR, 'true');
        const editor = fakeEditor();

        attachReadOnlySync(container, () => [editor], false);

        expect(editor.enableReadOnlyMode).toHaveBeenCalledWith('read-only');
        expect(editor.isReadOnly).toBe(true);
    });

    /**
     * @scenario case=fallback_textarea
     *
     * @effects fallback_textarea_readonly_follows_container_attr
     */
    it('편집기를 못 불러와 평문 입력으로 내려앉은 경우에도 textarea 읽기 전용이 따라간다', async () => {
        const container = document.createElement('div');
        container.setAttribute(READONLY_ATTR, 'true');
        const textarea = document.createElement('textarea');
        container.appendChild(textarea);
        document.body.appendChild(container);

        attachReadOnlySync(container, () => [], true);
        expect(textarea.readOnly).toBe(true);

        container.setAttribute(READONLY_ATTR, 'false');
        await flush();
        expect(textarea.readOnly).toBe(false);
    });
});

describe('[case:legacy] 예전 조각(표식 없음)은 초기화 값을 쓴다', () => {
    /**
     * @scenario case=legacy
     *
     * @effects legacy_container_keeps_init_value
     */
    it('표식이 없거나 true/false 가 아니면 초기화 값으로 판정한다', () => {
        const container = document.createElement('div');

        expect(readOnlyFromContainer(container, true)).toBe(true);
        expect(readOnlyFromContainer(container, false)).toBe(false);

        // 엔진이 식을 아직 평가하지 못한 값(미평가 `{{…}}`)이나 빈 값도 표식이 없는 것으로 본다
        container.setAttribute(READONLY_ATTR, '{{_computed.isReadOnly}}');
        expect(readOnlyFromContainer(container, true)).toBe(true);
        expect(readOnlyFromContainer(container, false)).toBe(false);
    });

    /**
     * @scenario case=follow
     *
     * @effects editor_readonly_follows_container_attr
     */
    it('클래스만 바뀌는 것(스타일 변경)에는 반응하지 않는다', async () => {
        const container = document.createElement('div');
        container.setAttribute(READONLY_ATTR, 'true');
        document.body.appendChild(container);
        const editor = fakeEditor();
        attachReadOnlySync(container, () => [editor], true);

        container.className = 'ckeditor5-editable';
        await flush();
        expect(editor.isReadOnly, '예전 클래스 표식은 더 이상 상태가 아니다').toBe(true);
    });
});

describe('[case:init_handler] initEditor 가 만든 편집기가 컨테이너 표식을 따라간다', () => {
    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).CKEDITOR;
        delete (window as any).CKEDITOR_VERSION;
        document.querySelectorAll('script, link, style').forEach((el) => el.remove());
        vi.restoreAllMocks();
    });

    /**
     * @scenario case=init_handler
     *
     * @effects editor_readonly_follows_container_attr
     */
    it('권한 확인 전 readOnly=true 로 만든 편집기가 클래스가 editable 로 바뀌면 입력 가능해진다', async () => {
        const editor = Object.assign(fakeEditor(), {
            destroy: async () => {},
            model: { document: { on: () => {} } },
            editing: { view: { document: { on: () => {} } } },
            ui: { view: { editable: { element: document.createElement('div') } } },
            getData: () => '',
            setData: () => {},
            on: () => {},
        });
        const create = vi.fn(async () => editor);
        (window as any).G7Core = {
            locale: { current: () => 'ko' },
            state: { get: () => ({}), getLocal: () => ({ form: {} }), setLocal: vi.fn() },
            asset: {
                plugin: (_id: string, p: string) => `/api/plugins/assets/sirsoft-ckeditor5?file=${encodeURIComponent(p)}`,
                loadStylesheet: vi.fn(async () => {}),
                loadScript: vi.fn(async () => {
                    (window as any).CKEDITOR_VERSION = '43.3.1';
                    (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
                }),
            },
        };
        const container = document.createElement('div');
        container.id = 'ckeditor5-content';
        container.setAttribute(READONLY_ATTR, 'true');
        document.body.appendChild(container);

        await initEditorHandler({ params: { name: 'content', readOnly: true } }, {});
        expect(create).toHaveBeenCalled();
        expect(editor.isReadOnly).toBe(true);

        container.setAttribute(READONLY_ATTR, 'false');
        await flush();
        expect(editor.isReadOnly, '권한 확인 뒤 입력 가능해야 한다(종전: 잠긴 채 남음)').toBe(false);

        await destroyEditorHandler({ params: { name: 'content' } }, {});
        container.setAttribute(READONLY_ATTR, 'true');
        await flush();
        expect(editor.isReadOnly, '해제한 뒤에는 따라가지 않는다').toBe(false);
    });
});
