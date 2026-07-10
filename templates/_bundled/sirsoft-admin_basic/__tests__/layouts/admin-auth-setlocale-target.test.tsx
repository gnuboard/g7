/**
 * @file admin-auth-setlocale-target.test.tsx
 * @description 비인증 화면(로그인·비밀번호찾기·비밀번호재설정) 언어 선택기의 setLocale 액션 형태 회귀 테스트
 *
 * 배경: 관리자 템플릿은 자체 `setLocaleHandler` 를 등록해 엔진 빌트인 `setLocale`(ActionDispatcher)
 * 을 덮어쓰고 있었다. 그 사본에는 두 결함이 있었다.
 *   ① `<button>` 의 `value` 는 항상 `''` 인데 `!== undefined && !== null` 로만 걸러 빈 문자열을
 *      로케일로 채택 → 이후 검증에서 조기 반환(드로어 언어 칩이 눌러도 무반응).
 *   ② `G7Core.config` 는 존재하지 않고, `state.get('_global.appConfig.supportedLocales')` 는
 *      `_global` 객체 전체를 반환해 `validLocales.includes` 가 TypeError.
 *
 * 조치: 사용자 템플릿(`sirsoft-basic`)과 동일하게 템플릿 사본을 제거하고 엔진 빌트인을 쓴다.
 * 엔진 빌트인은 **`action.target` 만** 읽으므로(`params.locale` 은 무시), 세 화면의 Select 액션도
 * `params` → `target` 으로 옮겨야 한다. `Select` 컴포넌트는 네이티브 `<select>` 가 아니라 커스텀
 * 위젯이지만 `{target:{value}}` 형태의 합성 이벤트를 발생시키므로 `{{$event.target.value}}` 가
 * 그대로 해석된다(브라우저 실측 확인).
 *
 * 이 테스트를 `params.locale` 로 되돌리면 세 화면의 언어 전환이 조용히 죽는다.
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

/** 레이아웃 전체에서 handler === 'setLocale' 인 액션을 모두 수집한다. */
function collectSetLocaleActions(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const n of node) collectSetLocaleActions(n, acc);
    return acc;
  }
  for (const action of node.actions ?? []) {
    if (action?.handler === 'setLocale') acc.push(action);
  }
  for (const k of ['children', 'components']) {
    if (node[k]) collectSetLocaleActions(node[k], acc);
  }
  return acc;
}

const layouts: Array<[string, string]> = [
  ['admin_login', 'layouts/admin_login.json'],
  ['admin_forgot_password', 'layouts/admin_forgot_password.json'],
  ['admin_reset_password', 'layouts/admin_reset_password.json'],
];

describe('비인증 화면 언어 선택기 — 엔진 빌트인 setLocale 규약', () => {
  it.each(layouts)('%s 의 setLocale 은 target 으로 로케일을 넘긴다', (_name, relPath) => {
    const layout = loadJson(relPath);
    const actions = collectSetLocaleActions(layout.components ?? layout);

    expect(actions.length).toBe(1);
    const [action] = actions;

    expect(action.type).toBe('change');
    expect(action.target).toBe('{{$event.target.value}}');
    // 엔진 빌트인은 params 를 읽지 않는다. 남아 있으면 무시되어 언어 전환이 죽는다.
    expect(action.params).toBeUndefined();
  });

  it('템플릿은 setLocale 핸들러를 자체 등록하지 않는다 (엔진 빌트인 사용)', () => {
    const indexTs = fs.readFileSync(
      path.resolve(baseDir, 'src/handlers/index.ts'),
      'utf8',
    );
    expect(indexTs).not.toContain('setLocaleHandler');
    expect(fs.existsSync(path.resolve(baseDir, 'src/handlers/setLocaleHandler.ts'))).toBe(false);
  });
});
