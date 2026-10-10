/**
 * @file admin-template-list-dependency-names.test.ts
 * @description 템플릿 목록 카드의 의존성 이름이 항상 글자인지 고정한다 (#727 실측 중 발견 — React #31)
 *
 * 결함: 카드 목록 식이 의존성 이름을 `d.name ?? d` 로 만들어, 목록 API 가 이름을 비워(null) 주면 의존성 객체
 * `{identifier, name, type}` 통째가 카드에 글자로 들어가 React #31("Objects are not valid as a React child")로
 * 카드 목록 전체가 그려지지 않았다. 모듈·플러그인 목록은 `d.name` 만 쓴다.
 *
 * 레이아웃에 적힌 식을 엔진에 그대로 넣어 결과 배열을 본다 — 이름이 비면 식별자로 대신한다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import tabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import tabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';

type Json = Record<string, any>;

/** `items` 에 카드 목록 식을 가진 노드를 찾는다. */
function findItemsBinding(node: unknown): string | undefined {
  if (Array.isArray(node)) {
    for (const item of node) {
      const hit = findItemsBinding(item);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    const items = obj.props?.items;
    if (typeof items === 'string' && items.includes('dependencies')) return items;
    for (const v of Object.values(obj)) {
      const hit = findItemsBinding(v);
      if (hit) return hit;
    }
  }
  return undefined;
}

/** `{{ … }}` 한 덩어리 바인딩을 값으로 평가한다. */
function evalBinding(binding: string, context: Json): unknown {
  return dataBindingEngine.evaluateExpression(binding.trim().replace(/^\{\{|\}\}$/g, ''), context);
}

const row = (dependencies: Json) => ({
  identifier: 'gnuboard7-hello_user_template',
  name: 'Hello 사용자 템플릿',
  version: '0.2.0',
  status: 'active',
  dependencies,
  abilities: { can_install: true, can_activate: true, can_uninstall: true, can_edit_layouts: true },
});

describe.each([
  ['_tab_user', tabUser],
  ['_tab_admin', tabAdmin],
])('[case:components-misc-object-fallback-react-31] %s 카드 목록 — 의존성 이름', (_name, layout) => {
  const binding = findItemsBinding(layout)!;

  // @scenario surface=template_list_card
  // @effects template_list_dependency_name_is_always_text
  it('카드 목록 식을 찾는다', () => {
    expect(binding).toBeTypeOf('string');
  });

  // @scenario surface=template_list_card
  // @effects template_list_dependency_name_is_always_text
  it('API 가 이름을 비워 주면 식별자로 대신하고, 객체를 이름 자리에 넣지 않는다', () => {
    const items = evalBinding(binding, {
      templates: {
        data: {
          data: [row({
            modules: [{ identifier: 'gnuboard7-hello_module', name: null, type: 'module' }],
            plugins: [{ identifier: 'sirsoft-daum_postcode', type: 'plugin' }],
          })],
        },
      },
    }) as Json[];

    const names = items[0].dependencies.map((d: Json) => d.name);
    expect(names).toEqual(['gnuboard7-hello_module', 'sirsoft-daum_postcode']);
  });

  // @scenario surface=template_list_card
  // @effects template_list_dependency_name_is_always_text
  it('이름이 있으면 이름을, 옛 문자열 목록이면 그 문자열을 쓴다', () => {
    const items = evalBinding(binding, {
      templates: {
        data: {
          data: [row({
            modules: [{ identifier: 'sirsoft-board', name: '게시판', type: 'module' }],
            plugins: ['sirsoft-daum_postcode'],
          })],
        },
      },
    }) as Json[];

    expect(items[0].dependencies).toEqual([
      { type: 'module', name: '게시판' },
      { type: 'plugin', name: 'sirsoft-daum_postcode' },
    ]);
  });
});
