// e2e:allow 업로드 단추 노출 판정은 편집기 생성 설정(툴바·extraPlugins)으로 잠그고, 실제 툴바는 Chrome MCP 실측 T1-a·T1-b(비회원·회원·관리자)로 확인한다.
/**
 * 회귀: 관리자가 아닌 사람에게는 편집기 이미지 업로드 단추를 주지 않는다 (#883)
 *
 * 결함: 사용자 글쓰기 화면에도 「Upload image from computer」 단추가 나와, 비회원이 누르면 이 플러그인의 관리자 전용 업로드
 * 주소가 401 을 주고 코어가 세션 만료로 보고 로그인 화면으로 옮겨 쓰던 글이 사라졌다. 일반 회원은 403 으로 실패했다.
 *
 * 계약: 플러그인 설정이 켜져 있어도 보는 사람이 관리자(부팅 때 받은 인증 사용자의 `is_admin`)일 때만 업로드 단추·어댑터를 붙인다.
 * 관리자 템플릿은 `_global.currentUser` 를 올리지 않으므로 판정은 `AuthManager.getUser()` 를 먼저 본다.
 * 편집기 경고(업로드 실패)는 네이티브 alert 대신 코어 토스트로 보낸다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-upload-gate.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initEditorHandler, viewerIsAdmin } from '../../handlers/initEditor';

type Listener = (evt: { stop: () => void }, data: Record<string, unknown>) => void;

let create: ReturnType<typeof vi.fn>;
let warningListeners: Listener[];

/**
 * 코어 대역을 깝니다.
 *
 * @param authUser 부팅 때 받은 인증 사용자 (없으면 비회원)
 * @param globalState `G7Core.state.get()` 결과
 */
function installCore(authUser: Record<string, unknown> | null, globalState: Record<string, unknown> = {}): ReturnType<typeof vi.fn> {
    const toastError = vi.fn();
    warningListeners = [];
    create = vi.fn(async () => ({
        destroy: async () => {},
        model: { document: { on: () => {} } },
        editing: { view: { document: { on: () => {} } } },
        ui: { view: { editable: { element: document.createElement('div') } } },
        plugins: {
            get: (name: string) => (name === 'Notification'
                ? { on: (event: string, listener: Listener) => { if (event === 'show:warning') warningListeners.push(listener); } }
                : undefined),
        },
        getData: () => '',
        setData: () => {},
        on: () => {},
        enableReadOnlyMode: () => {},
    }));
    (window as any).G7Core = {
        locale: { current: () => 'ko' },
        toast: { error: toastError },
        AuthManager: { getInstance: () => ({ getUser: () => authUser }) },
        state: { get: () => globalState, getLocal: () => ({ form: {} }), setLocal: vi.fn() },
        asset: {
            plugin: (_id: string, p: string) => `/api/plugins/assets/sirsoft-ckeditor5?file=${encodeURIComponent(p)}`,
            loadStylesheet: vi.fn(async () => {}),
            loadScript: vi.fn(async () => {
                (window as any).CKEDITOR_VERSION = '43.3.1';
                (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
            }),
        },
    };

    return toastError;
}

/**
 * 편집기를 띄우고 생성 설정을 돌려줍니다.
 *
 * @param imageUpload 플러그인 설정의 이미지 업로드 값
 * @return CKEditor 생성 설정
 */
async function mountAndGetConfig(imageUpload: boolean): Promise<Record<string, any>> {
    const container = document.createElement('div');
    container.id = 'ckeditor5-content';
    document.body.appendChild(container);

    await initEditorHandler({ params: { name: 'content', imageUpload } }, {});

    expect(create).toHaveBeenCalled();

    return create.mock.calls[0][1];
}

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).CKEDITOR;
    delete (window as any).CKEDITOR_VERSION;
    document.querySelectorAll('script, link, style').forEach((el) => el.remove());
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('편집기 이미지 업로드 단추 — 관리자에게만 (#883)', () => {
    /**
     * @scenario case=guest_setting_on
     * @effects upload_button_hidden_for_non_admin
     */
    it('비회원: 설정이 켜져 있어도 업로드 단추·어댑터가 없다', async () => {
        installCore(null);
        const config = await mountAndGetConfig(true);
        expect(config.toolbar.items).not.toContain('uploadImage');
        expect(config.extraPlugins).toBeUndefined();
    });

    /**
     * @scenario case=member_setting_on
     * @effects upload_button_hidden_for_non_admin
     */
    it('일반 회원: 업로드 단추가 없다', async () => {
        installCore({ uuid: 'm', is_admin: false }, { _global: { currentUser: { uuid: 'm', is_admin: false } } });
        const config = await mountAndGetConfig(true);
        expect(config.toolbar.items).not.toContain('uploadImage');
        expect(config.extraPlugins).toBeUndefined();
    });

    /**
     * @scenario case=admin_setting_on
     * @effects upload_button_shown_for_admin
     */
    it('관리자: 업로드 단추와 어댑터가 있다', async () => {
        installCore({ uuid: 'a', is_admin: true });
        const config = await mountAndGetConfig(true);
        expect(config.toolbar.items).toContain('uploadImage');
        expect(config.extraPlugins).toHaveLength(1);
    });

    /**
     * @scenario case=admin_setting_off
     * @effects upload_button_hidden_when_setting_off
     */
    it('관리자라도 설정이 꺼져 있으면 단추가 없다', async () => {
        installCore({ uuid: 'a', is_admin: true });
        const config = await mountAndGetConfig(false);
        expect(config.toolbar.items).not.toContain('uploadImage');
    });

    /**
     * @scenario case=auth_user_fallback
     * @effects upload_button_shown_for_admin, upload_button_hidden_for_non_admin
     */
    it('인증 사용자가 없으면 사용자 템플릿 _global.currentUser 로 판정한다', () => {
        installCore(null);
        expect(viewerIsAdmin({ _global: { currentUser: { is_admin: true } } })).toBe(true);
        expect(viewerIsAdmin({ _global: { currentUser: { is_admin: false } } })).toBe(false);
        expect(viewerIsAdmin({})).toBe(false);
    });
});

describe('편집기 경고 알림 — 네이티브 alert 대신 코어 토스트 (#883)', () => {
    /**
     * @scenario case=warning_toast
     * @effects editor_warning_routed_to_toast
     */
    it('show:warning 을 토스트로 보내고 기본 alert 를 막는다', async () => {
        const toastError = installCore({ uuid: 'a', is_admin: true });
        await mountAndGetConfig(true);

        expect(warningListeners).toHaveLength(1);
        const stop = vi.fn();
        warningListeners[0]({ stop }, { message: '이미지를 올리려면 로그인이 필요합니다.' });

        expect(toastError).toHaveBeenCalledWith('이미지를 올리려면 로그인이 필요합니다.');
        expect(stop).toHaveBeenCalledOnce();
    });
});
