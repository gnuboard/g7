/**
 * isolatedScopeUtils.test.ts — 격리 스코프 스캔·짝검증 순수 유틸
 *
 * 검증:
 *  ① collectIsolatedScopes — 중첩·iteration·responsive 분기 내부 전수
 *  ② classifyIsolatedOrphan — isolatedState 0개면 전부 orphan / 존재 시 비-orphan(보수적)
 *  ③ buildScopeIdCandidates — 기존 scopeId + initIsolated 키 + 관용 패턴 합집합·중복 제거
 *  ④ 역방향(isolatedState 보유·initIsolated 없음) = orphan 아님
 */

import { describe, it, expect } from 'vitest';
import {
  collectIsolatedScopes,
  classifyIsolatedOrphan,
  buildScopeIdCandidates,
} from '../../spec/isolatedScopeUtils';

describe('collectIsolatedScopes', () => {
  it('중첩·iteration·responsive 분기 내부 격리 노드를 전수 수집한다', () => {
    const components = [
      {
        name: 'Div',
        children: [
          { name: 'CategorySelector', isolatedState: { selectedId: null }, isolatedScopeId: 'category-selector' },
          {
            name: 'Div',
            children: [
              { name: 'Slider', iteration: { source: '{{ x }}' }, isolatedState: { idx: 0 }, isolatedScopeId: 'product-slider' },
            ],
          },
        ],
        responsive: {
          mobile: {
            children: [{ name: 'MiniWidget', isolatedState: { step: 1 }, isolatedScopeId: 'wizard' }],
          },
        },
      },
    ];
    const scopes = collectIsolatedScopes(components);
    const ids = scopes.map((s) => s.scopeId).sort();
    expect(ids).toEqual(['category-selector', 'product-slider', 'wizard']);
    // 시작값 보존.
    expect(scopes.find((s) => s.scopeId === 'category-selector')?.initialState).toEqual({ selectedId: null });
  });

  it('격리 노드 없으면 빈 배열', () => {
    expect(collectIsolatedScopes([{ name: 'Div', children: [{ name: 'Span' }] }])).toEqual([]);
  });
});

describe('classifyIsolatedOrphan', () => {
  it('isolatedState 0개 → 모든 initIsolated 키 orphan', () => {
    const map = classifyIsolatedOrphan(['scrollIdx', 'step'], []);
    expect(map).toEqual({ scrollIdx: true, step: true });
  });

  it('isolatedState 존재 → 비-orphan(보수적)', () => {
    const scopes = [{ scopeId: 'wizard', initialState: { step: 1 } }];
    const map = classifyIsolatedOrphan(['step'], scopes);
    expect(map.step).toBe(false);
  });

  it('상속받은 키도 동일 짝 검증 대상(I17/I18)', () => {
    // 노드 0개면 상속 키도 orphan.
    expect(classifyIsolatedOrphan(['inheritedKey'], [])).toEqual({ inheritedKey: true });
    // 노드 있으면 상속 키도 비-orphan.
    expect(classifyIsolatedOrphan(['inheritedKey'], [{ scopeId: 's' }])).toEqual({ inheritedKey: false });
  });
});

describe('buildScopeIdCandidates', () => {
  it('기존 scopeId + initIsolated 키 + 관용 패턴 합집합·중복 제거', () => {
    const scopes = [{ scopeId: 'category-selector' }];
    const candidates = buildScopeIdCandidates(scopes, ['scroll']);
    expect(candidates).toContain('category-selector'); // 기존.
    expect(candidates).toContain('scroll'); // initIsolated 키.
    expect(candidates).toContain('scroll-scroll'); // 관용 패턴.
    expect(candidates).toContain('scroll-selector');
    // 중복 제거 — Set 기반.
    expect(new Set(candidates).size).toBe(candidates.length);
  });

  it('빈 입력 → 빈 후보', () => {
    expect(buildScopeIdCandidates([], [])).toEqual([]);
  });
});
