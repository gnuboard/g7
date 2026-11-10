/**
 * @file thumbnail-layouts.test.ts
 * @description 업로드 목록과 본문 표시 확장이 코어 이미지 썸네일에 맞게 값을 넘기는지
 *
 * - 업로드 목록의 그림 칸은 64px 고정이라 sm 규격을 받는다. 빠지면 행마다 원본이 내려간다.
 * - 본문 표시 확장은 코어 HtmlContent 자리를 대체하므로 호출자가 준 `imageSizes` 를 그대로 넘겨야 한다. 빠지면 호출자의 값이
 *   이 플러그인을 켠 사이트에서만 조용히 버려진다.
 *
 * 굽힌 레이아웃 JSON 으로 판정한다.
 *
 * @scenario-manifest tests/scenarios/image-serve-thumbnail.yaml
 */

import { describe, it, expect } from 'vitest';

import uploads from '../../../layouts/admin/ckeditor5_uploads.json';
import htmlContent from '../../../extensions/html-content.json';

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

describe('본문 이미지 썸네일 레이아웃', () => {
    /**
     * @scenario thumbnail_query=layout_binding
     * @effects ckeditor_uploads_list_uses_sm_preset
     */
    it('업로드 목록 이미지는 sm 규격이다', () => {
        const images = collect(uploads, (n) => n.name === 'Img' && n.props?.src === '{{row.download_url}}');

        expect(images).toHaveLength(1);
        expect(images[0].props.thumbPreset).toBe('sm');
    });

    /**
     * @scenario thumbnail_query=layout_binding
     * @effects ckeditor_html_content_forwards_image_sizes
     */
    it('본문 표시 확장은 호출자가 준 imageSizes 를 HtmlContent 에 넘긴다', () => {
        const [node] = collect(htmlContent, (n) => n.name === 'HtmlContent');

        expect(evaluate(node.props.imageSizes, { extensionPointProps: { imageSizes: '100vw' } })).toBe('100vw');
        expect(evaluate(node.props.imageSizes, { extensionPointProps: {} })).toBeUndefined();
    });
});
