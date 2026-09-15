/**
 * @file action-recipes-contract.test.ts
 * @description 레이아웃 편집기 액션 레시피(actionRecipes.json) ↔ 실제 핸들러 계약 일치 회귀 테스트
 *
 * 배경: 편집기의 「액션 추가」 팔레트는 이 레시피의 `build` 를 그대로 레이아웃 JSON 으로 굽는다.
 * `setTheme` 레시피가 `params.target` 으로 굽고 있었으나 테마 핸들러는 `action.target` 만 읽으므로,
 * 편집기로 만든 테마 버튼은 생성 즉시 no-op 이었다 (오류·경고 없음).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const recipes = JSON.parse(
  fs.readFileSync(
    path.resolve(__dirname, '../../editor-spec/actionRecipes.json'),
    'utf8',
  ),
);

describe('actionRecipes.json — 핸들러 계약 일치', () => {
  // 두 핸들러 모두 action.target 만 읽는다 (src/handlers/setThemeHandler.ts).
  it.each(['setTheme', 'initTheme'])('%s 는 top-level target 으로 굽는다', (id) => {
    const build = recipes[id]?.build;
    expect(build).toBeDefined();
    expect(build.target).toBe('{{target}}');
    expect(build.params).toBeUndefined();
    expect((recipes[id].params ?? []).map((p: any) => p.key)).toContain('target');
  });

  it('changeState 는 setState 의 상태 범위를 params.target 으로 넘긴다', () => {
    // handleSetState 는 resolvedParams 에서 target 을 읽는다 (루트 action.target 은 무시).
    // 루트에 두면 기본값 'component' 로 떨어져, 나중에 global 을 고를 수 있게 되는 순간
    // 전역 대신 _local 에 조용히 기록된다.
    const build = recipes.changeState?.build;
    expect(build.handler).toBe('setState');
    expect(build.target).toBeUndefined();
    expect(build.params?.target).toBe('local');
  });
});
