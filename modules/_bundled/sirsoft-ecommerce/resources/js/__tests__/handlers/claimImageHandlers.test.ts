/**
 * claimImageHandlers 테스트 (#599 S11)
 *
 * @description
 * 두 축만 고정한다:
 *  - **상한은 운영자 설정이 정한다.** 화면·핸들러가 리터럴을 들고 있으면 운영자가 값을 바꿨을
 *    때 안내 문구와 실제 거절선이 갈린다.
 *  - **File prototype 방어.** `_local` 상태를 거치면 File 의 prototype 이 소실될 수 있어
 *    `instanceof File` 만 보면 유효한 파일이 조용히 버려진다(리뷰 작성 핸들러와 같은 방어).
 *
 * @scenario exchange_mode=same, preview_result=ok
 * @effects claim_image_limits_come_from_operator_settings,
 *   claim_image_extract_survives_lost_file_prototype,
 *   claim_image_upload_defers_to_submit_and_keeps_uploaded_on_retry
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    claimRequestConfig,
    extractClaimFiles,
    filterClaimFilesByLimits,
    removeClaimImageHandler,
    setClaimImageFilesHandler,
    uploadPendingClaimFiles,
} from '../../handlers/claimImageHandlers';

let mockLocalState: Record<string, any> = {};
let mockGlobalState: Record<string, any> = {};
let mockDataSources: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn((key: string) => (key === '_global' ? mockGlobalState : undefined)),
    },
    dataSource: {
        get: vi.fn((id: string) => mockDataSources[id] ?? null),
    },
    api: { post: vi.fn(), delete: vi.fn() },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

/**
 * 지정한 크기의 더미 파일을 만듭니다.
 *
 * @param name 파일명
 * @param bytes 바이트 수
 * @returns File
 */
function makeFile(name: string, bytes: number): File {
    return new File([new Uint8Array(bytes)], name, { type: 'image/png' });
}

const API_BASE = '/api/modules/sirsoft-ecommerce/user/orders/120';

describe('claimImageHandlers', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = { claimImages: [], claimImageFiles: [] };
        mockGlobalState = {};
        mockDataSources = {
            claimable: { data: { settings: { max_images: 2, image_max_size_kb: 1 } } },
        };
        (window as any).G7Core = mockG7Core;
    });

    /**
     * @scenario attachment_lifecycle=queue_on_select
     *
     * @effects claim_attachment_selection_only_queues
     */
    it('File prototype 이 소실돼도 파일을 꺼낸다', () => {
        const real = makeFile('a.png', 10);
        const wrapped = { file: real };
        const lostPrototype = { file: { name: 'c.png', size: 12 } };

        expect(extractClaimFiles([real, wrapped, lostPrototype, null, undefined])).toHaveLength(3);
    });

    /**
     * @scenario attachment_limit=size_exceeded
     *
     * @effects claim_attachment_filter_flags_size_exceeded
     */
    it('상한 판정은 운영자 설정(max_images / image_max_size_kb)을 따른다', () => {
        const small = makeFile('a.png', 100);
        const large = makeFile('b.png', 4096);

        const result = filterClaimFilesByLimits([small, large], 0, { max_images: 2, image_max_size_kb: 1 });

        expect(result.sizeExceeded).toBe(true);
        expect(result.accepted).toHaveLength(1);
        expect(result.countExceeded).toBe(false);
    });

    /**
     * @scenario attachment_limit=count_exceeded
     *
     * @effects claim_attachment_filter_flags_count_exceeded
     */
    it('이미 올라간 장수를 합쳐 상한을 넘으면 넘는 만큼만 자른다', () => {
        const files = [makeFile('a.png', 10), makeFile('b.png', 10)];

        const result = filterClaimFilesByLimits(files, 1, { max_images: 2, image_max_size_kb: 1 });

        expect(result.countExceeded).toBe(true);
        expect(result.accepted).toHaveLength(1);
    });

    /**
     * 상한 안쪽이면 아무것도 걸러지지 않고 플래그도 서지 않는다.
     *
     * 이 판정이 정확한 것이 S2-D10 의 근거다 — 「신호만 연결하면 안내가 복구된다」는
     * 이 함수가 옳다는 전제 위에 선다. 실측(주문 754)에서는 이 판정까지 도달하지 못했다:
     * `FileUploader` 컴포지트가 상한 초과분을 **먼저 걸러 내** change 페이로드에도
     * DOM `input.files` 에도 남기지 않았고, 그래서 핸들러가 아예 호출되지 않았다.
     *
     * @scenario attachment_limit=within_limits
     *
     * @effects claim_attachment_filter_accepts_within_limits
     */
    it('상한 안쪽이면 전부 통과하고 거절 플래그가 서지 않는다', () => {
        const files = [makeFile('a.png', 100), makeFile('b.png', 200)];

        const result = filterClaimFilesByLimits(files, 0, { max_images: 5, image_max_size_kb: 2048 });

        expect(result.accepted).toHaveLength(2);
        expect(result.countExceeded).toBe(false);
        expect(result.sizeExceeded).toBe(false);
    });

    /**
     * @scenario attachment_lifecycle=queue_on_select
     *
     * @effects claim_attachment_selection_only_queues
     */
    it('선택 변경은 업로드하지 않고 대기열에만 담는다 (제출 직전 업로드)', () => {
        const files = [makeFile('a.png', 10)];

        setClaimImageFilesHandler({ handler: 'setClaimImageFiles', params: { files } }, {} as any);

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockLocalState.claimImageFiles).toEqual(files);
    });

    /**
     * @scenario attachment_lifecycle=queue_on_select
     *
     * @effects claim_attachment_selection_only_queues
     */
    it('선택 변경 — 상한 초과는 그 자리에서 알린다', () => {
        setClaimImageFilesHandler(
            { handler: 'setClaimImageFiles', params: { files: [makeFile('a.png', 4096)] } },
            {} as any
        );

        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });

    /**
     * @scenario attachment_lifecycle=upload_before_submit
     *
     * @effects claim_attachment_upload_happens_once_before_submit
     */
    it('제출 직전 업로드 — 성공분만 누적하고 대기열을 비운다', async () => {
        mockLocalState.claimImageFiles = [makeFile('a.png', 10)];
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { id: 9, original_name: 'a.png' } });

        const ids = await uploadPendingClaimFiles(mockG7Core, { claimApiBase: API_BASE, isGuest: false });

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            API_BASE + '/claims/images',
            expect.any(FormData),
            expect.objectContaining({ headers: expect.objectContaining({ 'Content-Type': 'multipart/form-data' }) })
        );
        expect(ids).toEqual([9]);
        expect(mockLocalState.claimImageFiles).toEqual([]);
        expect(mockLocalState.claimImages).toHaveLength(1);
    });

    /**
     * @scenario attachment_lifecycle=upload_before_submit
     *
     * @effects claim_attachment_upload_happens_once_before_submit
     */
    it('제출 직전 업로드 — 올릴 파일이 없으면 이미 올라간 첨부 ID 만 돌려준다 (재시도에서 재업로드 금지)', async () => {
        mockLocalState.claimImages = [{ id: 9 }, { id: 10 }];
        mockLocalState.claimImageFiles = [];

        const ids = await uploadPendingClaimFiles(mockG7Core, { claimApiBase: API_BASE, isGuest: false });

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(ids).toEqual([9, 10]);
    });

    /**
     * @scenario attachment_lifecycle=guest_token_header
     *
     * @effects claim_attachment_guest_requests_carry_the_order_token
     */
    it('비회원 — 셸이 선언했을 때만 주문 토큰 헤더를 붙인다', () => {
        mockGlobalState = { guestOrderToken: 'guest-token-abc' };

        expect(claimRequestConfig(false)).toBeUndefined();
        expect(claimRequestConfig(true)).toEqual({ headers: { 'X-Guest-Order-Token': 'guest-token-abc' } });
    });

    /**
     * @scenario attachment_lifecycle=delete
     *
     * @effects claim_attachment_delete_updates_the_list_only_on_success
     */
    it('첨부 삭제 — 성공하면 목록에서 뺀다', async () => {
        mockLocalState.claimImages = [{ id: 9 }, { id: 10 }];
        mockG7Core.api.delete.mockResolvedValue({ success: true });

        await removeClaimImageHandler(
            {
                handler: 'removeClaimImage',
                params: { imageId: 9, imageDeleteBase: '/api/modules/sirsoft-ecommerce/user/claims/images', isGuest: false },
            },
            {} as any
        );

        expect(mockG7Core.api.delete).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/claims/images/9',
            undefined
        );
        expect(mockLocalState.claimImages).toEqual([{ id: 10 }]);
    });

    /**
     * @scenario attachment_lifecycle=delete
     *
     * @effects claim_attachment_delete_updates_the_list_only_on_success
     */
    it('첨부 삭제 — 실패하면 목록을 건드리지 않는다', async () => {
        mockLocalState.claimImages = [{ id: 9 }];
        mockG7Core.api.delete.mockRejectedValue({ response: { data: { message: '삭제 실패' } } });

        await removeClaimImageHandler(
            {
                handler: 'removeClaimImage',
                params: { imageId: 9, imageDeleteBase: '/api/modules/sirsoft-ecommerce/user/claims/images' },
            },
            {} as any
        );

        expect(mockG7Core.toast.error).toHaveBeenCalledWith('삭제 실패');
        expect(mockLocalState.claimImages).toEqual([{ id: 9 }]);
    });
});
