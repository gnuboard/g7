// e2e:allow 굽힌 레이아웃 JSON 의 미리보기 주소 식만 평가한다 — 실제 목록의 썸네일 요청은 계획 §8 Chrome MCP 실측으로 확인한다.
/**
 * @file uploadsThumbnailPreviewUrl.test.tsx
 * @description 업로드 관리 목록의 그림 칸은 썸네일 기준 주소(`preview_url`, 항상 API)에 sm 규격을 건다
 *
 * 공개 자산 디스크(CDN) 행의 `download_url` 은 직접 주소라 thumbPreset 이 걸리지 않아 행마다 원본이 내려간다.
 * 서버가 함께 내리는 `preview_url` 을 먼저 쓰고, 그 필드가 없는 예전 응답은 `download_url` 로 돌아간다.
 *
 * @scenario-manifest tests/scenarios/public-asset-cdn.yaml
 */

import { describe, it, expect } from 'vitest';

import uploads from '../../../layouts/admin/ckeditor5_uploads.json';

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

/**
 * `{{ ... }}` 표현식을 주어진 문맥으로 평가한다.
 *
 * @param raw 표현식
 * @param scope 문맥
 * @returns 평가 결과
 */
function evaluate(raw: string, scope: Record<string, unknown>): unknown {
    const expression = raw.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(scope), `return (${expression});`)(...Object.values(scope));
}

const thumbnailCell = (): Node => {
    const images = collect(uploads, (n) => n.name === 'Img' && n.props?.thumbPreset === 'sm');
    expect(images).toHaveLength(1);

    return images[0];
};

describe('업로드 목록 그림 칸 — preview_url 우선', () => {
    /**
     * @scenario consumer=editor, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     * @effects preview_url_is_always_api_serving_url
     */
    it('CDN 행은 직접 주소가 아니라 API 미리보기 주소에 sm 규격을 건다', () => {
        const node = thumbnailCell();
        const row = {
            download_url: 'https://cdn.test/assets/sirsoft-ckeditor5/images/2026/11/12/a.jpg',
            preview_url: '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc',
        };

        expect(evaluate(node.props.src, { row })).toBe('/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc');
        expect(node.props.thumbPreset).toBe('sm');
    });

    /**
     * @scenario consumer=editor, disk_setting=none, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=legacy_local_row
     * @effects preview_url_is_always_api_serving_url
     */
    it('preview_url 이 없는 예전 응답은 download_url 로 돌아간다', () => {
        const node = thumbnailCell();
        const row = { download_url: '/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc' };

        expect(evaluate(node.props.src, { row })).toBe('/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc');
    });
});
