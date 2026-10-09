/**
 * @file admin-role-menu-followups.test.ts
 * @description 역할 삭제 확인 창·메뉴 편집 저장 뒤 주소 회귀 테스트 (7.1.0 실측 후속)
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 *
 * 테스트 대상:
 * - layouts/partials/admin_role_list/_modal_delete.json — 창에 지울 역할 이름이 보여야 한다
 *   (목록이 전역 `deleteTargetName` 에 이름을 담아 주는데 창이 쓰지 않아, 어떤 역할을 지우는지
 *   창만 보고는 알 수 없었다).
 * - layouts/partials/admin_menu_list/_panel_form.json — 저장·취소 뒤 주소의 `mode=edit` 가 남아
 *   새로고침하면 이미 저장한 메뉴의 편집 폼이 다시 열리던 문제. 저장 성공은 `mode=view`(+저장한
 *   메뉴 슬러그)로, 취소는 고른 메뉴가 풀리므로 `menu`·`mode` 를 함께 지운다.
 */

import { describe, it, expect } from 'vitest';

type Node = Record<string, any>;

/** 레이아웃 JSON 전체에서 조건에 맞는 객체를 모두 모은다. */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, pred, out));
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node as Node).forEach((child) => collect(child, pred, out));
  }

  return out;
}

describe('역할 삭제 확인 창', () => {
  const partial = require('../../layouts/partials/admin_role_list/_modal_delete.json');

  /** @effects role_delete_confirm_shows_name */
  it('지울 역할 이름(_global.deleteTargetName)을 창에 보여 준다', () => {
    const texts = collect(partial, (n) => typeof n.text === 'string' && n.text.includes('_global.deleteTargetName'));
    expect(texts.length).toBeGreaterThan(0);
  });
});

describe('메뉴 편집 판 저장·취소 뒤 주소', () => {
  const partial = require('../../layouts/partials/admin_menu_list/_panel_form.json');
  const replaces = collect(partial, (n) => n.handler === 'replaceUrl');

  /** @effects menu_form_url_mode_follows_panel */
  it('저장 성공은 주소를 보기 모드와 저장한 메뉴로 맞춘다', () => {
    const toView = replaces.find((a) => a.params?.query?.mode === 'view');
    expect(toView).toBeDefined();
    expect(toView!.params.mergeQuery).toBe(true);
    expect(toView!.params.query.menu).toBe('{{result.data.slug}}');
  });

  /** @effects menu_form_url_mode_follows_panel */
  it('취소는 고른 메뉴가 풀리므로 menu·mode 를 주소에서 지운다', () => {
    const cleared = replaces.find((a) => a.params?.query?.mode === '' && a.params?.query?.menu === '');
    expect(cleared).toBeDefined();
    expect(cleared!.params.mergeQuery).toBe(true);
  });

  /** @effects menu_form_url_mode_follows_panel */
  it('편집 판에는 주소를 mode=edit 로 두는 동작이 없다', () => {
    expect(replaces.some((a) => a.params?.query?.mode === 'edit')).toBe(false);
  });
});
