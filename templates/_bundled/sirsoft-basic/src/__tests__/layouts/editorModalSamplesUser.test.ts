/**
 * @file editorModalSamplesUser.test.ts
 * @description 레이아웃 편집기에서 쇼핑 사용자 창이 표본 상태로 채워져 열리는지
 *
 * 편집기 모달 목록의 쇼핑 사용자 창 37개를 하나씩 열어 보니(2026-10-07) 몇 창이 표본 없이 열렸다 —
 * 「{{number}} 신청을 철회하시겠습니까?」(신청 철회 확인 5곳), 빈 「첨부 사진 보기」·「배송지 변경」,
 * 깨진 상품 그림(구매 확정·리뷰 작성·상품 문의 작성). 운영 화면에서는 앞선 동작(목록 행의 버튼)이
 * 상태를 담아 주므로 정상이지만, 편집기는 `editor-spec/states.json` 의 창 표본이 그 자리를 채워야 한다.
 * 자동 생성 표본이 남긴 「샘플 값」 글자가 그림 주소·선택값 자리에 들어가면 깨진 그림·빈 본문이 된다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const TEMPLATE_ROOT = resolve(__dirname, '../../..');

type Json = Record<string, any>;

const states: Json = JSON.parse(readFileSync(join(TEMPLATE_ROOT, 'editor-spec/states.json'), 'utf8'));

/**
 * 창 id 의 기본 표본 상태(local·global)를 찾습니다.
 *
 * @param modalId 창 id
 * @return 기본 표본 상태(없으면 null)
 */
function sampleFor(modalId: string): Json | null {
    const found: Json[] = [];
    const walk = (node: any): void => {
        if (Array.isArray(node)) {
            node.forEach(walk);

            return;
        }
        if (node && typeof node === 'object') {
            if (node.scope?.kind === 'modal' && node.scope?.match === modalId && Array.isArray(node.items)) {
                found.push(node);
            }
            Object.values(node).forEach(walk);
        }
    };
    walk(states);
    const items = found.flatMap((g) => g.items);
    const item = items.find((i: Json) => i.default) ?? items[0];

    return item?.initialState ?? null;
}

/**
 * 그림으로 쓸 수 있는 주소인지 판정합니다(편집기 표본 그림 = data URI 또는 절대·루트 경로).
 *
 * @param value 값
 * @return 그림 주소이면 true
 */
function isImageUrl(value: unknown): boolean {
    return typeof value === 'string' && /^(data:image\/|https?:\/\/|\/)/.test(value);
}

describe('편집기 쇼핑 사용자 창 표본', () => {
    /**
     * @scenario case=c01
     * @effects user_modal_samples_render
     */
    it('신청 철회 확인 창 표본이 신청번호를 채운다(자리 표시자 노출 방지)', () => {
        const s = sampleFor('modal_claim_withdraw');
        expect(typeof s?.local?.withdrawClaimNumber).toBe('string');
        expect(String(s?.local?.withdrawClaimNumber).trim()).not.toBe('');
    });

    /**
     * @scenario case=c02
     * @effects user_modal_samples_render
     */
    it('첨부 사진 보기 창 표본이 그림 주소를 채운다', () => {
        expect(isImageUrl(sampleFor('modal_claim_image_viewer')?.local?.claimImageViewerUrl)).toBe(true);
    });

    /**
     * @scenario case=c03
     * @effects user_modal_samples_render
     */
    it('구매 확정·리뷰 작성 창 표본이 대상 상품 그림·이름을 채운다', () => {
        const confirm = sampleFor('modal_confirm_purchase')?.local?.confirmTarget;
        const review = sampleFor('modal_write_review')?.local?.reviewTarget;
        expect(isImageUrl(confirm?.thumbnail_url)).toBe(true);
        expect(String(confirm?.product_name ?? '').trim()).not.toBe('');
        expect(isImageUrl(review?.thumbnail_url)).toBe(true);
        expect(String(review?.product_name ?? '').trim()).not.toBe('');
    });

    /**
     * @scenario case=c04
     * @effects user_modal_samples_render
     */
    it('상품 문의 작성 창 표본의 상품 그림이 그림 주소다(「샘플 값」 글자 아님)', () => {
        expect(isImageUrl(sampleFor('qna_write_modal')?.global?.qnaProductImage)).toBe(true);
    });

    /**
     * @scenario case=c05
     * @effects user_modal_samples_render
     */
    it('배송지 변경 창 표본의 탭 값이 화면이 아는 값(saved·manual)이라 본문이 열린다', () => {
        const g = sampleFor('changeAddressModal')?.global;
        expect(['saved', 'manual']).toContain(g?.changeAddressMode);
        if (g?.changeAddressMode === 'manual') {
            expect(typeof g?.editingShippingAddress).toBe('object');
        }
    });
});
