/**
 * @file uploaderRealNameSub.test.tsx
 * @description 업로드 관리 목록의 「올린 사람」 실명 보조 줄 (#490)
 *
 * 올린 사람 칸의 이름(`uploader_name`)은 관리자 영역 표시 이름이다. 서버는 보는 관리자에게 회원 조회 권한이 있고 실명이
 * 표시 이름과 다를 때만 `uploader_real_name` 을 더한다. 화면은 그 키가 있을 때만 이름 아래에 작은 글씨 보조 줄을 그리고,
 * 없으면 줄 자체를 그리지 않는다. 목록은 DataGrid 한 벌이 넓은 화면 표와 좁은 화면 카드를 같은 칸 정의로 그리므로
 * 칸 정의에 넣은 노드를 굽기 산출 JSON 에서 꺼내 행 문맥으로 그려 본다.
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistry } from '@core/template-engine/__tests__/utils/layoutTestUtils';

import uploadsLayout from '../../../layouts/admin/ckeditor5_uploads.json';

type Json = Record<string, any>;

const LANG_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../lang');

/** 노드 트리를 돌며 조건에 맞는 노드를 모은다. */
function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, match, out));
        return out;
    }
    if (node && typeof node === 'object') {
        if (match(node)) out.push(node);
        Object.values(node).forEach((value) => collect(value, match, out));
    }
    return out;
}

/** DOM 에 그대로 실을 속성만 고른다. */
function domProps(props: Json): Json {
    return Object.fromEntries(Object.entries(props).filter(([k]) => k.startsWith('data-') || k.startsWith('aria-') || k === 'role'));
}

/** 태그 하나를 그대로 그리는 시험 컴포넌트를 만든다. */
function passthrough(Tag: string): React.FC<any> {
    return ({ className, children, text, ...rest }) => React.createElement(Tag, { className, ...domProps(rest) }, children ?? text);
}

/** 시험 컴포넌트 등록소. */
function registry() {
    const r = createMockComponentRegistry();
    r.register('basic', 'Div', passthrough('div'));
    r.register('basic', 'Span', passthrough('span'));
    r.register('layout', 'Fragment', ({ children }: any) => React.createElement(React.Fragment, null, children));

    return r;
}

const column = collect(uploadsLayout, (n) => n.field === 'uploader_name' && Array.isArray(n.cellChildren))[0];

let active: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    active?.cleanup();
    active = null;
});

describe('업로드 관리 목록 — 올린 사람 실명 보조 줄', () => {
    // @scenario case=uploader_sub_line_markup
    // @effects uploader_sub_line_in_uploader_cell, uploader_sub_line_label_uses_expression_translation
    it('올린 사람 칸 안에 보조 줄이 하나 있고, 번역 인자를 식으로 넘긴다', () => {
        expect(column).toBeDefined();
        const nodes = collect(column.cellChildren, (n) => n.props?.['data-testid'] === 'real-name-sub');
        expect(nodes).toHaveLength(1);
        expect(nodes[0].if).toBe('{{!!row.uploader_real_name}}');
        // 표 칸 안에서는 `$t:키|인자={{row.값}}` 이 행 값을 받지 못하므로 식 안의 번역 호출을 쓴다
        expect(nodes[0].props['aria-label']).toContain("$t('sirsoft-ckeditor5.admin.uploads.real_name_sub', { name: row.uploader_real_name })");
        expect(JSON.stringify(column.cellChildren)).toContain('row.uploader_name');
    });

    // @scenario case=uploader_sub_line_rendering
    // @effects uploader_real_name_sub_line_drawn_only_when_key_present
    it('실명 키가 있는 행에만 이름 아래 보조 줄을 그린다', async () => {
        const ko = JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'ko.json'), 'utf-8'));
        const rows = [
            { id: 1, uploader_name: '길동이', uploader_real_name: '홍길동' },
            { id: 2, uploader_name: '길동이' },
            { id: 3, uploader_name: null },
        ];

        active = createLayoutTest(
            {
                version: '1.0.0',
                layout_name: 'test_uploader_real_name_sub',
                components: [{ type: 'basic', name: 'Div', iteration: { source: '{{rows}}', item_var: 'row' }, props: { 'data-testid': 'row' }, children: column.cellChildren }],
            } as any,
            {
                componentRegistry: registry(),
                translations: { 'sirsoft-ckeditor5': ko },
                locale: 'ko',
                initialState: { _local: {}, _global: {} },
                initialData: { rows },
            } as any,
        );

        const { container } = await active.render();
        const drawn = Array.from(container.querySelectorAll('[data-testid="row"]'));
        expect(drawn).toHaveLength(3);

        const subs = drawn[0].querySelectorAll('[data-testid="real-name-sub"]');
        expect(subs).toHaveLength(1);
        expect(subs[0].textContent).toBe('홍길동');
        expect(subs[0].getAttribute('aria-label')).toBe('실명 홍길동');
        expect(subs[0].getAttribute('role')).toBe('note');
        expect(drawn[0].textContent).toContain('길동이');

        // 키가 없으면 줄 자체가 없다
        expect(drawn[1].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
        expect(drawn[2].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
        expect(drawn[2].textContent).toContain('-');
    });
});
