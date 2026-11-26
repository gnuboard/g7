/**
 * @file adminRealNameSub.test.tsx
 * @description GDPR 관리자 화면의 회원 실명 보조 줄
 *
 * 동의 기록의 회원 칸(`user.name`)과 정책 버전의 발행자(`publisher.name`)는 관리자 영역 표시 이름이다. 서버는 보는 관리자에게
 * 회원 조회 권한이 있고 실명이 표시 이름과 다를 때만 같은 묶음에 `real_name` 을 더한다. 화면은 그 키가 있을 때만 이름 아래에
 * 작은 글씨 보조 줄을 그리고, 없으면 줄 자체를 그리지 않는다.
 *
 * 자리는 넷이다: 동의 기록 표의 회원 칸, 설정 화면 정책 버전 카드의 발행자, 그 아래 이력 표의 발행자 칸, 정책 버전 스냅샷 창의
 * 발행자. 굽기 산출 JSON 에서 보조 줄을 담은 노드를 꺼내 행 문맥(또는 데이터소스 문맥)으로 그려 본다.
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistry } from '@core/template-engine/__tests__/utils/layoutTestUtils';

import consentLogLayout from '../../../layouts/admin/gdpr_consent_log.json';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import snapshotModalLayout from '../../../layouts/admin/partials/_shared/_policy_version_snapshot_modal.json';

type Json = Record<string, any>;

const LANG_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../lang');
const ko = JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'ko.json'), 'utf-8'));

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

/** 보조 줄을 직접 자식으로 가진 노드를 모은다. */
function subLineParents(tree: unknown): Json[] {
    return collect(tree, (n) => Array.isArray(n.children) && n.children.some((c: any) => c?.props?.['data-testid'] === SUB));
}

/** DOM 에 그대로 실을 속성만 고른다. */
function domProps(props: Json): Json {
    return Object.fromEntries(Object.entries(props).filter(([k]) => k.startsWith('data-') || k.startsWith('aria-') || k === 'role'));
}

/** 태그 하나를 그대로 그리는 시험 컴포넌트를 만든다. */
function passthrough(Tag: string): React.FC<any> {
    return ({ className, children, text, ...rest }) => React.createElement(Tag, { className, ...domProps(rest) }, children ?? text);
}

/** 시험 컴포넌트 등록소. 표 칸(`Td`)은 표 밖에서 그리므로 div 로 그린다. */
function registry() {
    const r = createMockComponentRegistry();
    r.register('basic', 'Div', passthrough('div'));
    r.register('basic', 'Span', passthrough('span'));
    r.register('basic', 'Td', passthrough('div'));
    r.register('basic', 'A', passthrough('a'));
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
        { version: '1.0.0', layout_name: 'test_gdpr_real_name_sub', components } as any,
        {
            componentRegistry: registry(),
            translations: { 'sirsoft-gdpr': ko },
            locale: 'ko',
            initialState: { _local: {}, _global: {} },
            initialData,
        } as any,
    );
    const { container } = await active.render();

    return container;
}

/** 행 셋(실명 있음, 실명 없음, 회원 없음)을 행마다 그린다. */
async function renderRows(children: Json[], rows: Json[]): Promise<Element[]> {
    const container = await render(
        [{ type: 'basic', name: 'Div', iteration: { source: '{{rows}}', item_var: 'row' }, props: { 'data-testid': 'row' }, children }],
        { rows },
    );

    return Array.from(container.querySelectorAll('[data-testid="row"]'));
}

/** 그려진 보조 줄 하나가 실명과 접근 가능한 이름을 가졌는지 본다. */
function expectSubLine(scope: Element, realName: string): void {
    const subs = scope.querySelectorAll(`[data-testid="${SUB}"]`);
    expect(subs).toHaveLength(1);
    expect(subs[0].textContent).toBe(realName);
    expect(subs[0].getAttribute('aria-label')).toBe(`실명 ${realName}`);
    expect(subs[0].getAttribute('role')).toBe('note');
}

const consentColumn = collect(consentLogLayout, (n) => n.field === 'subject' && Array.isArray(n.cellChildren))[0];
const settingsParents = subLineParents(settingsLayout);
const modalParents = subLineParents(snapshotModalLayout);

describe('GDPR 관리자 화면 — 회원 실명 보조 줄', () => {
    // @scenario case=sub_line_markup
    // @effects sub_line_in_each_member_name_spot, sub_line_label_uses_expression_translation
    it('네 자리에 보조 줄이 하나씩 있고, 번역 인자를 식으로 넘긴다', () => {
        const consentSubs = collect(consentColumn?.cellChildren, (n) => n.props?.['data-testid'] === SUB);
        expect(consentSubs).toHaveLength(1);
        expect(consentSubs[0].if).toBe('{{!!row.user?.real_name}}');
        // 표 칸 안에서는 `$t:키|인자={{row.값}}` 이 행 값을 받지 못하므로 식 안의 번역 호출을 쓴다
        expect(consentSubs[0].props['aria-label']).toContain("$t('sirsoft-gdpr.common.real_name_sub', { name: row.user.real_name })");

        expect(settingsParents).toHaveLength(2);
        expect(modalParents).toHaveLength(1);
        const ifs = [...settingsParents, ...modalParents].map((p) => p.children.find((c: any) => c?.props?.['data-testid'] === SUB).if);
        expect(ifs).toEqual([
            '{{!!gdprPolicyVersionCurrent?.data?.data?.publisher?.real_name}}',
            '{{!!row?.publisher?.real_name}}',
            '{{!!gdprPolicyVersionSnapshot?.data?.data?.publisher?.real_name}}',
        ]);
        [...settingsParents, ...modalParents].forEach((p) => {
            const sub = p.children.find((c: any) => c?.props?.['data-testid'] === SUB);
            expect(sub.props['aria-label']).toContain("$t('sirsoft-gdpr.common.real_name_sub', { name: ");
            // 보조 줄은 이름과 같은 칸 안에 있다 (스냅샷 창의 값 칸은 display:contents 격자의 한 칸이다)
            expect(JSON.stringify(p.children)).toContain('publisher.name');
        });
    });

    // @scenario case=consent_log_sub_line_rendering
    // @effects consent_log_sub_line_drawn_only_when_key_present
    it('동의 기록 표는 실명 키가 있는 회원 행에만 보조 줄을 그린다', async () => {
        const drawn = await renderRows(consentColumn.cellChildren, [
            { id: 1, user_id: 7, user: { uuid: 'u-1', name: '길동이', real_name: '홍길동', email: 'a@example.com' } },
            { id: 2, user_id: 8, user: { uuid: 'u-2', name: '길동이', email: 'b@example.com' } },
            { id: 3, user_id: null, user: null, session_id: 'abcdef1234' },
        ]);

        expect(drawn).toHaveLength(3);
        expectSubLine(drawn[0], '홍길동');
        expect(drawn[0].textContent).toContain('길동이 (a@example.com)');
        expect(drawn[1].querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
        expect(drawn[2].querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
    });

    // @scenario case=policy_history_sub_line_rendering
    // @effects policy_history_sub_line_drawn_only_when_key_present
    it('정책 버전 이력 표는 실명 키가 있는 발행자 행에만 보조 줄을 그린다', async () => {
        const drawn = await renderRows([settingsParents[1]], [
            { version: 3, publisher: { uuid: 'u-1', name: '길동이', real_name: '홍길동', email: 'a@example.com' } },
            { version: 2, publisher: { uuid: 'u-2', name: '길동이', email: 'b@example.com' } },
            { version: 1, publisher: null },
        ]);

        expect(drawn).toHaveLength(3);
        expectSubLine(drawn[0], '홍길동');
        expect(drawn[0].textContent).toContain('길동이 (a@example.com)');
        expect(drawn[1].querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
        expect(drawn[2].querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
    });

    // @scenario case=policy_current_and_snapshot_sub_line_rendering
    // @effects policy_current_and_snapshot_sub_line_drawn_only_when_key_present
    it('정책 버전 카드와 스냅샷 창의 발행자는 실명 키가 있을 때만 보조 줄을 그린다', async () => {
        const withReal = { data: { data: { publisher: { uuid: 'u-1', name: '길동이', real_name: '홍길동', email: 'a@example.com' } } } };
        const withoutReal = { data: { data: { publisher: { uuid: 'u-1', name: '길동이', email: 'a@example.com' } } } };

        const current = await render([settingsParents[0]], { gdprPolicyVersionCurrent: withReal });
        expectSubLine(current, '홍길동');
        expect(current.textContent).toContain('길동이 (a@example.com)');
        active?.cleanup();

        const currentPlain = await render([settingsParents[0]], { gdprPolicyVersionCurrent: withoutReal });
        expect(currentPlain.querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
        active?.cleanup();

        const modal = await render([modalParents[0]], { gdprPolicyVersionSnapshot: withReal });
        expectSubLine(modal, '홍길동');
        active?.cleanup();

        const modalPlain = await render([modalParents[0]], { gdprPolicyVersionSnapshot: withoutReal });
        expect(modalPlain.querySelectorAll(`[data-testid="${SUB}"]`)).toHaveLength(0);
    });
});
