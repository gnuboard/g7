// e2e:allow 코어 규칙에 따른 편집기 생성 설정(툴바·mediaEmbed 제공자·htmlSupport.disallow)을 잠그고, 실제 삽입·표시는 Chrome MCP 실측 T2-f·T10-a 로 확인한다.
/**
 * 편집기가 코어 「본문 콘텐츠」 규칙을 따른다 — 외부 영상 서비스·금지 태그 (#883 5단계)
 *
 * 외부 영상 삽입이 켜져 있으면 표준 툴바에도 「미디어 삽입」이 서고, 제공자는 규칙의 서비스(서버가 컴파일한 정규식)만이며 CKEditor
 * 기본 제공자는 쓰지 않는다. 표시 규칙이 지울 태그는 `htmlSupport.disallow` 로 편집기도 받지 않는다. 규칙이 없으면 종전 설정.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-content-media-contract.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initEditorHandler, mediaEmbedProviders } from '../handlers/initEditor';
import fixture from '../../../../../../resources/js/core/contentPolicy/__tests__/fixtures/render-policy.json';

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

describe('편집기 — 본문 콘텐츠 규칙', () => {
    /**
     * @scenario case=policy_embed_providers
     * @effects editor_embed_providers_from_policy, editor_disallows_forbidden_tags
     */
    it('규칙이 있으면 미디어 삽입 단추·규칙 제공자만·금지 태그 disallow', async () => {
        const policy = fixture.render_policy as any;
        (window as any).G7Core.contentPolicy = {
            editorConstraints: () => ({ providers: policy.embed_providers, forbidTags: policy.forbid_tags, allowExternalEmbed: true, allowUploadedVideo: false, allowUploadedAudio: false }),
        };
        const config = await mount({});

        expect(config.toolbar.items).toContain('mediaEmbed');
        expect(config.mediaEmbed.providers).toEqual([]);
        expect(config.mediaEmbed.extraProviders.map((p: any) => p.name)).toEqual(policy.embed_providers.map((p: any) => p.id));
        expect(config.htmlSupport.disallow[0].name.test('script')).toBe(true);
        expect(config.htmlSupport.disallow[0].name.test('figure')).toBe(false);
    });

    /**
     * @scenario case=policy_embed_off
     * @effects editor_embed_hidden_when_off
     */
    it('외부 영상 삽입이 꺼지면 미디어 삽입 단추가 없다', async () => {
        (window as any).G7Core.contentPolicy = { editorConstraints: () => ({ providers: [], forbidTags: ['script'], allowExternalEmbed: false, allowUploadedVideo: false, allowUploadedAudio: false }) };
        const config = await mount({ toolbar: 'full' });

        expect(config.toolbar.items).not.toContain('mediaEmbed');
    });

    /**
     * @scenario case=policy_preview_html
     * @effects editor_preview_uses_player_from_id
     */
    it('미리보기는 꺼낸 id 로만 만든 플레이어 주소', () => {
        const [youtube] = mediaEmbedProviders((fixture.render_policy as any).embed_providers);
        const match = 'https://youtu.be/dQw4w9WgXcQ'.match(youtube.url[1]) as RegExpMatchArray;

        expect(youtube.html(match)).toContain('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"');
    });
});
