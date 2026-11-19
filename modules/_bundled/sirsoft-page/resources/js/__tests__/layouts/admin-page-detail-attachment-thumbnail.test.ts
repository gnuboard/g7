/**
 * @file admin-page-detail-attachment-thumbnail.test.ts
 * @description 관리자 페이지 상세 첨부 카드의 이미지가 썸네일 규격을 받는지
 *
 * 카드의 그림 칸은 48px 고정(`object-cover`)이다. 규격이 빠지면 원본이 카드마다 내려가고 오류도 나지 않는다.
 * 굽힌 레이아웃 JSON 으로 판정한다.
 *
 * @scenario-manifest tests/scenarios/page-attachment-thumbnail.yaml
 */

import { describe, it, expect } from 'vitest';

import adminPageDetail from '../../../layouts/admin/admin_page_detail.json';

type Node = Record<string, any>;

/**
 * 트리에서 조건에 맞는 노드를 모은다.
 *
 * @param node 탐색 대상
 * @param predicate 조건
 * @param found 결과 누적
 * @returns 찾은 노드
 */
function collect(node: unknown, predicate: (n: Node) => boolean, found: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((item) => collect(item, predicate, found));
    } else if (node && typeof node === 'object') {
        if (predicate(node as Node)) {
            found.push(node as Node);
        }
        Object.values(node).forEach((value) => collect(value, predicate, found));
    }

    return found;
}

describe('관리자 페이지 상세 첨부 썸네일', () => {
    /**
     * @scenario case=admin_detail_preset
     * @effects admin_detail_attachment_uses_sm_preset
     */
    it('첨부 미리보기 이미지는 sm 규격이고 첨부 미리보기 주소를 그대로 받는다', () => {
        const images = collect(adminPageDetail, (n) => n.name === 'Img' && String(n.props?.src ?? '').includes('attachment_item?.preview_url'));

        expect(images).toHaveLength(1);
        expect(images[0].props.thumbPreset).toBe('sm');
        expect(images[0].props.src).toBe('{{attachment_item?.preview_url}}');
    });
});
