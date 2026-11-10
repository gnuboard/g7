/**
 * @file admin-board-post-form-content-media-target.test.ts
 * @description 관리자 글쓰기 — 편집기 업로드 계약을 표준 계약 엔드포인트에서 받는다 (#887)
 *
 * 편집 화면이 「무엇을 올릴 수 있나」를 받는 길을 확장마다 `GET …/content-media/target` 으로 맞췄다. 관리자 글쓰기는
 * 데이터소스 `content_media_target`(관리자 계약 주소)을 받아 편집기 `mediaUpload` 에 임시 키를 더해 넘긴다. 올릴 권한이
 * 없거나 받지 못하면 조용히 넘기고(값 `{data: null}`) 업로드 없이 편집기를 세운다. 편집기는 처음 세워질 때의 계약만 쓰므로
 * 그 소스를 받기(실패 포함) 전에는 세우지 않는다.
 *
 * 검증 방식: 레이아웃 JSON 의 데이터소스 선언과 편집기 노드의 표시 조건·props 식을 화면 상태 표본으로 평가(DOM 비의존).
 *
 * @scenario surface=admin
 * @effects editor_waits_for_target_source, media_upload_from_target_with_temp_key
 */

import { describe, it, expect } from 'vitest';

import postForm from '../../../layouts/admin/admin_board_post_form.json';
import formFields from '../../../layouts/admin/partials/admin_board_post_form/_form_fields.json';

type Node = Record<string, unknown>;

/**
 * 트리에서 id 로 노드를 찾는다.
 *
 * @param node 순회 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 (없으면 null)
 */
function findById(node: unknown, id: string): Node | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findById(child, id);
            if (found) {
                return found;
            }
        }
        return null;
    }
    if (node && typeof node === 'object') {
        const obj = node as Node;
        if (obj.id === id && obj.type === 'extension_point') {
            return obj;
        }
        for (const value of Object.values(obj)) {
            const found = findById(value, id);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

/**
 * `{{ ... }}` 식을 화면 상태 표본으로 평가한다.
 *
 * @param expression 식
 * @param scope 상태 표본
 * @returns 평가 결과
 */
function evaluate(expression: string, scope: Record<string, unknown>): unknown {
    const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(scope);
    // eslint-disable-next-line no-new-func
    return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

const dataSources = (postForm as Node).data_sources as Node[];
const target = dataSources.find((d) => d.id === 'content_media_target');
const editor = findById(formFields, 'content_section');

describe('관리자 글쓰기 — 편집기 업로드 계약 데이터소스 (#887)', () => {
    // @scenario surface=admin
    // @effects editor_waits_for_target_source
    it('관리자 표준 계약 엔드포인트를 데이터소스로 받고, 실패는 조용히 넘겨 data: null 로 둔다', () => {
        expect(target).toBeDefined();
        expect(target?.endpoint).toBe('/api/modules/sirsoft-board/admin/board/{{route.slug}}/content-media/target');
        expect(target?.method).toBe('GET');
        expect(target?.label_key).toBe('$t:sirsoft-board.editor.data_source.content_media_target');
        expect((target?.errorHandling as Node)?.default).toEqual({ handler: 'suppress' });
        expect(target?.fallback).toEqual({ data: null });
    });

    // @scenario surface=admin
    // @effects editor_waits_for_target_source
    it('계약 소스를 받기 전에는 편집기를 세우지 않고, 받으면(실패 포함) 세운다', () => {
        expect(editor).not.toBeNull();
        const shows = (scope: { content_media_target: unknown; form_meta: unknown }) => Boolean(evaluate(editor?.if as string, scope));
        const formMeta = { data: {} };

        expect(shows({ content_media_target: undefined, form_meta: formMeta })).toBe(false);
        expect(shows({ content_media_target: { data: null }, form_meta: formMeta })).toBe(true);
        expect(shows({ content_media_target: { data: { endpoint: '/x' } }, form_meta: formMeta })).toBe(true);
        // 폼 메타 대기(#883)는 그대로 — 계약을 받았어도 폼 메타 전이면 세우지 않는다
        expect(shows({ content_media_target: { data: { endpoint: '/x' } }, form_meta: undefined })).toBe(false);
    });

    // @scenario surface=admin
    // @effects media_upload_from_target_with_temp_key
    it('편집기 mediaUpload 는 계약에 임시 키를 더한 값이고, 계약이 없으면 null 이다', () => {
        const mediaUpload = (editor?.props as Node)?.mediaUpload as string;
        const contract = { endpoint: '/api/modules/sirsoft-board/admin/board/free/content-media', fields: { a: 1 }, fileField: 'file' };

        expect(evaluate(mediaUpload, { content_media_target: { data: contract }, _local: { tempKey: 'tk-1' } })).toEqual({
            ...contract,
            fields: { a: 1, temp_key: 'tk-1' },
        });
        expect(evaluate(mediaUpload, { content_media_target: { data: null }, _local: { tempKey: 'tk-1' } })).toBeNull();
        expect(mediaUpload).not.toContain('form_meta');
    });
});
