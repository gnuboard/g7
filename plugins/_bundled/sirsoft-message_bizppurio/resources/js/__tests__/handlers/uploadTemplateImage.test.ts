// e2e:allow 업로드 in-flight 경합·모달 재시딩은 브라우저에서 재현하려면 네트워크 지연을
// 인위적으로 만들어야 한다. 브라우저가 담당하는 축(업로드 성공 → URL 기입 → 저장)은
// tests/Playwright/specs/admin/template-lifecycle.spec.ts 가 맡고, 여기서는 경합 자체를 고정한다.
/**
 * uploadTemplateImage 핸들러 — in-flight 경합 가드 (#597 라운드 5 R2·R3)
 *
 * 라운드 5 이전에는 다음 두 경로가 열려 있었다:
 *  - 업로드가 진행 중인데 파일을 다시 고르면 두 요청이 겹치고, 먼저 끝난 쪽이
 *    `uploading:false` 를 써서 저장 버튼 잠금이 풀린다. 나중에 끝나는 업로드는
 *    저장 이후에 templateImageUrl 을 덮는다.
 *  - 업로드 중 모달을 닫고 다른 알림의 모달을 열면(취소는 의도적으로 잠기지 않는다),
 *    먼저 시작한 업로드의 결과가 새 모달의 폼에 기입된다. 실패 경로에서는 새 모달이
 *    방금 시딩한 이미지 값이 지워진다.
 *
 * 둘 다 예외도 경고도 남기지 않으므로, 여기서 고정하지 않으면 되돌려도 red 가 나지 않는다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { uploadTemplateImageHandler } from '../../handlers/uploadTemplateImage';

/** 전역 상태를 흉내내는 최소 스토어 (dot 경로 setState + getGlobal) */
function makeStore(initial: Record<string, any> = {}) {
    const state: Record<string, any> = { bz_tpl_modal: { content: {} }, bz_tpl_upload: {}, ...initial };

    const setGlobal = vi.fn((updates: Record<string, unknown>) => {
        for (const [path, value] of Object.entries(updates)) {
            const keys = path.split('.');
            let cur: any = state;
            for (const k of keys.slice(0, -1)) {
                if (cur[k] == null || typeof cur[k] !== 'object') cur[k] = {};
                cur = cur[k];
            }
            cur[keys[keys.length - 1]] = value;
        }
    });

    return { state, setGlobal, getGlobal: () => state };
}

/** change 이벤트 컨텍스트(파일 1개 선택) */
function fileContext(name = 'a.png') {
    const input = { files: [new File(['x'], name, { type: 'image/png' })], value: name } as unknown as HTMLInputElement;

    return { context: { event: { target: input } as unknown as Event }, input };
}

const ACTION = {
    handler: 'sirsoft-message_bizppurio.uploadTemplateImage',
    params: {
        stateTarget: 'global',
        statePathUrl: 'bz_tpl_modal.content.templateImageUrl',
        statePathName: 'bz_tpl_modal.content.templateImageName',
        statePathStatus: 'bz_tpl_upload',
    },
} as any;

let store: ReturnType<typeof makeStore>;
let apiPost: ReturnType<typeof vi.fn>;

/** 서버가 4xx·5xx 로 답한 것처럼 API 클라이언트 예외를 만든다 */
function httpError(status: number, data: Record<string, unknown>) {
    return Object.assign(new Error(`HTTP ${status}`), { response: { status, data } });
}

beforeEach(() => {
    store = makeStore();
    apiPost = vi.fn();
    (window as any).G7Core = {
        state: { setGlobal: store.setGlobal, getGlobal: store.getGlobal },
        api: { post: apiPost },
        t: (k: string) => k,
    };
    localStorage.setItem('auth_token', 'tkn');
});

afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
});

describe('uploadTemplateImage — in-flight 경합 가드', () => {
    /**
     * @scenario content_variant=ad_image
     * @effects upload_rejects_concurrent_selection_while_in_flight
     */
    it('업로드가 진행 중이면 두 번째 파일 선택을 무시한다(요청 1회)', async () => {
        let release: (v: any) => void = () => {};
        apiPost.mockImplementation(() => new Promise((r) => { release = r; }));

        const first = uploadTemplateImageHandler(ACTION, fileContext('one.png').context as any);
        // 첫 요청이 아직 열려 있는 상태에서 두 번째 선택
        await uploadTemplateImageHandler(ACTION, fileContext('two.png').context as any);

        expect(apiPost, '겹친 업로드가 나가면 먼저 끝난 쪽이 저장 잠금을 풀어 버린다').toHaveBeenCalledTimes(1);

        release({ success: true, data: { url: 'https://k/1.png' } });
        await first;

        expect(store.state.bz_tpl_modal.content.templateImageUrl).toBe('https://k/1.png');
        expect(store.state.bz_tpl_upload.uploading).toBe(false);
    });

    /**
     * @scenario content_variant=ad_image
     * @effects upload_result_discarded_when_modal_reseeded
     */
    it('응답 도착 전 모달이 다시 시딩되면 성공 결과를 폼에 쓰지 않는다', async () => {
        let release: (v: any) => void = () => {};
        apiPost.mockImplementation(() => new Promise((r) => { release = r; }));

        const pending = uploadTemplateImageHandler(ACTION, fileContext('one.png').context as any);
        expect(store.state.bz_tpl_upload.uploading).toBe(true);

        // 다른 알림의 모달을 여는 지점이 하는 일: 상태 리시드
        store.setGlobal({ 'bz_tpl_upload': { uploading: false, error: null } });
        store.setGlobal({ 'bz_tpl_modal.content.templateImageUrl': 'https://k/keep.png' });

        release({ success: true, data: { url: 'https://k/stale.png' } });
        await pending;

        expect(store.state.bz_tpl_modal.content.templateImageUrl,
            '이전 모달의 업로드 결과가 지금 열린 모달의 폼을 덮으면 안 된다').toBe('https://k/keep.png');
    });

    /**
     * @scenario content_variant=ad_image
     * @effects upload_result_discarded_when_modal_reseeded
     */
    it('응답 도착 전 모달이 다시 시딩되면 실패 결과도 새 모달의 값을 지우지 않는다', async () => {
        let reject: (e: any) => void = () => {};
        apiPost.mockImplementation(() => new Promise((_r, j) => { reject = j; }));

        const pending = uploadTemplateImageHandler(ACTION, fileContext('one.png').context as any);

        store.setGlobal({ 'bz_tpl_upload': { uploading: false, error: null } });
        store.setGlobal({ 'bz_tpl_modal.content.templateImageUrl': 'https://k/keep.png' });

        reject(httpError(422, { success: false, message: '실패' }));
        await pending;

        expect(store.state.bz_tpl_modal.content.templateImageUrl).toBe('https://k/keep.png');
        expect(store.state.bz_tpl_upload.error, '새 모달에 남의 실패 배너가 뜨면 안 된다').toBeNull();
    });

    /**
     * @scenario content_variant=ad_image
     * @effects upload_rejects_concurrent_selection_while_in_flight
     */
    it('업로드가 끝나면 다음 선택을 다시 받는다(가드가 영구 잠기지 않는다)', async () => {
        apiPost.mockResolvedValue({ success: true, data: { url: 'https://k/1.png' } });

        await uploadTemplateImageHandler(ACTION, fileContext('one.png').context as any);
        await uploadTemplateImageHandler(ACTION, fileContext('two.png').context as any);

        expect(apiPost).toHaveBeenCalledTimes(2);
    });

    /**
     * @scenario content_variant=ad_image
     * @effects image_upload_failure_clears_image_value
     */
    it('성공 응답인데 url 이 비면 실패로 처리하고 이미지 값을 비운다', async () => {
        store.setGlobal({ 'bz_tpl_modal.content.templateImageUrl': 'https://k/old.png' });
        apiPost.mockResolvedValue({ success: true, data: { url: '   ' } });

        await uploadTemplateImageHandler(ACTION, fileContext().context as any);

        expect(store.state.bz_tpl_modal.content.templateImageUrl).toBe('');
        expect(store.state.bz_tpl_upload.error).toBeTruthy();
        expect(store.state.bz_tpl_upload.uploading).toBe(false);
    });
});

/**
 * 요청 경로 (#639) — 코어 클라이언트를 우회해 로그인 토큰을 직접 읽고 Bearer 를 붙이던 raw fetch 를
 * `G7Core.api` 로 옮겼다. Bearer 부착·401 처리·운영자 로케일 헤더는 코어가 맡는다.
 */
describe('uploadTemplateImage — 코어 API 클라이언트 경유', () => {
    /**
     * @scenario content_variant=ad_image
     * @effects image_upload_sent_through_core_api_client
     */
    it('파일을 image 필드에 담아 G7Core.api 로 보내고 로그인 토큰·fetch 를 쓰지 않는다', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const getItem = vi.spyOn(Storage.prototype, 'getItem');
        apiPost.mockResolvedValue({ success: true, data: { url: 'https://k/ok.png' } });

        await uploadTemplateImageHandler(ACTION, fileContext('ok.png').context as any);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(apiPost).toHaveBeenCalledTimes(1);
        const [url, body, config] = apiPost.mock.calls[0];
        expect(url).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates/image');
        expect((body as FormData).get('image')).toBeInstanceOf(File);
        expect(config.headers).not.toHaveProperty('Authorization');
        expect(getItem.mock.calls.map(([key]) => key)).not.toContain('auth_token');
        expect(store.state.bz_tpl_modal.content.templateImageUrl).toBe('https://k/ok.png');
    });

    /**
     * @scenario content_variant=ad_image
     * @effects image_upload_sent_through_core_api_client, image_upload_failure_clears_image_value
     */
    it('서버가 거절하면(4xx) 응답의 사유를 그대로 배너에 싣고 이미지 값을 비운다', async () => {
        store.setGlobal({ 'bz_tpl_modal.content.templateImageUrl': 'https://k/old.png' });
        apiPost.mockRejectedValue(httpError(422, {
            success: false,
            message: '검증 실패',
            errors: { bizppurio_message: '이미지 가로는 500px 이상이어야 합니다.' },
        }));

        await uploadTemplateImageHandler(ACTION, fileContext().context as any);

        expect(store.state.bz_tpl_upload.error).toBe('이미지 가로는 500px 이상이어야 합니다.');
        expect(store.state.bz_tpl_modal.content.templateImageUrl).toBe('');
        expect(store.state.bz_tpl_upload.uploading).toBe(false);
    });

    /**
     * @scenario content_variant=ad_image
     * @effects image_upload_failure_clears_image_value
     */
    it('응답이 없는 실패(네트워크)는 예외 원문 대신 번역 문구를 싣는다', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        apiPost.mockRejectedValue(new TypeError('Failed to fetch'));

        await uploadTemplateImageHandler(ACTION, fileContext().context as any);

        // 모의 번역기는 키를 그대로 돌려주므로 영문 폴백이 쓰인다
        expect(store.state.bz_tpl_upload.error).toBe('Failed to upload the image.');
        expect(store.state.bz_tpl_upload.uploading).toBe(false);
    });
});
