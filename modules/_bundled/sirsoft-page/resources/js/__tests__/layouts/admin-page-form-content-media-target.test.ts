/**
 * 관리자 페이지 작성 화면 — 편집기 본문 미디어 업로드 계약을 표준 계약 엔드포인트에서 받는다 (#887 G10)
 *
 * 계약을 전하는 길을 확장마다 `GET …/content-media/target` 하나로 맞췄다. 페이지 폼은 데이터 소스 `contentMediaTarget` 으로
 * 받아 `html_editor` 의 `mediaUpload` 에 넘기고, 폼 임시 키를 `fields.temp_key` 에 더한다(옛 첨부 설정 응답의 `content_media` 는
 * 서버가 호환으로 계속 싣지만 화면은 더 읽지 않는다). 받는 종류가 없거나 권한이 없으면 null — 편집기는 종전대로 동작한다.
 *
 * @scenario-manifest tests/scenarios/page-content-media-target.yaml
 */

import { describe, it, expect } from 'vitest';

import adminPageForm from '../../../layouts/admin/admin_page_form.json';
import koRoot from '../../../lang/ko.json';
import enRoot from '../../../lang/en.json';

type AnyNode = Record<string, any>;

function findById(node: AnyNode | null, id: string): AnyNode | null {
    if (!node) return null;
    if (node.id === id) return node;
    for (const child of node.children ?? []) {
        const found = findById(child, id);
        if (found) return found;
    }
    for (const slotChildren of Object.values(node.slots ?? {})) {
        if (Array.isArray(slotChildren)) {
            for (const child of slotChildren) {
                const found = findById(child as AnyNode, id);
                if (found) return found;
            }
        }
    }
    return null;
}

/**
 * 레이아웃의 `{{ }}` 식을 주어진 문맥으로 평가한다(엔진과 같은 JS 식 의미).
 */
function evaluate(expr: string, ctx: Record<string, unknown>): unknown {
    const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(ctx);
    // eslint-disable-next-line no-new-func
    return new Function(...names, `return (${body});`)(...names.map((n) => ctx[n]));
}

const form = adminPageForm as AnyNode;

describe('관리자 페이지 작성 화면 — 본문 미디어 업로드 계약 데이터 소스', () => {
    /**
     * @scenario permission=create
     * @effects form_reads_target_data_source
     */
    it('계약은 표준 계약 엔드포인트 데이터 소스에서 받는다 — 실패하면 null', () => {
        const ds = (form.data_sources as AnyNode[]).find((d) => d.id === 'contentMediaTarget');
        expect(ds?.endpoint).toBe('/api/modules/sirsoft-page/admin/content-media/target');
        expect(ds?.method).toBe('GET');
        expect(ds?.auth_required).toBe(true);
        expect(ds?.fallback).toEqual({ data: null });
        expect((koRoot as AnyNode).editor.data_source.contentMediaTarget).toBeTruthy();
        expect((enRoot as AnyNode).editor.data_source.contentMediaTarget).toBeTruthy();
    });

    /**
     * @scenario permission=create
     * @effects form_reads_target_data_source
     */
    it('편집기 mediaUpload 는 계약에 폼 임시 키를 더하고, 계약이 없으면 null 이다', () => {
        const editor = findById(form, 'content_editor');
        const expr: string = editor?.props?.mediaUpload ?? '';
        expect(expr).not.toContain('attachmentSettings');

        const contract = { version: 1, endpoint: '/api/modules/sirsoft-page/admin/content-media', fields: { extra: 'x' } };
        const resolved = evaluate(expr, { contentMediaTarget: { data: contract }, _local: { tempKey: 'tk-1' } }) as AnyNode;
        expect(resolved.endpoint).toBe(contract.endpoint);
        expect(resolved.fields).toEqual({ extra: 'x', temp_key: 'tk-1' });
        expect(contract.fields).toEqual({ extra: 'x' });

        expect(evaluate(expr, { contentMediaTarget: { data: null }, _local: { tempKey: 'tk-1' } })).toBeNull();
        expect(evaluate(expr, { contentMediaTarget: null, _local: { tempKey: 'tk-1' } })).toBeNull();
    });
});
