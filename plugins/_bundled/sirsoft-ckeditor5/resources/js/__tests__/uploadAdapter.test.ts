/**
 * CKEditor5 이미지 업로드는 코어 API 클라이언트(`G7Core.api`)로 보낸다 (#639)
 *
 * 종전에는 CDN 의 SimpleUploadAdapter 에 브라우저 저장소의 로그인 토큰으로 만든 Authorization 헤더를
 * 넘겼다. 코어 클라이언트를 우회해 토큰 갱신·401 처리를 놓치는 결함군이라, 업로드 어댑터가
 * `G7Core.api.post(url, formData)` 로 보내고 Bearer 는 코어가 싣는다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createG7UploadAdapterPlugin, G7UploadAdapter, pickResponsiveUrls } from '../handlers/uploadAdapter';

const UPLOAD_URL = '/api/plugins/sirsoft-ckeditor5/upload';

/**
 * CKEditor FileLoader 모의 객체를 만든다.
 *
 * @param file 업로드할 파일
 * @returns FileLoader
 */
function makeLoader(file: File | null = new File(['png'], 'photo.png', { type: 'image/png' })) {
    return { file: Promise.resolve(file), uploadTotal: null as number | null, uploaded: 0 };
}

describe('CKEditor5 업로드 어댑터', () => {
    let apiPost: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiPost = vi.fn();
        (window as any).G7Core = { api: { post: apiPost } };
        window.localStorage.clear();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        window.localStorage.clear();
    });

    /**
     * @scenario outcome=uploaded
     * @effects upload_sent_through_core_api_client, no_login_token_read
     */
    it('파일을 upload 필드에 담아 G7Core.api 로 보내고 로그인 토큰은 읽지 않는다', async () => {
        apiPost.mockResolvedValue({ url: '/storage/uploads/photo.png' });
        window.localStorage.setItem('auth_token', 'member-token');
        const getItem = vi.spyOn(Storage.prototype, 'getItem');

        const result = await new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload();

        expect(result).toEqual({ default: '/storage/uploads/photo.png' });
        expect(apiPost).toHaveBeenCalledTimes(1);
        const [url, body, config] = apiPost.mock.calls[0];
        expect(url).toBe(UPLOAD_URL);
        expect(body).toBeInstanceOf(FormData);
        expect((body as FormData).get('upload')).toBeInstanceOf(File);
        // Bearer 는 코어 클라이언트가 싣는다 — 어댑터가 Authorization 을 만들지 않는다
        expect(config.headers).not.toHaveProperty('Authorization');
        expect(getItem.mock.calls.map(([key]) => key)).not.toContain('auth_token');
    });

    /**
     * @scenario outcome=uploaded
     * @effects upload_progress_reported
     */
    it('전송 진행률을 CKEditor 로더에 알린다', async () => {
        const loader = makeLoader();
        apiPost.mockImplementation(async (_url: string, _body: FormData, config: any) => {
            config.onUploadProgress({ loaded: 50, total: 200 });

            return { url: '/storage/uploads/photo.png' };
        });

        await new G7UploadAdapter(loader, UPLOAD_URL).upload();

        expect(loader.uploadTotal).toBe(200);
        expect(loader.uploaded).toBe(50);
    });

    /**
     * @scenario outcome=rejected
     * @effects server_error_message_shown
     */
    it('서버가 거절하면 응답의 error.message 를 CKEditor 알림 문구로 넘긴다', async () => {
        apiPost.mockRejectedValue({ response: { status: 422, data: { error: { message: '이미지 파일만 올릴 수 있습니다.' } } } });

        await expect(new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload()).rejects.toBe('이미지 파일만 올릴 수 있습니다.');
    });

    /**
     * @scenario outcome=anonymous_unauthorized
     * @effects login_required_message_shown_without_redirect
     */
    it('토큰 없이 보낸 업로드의 401 은 「로그인이 필요합니다」 문구로 거부한다 (#883)', async () => {
        const anonymous401 = { response: { status: 401, data: { message: '인증이 필요합니다.' } }, g7Auth: { tokenSent: false } };
        apiPost.mockRejectedValue(anonymous401);
        (window as any).G7Core.api.isAnonymousUnauthorized = (e: any) => e === anonymous401;
        (window as any).G7Core.t = (key: string) => (key === 'sirsoft-ckeditor5.editor.upload_login_required'
            ? '이미지를 올리려면 로그인이 필요합니다. 작성 중인 내용은 그대로 있습니다.'
            : key);

        await expect(new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload())
            .rejects.toBe('이미지를 올리려면 로그인이 필요합니다. 작성 중인 내용은 그대로 있습니다.');
    });

    /**
     * @scenario outcome=rejected
     * @effects server_error_message_shown
     */
    it('토큰을 실은 401 은 종전대로 서버 문구를 넘긴다 (판정 메서드가 없는 옛 코어 포함)', async () => {
        apiPost.mockRejectedValue({ response: { status: 401, data: { message: '인증이 필요합니다.' } } });

        await expect(new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload()).rejects.toBe('인증이 필요합니다.');
    });

    /**
     * @scenario outcome=rejected
     * @effects server_error_message_shown
     */
    it('응답에 주소가 없으면 기본 실패 문구로 거부한다', async () => {
        apiPost.mockResolvedValue({});

        await expect(new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload()).rejects.toBe("Couldn't upload file: photo.png.");
    });

    /**
     * @scenario outcome=rejected
     * @effects server_error_message_shown
     */
    it('기본 실패 문구는 화면 언어로 번역해 파일 이름을 채운다', async () => {
        apiPost.mockResolvedValue({});
        (window as any).G7Core.t = (key: string) => (key === 'sirsoft-ckeditor5.editor.upload_failed' ? '파일을 올리지 못했습니다: {{name}}' : key);

        await expect(new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload()).rejects.toBe('파일을 올리지 못했습니다: photo.png');
    });

    /**
     * @scenario outcome=aborted
     * @effects upload_abortable
     */
    it('abort 하면 진행 중인 요청의 signal 이 중단된다', async () => {
        let captured: AbortSignal | undefined;
        apiPost.mockImplementation((_url: string, _body: FormData, config: any) => {
            captured = config.signal;

            return new Promise(() => undefined);
        });

        const adapter = new G7UploadAdapter(makeLoader(), UPLOAD_URL);
        void adapter.upload();
        await Promise.resolve();
        await Promise.resolve();
        adapter.abort();

        expect(captured?.aborted).toBe(true);
    });

    /**
     * 공개 자산 디스크(CDN) 행 — 서버가 반응형 후보를 내리면 CKEditor 가 본문에 srcset 으로 저장하도록 그대로 돌려준다(#878)
     *
     * @scenario outcome=uploaded
     * @effects responsive_urls_returned_to_editor
     */
    it('응답에 urls 가 있으면 { default, 가로 후보 } 를 돌려준다', async () => {
        apiPost.mockResolvedValue({
            url: 'https://cdn.test/assets/sirsoft-ckeditor5/images/2026/11/12/a.jpg',
            urls: {
                '640': '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc?size=md',
                '1280': '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc?size=lg',
                default: 'https://cdn.test/assets/sirsoft-ckeditor5/images/2026/11/12/a.jpg',
            },
        });

        const result = await new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload();

        expect(result).toEqual({
            default: 'https://cdn.test/assets/sirsoft-ckeditor5/images/2026/11/12/a.jpg',
            '640': '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc?size=md',
            '1280': '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc?size=lg',
        });
    });

    /**
     * 직접 URL 이 없는 행(로컬 디스크)은 서버가 urls 를 싣지 않는다 — 종전 { default } 그대로
     *
     * @scenario outcome=uploaded
     * @effects responsive_urls_returned_to_editor
     */
    it('urls 가 없으면 종전처럼 { default } 만 돌려준다', async () => {
        apiPost.mockResolvedValue({ url: '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc' });

        const result = await new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload();

        expect(result).toEqual({ default: '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc' });
    });

    /**
     * @scenario outcome=uploaded
     * @effects responsive_urls_returned_to_editor
     */
    it('urls 의 비문자열·빈 값·숫자 아닌 키는 버리고, default 가 없거나 후보가 없으면 쓰지 않는다', async () => {
        expect(pickResponsiveUrls({ default: '/a.jpg', '640': 123, '1280': '', foo: '/x', '0640': '/y', '960': '/b?size=md' }))
            .toEqual({ default: '/a.jpg', '960': '/b?size=md' });
        expect(pickResponsiveUrls({ '640': '/b?size=md' })).toBeNull();
        expect(pickResponsiveUrls({ default: '', '640': '/b?size=md' })).toBeNull();
        expect(pickResponsiveUrls({ default: '/a.jpg' })).toBeNull();
        expect(pickResponsiveUrls(['/a.jpg'])).toBeNull();
        expect(pickResponsiveUrls(null)).toBeNull();

        // urls 가 쓸 수 없는 모양이면 최상위 url 로 돌아간다
        apiPost.mockResolvedValue({ url: '/storage/a.jpg', urls: { default: '/storage/a.jpg', '640': 1 } });
        const result = await new G7UploadAdapter(makeLoader(), UPLOAD_URL).upload();
        expect(result).toEqual({ default: '/storage/a.jpg' });
    });

    /**
     * @scenario outcome=uploaded
     * @effects upload_sent_through_core_api_client
     */
    it('편집기 플러그인은 FileRepository 의 어댑터 생성기를 이 어댑터로 바꾼다', () => {
        const fileRepository: Record<string, any> = {};
        const editor = { plugins: { get: vi.fn(() => fileRepository) } };

        createG7UploadAdapterPlugin(UPLOAD_URL)(editor);

        expect(editor.plugins.get).toHaveBeenCalledWith('FileRepository');
        expect(fileRepository.createUploadAdapter(makeLoader())).toBeInstanceOf(G7UploadAdapter);
    });
});
