/**
 * @file admin-auth-settheme-target.test.tsx
 * @description 비인증 화면(로그인·비밀번호찾기·비밀번호재설정) 테마 버튼의 setTheme 액션 형태 회귀 테스트
 *
 * 배경: 세 화면의 테마 버튼(밝게/어둡게/자동)이 `"params": { "theme": "dark" }` 형태로
 * `setTheme` 을 호출했으나, 템플릿 핸들러(`src/handlers/setThemeHandler.ts`)는 **`action.target`
 * 만** 읽는다. 엔진(ActionDispatcher)에도 `target` ↔ `params` 상호 폴백이 없으므로 클릭 시
 * `[Handler:SetTheme] Invalid theme: undefined` 경고만 남기고 아무 일도 일어나지 않았다.
 * 로그인 이후 화면은 핸들러를 경유하지 않는 `ThemeToggle` 컴포지트를 쓰므로 정상이었고,
 * 그래서 이 결함은 미인증 3화면에서만 나타났다.
 *
 * 조치: 같은 디렉토리의 `admin-auth-setlocale-target.test.tsx` 가 잠근 setLocale 선례와 동형으로,
 * 세 화면의 액션을 `params.theme` → top-level `target` 으로 옮겼다.
 *
 * 이 테스트를 `params.theme` 로 되돌리면 세 화면의 테마 전환이 조용히 죽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

/** 레이아웃 전체에서 handler === 'setTheme' 인 액션을 모두 수집한다. */
function collectSetThemeActions(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const n of node) collectSetThemeActions(n, acc);
    return acc;
  }
  for (const action of node.actions ?? []) {
    if (action?.handler === 'setTheme') acc.push(action);
  }
  for (const k of ['children', 'components']) {
    if (node[k]) collectSetThemeActions(node[k], acc);
  }
  return acc;
}

const VALID_THEMES = ['light', 'dark', 'auto'];

const layouts: Array<[string, string]> = [
  ['admin_login', 'layouts/admin_login.json'],
  ['admin_forgot_password', 'layouts/admin_forgot_password.json'],
  ['admin_reset_password', 'layouts/admin_reset_password.json'],
];

describe('비인증 화면 테마 버튼 — setThemeHandler 규약', () => {
  it.each(layouts)('%s 의 setTheme 3건은 target 으로 테마를 넘긴다', (_name, relPath) => {
    const layout = loadJson(relPath);
    const actions = collectSetThemeActions(layout.components ?? layout);

    // 밝게 / 어둡게 / 자동 세 버튼
    expect(actions.length).toBe(3);

    for (const action of actions) {
      expect(action.type).toBe('click');
      expect(VALID_THEMES).toContain(action.target);
      // 핸들러는 params 를 읽지 않는다. 남아 있으면 무시되어 테마 전환이 죽는다.
      expect(action.params).toBeUndefined();
    }

    // 세 버튼이 서로 다른 테마를 지정한다
    expect([...actions.map((a) => a.target)].sort()).toEqual(['auto', 'dark', 'light']);
  });

  it('세 화면 합계 9건이 모두 target 형식이다', () => {
    const all = layouts.flatMap(([, relPath]) => {
      const layout = loadJson(relPath);
      return collectSetThemeActions(layout.components ?? layout);
    });

    expect(all.length).toBe(9);
    expect(all.every((a) => VALID_THEMES.includes(a.target))).toBe(true);
    expect(all.every((a) => a.params === undefined)).toBe(true);
  });
});
