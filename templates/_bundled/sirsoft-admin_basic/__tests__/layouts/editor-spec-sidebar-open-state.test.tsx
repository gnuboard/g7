/**
 * @file editor-spec-sidebar-open-state.test.tsx
 * @description 편집기 캔버스에서 모바일 드로어를 펼쳐 편집할 수 있는지 (editor-spec states)
 *
 * 배경: 언어·통화·배송국가 칩은 모바일 드로어(`admin_drawer_prefs`) 안에 있고, 드로어는
 * `_global.sidebarOpen` 으로 열린다. 편집기 캔버스는 정적 시뮬레이션이라 햄버거 클릭이 없으므로,
 * `sidebarOpen` 을 페이지 상태로 주입하지 않으면 드로어 내부를 영영 편집할 수 없다.
 *
 * `_admin_base` 는 라우트가 아니라 베이스 레이아웃이므로 `scope.kind: 'base'` 로 매칭해야 한다
 * (`LayoutEditorChrome` 이 `__base__/{layoutName}` → `{kind:'base', match:layoutName}` 로 도출).
 * 사용자 템플릿 `_user_base` 의 `mobile_menu_open` 과 동형.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const states = JSON.parse(
  fs.readFileSync(path.resolve(baseDir, 'editor-spec/states.json'), 'utf8'),
);

/** `matchStateItems` 와 동일한 매칭 규칙 (kind + match 완전 일치). */
function matchGroup(kind: string, match: string) {
  return (states.groups ?? []).filter(
    (g: any) => g.scope?.kind === kind && g.scope?.match === match,
  );
}

describe('editor-spec states — _admin_base 드로어 상태', () => {
  it('_admin_base 에 base scope 상태 그룹이 있다', () => {
    const groups = matchGroup('base', '_admin_base');
    expect(groups).toHaveLength(1);
  });

  it('sidebar_open 상태가 _global.sidebarOpen 을 true 로 주입한다', () => {
    const [group] = matchGroup('base', '_admin_base');
    const item = (group.items ?? []).find((i: any) => i.id === 'sidebar_open');

    expect(item).toBeTruthy();
    // 드로어 게이트는 `_admin_base.json` 의 `left_sidebar_area_portable_open` → `_global.sidebarOpen`.
    expect(item.initialState?.global?.sidebarOpen).toBe(true);
  });

  it('기본 상태(default)가 존재해 드로어 닫힌 화면도 미리본다', () => {
    const [group] = matchGroup('base', '_admin_base');
    const def = (group.items ?? []).find((i: any) => i.default === true);
    expect(def?.id).toBe('default');
    // 기본 상태는 sidebarOpen 을 주입하지 않는다(닫힌 드로어).
    expect(def.initialState?.global?.sidebarOpen).toBeUndefined();
  });

  it('모든 상태 라벨이 $t: 다국어 키이고 ko/en 에 정의되어 있다', () => {
    const [group] = matchGroup('base', '_admin_base');
    const ko = JSON.parse(fs.readFileSync(path.resolve(baseDir, 'lang/partial/ko/editor.json'), 'utf8'));
    const en = JSON.parse(fs.readFileSync(path.resolve(baseDir, 'lang/partial/en/editor.json'), 'utf8'));

    for (const item of group.items ?? []) {
      expect(item.label).toMatch(/^\$t:editor\.state\./);
      const key = item.label.replace('$t:editor.state.', '');
      expect(ko.state?.[key], `ko editor.state.${key}`).toBeTruthy();
      expect(en.state?.[key], `en editor.state.${key}`).toBeTruthy();
    }
  });
});
