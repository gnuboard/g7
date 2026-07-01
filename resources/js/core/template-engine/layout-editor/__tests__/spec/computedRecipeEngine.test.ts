/**
 * computedRecipeEngine.test.ts — 자동 계산 친화 보기·3단계 틀·미리보기 평가
 *
 *
 * 검증:
 *  - normalizeComputedRecipes: expr 없는 항목·comment 제외, __source 보존
 *  - buildComputedExpr ↔ matchComputed 왕복(프리셋, {{ }} 한 쌍 보장)
 *  - buildCustomComputedExpr ↔ matchCustomComputed 왕복(7동사)
 *  - resolveComputedCard 우선순위(preset → custom → advanced)
 *  - evaluateComputedPreview 값/타입(엔진 evaluator 위임) + 실패 시 ok:false
 */

import { describe, it, expect } from 'vitest';
import {
  normalizeComputedRecipes,
  buildComputedExpr,
  matchComputed,
  buildCustomComputedExpr,
  matchCustomComputed,
  resolveComputedCard,
  evaluateComputedPreview,
  type CustomComputedModel,
  type NormalizedComputedRecipe,
} from '../../spec/computedRecipeEngine';

// R-P1: 필터 값 자동 채우기 — `_local.{localPath} ?? query.{queryKey} ?? '{fallback}'`
const FILTER_DEFAULT: NormalizedComputedRecipe = {
  id: 'computed.preset.filter_default',
  label: '$t:filter_default',
  params: [
    { key: 'localPath', widget: 'text' },
    { key: 'queryKey', widget: 'text' },
    { key: 'fallback', widget: 'text' },
  ],
  expr: "_local.{localPath} ?? query.{queryKey} ?? '{fallback}'",
};

// R-P2: 권한 없으면 읽기전용
const READONLY_BY_ABILITY: NormalizedComputedRecipe = {
  id: 'computed.preset.readonly_by_ability',
  params: [
    { key: 'source', widget: 'datasource-picker' },
    { key: 'abilityKey', widget: 'text' },
  ],
  expr: '{source}?.data?.abilities?.{abilityKey} !== true',
};

describe('normalizeComputedRecipes', () => {
  it('expr 문자열 없는 항목과 comment 를 제외하고 __source 를 보존한다', () => {
    const out = normalizeComputedRecipes({
      comment: { expr: 'ignored' } as never,
      ok: { label: '$t:ok', expr: 'a ?? b', __source: { kind: 'template' } } as never,
      noExpr: { label: '$t:no' } as never,
    });
    expect(out).toHaveLength(1);
    expect(out[0].id).toBe('ok');
    expect(out[0].source).toEqual({ kind: 'template' });
  });
});

describe('buildComputedExpr ↔ matchComputed (프리셋 왕복)', () => {
  it('R-P1 필터 기본값을 생성하고 다시 역해석한다', () => {
    const built = buildComputedExpr(FILTER_DEFAULT, {
      localPath: 'category',
      queryKey: 'cat',
      fallback: 'all',
    });
    expect(built).toBe("{{ _local.category ?? query.cat ?? 'all' }}");
    const matched = matchComputed(built, [FILTER_DEFAULT]);
    expect(matched?.recipeId).toBe('computed.preset.filter_default');
    expect(matched?.params).toEqual({ localPath: 'category', queryKey: 'cat', fallback: 'all' });
  });

  it('데이터 칩(이미 {{ }})을 끼워도 중첩 보간이 생기지 않는다', () => {
    const built = buildComputedExpr(
      { expr: '{source}?.data?.x' },
      { source: '{{ products }}' },
    );
    expect(built).toBe('{{ products?.data?.x }}');
    expect(built).not.toContain('{{ {{');
  });

  it('R-P2 권한 보기를 왕복한다', () => {
    const built = buildComputedExpr(READONLY_BY_ABILITY, {
      source: 'detail',
      abilityKey: 'can_update',
    });
    expect(built).toBe('{{ detail?.data?.abilities?.can_update !== true }}');
    const matched = matchComputed(built, [READONLY_BY_ABILITY]);
    expect(matched?.recipeId).toBe('computed.preset.readonly_by_ability');
    expect(matched?.params).toEqual({ source: 'detail', abilityKey: 'can_update' });
  });

  it('매칭 안 되는 식은 null', () => {
    expect(matchComputed('{{ totally.different }}', [FILTER_DEFAULT])).toBeNull();
  });

  // R-P8: 먼저 있는 값 고르기 — 가변 후보(`{candidates*}`) ?? 체인 (인덱스
  // 플레이스홀더 미치환 결함 수정). candidates 는 쉼표 구분 다중 → ` ?? ` 체인 동적 생성/역해석.
  const FIRST_OF: NormalizedComputedRecipe = {
    id: 'first_of',
    label: '$t:first_of',
    params: [
      { key: 'candidates', widget: 'binding-list' },
      { key: 'fallback', widget: 'text' },
    ],
    expr: "{candidates*} ?? '{fallback}'",
  };

  it('R-P8 first_of 후보 2개 — 가변 토큰을 ?? 체인으로 생성하고 쉼표로 역해석', () => {
    const built = buildComputedExpr(FIRST_OF, {
      candidates: 'product.data.name, product.data.title',
      fallback: '없음',
    });
    expect(built).toBe("{{ product.data.name ?? product.data.title ?? '없음' }}");
    expect(built).not.toContain('candidates'); // 플레이스홀더 잔존(깨진 식) 0
    const matched = matchComputed(built, [FIRST_OF]);
    expect(matched?.recipeId).toBe('first_of');
    expect(matched?.params).toEqual({
      candidates: 'product.data.name, product.data.title',
      fallback: '없음',
    });
  });

  it('R-P8 first_of 후보 3개 — 가변 길이 체인', () => {
    const built = buildComputedExpr(FIRST_OF, { candidates: 'a, b, c', fallback: 'x' });
    expect(built).toBe("{{ a ?? b ?? c ?? 'x' }}");
    const matched = matchComputed(built, [FIRST_OF]);
    expect(matched?.params.candidates).toBe('a, b, c');
  });

  it('R-P8 first_of 후보 1개 — 단일 체인도 동작', () => {
    const built = buildComputedExpr(FIRST_OF, { candidates: 'only.one', fallback: 'fb' });
    expect(built).toBe("{{ only.one ?? 'fb' }}");
  });

  it('R-P8 first_of 빈 후보(프리셋 빈 추가) — 플레이스홀더 {candidates*} 가 값으로 새지 않음', () => {
    const built = buildComputedExpr(FIRST_OF, {});
    // candidates 미입력 → 빈 문자열(체인에서 사라짐). `{candidates*}` 리터럴 잔존 0.
    expect(built).not.toContain('candidates');
    expect(built).not.toContain('*');
    // 앞이 `?? ` 로 시작하는 깨진 식이 아니어야 한다(빈 후보면 연결자도 제거).
    expect(built).not.toMatch(/\{\{\s*\?\?/);
  });

  it('R-P8 first_of 빈 추가 식도 프리셋으로 매칭(고급으로 안 빠짐) — 친화 편집 유지', () => {
    // 빈 추가 → `{{ '{fallback}' }}` (candidates·fallback 미입력). 가변 토큰 0개 + 옵셔널
    // 연결자로 first_of 패턴에 매칭돼야 [고급]이 아니라 친화 카드로 남아 후보를 채울 수 있다.
    const builtEmpty = buildComputedExpr(FIRST_OF, {});
    const matched = matchComputed(builtEmpty, [FIRST_OF]);
    expect(matched?.recipeId).toBe('first_of');
    // 후보 1개만 채운 경우도 매칭.
    const built1 = buildComputedExpr(FIRST_OF, { candidates: 'a', fallback: 'fb' });
    expect(matchComputed(built1, [FIRST_OF])?.params.candidates).toBe('a');
  });
});

describe('buildCustomComputedExpr ↔ matchCustomComputed (3단계 틀 7동사)', () => {
  it('count 동사 — 조건 있는 개수', () => {
    const model: CustomComputedModel = {
      key: 'activeCount',
      source: 'products.data.data',
      op: 'count',
      conditions: [{ field: 'status', cmp: '=', value: 'active' }],
    };
    const built = buildCustomComputedExpr(model);
    expect(built).toBe("{{ (products.data.data ?? []).filter(x => x.status === 'active').length }}");
    const matched = matchCustomComputed(built);
    expect(matched?.op).toBe('count');
    expect(matched?.source).toBe('products.data.data');
    expect(matched?.conditions).toEqual([{ field: 'status', cmp: '=', value: 'active' }]);
  });

  it('filter 동사 — 다중 조건 AND', () => {
    const model: CustomComputedModel = {
      key: 'onSale',
      source: 'items',
      op: 'filter',
      conditions: [
        { field: 'on_sale', cmp: '=', value: 'true' },
        { field: 'stock', cmp: '>', value: '0' },
      ],
    };
    const built = buildCustomComputedExpr(model);
    expect(built).toBe('{{ (items ?? []).filter(x => x.on_sale === true && x.stock > 0) }}');
    const matched = matchCustomComputed(built);
    expect(matched?.op).toBe('filter');
    expect(matched?.conditions).toEqual([
      { field: 'on_sale', cmp: '=', value: 'true' },
      { field: 'stock', cmp: '>', value: '0' },
    ]);
  });

  it('sum 동사 — 합산 필드', () => {
    const model: CustomComputedModel = {
      key: 'total',
      source: 'cart.items',
      op: 'sum',
      sumField: 'price',
      conditions: [],
    };
    const built = buildCustomComputedExpr(model);
    expect(built).toBe(
      '{{ (cart.items ?? []).filter(x => true).reduce((s, x) => s + (x.price ?? 0), 0) }}',
    );
    const matched = matchCustomComputed(built);
    expect(matched?.op).toBe('sum');
    expect(matched?.sumField).toBe('price');
  });

  it('toOptions 동사 — value/label', () => {
    const model: CustomComputedModel = {
      key: 'opts',
      source: 'categories.data',
      op: 'toOptions',
      valueField: 'id',
      labelField: 'name',
    };
    const built = buildCustomComputedExpr(model);
    expect(built).toBe(
      '{{ (categories.data ?? []).map(x => ({ value: x.id, label: x.name })) }}',
    );
    const matched = matchCustomComputed(built);
    expect(matched?.op).toBe('toOptions');
    expect(matched?.valueField).toBe('id');
    expect(matched?.labelField).toBe('name');
  });

  it('nth 동사 — 인덱스 + 속성', () => {
    const model: CustomComputedModel = {
      key: 'first',
      source: 'tabs',
      op: 'nth',
      index: '0',
      prop: 'label',
    };
    const built = buildCustomComputedExpr(model);
    expect(built).toBe('{{ (tabs ?? [])[0]?.label }}');
    const matched = matchCustomComputed(built);
    expect(matched?.op).toBe('nth');
    expect(matched?.index).toBe('0');
    expect(matched?.prop).toBe('label');
  });

  it('includes 비교 조건', () => {
    const model: CustomComputedModel = {
      key: 'tagged',
      source: 'posts',
      op: 'filter',
      conditions: [{ field: 'tags', cmp: 'includes', value: 'news' }],
    };
    const built = buildCustomComputedExpr(model);
    expect(built).toBe("{{ (posts ?? []).filter(x => (x.tags ?? []).includes('news')) }}");
    const matched = matchCustomComputed(built);
    expect(matched?.conditions).toEqual([{ field: 'tags', cmp: 'includes', value: 'news' }]);
  });
});

describe('resolveComputedCard 우선순위', () => {
  it('프리셋이 먼저 매칭된다', () => {
    const built = buildComputedExpr(FILTER_DEFAULT, {
      localPath: 'category',
      queryKey: 'cat',
      fallback: 'all',
    });
    const card = resolveComputedCard(built, [FILTER_DEFAULT]);
    expect(card.kind).toBe('preset');
  });

  it('프리셋 미매칭이면 3단계 틀로 환원', () => {
    const built = '{{ (items ?? []).filter(x => x.status === \'active\') }}';
    const card = resolveComputedCard(built, [FILTER_DEFAULT]);
    expect(card.kind).toBe('custom');
  });

  it('틀로도 표현 못 하는 복잡 식은 advanced', () => {
    const card = resolveComputedCard(
      '{{ (() => { const m = {}; return m; })() }}',
      [FILTER_DEFAULT],
    );
    expect(card.kind).toBe('advanced');
  });
});

describe('evaluateComputedPreview (엔진 evaluator 위임)', () => {
  it('샘플 컨텍스트로 값/타입을 평가한다', () => {
    const r = evaluateComputedPreview('{{ (items ?? []).length }}', {
      items: [1, 2, 3],
    });
    expect(r).toEqual({ ok: true, value: 3, type: 'number' });
  });

  it('리스트 타입을 분류한다', () => {
    const r = evaluateComputedPreview('{{ items }}', { items: [{ a: 1 }] });
    expect(r.ok).toBe(true);
    if (r.ok) expect(r.type).toBe('list');
  });

  it('빈 식은 ok:false', () => {
    expect(evaluateComputedPreview('', {})).toEqual({ ok: false, reason: 'empty' });
  });
});
