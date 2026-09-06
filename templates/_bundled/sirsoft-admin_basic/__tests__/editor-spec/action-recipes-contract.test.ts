/**
 * @file action-recipes-contract.test.ts
 * @description 레이아웃 편집기 액션 레시피(actionRecipes.json) ↔ 실제 핸들러 계약 일치 회귀 테스트
 *
 * 배경: 편집기의 「액션 추가」 팔레트는 이 레시피의 `build` 를 그대로 레이아웃 JSON 으로 굽는다.
 * 그래서 레시피가 핸들러 계약과 어긋나 있으면, 운영자가 편집기로 만든 액션이 **생성 즉시 no-op** 이
 * 된다. 예외도 오류도 남지 않고 버튼만 반응하지 않으므로 만든 사람이 알아챌 방법이 없다.
 *
 * 아래 8종은 실제로 어긋나 있던 항목이다 (dev-g7#640 부수의무 전수 조사).
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

/** 레시피의 입력 필드 선언에서 key 목록을 뽑는다. */
function paramKeys(id: string): string[] {
  return (recipes[id]?.params ?? []).map((p: any) => p.key);
}

describe('actionRecipes.json — 핸들러 계약 일치', () => {
  describe('target 형 액션 (params 가 아니라 top-level target)', () => {
    // setLocale 은 엔진 빌트인(ActionDispatcher), setTheme/initTheme 은 템플릿 핸들러.
    // 셋 다 action.target 만 읽고 params 는 보지 않는다.
    it.each(['setLocale', 'setTheme', 'initTheme'])('%s 는 top-level target 으로 굽는다', (id) => {
      const build = recipes[id]?.build;
      expect(build).toBeDefined();
      expect(build.target).toBe('{{target}}');
      expect(build.params).toBeUndefined();
      expect(paramKeys(id)).toContain('target');
    });
  });

  it('scrollToSection 은 params.targetId 로 굽는다 (sectionId 아님)', () => {
    const build = recipes.scrollToSection?.build;
    expect(build.params).toEqual({ targetId: '{{targetId}}' });
    expect(build.params.sectionId).toBeUndefined();
    expect(paramKeys('scrollToSection')).toEqual(['targetId']);
  });

  it('setDateRange 의 preset 선택지는 핸들러 유효값 안에 있다', () => {
    // setDateRangeHandler 의 DatePreset 타입
    const validPresets = ['today', 'week', 'month', '3months', '6months', '1year'];
    const options = recipes.setDateRange?.params?.[0]?.options ?? [];
    const values = options.map((o: any) => o.value);

    expect(values.length).toBeGreaterThan(0);
    for (const v of values) expect(validPresets).toContain(v);
    // 'year' 는 핸들러가 모르는 값 — switch 어느 분기에도 걸리지 않는다
    expect(values).not.toContain('year');
    expect(values).toContain('1year');
  });

  it('toggleFilterVisibility 는 storageKey + filterId 를 모두 넘긴다', () => {
    const build = recipes.toggleFilterVisibility?.build;
    expect(build.params).toEqual({
      storageKey: '{{storageKey}}',
      filterId: '{{filterId}}',
    });
    expect(build.params.filterKey).toBeUndefined();
    expect(paramKeys('toggleFilterVisibility').sort()).toEqual(['filterId', 'storageKey']);
  });

  it('initFilterVisibility 는 storageKey 를 넘긴다 (없으면 핸들러가 조기 반환)', () => {
    const build = recipes.initFilterVisibility?.build;
    expect(build.params).toEqual({ storageKey: '{{storageKey}}' });
    expect(paramKeys('initFilterVisibility')).toEqual(['storageKey']);
  });

  it('saveMultilingualTag 은 인자를 받지 않는다 (핸들러가 전역 편집 상태만 읽음)', () => {
    const recipe = recipes.saveMultilingualTag;
    expect(recipe.build.params).toBeUndefined();
    expect(recipe.params).toEqual([]);
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
