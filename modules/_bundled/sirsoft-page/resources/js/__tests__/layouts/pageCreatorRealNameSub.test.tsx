/**
 * @file pageCreatorRealNameSub.test.tsx
 * @description 페이지 상세의 작성자와 버전 이력 저장자 실명 보조 줄
 *
 * 페이지 응답의 `creator.name` 은 관리자 영역 표시 이름이다. 서버는 보는 관리자에게 회원 조회 권한이 있고 실명이 표시 이름과
 * 다를 때만 같은 묶음에 `real_name` 을 더한다. 화면은 그 키가 있을 때만 이름 아래에 작은 글씨 보조 줄을 그리고, 없으면 줄 자체를
 * 그리지 않는다. 자리는 둘이다: 상세 정보 격자의 작성자, 버전 이력 표의 저장자 칸.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistry } from '@core/template-engine/__tests__/utils/layoutTestUtils';

import detailLayout from '../../../layouts/admin/admin_page_detail.json';

type Json = Record<string, any>;

const LANG_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../lang');
const ko = JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'ko.json'), 'utf-8'));
const koAdmin = JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'partial/ko/admin.json'), 'utf-8'));

const SUB = 'real-name-sub';

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
    r.register('basic', 'P', passthrough('p'));
    r.register('layout', 'Fragment', ({ children }: any) => React.createElement(React.Fragment, null, children));

    return r;
}

let active: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    active?.cleanup();
    active = null;
});

/** 노드 목록을 데이터 문맥으로 그린다. */
async function render(components: Json[], initialData: Json): Promise<HTMLElement> {
    active = createLayoutTest(
        { version: '1.0.0', layout_name: 'test_page_real_name_sub', components } as any,
        {
            componentRegistry: registry(),
            translations: { 'sirsoft-page': { ...ko, admin: koAdmin } },
            locale: 'ko',
            initialState: { _local: {}, _global: {} },
            initialData,
        } as any,
    );
    const { container } = await active.render();

    return container;
}

/** 그려진 보조 줄 하나가 실명과 접근 가능한 이름을 가졌는지 본다. */
function expectSubLine(scope: Element, realName: string): void {
    const subs = scope.querySelectorAll(`[data-testid="${SUB}"]`);
    expect(subs).toHaveLength(1);
    expect(subs[0].textContent).toBe(realName);
    expect(subs[0].getAttribute('aria-label')).toBe(`실명 ${realName}`);
    expect(subs[0].getAttribute('role')).toBe('note');
}

const infoParent = collect(
    detailLayout,
    (n) => Array.isArray(n.children) && n.children.some((c: any) => c?.props?.['data-testid'] === SUB && String(c.if).includes('page?.data?.creator')),
)[0];
const versionColumn = collect(detailLayout, (n) => n.field === 'creator' && Array.isArray(n.cellChildren))[0];

describe('페이지 상세 — 작성자 실명 보조 줄', () => {
    // @scenario case=creator_sub_line_markup
    // @effects creator_sub_line_in_info_and_version_cell, creator_sub_line_label_uses_expression_translation
    it('상세 정보의 작성자와 버전 이력의 저장자 칸에 보조 줄이 하나씩 있다', () => {
        expect(infoParent).toBeDefined();
        expect(JSON.stringify(infoParent.children)).toContain('page?.data?.creator?.name');

        expect(versionColumn).toBeDefined();
        const subs = collect(versionColumn.cellChildren, (n) => n.props?.['data-testid'] === SUB);
        expect(subs).toHaveLength(1);
        expect(subs[0].if).toBe('{{!!row.creator?.real_name}}');
        // 표 칸 안에서는 `$t:키|인자={{row.값}}` 이 행 값을 받지 못하므로 식 안의 번역 호출을 쓴다
        expect(subs[0].props['aria-label']).toContain("$t('sirsoft-page.admin.page.detail.real_name_sub', { name: row.creator.real_name })");
        expect(JSON.stringify(versionColumn.cellChildren)).toContain('row.creator?.name');
    });

    // @scenario case=info_creator_sub_line_rendering
    // @effects info_creator_sub_line_drawn_only_when_key_present
    it('상세 정보의 작성자는 실명 키가 있을 때만 보조 줄을 그린다', async () => {
        const withReal = await render([infoParent], { page: { data: { creator: { uuid: 'u-1', name: '길동이', real_name: '홍길동' } } } });
        expectSubLine(withReal, '홍길동');
        expect(withReal.textContent).toContain('길동이');
        active?.cleanup();

        const plain = await render([infoParent], { page: { data: { creator: { uuid: 'u-1', name: '길동이' } } } });
        expect(plain.querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
        expect(plain.textContent).toContain('길동이');
    });

    // @scenario case=version_creator_sub_line_rendering
    // @effects version_creator_sub_line_drawn_only_when_key_present
    it('버전 이력 표는 실명 키가 있는 행에만 보조 줄을 그린다', async () => {
        const container = await render(
            [{ type: 'basic', name: 'Div', iteration: { source: '{{rows}}', item_var: 'row' }, props: { 'data-testid': 'row' }, children: versionColumn.cellChildren }],
            {
                rows: [
                    { id: 1, creator: { uuid: 'u-1', name: '길동이', real_name: '홍길동' } },
                    { id: 2, creator: { uuid: 'u-1', name: '길동이' } },
                    { id: 3, creator: null },
                ],
            },
        );
        const drawn = Array.from(container.querySelectorAll('[data-testid="row"]'));

        expect(drawn).toHaveLength(3);
        expectSubLine(drawn[0], '홍길동');
        expect(drawn[0].textContent).toContain('길동이');
        expect(drawn[1].querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
        expect(drawn[2].querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
        expect(drawn[2].textContent).toContain('-');
    });
});
