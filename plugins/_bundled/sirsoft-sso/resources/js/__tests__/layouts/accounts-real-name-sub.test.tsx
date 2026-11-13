/**
 * @file accounts-real-name-sub.test.tsx
 * @description 관리자 「회원 연결」 목록의 회원 실명 보조 줄 (#490)
 *
 * 회원 칸의 이름(`user.name`)은 관리자 영역 표시 이름이다. 서버는 보는 관리자에게 회원 조회 권한이 있고 실명이 표시 이름과
 * 다를 때만 `user.real_name` 을 더한다. 화면은 그 키가 있을 때만 이름 아래에 작은 글씨 보조 줄을 그리고, 없으면 줄 자체를
 * 그리지 않는다. 목록은 DataGrid 한 벌이 넓은 화면 표와 좁은 화면 카드를 같은 칸 정의로 그리므로 칸 정의에 넣은 노드를
 * 굽기 산출 JSON 에서 꺼내 행 문맥으로 그려 본다.
 */

import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistry } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { flatten, lang, load } from './p4-layout-helpers';

type Json = Record<string, any>;

/** DOM 에 그대로 실을 속성만 고른다. */
function domProps(props: Json): Json {
  return Object.fromEntries(Object.entries(props).filter(([k]) => k.startsWith('data-') || k.startsWith('aria-') || k === 'role' || k === 'href'));
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
  r.register('basic', 'A', passthrough('a'));
  r.register('layout', 'Fragment', ({ children }: any) => React.createElement(React.Fragment, null, children));

  return r;
}

const layout = load('resources/layouts/admin/sso_accounts');
const column = flatten(layout).find((n) => n.field === 'user' && Array.isArray(n.cellChildren));

let active: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
  active?.cleanup();
  active = null;
});

describe('회원 연결 목록 — 회원 실명 보조 줄', () => {
  // @scenario case=accounts_sub_line_markup
  // @effects accounts_sub_line_in_user_cell, accounts_sub_line_label_uses_expression_translation, real_name_sub_key_present_in_ko_en
  it('회원 칸 안에 보조 줄이 하나 있고, 번역 인자를 식으로 넘기며 ko 와 en 문구가 있다', () => {
    expect(column).toBeDefined();
    const nodes = flatten(column.cellChildren).filter((n) => n?.props?.['data-testid'] === 'real-name-sub');
    expect(nodes).toHaveLength(1);
    expect(nodes[0].if).toBe('{{!!row.user?.real_name}}');
    // 표 칸 안에서는 `$t:키|인자={{row.값}}` 이 행 값을 받지 못하므로 식 안의 번역 호출을 쓴다
    expect(nodes[0].props['aria-label']).toContain("$t('sirsoft-sso.admin.common.real_name_sub', { name: row.user?.real_name })");
    expect(JSON.stringify(column.cellChildren)).toContain('row.user?.name');

    expect(lang('ko').admin.common.real_name_sub).toBe('실명 {{name}}');
    expect(lang('en').admin.common.real_name_sub).toContain('{{name}}');
  });

  // @scenario case=accounts_sub_line_rendering
  // @effects accounts_real_name_sub_line_drawn_only_when_key_present
  it('실명 키가 있는 행에만 이름 아래 보조 줄을 그린다', async () => {
    const rows = [
      { id: 1, user: { uuid: 'u1', name: '길동이', real_name: '홍길동', email: 'a@example.test' } },
      { id: 2, user: { uuid: 'u2', name: '길동이', email: 'b@example.test' } },
      { id: 3, user: null },
    ];

    active = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'test_accounts_real_name_sub',
        components: [{ type: 'basic', name: 'Div', iteration: { source: '{{rows}}', item_var: 'row' }, props: { 'data-testid': 'row' }, children: column.cellChildren }],
      } as any,
      {
        componentRegistry: registry(),
        translations: { 'sirsoft-sso': lang('ko') },
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
    expect(drawn[0].querySelector('a')?.textContent).toBe('길동이');

    // 키가 없으면 줄 자체가 없다
    expect(drawn[1].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
    expect(drawn[2].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
  });
});

describe('정책 화면 비상 계정 — 회원 실명 보조 줄', () => {
  // @scenario case=emergency_users_admin_area_name
  // @effects emergency_users_sub_line_markup
  it('비상 계정 행과 후보 행에 실명 보조 줄이 있고, 후보를 더할 때 실명도 함께 옮긴다', () => {
    const tab = load('resources/layouts/admin/partials/plugin_settings/_policy_tab');
    const nodes = flatten(tab);
    const listSub = nodes.filter((n) => n?.props?.['data-testid'] === 'emergency-real-name-sub');
    const candidateSub = nodes.filter((n) => n?.props?.['data-testid'] === 'emergency-candidate-real-name-sub');
    expect(listSub).toHaveLength(1);
    expect(listSub[0].if).toBe('{{!!emergency.real_name}}');
    expect(listSub[0].props['aria-label']).toContain("$t('sirsoft-sso.admin.common.real_name_sub', { name: emergency.real_name })");
    expect(candidateSub).toHaveLength(1);
    expect(candidateSub[0].if).toBe('{{!!candidate.real_name}}');
    expect(JSON.stringify(tab)).toContain('real_name: candidate.real_name ?? null');
  });
});
