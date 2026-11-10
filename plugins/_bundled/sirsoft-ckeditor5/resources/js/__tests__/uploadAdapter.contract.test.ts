/**
 * 본문 미디어 업로드 계약 경로 — 편집기는 계약을 읽기만 한다 (#883 4단계)
 *
 * 화면이 `mediaUpload` 계약을 넘기면 어댑터는 코어 `G7Core.contentMedia` 로 판정(형식·크기)·문서당 개수 사전 차단·업로드를
 * 한다. 계약이 없으면 종전 경로(이 플러그인의 관리자 전용 업로드)다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/editor-content-media-contract.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { G7UploadAdapter } from '../handlers/uploadAdapter';

const TARGET = {
    version: 1,
    endpoint: '/api/modules/sirsoft-board/boards/free/content-media',
    response: { urlPath: 'data.url' },
    mediaUrlPattern: '/content-media/(?<hash>[A-Za-z0-9]{12})',
    policy: {
        image: { enabled: true, maxSizeMb: 1, extensions: ['png'], mimeTypes: ['image/png'] },
        video: { enabled: false, maxSizeMb: 1, extensions: [], mimeTypes: [] },
        audio: { enabled: false, maxSizeMb: 1, extensions: [], mimeTypes: [] },
        maxPerDocument: 2,
    },
};

/**
 * FileLoader 대역.
 *
 * @param file 파일
 * @returns FileLoader
 */
function loader(file: File) {
    return { file: Promise.resolve(file), uploadTotal: null as number | null, uploaded: 0 };
}

describe('업로드 어댑터 — 본문 미디어 계약', () => {
    let upload: ReturnType<typeof vi.fn>;
    let validateFile: ReturnType<typeof vi.fn>;
    let isAtLimit: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        upload = vi.fn(async () => ({ url: '/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA?signature=x', kind: 'image' }));
        validateFile = vi.fn(() => ({ ok: true, kind: 'image' }));
        isAtLimit = vi.fn(() => false);
        apiPost = vi.fn();
        (window as any).G7Core = {
            t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`,
            api: { post: apiPost, isAnonymousUnauthorized: () => false },
            contentMedia: { upload, validateFile, isAtLimit },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    /**
     * @scenario case=contract_upload
     * @effects uploads_through_core_contract, legacy_endpoint_not_used
     */
    it('계약이 있으면 코어 contentMedia.upload 로 올리고 종전 주소는 쓰지 않는다', async () => {
        const adapter = new G7UploadAdapter(loader(new File(['x'], 'a.png', { type: 'image/png' })), {
            target: TARGET,
            legacyUrl: '/api/plugins/sirsoft-ckeditor5/upload',
            getContent: () => '<p></p>',
        });

        const result = await adapter.upload();

        expect(result.default).toContain('/content-media/AAAAAAAAAAAA');
        expect(upload).toHaveBeenCalledTimes(1);
        expect(apiPost).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=policy_rejected
     * @effects rejected_before_request
     */
    it('정책 밖 파일은 요청 없이 판정 문구로 거부한다', async () => {
        validateFile.mockReturnValue({ ok: false, kind: 'image', messageKey: 'core.content_media.client.file_too_large', params: { max: 1 } });
        const adapter = new G7UploadAdapter(loader(new File(['x'], 'a.png')), { target: TARGET, legacyUrl: '/x' });

        await expect(adapter.upload()).rejects.toBe('core.content_media.client.file_too_large{"max":1}');
        expect(upload).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=limit_reached
     * @effects limit_blocked_before_request
     */
    it('문서당 최대 개수에 닿았으면 요청 없이 막는다', async () => {
        isAtLimit.mockReturnValue(true);
        const adapter = new G7UploadAdapter(loader(new File(['x'], 'a.png')), { target: TARGET, legacyUrl: '/x', getContent: () => '<img>' });

        await expect(adapter.upload()).rejects.toBe('core.content_media.client.limit_reached{"max":2}');
        expect(upload).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=anonymous_401
     * @effects anonymous_401_keeps_draft_message
     */
    it('무토큰 401 은 로그인 필요 안내로 바꾼다(글 유지)', async () => {
        upload.mockRejectedValue({ response: { status: 401 } });
        (window as any).G7Core.api.isAnonymousUnauthorized = () => true;
        const adapter = new G7UploadAdapter(loader(new File(['x'], 'a.png')), { target: TARGET, legacyUrl: '/x' });

        // 번역 대역이 키를 그대로 돌려주면(번역 없음) 대체 문구 — 로그인 화면으로 옮기지 않고 글이 남아 있음을 알린다
        await expect(adapter.upload()).rejects.toBe('Sign in to upload images. Your draft is kept.');
    });

    /**
     * @scenario case=no_contract
     * @effects legacy_path_without_contract
     */
    it('계약이 없으면 종전 주소로 upload 필드를 보낸다', async () => {
        apiPost.mockResolvedValue({ url: '/storage/a.png' });
        const adapter = new G7UploadAdapter(loader(new File(['x'], 'a.png')), '/api/plugins/sirsoft-ckeditor5/upload');

        await expect(adapter.upload()).resolves.toEqual({ default: '/storage/a.png' });
        expect(apiPost.mock.calls[0][0]).toBe('/api/plugins/sirsoft-ckeditor5/upload');
        expect(upload).not.toHaveBeenCalled();
    });
});
