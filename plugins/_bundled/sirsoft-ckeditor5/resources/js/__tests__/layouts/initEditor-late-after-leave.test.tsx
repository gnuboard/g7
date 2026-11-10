// e2e:allow 편집기 생성 중 화면 이탈 — 생성 지연 경합이라 시험 대역 + Chrome MCP 실제 키 재실측(편집기 생성 전 사이드바 이동, form 0)으로 잠근다.
/**
 * 회귀: 편집기가 늦게 서는 동안 화면을 떠나면 새 화면 상태에 편집기 모드를 쓰지 않는다
 *
 * 결함: 관리자 상품 수정 → 사이드바 「상품 관리」로 바로 옮기면 목록 화면 `_local` 에 `form: { description_mode: 'html' }`
 * 이 생겼다. 편집기 생성(`ClassicEditor.create`)은 비동기라 끝나는 데 수 초가 걸리는데, 끝난 뒤 컨테이너가 이미 화면에서
 * 떨어져 나갔는지 보지 않고 `G7Core.state.setLocal({ 'form.{name}_mode': 'html' })` 를 불러, 그때의 화면(목록)에 썼다.
 * 떨어져 나간 편집기는 해제하지 않아 인스턴스도 남았다.
 *
 * 계약: 생성이 끝났을 때 컨테이너가 문서에 없으면 편집기를 해제하고 폼에 아무것도 쓰지 않는다. 붙어 있으면 종전대로 쓴다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-route-reseed.yaml
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { initEditorHandler } from '../../handlers/initEditor';
import { editorInstances } from '../../handlers/editorInstances';

/** create 를 손으로 풀 수 있는 편집기 대역 */
function heldEditor() {
    let release: () => void = () => {};
    const destroy = vi.fn(async () => {});
    const editor = {
        destroy,
        enableReadOnlyMode: () => {},
        disableReadOnlyMode: () => {},
        isReadOnly: false,
        model: { document: { on: () => {} } },
        editing: { view: { document: { on: () => {} } } },
        ui: { view: { editable: { element: document.createElement('div') } } },
        getData: () => '',
        setData: () => {},
        on: () => {},
    };
    const create = vi.fn(() => new Promise((resolve) => { release = () => resolve(editor); }));
    return { editor, create, destroy, release: () => release() };
}

function installCore(create: (...args: any[]) => any, setLocal: ReturnType<typeof vi.fn>) {
    (window as any).G7Core = {
        locale: { current: () => 'ko' },
        state: { get: () => ({}), getLocal: () => ({ form: {} }), setLocal },
        asset: {
            plugin: (_id: string, p: string) => `/api/plugins/assets/sirsoft-ckeditor5?file=${encodeURIComponent(p)}`,
            loadStylesheet: vi.fn(async () => {}),
            loadScript: vi.fn(async () => {
                (window as any).CKEDITOR_VERSION = '43.3.1';
                (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
            }),
        },
    };
}

const modeWrites = (setLocal: ReturnType<typeof vi.fn>) =>
    setLocal.mock.calls.filter(([v]) => v && typeof v === 'object' && Object.keys(v).some((k) => k.endsWith('_mode')));

afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).CKEDITOR;
    delete (window as any).CKEDITOR_VERSION;
    document.body.innerHTML = '';
    document.querySelectorAll('script, link, style').forEach((el) => el.remove());
    vi.restoreAllMocks();
});

describe('[case:editor_late_after_leave] 편집기 생성 중 화면을 떠남', () => {
    /**
     * @scenario case=editor_late_after_leave
     * @effects no_form_write_after_leaving_screen
     */
    it('단일 편집기: 생성이 끝나기 전에 컨테이너가 떨어져 나가면 mode 를 쓰지 않고 편집기를 해제한다', async () => {
        const held = heldEditor();
        const setLocal = vi.fn();
        installCore(held.create, setLocal);
        const container = document.createElement('div');
        container.id = 'ckeditor5-description';
        document.body.appendChild(container);

        const pending = initEditorHandler({ params: { name: 'description' } }, {});
        await vi.waitFor(() => expect(held.create).toHaveBeenCalled());

        container.remove(); // 화면 전환 — 수정 화면이 내려감
        held.release();
        await pending;

        expect(modeWrites(setLocal), '떠난 화면의 편집기가 새 화면 _local 에 form.description_mode 를 쓰면 안 된다').toEqual([]);
        expect(held.destroy).toHaveBeenCalled();
    });

    /**
     * @scenario case=editor_late_after_leave
     * @effects no_form_write_after_leaving_screen
     */
    it('다국어 편집기: 생성이 끝나기 전에 컨테이너가 떨어져 나가면 mode 를 쓰지 않고 편집기를 해제한다', async () => {
        const held = heldEditor();
        const setLocal = vi.fn();
        installCore(held.create, setLocal);
        const container = document.createElement('div');
        container.id = 'ckeditor5-description';
        document.body.appendChild(container);

        const pending = initEditorHandler({ params: { name: 'description', multilingual: true, content: { ko: '' } } }, {});
        await vi.waitFor(() => expect(held.create).toHaveBeenCalled());

        container.remove();
        held.release();
        await pending;

        expect(modeWrites(setLocal)).toEqual([]);
        expect(held.destroy).toHaveBeenCalled();
    });

    /**
     * @scenario case=editor_late_after_leave
     * @effects no_form_write_after_leaving_screen
     */
    it('대조: 컨테이너가 붙어 있으면 종전대로 form.{name}_mode 를 html 로 둔다', async () => {
        const held = heldEditor();
        const setLocal = vi.fn();
        installCore(held.create, setLocal);
        const container = document.createElement('div');
        container.id = 'ckeditor5-description';
        document.body.appendChild(container);

        const pending = initEditorHandler({ params: { name: 'description' } }, {});
        await vi.waitFor(() => expect(held.create).toHaveBeenCalled());
        held.release();
        await pending;

        expect(modeWrites(setLocal)).toEqual([[{ 'form.description_mode': 'html' }]]);
        expect(held.destroy).not.toHaveBeenCalled();
    });
});

/** create 호출마다 따로 풀 수 있는 편집기 대역 — 앞 화면·다음 화면 편집기를 구분한다 */
function heldEditors() {
    const made: Array<{ editor: ReturnType<typeof heldEditor>['editor']; release: () => void }> = [];
    const create = vi.fn(() => {
        const held = heldEditor();
        const promise = held.create();
        made.push({ editor: held.editor, release: held.release });
        return promise;
    });
    return { create, made };
}

describe('[case:editor_late_after_leave] 떠난 화면의 늦은 편집기가 다음 화면 편집기 등록을 지우지 않는다', () => {
    /**
     * 편집기 등록부 키는 `ckeditor5-{name}` 이라 화면이 달라도 같다. 앞 화면 편집기가 다 서기 전에 같은 이름의 편집기가 있는
     * 화면으로 옮기면, 앞 화면의 늦은 해제가 다음 화면 편집기 등록을 지워 해제·읽기 전용 전환·다음 초기화 정리가 그 편집기에 닿지 않는다.
     *
     * @scenario case=editor_late_after_leave
     * @effects next_screen_editor_registration_kept
     */
    it.each([
        ['단일', { name: 'description' }],
        ['다국어', { name: 'description', multilingual: true, content: { ko: '' } }],
    ])('%s 편집기: 앞 화면 편집기가 나중에 서도 다음 화면 편집기 등록이 남는다', async (_label, params) => {
        const held = heldEditors();
        installCore(held.create, vi.fn());
        const first = document.createElement('div');
        first.id = 'ckeditor5-description';
        document.body.appendChild(first);

        const firstPending = initEditorHandler({ params }, {});
        await vi.waitFor(() => expect(held.create).toHaveBeenCalledTimes(1));

        // 화면 전환 — 같은 이름의 편집기가 있는 다음 화면이 선다
        first.remove();
        const second = document.createElement('div');
        second.id = 'ckeditor5-description';
        document.body.appendChild(second);
        const secondPending = initEditorHandler({ params }, {});
        await vi.waitFor(() => expect(held.create).toHaveBeenCalledTimes(2));

        held.made[1].release();
        await secondPending;
        held.made[0].release();
        await firstPending;

        const registered = editorInstances.get('ckeditor5-description');
        expect(registered, '다음 화면 편집기 등록이 지워지면 안 된다').toBeDefined();
        expect([...(registered?.values() ?? [])]).toEqual([held.made[1].editor]);
        expect(held.made[0].editor.destroy).toHaveBeenCalled();
        expect(held.made[1].editor.destroy).not.toHaveBeenCalled();
    });
});
