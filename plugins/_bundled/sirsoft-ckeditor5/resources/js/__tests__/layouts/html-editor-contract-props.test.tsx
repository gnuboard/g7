// e2e:allow 계약 prop 에 따른 편집기 생성 설정(툴바·형식·extraPlugins)을 잠그고, 실제 업로드는 Chrome MCP 실측 T1-c·T1-d·T2-a 로 확인한다.
/**
 * 본문 미디어 계약 prop(`mediaUpload`) — 계약이 있으면 비회원·일반 회원도 화면의 업로드 주소로 올린다 (#883 4단계)
 *
 * 계약이 있으면 업로드 단추는 계약 정책(이미지 허용)이 정하고, 파일 선택 형식은 그 정책의 이미지 확장자다. 정책이 동영상·오디오를
 * 켰으면 그 단추도 툴바에 선다. 계약이 없으면(복제 템플릿) 종전 규칙 — 관리자만.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-content-media-contract.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initEditorHandler, resolveMediaUploadTarget, uploadTypesFromExtensions } from '../../handlers/initEditor';

let create: ReturnType<typeof vi.fn>;

const CONTRACT = {
    version: 1,
    endpoint: '/api/modules/sirsoft-board/boards/free/content-media',
    response: { urlPath: 'data.url' },
    mediaUrlPattern: '/content-media/(?<hash>[A-Za-z0-9]{12})',
    policy: {
        image: { enabled: true, maxSizeMb: 5, extensions: ['jpg', 'png'], mimeTypes: ['image/jpeg', 'image/png'] },
        video: { enabled: true, maxSizeMb: 5, extensions: ['mp4'], mimeTypes: ['video/mp4'] },
        audio: { enabled: false, maxSizeMb: 5, extensions: [], mimeTypes: [] },
        maxPerDocument: 30,
    },
};

/**
 * 코어 대역을 깝니다 — 보는 사람은 비회원.
 *
 * @return 없음
 */
function installCore(): void {
    create = vi.fn(async () => ({
        destroy: async () => {},
        model: { document: { on: () => {} } },
        editing: { view: { document: { on: () => {} } } },
        ui: { view: { editable: { element: document.createElement('div') } } },
        plugins: { get: () => undefined },
        getData: () => '',
        setData: () => {},
        on: () => {},
        enableReadOnlyMode: () => {},
    }));
    (window as any).G7Core = {
        locale: { current: () => 'ko' },
        toast: { error: vi.fn() },
        AuthManager: { getInstance: () => ({ getUser: () => null }) },
        state: { get: () => ({}), getLocal: () => ({ form: {} }), setLocal: vi.fn() },
        contentMedia: { normalizeTarget: (raw: any) => (raw?.version === 1 ? raw : null) },
        asset: {
            plugin: (_id: string, p: string) => `/assets?file=${encodeURIComponent(p)}`,
            loadStylesheet: vi.fn(async () => {}),
            loadScript: vi.fn(async () => {
                (window as any).CKEDITOR_VERSION = '43.3.1';
                (window as any).CKEDITOR = { ClassicEditor: Object.assign(class {}, { create }) };
            }),
        },
    };
}

/**
 * 편집기를 띄우고 생성 설정을 돌려줍니다.
 *
 * @param params initEditor 파라미터
 * @return CKEditor 생성 설정
 */
async function mount(params: Record<string, unknown>): Promise<Record<string, any>> {
    const container = document.createElement('div');
    container.id = 'ckeditor5-content';
    document.body.appendChild(container);
    await initEditorHandler({ params: { name: 'content', ...params } }, {});
    expect(create).toHaveBeenCalled();

    return create.mock.calls[0][1];
}

beforeEach(() => {
    document.body.innerHTML = '';
    installCore();
});

afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).CKEDITOR;
    delete (window as any).CKEDITOR_VERSION;
    document.querySelectorAll('script, link, style').forEach((el) => el.remove());
    vi.restoreAllMocks();
});

describe('html_editor mediaUpload 계약', () => {
    /**
     * @scenario case=guest_with_contract
     * @effects contract_enables_upload_for_guest, upload_types_follow_policy, media_buttons_follow_policy
     */
    it('비회원이라도 계약이 있으면 업로드 단추·어댑터가 서고, 형식은 정책 확장자, 동영상 단추가 선다', async () => {
        const config = await mount({ imageUpload: false, mediaUpload: CONTRACT });

        expect(config.toolbar.items).toContain('uploadImage');
        expect(config.toolbar.items).toContain('g7VideoUpload');
        expect(config.toolbar.items).not.toContain('g7AudioUpload');
        expect(config.image.upload.types).toEqual(['jpeg', 'png']);
        expect(config.extraPlugins).toHaveLength(2);
    });

    /**
     * @scenario case=contract_image_disabled
     * @effects contract_image_disabled_hides_upload
     */
    it('계약 정책이 이미지를 끄면 업로드 단추가 없다', async () => {
        const config = await mount({ mediaUpload: { ...CONTRACT, policy: { ...CONTRACT.policy, image: { ...CONTRACT.policy.image, enabled: false }, video: { ...CONTRACT.policy.video, enabled: false } } } });

        expect(config.toolbar.items).not.toContain('uploadImage');
        expect(config.extraPlugins).toBeUndefined();
    });

    /**
     * @scenario case=no_contract_clone_template
     * @effects legacy_rule_without_contract
     */
    it('계약이 없으면(복제 템플릿·null 문자열) 종전 규칙 — 비회원은 단추 없음', async () => {
        const config = await mount({ imageUpload: true, mediaUpload: 'null' });

        expect(config.toolbar.items).not.toContain('uploadImage');
        expect(config.image.upload.types).toEqual(['jpeg', 'png', 'gif', 'webp']);
    });

    /**
     * @scenario case=helpers
     * @effects contract_parsing_helpers
     */
    it('계약 해석·형식 변환 보조 함수', () => {
        expect(resolveMediaUploadTarget(JSON.stringify(CONTRACT))?.endpoint).toBe(CONTRACT.endpoint);
        expect(resolveMediaUploadTarget('{{form_meta.data.content_media}}')).toBeNull();
        expect(resolveMediaUploadTarget({ version: 2 })).toBeNull();
        expect(uploadTypesFromExtensions(['jpg', 'jpeg', 'png', 'avif', 'bmp'])).toEqual(['jpeg', 'png', 'avif']);
    });
});
