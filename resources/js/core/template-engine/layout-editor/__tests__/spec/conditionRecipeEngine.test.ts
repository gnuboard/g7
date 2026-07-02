/**
 * conditionRecipeEngine.test.ts — 친화 조건 → if 식 합성 DataProvider
 *
 * 코어 `if` 규칙 회귀 가드:
 *  - 최종 결과 전체가 단일 `{{ }}` 한 쌍 — 중첩 보간 `{{ {{x}} }}` 미발생.
 *  - 옵셔널 체이닝(`?.`) / 배열 길이 `(arr?.length ?? 0)` 형태 보존.
 *  - 로그인 판정 경로 보존(임의 식 금지).
 */

import { describe, it, expect } from 'vitest';
import {
  normalizeConditionRecipes,
  normalizeConditionExpr,
  buildClauseExpr,
  combineConditions,
  matchSinglePreset,
  parseConditionExpr,
} from '../../spec/conditionRecipeEngine';

const RECIPES = {
  comment: '메모',
  operators: [
    { value: 'isLoggedIn', label: '$t:editor.condition.is_logged_in', expr: '_global?.currentUser?.uuid' },
    { value: 'isGuest', label: 'guest', expr: '!_global?.currentUser?.uuid' },
    { value: 'dsHasData', label: 'has', params: [{ key: 'src', widget: 'datasource-picker' }], expr: '({src}?.data?.length ?? 0) > 0' },
    { value: 'fieldEquals', label: 'eq', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} === '{value}'" },
    { value: 'fieldHasError', label: 'err', params: [{ key: 'field' }], expr: "!!_local?.errors?.['{field}']" },
  ],
};

const ops = normalizeConditionRecipes(RECIPES);

describe('normalizeConditionRecipes', () => {
  it('operators 배열을 정규화한다 (expr 본문 보존)', () => {
    expect(ops.map((o) => o.value)).toEqual(['isLoggedIn', 'isGuest', 'dsHasData', 'fieldEquals', 'fieldHasError']);
    expect(ops[0].expr).toBe('_global?.currentUser?.uuid');
  });

  it('S6-2 평탄 맵({id:{label,expr-with-braces}})을 흡수하고 {{ }} 를 벗긴다', () => {
    const flat = normalizeConditionRecipes({
      comment: 'm',
      isAdmin: { label: 'admin', expr: '{{_global?.currentUser?.is_admin === true}}' },
    });
    expect(flat[0]).toMatchObject({ value: 'isAdmin', expr: '_global?.currentUser?.is_admin === true' });
  });
});

describe('buildClauseExpr — {paramKey} 치환', () => {
  it('파라미터 없는 프리셋은 expr 그대로', () => {
    expect(buildClauseExpr({ operator: 'isLoggedIn', params: {} }, ops)).toBe('_global?.currentUser?.uuid');
  });

  it('{src} 치환 — 옵셔널 체이닝 + 배열 길이 ?? 0 형태 보존', () => {
    const e = buildClauseExpr({ operator: 'dsHasData', params: { src: 'posts' } }, ops);
    expect(e).toBe('(posts?.data?.length ?? 0) > 0');
  });

  it('{field}/{value} 치환', () => {
    const e = buildClauseExpr({ operator: 'fieldEquals', params: { field: 'status', value: 'open' } }, ops);
    expect(e).toBe("status === 'open'");
  });

  it('미발견 operator → null', () => {
    expect(buildClauseExpr({ operator: 'nope', params: {} }, ops)).toBeNull();
  });
});

describe('combineConditions — 단일 {{ }} 합성 (코어 if 규칙)', () => {
  it('단일 조건 → {{ 식 }}', () => {
    const r = combineConditions([{ operator: 'isLoggedIn', params: {} }], 'and', ops);
    expect(r).toBe('{{ _global?.currentUser?.uuid }}');
  });

  it('AND 결합 — 각 절 괄호 + && + 전체 한 쌍 {{ }}', () => {
    const r = combineConditions(
      [{ operator: 'isLoggedIn', params: {} }, { operator: 'dsHasData', params: { src: 'posts' } }],
      'and',
      ops,
    );
    expect(r).toBe('{{ (_global?.currentUser?.uuid) && ((posts?.data?.length ?? 0) > 0) }}');
  });

  it('OR 결합', () => {
    const r = combineConditions(
      [{ operator: 'isLoggedIn', params: {} }, { operator: 'isGuest', params: {} }],
      'or',
      ops,
    );
    expect(r).toBe('{{ (_global?.currentUser?.uuid) || (!_global?.currentUser?.uuid) }}');
  });

  it('중첩 보간 {{ {{x}} }} 를 만들지 않는다', () => {
    const r = combineConditions(
      [{ operator: 'fieldEquals', params: { field: 'a', value: 'b' } }, { operator: 'isLoggedIn', params: {} }],
      'and',
      ops,
    );
    // {{ 가 정확히 1개, }} 가 정확히 1개
    expect((r.match(/\{\{/g) ?? []).length).toBe(1);
    expect((r.match(/\}\}/g) ?? []).length).toBe(1);
  });

  it('빈 절 목록 → 빈 문자열 (if 제거)', () => {
    expect(combineConditions([], 'and', ops)).toBe('');
  });

  it('fieldHasError — 옵셔널 체이닝 인덱스 접근 보존', () => {
    const r = combineConditions([{ operator: 'fieldHasError', params: { field: 'email' } }], 'and', ops);
    expect(r).toBe("{{ !!_local?.errors?.['email'] }}");
  });
});

describe('matchSinglePreset — 역해석', () => {
  it('파라미터 없는 단일 프리셋 식 → operator 복원', () => {
    expect(matchSinglePreset('{{ _global?.currentUser?.uuid }}', ops)).toBe('isLoggedIn');
    expect(matchSinglePreset('{{_global?.currentUser?.uuid}}', ops)).toBe('isLoggedIn');
  });

  it('파라미터 있는 조건은 모호 → null (고급 표시)', () => {
    expect(matchSinglePreset('{{ (posts?.data?.length ?? 0) > 0 }}', ops)).toBeNull();
  });

  it('알 수 없는 식 → null', () => {
    expect(matchSinglePreset('{{ someCustom.expr }}', ops)).toBeNull();
  });
});

describe('parseConditionExpr — 라운드트립 역해석 (modal remount 무상태 보존)', () => {
  it('단일 프리셋 → 절 1개', () => {
    const r = parseConditionExpr('{{ _global?.currentUser?.uuid }}', ops);
    expect(r).toEqual({ clauses: [{ operator: 'isLoggedIn', params: {} }], combinator: 'and' });
  });

  it('파라미터 조건 → 절 + 파라미터 값 복원', () => {
    const r = parseConditionExpr('{{ (posts?.data?.length ?? 0) > 0 }}', ops);
    expect(r?.clauses).toEqual([{ operator: 'dsHasData', params: { src: 'posts' } }]);
  });

  it('따옴표 값 조건 — fieldEquals 라운드트립', () => {
    const expr = combineConditions([{ operator: 'fieldEquals', params: { field: 'status', value: 'open' } }], 'and', ops);
    const r = parseConditionExpr(expr, ops);
    expect(r?.clauses).toEqual([{ operator: 'fieldEquals', params: { field: 'status', value: 'open' } }]);
  });

  it('AND 결합 라운드트립 (combineConditions → parseConditionExpr 동치)', () => {
    const clauses = [
      { operator: 'isLoggedIn', params: {} },
      { operator: 'dsHasData', params: { src: 'posts' } },
    ];
    const expr = combineConditions(clauses, 'and', ops);
    const r = parseConditionExpr(expr, ops);
    expect(r?.combinator).toBe('and');
    expect(r?.clauses).toEqual(clauses);
  });

  it('OR 결합 라운드트립', () => {
    const clauses = [
      { operator: 'isLoggedIn', params: {} },
      { operator: 'isGuest', params: {} },
    ];
    const expr = combineConditions(clauses, 'or', ops);
    const r = parseConditionExpr(expr, ops);
    expect(r).toEqual({ clauses, combinator: 'or' });
  });

  it('손작성 임의 식 → null (고급 취급)', () => {
    expect(parseConditionExpr('{{ custom.weird && thing }}', ops)).toBeNull();
    expect(parseConditionExpr('', ops)).toBeNull();
    expect(parseConditionExpr(undefined, ops)).toBeNull();
  });
});

// 의미 보존 정규화 후 매칭(Bug2). 손작성 번들 레이아웃 식이 recipe expr 과
// `?.`/`!!`/공백/외곽괄호만 다를 때 프리셋으로 인식되어야 한다(고급으로 떨어지지 않음).
describe('normalizeConditionExpr — 의미 보존 정규화', () => {
  it('공백 제거 + 옵셔널 체이닝(?. → .) + 외곽 !! 흡수 + 외곽 괄호 1겹 제거', () => {
    expect(normalizeConditionExpr('_local?.isSaving')).toBe('_local.isSaving');
    expect(normalizeConditionExpr('_local.isSaving')).toBe('_local.isSaving');
    expect(normalizeConditionExpr('!!_local?.errors')).toBe('_local.errors');
    expect(normalizeConditionExpr('_local.errors')).toBe('_local.errors');
    expect(normalizeConditionExpr('!!route?.id')).toBe('route.id');
    expect(normalizeConditionExpr('route?.id')).toBe('route.id');
    expect(normalizeConditionExpr('a === b')).toBe('a===b');
    expect(normalizeConditionExpr('(x)')).toBe('x');
  });

  it('단항 부정(!)은 흡수하지 않는다 (의미 다름)', () => {
    expect(normalizeConditionExpr('!_local?.isSaving')).toBe('!_local.isSaving');
    // !! 만 흡수, 단일 ! 는 보존
    expect(normalizeConditionExpr('!x')).toBe('!x');
    expect(normalizeConditionExpr('!!x')).toBe('x');
    expect(normalizeConditionExpr('!!!x')).toBe('!x');
  });

  it('외곽 괄호가 식 전체를 감싸지 않으면 보존 ((a) && (b))', () => {
    expect(normalizeConditionExpr('(a) && (b)')).toBe('(a)&&(b)');
  });
});

describe('matchSinglePreset — 손작성 식 정규화 매칭 (Bug2 회귀)', () => {
  const REAL = normalizeConditionRecipes({
    operators: [
      { value: 'isSaving', label: 's', expr: '_local?.isSaving' },
      { value: 'notSaving', label: 'ns', expr: '!_local?.isSaving' },
      { value: 'hasErrors', label: 'e', expr: '!!_local?.errors' },
      { value: 'isLoggedIn', label: 'li', expr: '_global?.currentUser?.uuid' },
      { value: 'isEditMode', label: 'edit', expr: '!!route?.id' },
    ],
  });

  it('_local.isSaving(손작성) → isSaving 프리셋 (recipe 는 _local?.isSaving)', () => {
    expect(matchSinglePreset('{{_local.isSaving}}', REAL)).toBe('isSaving');
  });
  it('!_local.isSaving → notSaving (단항 부정 보존)', () => {
    expect(matchSinglePreset('{{!_local.isSaving}}', REAL)).toBe('notSaving');
  });
  it('_local.errors → hasErrors (recipe 는 !!_local?.errors)', () => {
    expect(matchSinglePreset('{{_local.errors}}', REAL)).toBe('hasErrors');
  });
  it('_global.currentUser?.uuid → isLoggedIn (recipe 는 _global?.currentUser?.uuid)', () => {
    expect(matchSinglePreset('{{_global.currentUser?.uuid}}', REAL)).toBe('isLoggedIn');
  });
  it('route?.id → isEditMode (recipe 는 !!route?.id)', () => {
    expect(matchSinglePreset('{{route?.id}}', REAL)).toBe('isEditMode');
  });
  it('대응 recipe 없는 자유 식 → null (고급 유지)', () => {
    expect(matchSinglePreset('{{_local.success}}', REAL)).toBeNull();
  });
});

describe('parseConditionExpr — 손작성 식 정규화 매칭 (Bug2 회귀)', () => {
  const REAL = normalizeConditionRecipes({
    operators: [
      { value: 'isSaving', label: 's', expr: '_local?.isSaving' },
      { value: 'hasErrors', label: 'e', expr: '!!_local?.errors' },
      { value: 'dsHasData', label: 'd', params: [{ key: 'src', widget: 'datasource-picker' }], expr: '({src}?.data?.length ?? 0) > 0' },
    ],
  });

  it('단일 손작성 식 → 프리셋 절 복원', () => {
    expect(parseConditionExpr('{{_local.isSaving}}', REAL)).toEqual({
      clauses: [{ operator: 'isSaving', params: {} }],
      combinator: 'and',
    });
    expect(parseConditionExpr('{{_local.errors}}', REAL)).toEqual({
      clauses: [{ operator: 'hasErrors', params: {} }],
      combinator: 'and',
    });
  });

  it('파라미터 식 — 손작성 ?. 차이 흡수 후 src 추출', () => {
    // recipe: ({src}?.data?.length ?? 0) > 0, 손작성: (posts.data.length ?? 0) > 0
    const r = parseConditionExpr('{{(posts.data.length ?? 0) > 0}}', REAL);
    expect(r?.clauses).toEqual([{ operator: 'dsHasData', params: { src: 'posts' } }]);
  });

  it('다른 shape 의 파라미터 식은 매칭 안 됨 (정상 — 고급 유지)', () => {
    // data.items.modules.length 는 recipe 의 data.length 와 경로가 달라 미매칭
    expect(parseConditionExpr('{{(x?.data?.items?.modules?.length ?? 0) > 0}}', REAL)).toBeNull();
  });

  // 탐욕적 캡처가 top-level &&/|| 를 삼켜 compound 식을 단일 프리셋으로
  // 오매칭하던 false-positive 회귀. compound 식은 단일 절 매칭에서 제외되어 advanced 유지.
  it('compound 식(A && B)은 단일 fieldEquals/fieldNotEquals 로 오매칭하지 않음 (고급 유지)', () => {
    const SPEC = normalizeConditionRecipes({
      operators: [
        { value: 'fieldEquals', label: 'eq', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} === '{value}'" },
        { value: 'fieldNotEquals', label: 'neq', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} !== '{value}'" },
      ],
    });
    // `A && B !== 'x'` → field 가 `A && B` 를 삼키면 안 됨 → 미매칭(고급)
    expect(parseConditionExpr("{{_global.x?.id && _global.x?.kind !== 'inicis'}}", SPEC)).toBeNull();
    // `!A || B === 'x'` → 미매칭(고급)
    expect(parseConditionExpr("{{!_global.x?.id || _global.x?.tab === 'file'}}", SPEC)).toBeNull();
    // 단순 단일 비교는 여전히 매칭
    expect(parseConditionExpr("{{_global.x?.tab === 'file'}}", SPEC)?.clauses).toEqual([
      { operator: 'fieldEquals', params: { field: '_global.x.tab', value: 'file' } },
    ]);
  });
});

// ──변종 방어 인프라 (L2 alias / 생성 canonical / L4 specificity / path-shape 가드) ──

describe('L2 recipe-local alias — 역해석은 변종 흡수, 생성은 canonical', () => {
  // 파라미터 없는 프리셋 — canonical + alias 표기 변종
  const NO_PARAM = normalizeConditionRecipes({
    operators: [
      {
        value: 'hasSuccess',
        label: 's',
        expr: '_local?.success',
        aliases: ['!!_local?.success', '_local?.success === true'],
      },
    ],
  });

  it('canonical expr 매칭', () => {
    expect(matchSinglePreset('{{_local.success}}', NO_PARAM)).toBe('hasSuccess');
  });
  it('alias(=== true) 매칭 — L1 이 흡수 못하는 준동치 변종', () => {
    expect(matchSinglePreset('{{_local.success === true}}', NO_PARAM)).toBe('hasSuccess');
  });
  it('alias(!! 표기) 매칭', () => {
    // !! 는 L1 이 이미 흡수하므로 canonical 로도 매칭되지만 alias 경로도 안전.
    expect(matchSinglePreset('{{!!_local.success}}', NO_PARAM)).toBe('hasSuccess');
  });

  // 파라미터 보유 프리셋 — 배열 길이 3변종을 canonical + aliases 로 1프리셋 흡수
  const LIST = normalizeConditionRecipes({
    operators: [
      {
        value: 'listNonEmpty',
        label: 'l',
        params: [{ key: 'path', widget: 'datasource-picker' }],
        expr: '({path}?.length ?? 0) > 0',
        aliases: ['{path} && {path}.length > 0', '{path}?.length > 0'],
      },
    ],
  });

  it('canonical (X?.length ?? 0) > 0 → path 추출', () => {
    expect(parseConditionExpr('{{(items?.length ?? 0) > 0}}', LIST)?.clauses).toEqual([
      { operator: 'listNonEmpty', params: { path: 'items' } },
    ]);
  });
  it('alias X?.length > 0 → path 추출 (준동치 변종 흡수)', () => {
    expect(parseConditionExpr('{{items?.length > 0}}', LIST)?.clauses).toEqual([
      { operator: 'listNonEmpty', params: { path: 'items' } },
    ]);
  });

  it('생성(combineConditions)은 항상 canonical expr — alias 로 생성하지 않음', () => {
    const r = combineConditions([{ operator: 'listNonEmpty', params: { path: 'items' } }], 'and', LIST);
    expect(r).toBe('{{ (items?.length ?? 0) > 0 }}');
  });

  it('aliases 미정의 recipe 도 정상 (빈 배열)', () => {
    const ops0 = normalizeConditionRecipes({ operators: [{ value: 'x', expr: '_local?.x' }] });
    expect(ops0[0].aliases).toEqual([]);
    expect(matchSinglePreset('{{_local.x}}', ops0)).toBe('x');
  });
});

describe('L4 specificity 우선 — 범용 truthy 가 구체 프리셋을 가로채지 않음', () => {
  // 범용 valueTruthy 를 **앞**에 선언하더라도, 구체 isSaving 이 우선 매칭돼야 한다.
  const SPEC = normalizeConditionRecipes({
    operators: [
      { value: 'valueTruthy', label: 't', params: [{ key: 'path' }], expr: '{path}' },
      { value: 'isSaving', label: 's', expr: '_local?.isSaving' },
    ],
  });

  it('_local.isSaving → isSaving (파라미터 0개 프리셋 우선, 범용 {path} 아님)', () => {
    expect(matchSinglePreset('{{_local.isSaving}}', SPEC)).toBe('isSaving');
    expect(parseConditionExpr('{{_local.isSaving}}', SPEC)?.clauses).toEqual([
      { operator: 'isSaving', params: {} },
    ]);
  });

  it('구체 프리셋과 무관한 임의 경로는 범용 valueTruthy 로 매칭', () => {
    expect(parseConditionExpr('{{some.other.path}}', SPEC)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: 'some.other.path' } },
    ]);
  });
});

describe('path-shape 가드 — 범용 {path} 가 비교/compound 를 삼키지 않음', () => {
  const SPEC = normalizeConditionRecipes({
    operators: [{ value: 'valueTruthy', label: 't', params: [{ key: 'path' }], expr: '{path}' }],
  });

  it('순수 경로만 valueTruthy 로 인식', () => {
    expect(parseConditionExpr('{{user.avatar_url}}', SPEC)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: 'user.avatar_url' } },
    ]);
  });

  it('비교 연산자 포함 식은 거부 (=== / > / < 등)', () => {
    expect(parseConditionExpr('{{count > 0}}', SPEC)).toBeNull();
    expect(parseConditionExpr('{{status === 1}}', SPEC)).toBeNull();
    expect(parseConditionExpr('{{a !== b}}', SPEC)).toBeNull();
  });

  it('그룹 괄호/삼항 포함 식은 거부', () => {
    expect(parseConditionExpr('{{(a ?? []).length}}', SPEC)).toBeNull();
    expect(parseConditionExpr('{{flag ? x : y}}', SPEC)).toBeNull();
  });

  it('따옴표 자유 리터럴 파라미터는 path-shape 가드 미적용 (비교 연산자 OK)', () => {
    // fieldEquals 의 value 는 따옴표 캡처 → 임의 문자열 허용
    const EQ = normalizeConditionRecipes({
      operators: [{ value: 'fieldEquals', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} === '{value}'" }],
    });
    expect(parseConditionExpr("{{status === 'a-b/c'}}", EQ)?.clauses).toEqual([
      { operator: 'fieldEquals', params: { field: 'status', value: 'a-b/c' } },
    ]);
  });
});

// ──P1 값 truthy/falsy 범용 프리셋 (valueTruthy/valueFalsy) ──
// 출고 recipes(templates/_bundled/*/editor-spec/conditionRecipes.json)와 동일 형태의
// 범용 1-param 프리셋이 임의 경로 truthy/falsy 식을 인식하되, 비교/compound 는 거부하고
// 구체 프리셋(0-param)을 가로채지 않는지(L4)를 가드한다.
describe('P1 valueTruthy/valueFalsy — 임의 경로 truthy/falsy 인식', () => {
  // 출고 recipes 의 P1 + 대표 구체 프리셋 구성(선언 순서: 구체 → 범용, 출고와 동일).
  const P1 = normalizeConditionRecipes({
    operators: [
      { value: 'isLoggedIn', label: 'li', expr: '_global?.currentUser?.uuid' },
      { value: 'isGuest', label: 'g', expr: '!_global?.currentUser?.uuid' },
      { value: 'isSaving', label: 's', expr: '_local?.isSaving' },
      { value: 'notSaving', label: 'ns', expr: '!_local?.isSaving' },
      { value: 'valueTruthy', label: 't', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '{path}' },
      { value: 'valueFalsy', label: 'f', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '!{path}' },
    ],
  });

  it('범용 truthy — 임의 경로(상태/데이터) 인식 + path 추출', () => {
    expect(parseConditionExpr('{{_global.isDeleting}}', P1)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: '_global.isDeleting' } },
    ]);
    expect(parseConditionExpr('{{_local.isSubmitting}}', P1)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: '_local.isSubmitting' } },
    ]);
    // 손작성 ?. 변종도 정규화로 흡수
    expect(parseConditionExpr('{{current_user.data?.avatar_url}}', P1)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: 'current_user.data.avatar_url' } },
    ]);
    // 깊은 경로
    expect(parseConditionExpr('{{_global.settings?.general?.site_logo_url}}', P1)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: '_global.settings.general.site_logo_url' } },
    ]);
  });

  it('범용 falsy — !경로 인식 + path 추출(부정 기호 제외)', () => {
    expect(parseConditionExpr('{{!_local.success}}', P1)?.clauses).toEqual([
      { operator: 'valueFalsy', params: { path: '_local.success' } },
    ]);
    expect(parseConditionExpr('{{!_global.settings?.general?.site_logo_url}}', P1)?.clauses).toEqual([
      { operator: 'valueFalsy', params: { path: '_global.settings.general.site_logo_url' } },
    ]);
  });

  it('L4 — 구체 프리셋(0-param)이 범용 truthy/falsy 를 가로채지 않음', () => {
    // _local.isSaving 은 isSaving(구체)으로, valueTruthy(범용) 아님
    expect(parseConditionExpr('{{_local.isSaving}}', P1)?.clauses).toEqual([{ operator: 'isSaving', params: {} }]);
    expect(parseConditionExpr('{{!_local.isSaving}}', P1)?.clauses).toEqual([{ operator: 'notSaving', params: {} }]);
    // 로그인 경로도 구체 우선
    expect(parseConditionExpr('{{_global.currentUser?.uuid}}', P1)?.clauses).toEqual([{ operator: 'isLoggedIn', params: {} }]);
    expect(parseConditionExpr('{{!_global.currentUser?.uuid}}', P1)?.clauses).toEqual([{ operator: 'isGuest', params: {} }]);
  });

  it('음성 — 비교/배열길이/compound 는 범용 truthy/falsy 로 오매칭하지 않음 (advanced 유지)', () => {
    // 숫자 비교
    expect(parseConditionExpr('{{_global.cartCount > 0}}', P1)).toBeNull();
    expect(parseConditionExpr('{{$locales.length > 1}}', P1)).toBeNull();
    // 배열길이 ?? 0
    expect(parseConditionExpr('{{(admin_menu.data ?? []).length > 0}}', P1)).toBeNull();
    // 명시 비교
    expect(parseConditionExpr('{{_global.currentUser?.is_admin === true}}', P1)).toBeNull();
    // compound (A && !B)
    expect(parseConditionExpr('{{_local.forgotError && !_local.forgotErrors}}', P1)).toBeNull();
    expect(parseConditionExpr('{{!_local.success && !_local.tokenError}}', P1)).toBeNull();
  });

  it('생성(combineConditions)은 canonical expr — valueTruthy={path}, valueFalsy=!{path}', () => {
    expect(combineConditions([{ operator: 'valueTruthy', params: { path: '_global.isDeleting' } }], 'and', P1)).toBe(
      '{{ _global.isDeleting }}',
    );
    expect(combineConditions([{ operator: 'valueFalsy', params: { path: '_local.success' } }], 'and', P1)).toBe(
      '{{ !_local.success }}',
    );
  });

  it('AND 결합 라운드트립 — 범용 truthy 2개', () => {
    const clauses = [
      { operator: 'valueTruthy', params: { path: '_global.isDeleting' } },
      { operator: 'valueFalsy', params: { path: '_local.success' } },
    ];
    const expr = combineConditions(clauses, 'and', P1);
    expect(parseConditionExpr(expr, P1)).toEqual({ clauses, combinator: 'and' });
  });
});

// ──P2 숫자비교(numGt/Gte/Lt/Lte) + P3 배열길이(listNonEmpty/listEmpty) ──
// 출고 recipes 와 동일 형태로 숫자 비교(2-param)·배열길이(1-param + L2 alias 3변종)를
// 인식하되, compound·반복키 false-positive 를 거부하고 specificity(`>=` > `>`)로 모호성을
// 해소하는지 가드한다.
describe('P2/P3 숫자비교·배열길이 프리셋', () => {
  // 출고 recipes 와 동일 선언 순서(구체 → P1 → P3 → P2).
  const P2P3 = normalizeConditionRecipes({
    operators: [
      { value: 'isSaving', label: 's', expr: '_local?.isSaving' },
      { value: 'dsHasData', label: 'd', params: [{ key: 'src', widget: 'datasource-picker' }], expr: '({src}?.data?.length ?? 0) > 0' },
      { value: 'dsEmpty', label: 'de', params: [{ key: 'src', widget: 'datasource-picker' }], expr: '({src}?.data?.length ?? 0) === 0' },
      { value: 'valueTruthy', label: 't', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '{path}' },
      { value: 'valueFalsy', label: 'f', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '!{path}' },
      {
        value: 'listNonEmpty', label: 'ln', params: [{ key: 'path', widget: 'state-key-picker' }],
        expr: '({path} ?? []).length > 0',
        aliases: ['{path} && {path}.length > 0', '{path}?.length > 0', '({path}?.length ?? 0) > 0'],
      },
      {
        value: 'listEmpty', label: 'le', params: [{ key: 'path', widget: 'state-key-picker' }],
        expr: '({path} ?? []).length === 0',
        aliases: ['!{path} || {path}.length === 0', '{path}?.length === 0', '({path}?.length ?? 0) === 0'],
      },
      { value: 'numGte', label: 'nge', params: [{ key: 'path', widget: 'state-key-picker' }, { key: 'n', widget: 'text' }], expr: '{path} >= {n}' },
      { value: 'numLte', label: 'nle', params: [{ key: 'path', widget: 'state-key-picker' }, { key: 'n', widget: 'text' }], expr: '{path} <= {n}' },
      { value: 'numGt', label: 'ng', params: [{ key: 'path', widget: 'state-key-picker' }, { key: 'n', widget: 'text' }], expr: '{path} > {n}' },
      { value: 'numLt', label: 'nl', params: [{ key: 'path', widget: 'state-key-picker' }, { key: 'n', widget: 'text' }], expr: '{path} < {n}' },
    ],
  });

  it('P2 numGt — 실데이터 미커버 식 인식 + path/n 추출', () => {
    expect(parseConditionExpr('{{_global.cartCount > 0}}', P2P3)?.clauses).toEqual([
      { operator: 'numGt', params: { path: '_global.cartCount', n: '0' } },
    ]);
    expect(parseConditionExpr('{{$locales.length > 1}}', P2P3)?.clauses).toEqual([
      { operator: 'numGt', params: { path: '$locales.length', n: '1' } },
    ]);
    expect(parseConditionExpr('{{board.post_count > 0}}', P2P3)?.clauses).toEqual([
      { operator: 'numGt', params: { path: 'board.post_count', n: '0' } },
    ]);
    // 옵셔널 체이닝 손작성 변종 흡수
    expect(parseConditionExpr('{{_global.statusCountsData?.deleted_count > 0}}', P2P3)?.clauses).toEqual([
      { operator: 'numGt', params: { path: '_global.statusCountsData.deleted_count', n: '0' } },
    ]);
  });

  it('P2 numGte/numLt/numLte 인식', () => {
    expect(parseConditionExpr('{{remainingSeconds >= 10}}', P2P3)?.clauses).toEqual([
      { operator: 'numGte', params: { path: 'remainingSeconds', n: '10' } },
    ]);
    expect(parseConditionExpr('{{count < 3}}', P2P3)?.clauses).toEqual([
      { operator: 'numLt', params: { path: 'count', n: '3' } },
    ]);
    expect(parseConditionExpr('{{count <= 5}}', P2P3)?.clauses).toEqual([
      { operator: 'numLte', params: { path: 'count', n: '5' } },
    ]);
  });

  it('L4 specificity — `>=` 가 `>` 보다 우선(리터럴 길이) → numGt 오매칭 차단', () => {
    // `a >= 1` 은 numGte. numGt(`{path} > {n}`)가 `{path}`=`a >`, `{n}`=`1` 로 삼키면 path-shape
    // 가드가 거부하므로 어차피 numGt 는 안 되지만, specificity 로도 numGte 가 먼저다.
    expect(parseConditionExpr('{{a >= 1}}', P2P3)?.clauses).toEqual([
      { operator: 'numGte', params: { path: 'a', n: '1' } },
    ]);
    expect(parseConditionExpr('{{a <= 1}}', P2P3)?.clauses).toEqual([
      { operator: 'numLte', params: { path: 'a', n: '1' } },
    ]);
  });

  it('P3 listNonEmpty — canonical (X ?? []).length > 0 + path 추출', () => {
    expect(parseConditionExpr('{{(admin_menu.data ?? []).length > 0}}', P2P3)?.clauses).toEqual([
      { operator: 'listNonEmpty', params: { path: 'admin_menu.data' } },
    ]);
  });

  it('P3 listNonEmpty — L2 alias 구조 변종 흡수(X && X.length>0 / X?.length>0)', () => {
    // alias 1: X && X.length > 0 (반복 키 — backreference 일치 필수)
    expect(parseConditionExpr('{{recent_posts.data && recent_posts.data.length > 0}}', P2P3)?.clauses).toEqual([
      { operator: 'listNonEmpty', params: { path: 'recent_posts.data' } },
    ]);
    // alias 2: X?.length > 0
    expect(parseConditionExpr('{{items?.length > 0}}', P2P3)?.clauses).toEqual([
      { operator: 'listNonEmpty', params: { path: 'items' } },
    ]);
  });

  it('P3 listEmpty — canonical + alias 인식', () => {
    expect(parseConditionExpr('{{(admin_menu.data ?? []).length === 0}}', P2P3)?.clauses).toEqual([
      { operator: 'listEmpty', params: { path: 'admin_menu.data' } },
    ]);
    expect(parseConditionExpr('{{items?.length === 0}}', P2P3)?.clauses).toEqual([
      { operator: 'listEmpty', params: { path: 'items' } },
    ]);
  });

  it('음성 — 반복 키 alias 가 서로 다른 두 식(compound)을 삼키지 않음 (backreference 검증)', () => {
    // `!a && b.length > 0` — alias `{path} && {path}.length > 0` 의 두 캡처(!a, b)가 불일치 →
    // backreference 검증으로 거부. compound 이므로 advanced 유지.
    expect(parseConditionExpr('{{!a && b.length > 0}}', P2P3)).toBeNull();
    // `x && y.length > 0` (경로는 같지 않음) → 거부
    expect(parseConditionExpr('{{x && y.length > 0}}', P2P3)).toBeNull();
  });

  it('음성 — compound 숫자비교는 단일 numGt 로 오매칭하지 않음 (advanced 유지)', () => {
    expect(parseConditionExpr('{{!_global.statusCountsLoading && _global.statusCountsData?.deleted_count > 0}}', P2P3)).toBeNull();
    expect(parseConditionExpr('{{admin_menu.success === true && (admin_menu.data ?? []).length === 0}}', P2P3)).toBeNull();
  });

  it('생성(combineConditions)은 canonical expr — alias 로 생성하지 않음', () => {
    expect(combineConditions([{ operator: 'numGt', params: { path: '_global.cartCount', n: '0' } }], 'and', P2P3)).toBe(
      '{{ _global.cartCount > 0 }}',
    );
    expect(combineConditions([{ operator: 'listNonEmpty', params: { path: 'admin_menu.data' } }], 'and', P2P3)).toBe(
      '{{ (admin_menu.data ?? []).length > 0 }}',
    );
    expect(combineConditions([{ operator: 'listEmpty', params: { path: 'items' } }], 'and', P2P3)).toBe(
      '{{ (items ?? []).length === 0 }}',
    );
  });

  it('라운드트립 — P2/P3 생성 후 재해석 시 동일 절 복원', () => {
    const cases: { operator: string; params: Record<string, string> }[] = [
      { operator: 'numGt', params: { path: '_global.cartCount', n: '0' } },
      { operator: 'numGte', params: { path: 'remainingSeconds', n: '10' } },
      { operator: 'listNonEmpty', params: { path: 'admin_menu.data' } },
      { operator: 'listEmpty', params: { path: 'items' } },
    ];
    for (const clause of cases) {
      const expr = combineConditions([clause], 'and', P2P3);
      expect(parseConditionExpr(expr, P2P3)?.clauses).toEqual([clause]);
    }
  });
});

// ──P5 명시 비교(valueIsTrue/valueIsFalse) ──
// `{path} === true` / `{path} === false` (1-param) 를 인식하되, 의미가 다른 `!== true`/truthy
// (`!{path}`) 와 혼동하지 않고, compound 는 advanced 로 유지하는지 가드한다.
// P4(리스트 존재)는 단계 2 에서 listNonEmpty/listEmpty 의 L2 alias(`X && X.length>0` 등)로
// 이미 흡수됐으므로 별도 recipe 를 두지 않는다(L3 중복 방지) — 본 블록은 P5 만 다룬다.
describe('P5 valueIsTrue/valueIsFalse — 명시 비교 프리셋', () => {
  // 출고 recipes 와 동일 선언 순서(구체 → P1 → P5). 구체 0-param(isAdmin)이 specificity 우선.
  const P5 = normalizeConditionRecipes({
    operators: [
      { value: 'isAdmin', label: 'a', expr: '_global?.currentUser?.is_admin === true' },
      { value: 'isSaving', label: 's', expr: '_local?.isSaving' },
      { value: 'valueTruthy', label: 't', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '{path}' },
      { value: 'valueFalsy', label: 'f', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '!{path}' },
      { value: 'valueIsTrue', label: 'vt', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '{path} === true' },
      { value: 'valueIsFalse', label: 'vf', params: [{ key: 'path', widget: 'state-key-picker' }], expr: '{path} === false' },
    ],
  });

  it('P5 valueIsTrue — 실데이터 미커버 식 인식 + path 추출(옵셔널 체이닝 흡수)', () => {
    expect(parseConditionExpr('{{_local.showCategoryRemoveModal === true}}', P5)?.clauses).toEqual([
      { operator: 'valueIsTrue', params: { path: '_local.showCategoryRemoveModal' } },
    ]);
    // `?.` 손작성 변종 → 정규화로 흡수, path 는 clean(`.`)
    expect(parseConditionExpr('{{_local.form?.report_policy?.notify_admin_on_report === true}}', P5)?.clauses).toEqual([
      { operator: 'valueIsTrue', params: { path: '_local.form.report_policy.notify_admin_on_report' } },
    ]);
    expect(parseConditionExpr('{{category?.required === true}}', P5)?.clauses).toEqual([
      { operator: 'valueIsTrue', params: { path: 'category.required' } },
    ]);
  });

  it('P5 valueIsFalse — `=== false` 인식 + path 추출', () => {
    expect(parseConditionExpr('{{_local.bulkApplyAll === false}}', P5)?.clauses).toEqual([
      { operator: 'valueIsFalse', params: { path: '_local.bulkApplyAll' } },
    ]);
    expect(parseConditionExpr('{{_global.bulkApplySnapshot?.applyAll === false}}', P5)?.clauses).toEqual([
      { operator: 'valueIsFalse', params: { path: '_global.bulkApplySnapshot.applyAll' } },
    ]);
  });

  it('L4 specificity — 구체 0-param(isAdmin) 이 valueIsTrue 보다 우선', () => {
    // `_global.currentUser.is_admin === true` 는 isAdmin(0-param)으로 인식되어야지
    // valueIsTrue(`{path}=...is_admin`)로 흡수되면 안 된다(구체 우선).
    expect(matchSinglePreset('{{_global.currentUser?.is_admin === true}}', P5)).toBe('isAdmin');
    expect(parseConditionExpr('{{_global.currentUser?.is_admin === true}}', P5)?.clauses).toEqual([
      { operator: 'isAdmin', params: {} },
    ]);
  });

  it('음성 — `!== true` 는 valueIsTrue/valueIsFalse 어디에도 매칭 안 됨(의미 다름 → advanced)', () => {
    // `=== false` 와 `!== true` 는 (null/undefined 에서) 동치가 아니므로 흡수하지 않는다.
    expect(parseConditionExpr('{{page?.data?.abilities?.can_delete !== true}}', P5)).toBeNull();
    expect(parseConditionExpr('{{page?.data?.abilities?.can_delete !== false}}', P5)).toBeNull();
  });

  it('음성 — compound(`A === true && B`)는 단일 valueIsTrue 로 오매칭하지 않음(advanced)', () => {
    expect(parseConditionExpr("{{policy.source_type === 'admin' && policy.abilities?.can_delete === true}}", P5)).toBeNull();
    expect(parseConditionExpr('{{admin_menu.success === true && (admin_menu.data ?? []).length === 0}}', P5)).toBeNull();
  });

  it('음성 — 단순 truthy(`{path}`)는 valueIsTrue 로 승격되지 않음(=== true 명시 없음)', () => {
    // `_local.flag` 는 valueTruthy(== true 명시 없음). valueIsTrue 로 잘못 잡히면 안 됨.
    expect(parseConditionExpr('{{_local.flag}}', P5)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: '_local.flag' } },
    ]);
  });

  it('생성(combineConditions)은 canonical `{path} === true` / `=== false`', () => {
    expect(combineConditions([{ operator: 'valueIsTrue', params: { path: '_local.modalOpen' } }], 'and', P5)).toBe(
      '{{ _local.modalOpen === true }}',
    );
    expect(combineConditions([{ operator: 'valueIsFalse', params: { path: '_local.bulkApplyAll' } }], 'and', P5)).toBe(
      '{{ _local.bulkApplyAll === false }}',
    );
  });

  it('라운드트립 — P5 생성 후 재해석 시 동일 절 복원', () => {
    const cases: { operator: string; params: Record<string, string> }[] = [
      { operator: 'valueIsTrue', params: { path: '_local.showCategoryRemoveModal' } },
      { operator: 'valueIsFalse', params: { path: '_local.bulkApplyAll' } },
    ];
    for (const clause of cases) {
      const expr = combineConditions([clause], 'and', P5);
      expect(parseConditionExpr(expr, P5)?.clauses).toEqual([clause]);
    }
  });
});

// ──비교 패턴 net-new 프리셋 + alias 확장 ──
// 전수 스캔이 가려낸 미커버 단일식: 경로 동등/부등(fieldMatches/Differs), !==false/true,
// 숫자 동등(numEquals), null/undefined 존재(valueNotNull/IsNull/Defined/Undefined),
// 그리고 기존 프리셋의 `?? D` coalesce 변종(numGt 등 alias). 인식은 변종 흡수, 생성은
// canonical, compound/오추출은 거부(false-positive 0) 를 가드한다.
describe('비교 패턴 프리셋 + coalesce alias', () => {
  // 출고 recipes 와 동일 형태(구체 → P1 → P5 → 단계4). specificity 가 우선순위 결정.
  const S4 = normalizeConditionRecipes({
    operators: [
      { value: 'isAdmin', label: 'a', expr: '_global?.currentUser?.is_admin === true' },
      { value: 'fieldEquals', label: 'fe', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} === '{value}'", aliases: ["({field} ?? '{default}') === '{value}'", "({field} ?? {default}) === '{value}'"] },
      { value: 'fieldNotEquals', label: 'fne', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} !== '{value}'", aliases: ["({field} ?? '{default}') !== '{value}'"] },
      { value: 'valueTruthy', label: 't', params: [{ key: 'path' }], expr: '{path}', aliases: ['{path} ?? false', '{path} ?? true'] },
      { value: 'numGt', label: 'gt', params: [{ key: 'path' }, { key: 'n' }], expr: '{path} > {n}', aliases: ['({path} ?? 0) > {n}', '({path} ?? []).length > {n}'] },
      { value: 'listEmpty', label: 'le', params: [{ key: 'path' }], expr: '({path} ?? []).length === 0', aliases: ['!({path} ?? []).length', '!{path}.length'] },
      { value: 'valueIsTrue', label: 'vt', params: [{ key: 'path' }], expr: '{path} === true' },
      { value: 'valueIsFalse', label: 'vf', params: [{ key: 'path' }], expr: '{path} === false' },
      { value: 'valueNotFalse', label: 'vnf', params: [{ key: 'path' }], expr: '{path} !== false' },
      { value: 'valueNotTrue', label: 'vnt', params: [{ key: 'path' }], expr: '{path} !== true' },
      { value: 'valueDefined', label: 'vd', params: [{ key: 'path' }], expr: '{path} !== undefined' },
      { value: 'valueUndefined', label: 'vu', params: [{ key: 'path' }], expr: '{path} === undefined' },
      { value: 'valueNotNull', label: 'vnn', params: [{ key: 'path' }], expr: '{path} !== null', aliases: ['{path} != null'] },
      { value: 'valueIsNull', label: 'vin', params: [{ key: 'path' }], expr: '{path} === null', aliases: ['{path} == null'] },
      { value: 'fieldMatches', label: 'fm', params: [{ key: 'a' }, { key: 'b' }], expr: '{a} === {b}', aliases: ['{a} == {b}', '({a} ?? 0) === {b}'] },
      { value: 'fieldDiffers', label: 'fd', params: [{ key: 'a' }, { key: 'b' }], expr: '{a} !== {b}', aliases: ['{a} != {b}'] },
    ],
  });

  it('C7 — `!== false` / `!== true` 인식 + path 추출', () => {
    expect(parseConditionExpr('{{posts?.data?.board?.settings?.show_view_count !== false}}', S4)?.clauses).toEqual([
      { operator: 'valueNotFalse', params: { path: 'posts.data.board.settings.show_view_count' } },
    ]);
    expect(parseConditionExpr('{{post?.data?.abilities?.can_download !== true}}', S4)?.clauses).toEqual([
      { operator: 'valueNotTrue', params: { path: 'post.data.abilities.can_download' } },
    ]);
  });

  it('C5/C6 — undefined/null 존재 비교 인식(느슨한 `!= null` alias 포함)', () => {
    expect(parseConditionExpr('{{user.data?.notify_comment !== undefined}}', S4)?.clauses).toEqual([
      { operator: 'valueDefined', params: { path: 'user.data.notify_comment' } },
    ]);
    expect(parseConditionExpr('{{_global.selectedMenu?.parent_id === null}}', S4)?.clauses).toEqual([
      { operator: 'valueIsNull', params: { path: '_global.selectedMenu.parent_id' } },
    ]);
    // 느슨한 `!= null` → valueNotNull alias 흡수
    expect(parseConditionExpr('{{_global.currentUser != null}}', S4)?.clauses).toEqual([
      { operator: 'valueNotNull', params: { path: '_global.currentUser' } },
    ]);
  });

  it('C8 — 경로 동등/부등 비교(fieldMatches/Differs) 2-path 추출', () => {
    expect(parseConditionExpr('{{_local.editingCurrencyIdx === currency._idx}}', S4)?.clauses).toEqual([
      { operator: 'fieldMatches', params: { a: '_local.editingCurrencyIdx', b: 'currency._idx' } },
    ]);
    expect(parseConditionExpr('{{$parent._local.editingTemplate !== template.id}}', S4)?.clauses).toEqual([
      { operator: 'fieldDiffers', params: { a: '$parent._local.editingTemplate', b: 'template.id' } },
    ]);
  });

  it('C4 — 숫자/리터럴 동등은 fieldMatches(`{a} === {b}`)로 흡수 + `(X ?? 0) === N` coalesce alias', () => {
    // numEquals 를 별도 두지 않는다 — `{path}==={n}` 과 `{a}==={b}` 는 동일 템플릿이라
    // specificity 동점 모호성(round-trip 비결정) 발생. fieldMatches 가 리터럴 RHS 도 흡수.
    expect(parseConditionExpr('{{modLayout.size_diff === 0}}', S4)?.clauses).toEqual([
      { operator: 'fieldMatches', params: { a: 'modLayout.size_diff', b: '0' } },
    ]);
    // (X ?? 0) === N coalesce 변종 흡수(fieldMatches alias)
    expect(parseConditionExpr('{{(item.product_coupon_discount_amount ?? 0) === 0}}', S4)?.clauses).toEqual([
      { operator: 'fieldMatches', params: { a: 'item.product_coupon_discount_amount', b: '0' } },
    ]);
  });

  it('C1 — 숫자비교 `(X ?? 0) > N` / `(X ?? []).length > N` alias 흡수', () => {
    expect(parseConditionExpr('{{(post?.view_count ?? 0) > 0}}', S4)?.clauses).toEqual([
      { operator: 'numGt', params: { path: 'post.view_count', n: '0' } },
    ]);
    expect(parseConditionExpr('{{(review.images ?? []).length > 1}}', S4)?.clauses).toEqual([
      { operator: 'numGt', params: { path: 'review.images', n: '1' } },
    ]);
  });

  it('P6 의미라벨 — `X ?? false` truthy fallback 은 valueTruthy alias 로 흡수', () => {
    expect(parseConditionExpr('{{_global.identityChallenge?.resending ?? false}}', S4)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: '_global.identityChallenge.resending' } },
    ]);
    expect(parseConditionExpr('{{_local.ui.basicInfoExpanded ?? true}}', S4)?.clauses).toEqual([
      { operator: 'valueTruthy', params: { path: '_local.ui.basicInfoExpanded' } },
    ]);
  });

  it('listEmpty — `!(X ?? []).length` 빈 리스트 alias 흡수', () => {
    expect(parseConditionExpr('{{!(boards_list?.data?.data ?? []).length}}', S4)?.clauses).toEqual([
      { operator: 'listEmpty', params: { path: 'boards_list.data.data' } },
    ]);
  });

  it('fieldEquals — `(X ?? \'d\') === \'v\'` / `(X ?? path) === \'v\'` coalesce alias 흡수', () => {
    expect(parseConditionExpr("{{(_local.activeTab ?? 'info') === 'info'}}", S4)?.clauses).toEqual([
      { operator: 'fieldEquals', params: { field: '_local.activeTab', default: 'info', value: 'info' } },
    ]);
    // default 가 path($locale) 인 변종
    expect(parseConditionExpr("{{($parent._local.editLang ?? $locale) === 'ko'}}", S4)?.clauses).toEqual([
      { operator: 'fieldEquals', params: { field: '$parent._local.editLang', default: '$locale', value: 'ko' } },
    ]);
  });

  it('L4 specificity — 구체 0-param(isAdmin) 이 단계4 비교 프리셋보다 우선', () => {
    expect(matchSinglePreset('{{_global.currentUser?.is_admin === true}}', S4)).toBe('isAdmin');
  });

  it('음성 — compound(`A === B && C`)는 단일 fieldMatches 로 오매칭하지 않음(advanced)', () => {
    expect(parseConditionExpr('{{a.x === b.y && c.z !== d.w}}', S4)).toBeNull();
  });

  it('음성 — 반복키 backreference: `!a !== b` 류 서로 다른 두 식은 fieldMatches 단일절로 오삼키지 않음', () => {
    // `a.x === b.y` 만 fieldMatches. `a.x === b.y === c.z`(2회 ===)는 비교 연산자가 캡처에
    // 들어가 path-shape 가드에 거부 → advanced.
    expect(parseConditionExpr('{{a.x === b.y === c.z}}', S4)).toBeNull();
  });

  it('음성 — `!= null` 외 느슨 비교가 path-shape 가드를 우회하지 않음(우변에 연산자 혼입 거부)', () => {
    // `x !== (a && b)` 우변 compound → 거부
    expect(parseConditionExpr('{{x !== (a && b)}}', S4)).toBeNull();
  });

  it('생성(combineConditions)은 canonical — 비교 프리셋', () => {
    expect(combineConditions([{ operator: 'valueNotFalse', params: { path: '_local.flag' } }], 'and', S4)).toBe('{{ _local.flag !== false }}');
    expect(combineConditions([{ operator: 'valueNotNull', params: { path: 'a.b' } }], 'and', S4)).toBe('{{ a.b !== null }}');
    expect(combineConditions([{ operator: 'fieldMatches', params: { a: 'p', b: 'q' } }], 'and', S4)).toBe('{{ p === q }}');
    // coalesce alias 인식 후 생성은 canonical(`?? 0` 떨어짐) — 명시 저장 시에만 정규화
    expect(combineConditions([{ operator: 'numGt', params: { path: 'post.view_count', n: '0' } }], 'and', S4)).toBe('{{ post.view_count > 0 }}');
  });

  it('라운드트립 — 단계4 canonical 생성 후 재해석 시 동일 절 복원', () => {
    const cases: { operator: string; params: Record<string, string> }[] = [
      { operator: 'valueNotFalse', params: { path: '_local.x' } },
      { operator: 'valueNotTrue', params: { path: '_local.y' } },
      { operator: 'valueDefined', params: { path: 'a.b' } },
      { operator: 'valueUndefined', params: { path: 'a.c' } },
      { operator: 'valueNotNull', params: { path: 'a.d' } },
      { operator: 'valueIsNull', params: { path: 'a.e' } },
      { operator: 'fieldMatches', params: { a: 'p', b: 'q' } },
      { operator: 'fieldDiffers', params: { a: 'r', b: 's' } },
    ];
    for (const clause of cases) {
      const expr = combineConditions([clause], 'and', S4);
      expect(parseConditionExpr(expr, S4)?.clauses).toEqual([clause]);
    }
  });
});

// ──60% 게이트 + 회귀 잠금 ──
// 단계 1~4 가 출고 recipes 에 추가한 범용 프리셋(P1~P5 + 비교 패턴)을 양 템플릿
// editor-spec 에서 직접 로드해, 게이트 토대인 프리셋 셋이 (a) 양 템플릿 동기로 존재하고
// (b) 대표 미커버→프리셋 식을 실제 출고 recipes 로 역해석·인식하는지 잠근다.
// 누군가 프리셋을 제거하거나 엔진 역해석이 회귀하면 60% 미달 전에 여기서 먼저 fail.
import adminRecipesJson from '../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec/conditionRecipes.json';
import basicRecipesJson from '../../../../../../../templates/_bundled/sirsoft-basic/editor-spec/conditionRecipes.json';

describe('60% 게이트 출고 recipes 회귀 잠금', () => {
  const adminOps = normalizeConditionRecipes(adminRecipesJson as Parameters<typeof normalizeConditionRecipes>[0]);
  const basicOps = normalizeConditionRecipes(basicRecipesJson as Parameters<typeof normalizeConditionRecipes>[0]);

  // 게이트 토대 프리셋 — 단계 1~4 에서 양 템플릿 동기로 추가된 범용 operator 전부.
  const GATE_PRESETS = [
    // P1
    'valueTruthy', 'valueFalsy',
    // P2/P3
    'numGt', 'numGte', 'numLt', 'numLte', 'listNonEmpty', 'listEmpty',
    // P5
    'valueIsTrue', 'valueIsFalse',
    // 단계 4 비교 패턴
    'valueNotFalse', 'valueNotTrue', 'valueDefined', 'valueUndefined',
    'valueNotNull', 'valueIsNull', 'fieldMatches', 'fieldDiffers',
  ];

  it('게이트 토대 프리셋이 admin 템플릿 출고 recipes 에 전부 존재한다', () => {
    const names = adminOps.map((o) => o.value);
    for (const p of GATE_PRESETS) expect(names).toContain(p);
  });

  it('게이트 토대 프리셋이 basic 템플릿 출고 recipes 에 전부 존재한다 (양 템플릿 동기)', () => {
    const names = basicOps.map((o) => o.value);
    for (const p of GATE_PRESETS) expect(names).toContain(p);
  });

  it('L3 — 한 템플릿 안에서 게이트 프리셋이 중복(정규화 동일 expr) 없이 고유하다', () => {
    for (const opSet of [adminOps, basicOps]) {
      const seen = new Map<string, string>();
      for (const op of opSet) {
        const key = normalizeConditionExpr(op.expr);
        // 같은 정규화 expr 을 다른 value 로 두 번 선언하면 중복 난립(audit L3 와 동일 불변).
        if (seen.has(key)) expect(seen.get(key)).toBe(op.value);
        else seen.set(key, op.value);
      }
    }
  });

  // 단계별 대표 미커버식 → 출고 recipes 로 역해석 시 의도한 프리셋 + 파라미터 복원까지
  // 인식되는지(전수 스위프 60%+ 의 단위 표본). parseConditionExpr 가 파라미터 프리셋의
  // 절 분해·파라미터 역추출을 담당(matchSinglePreset 은 0-param 전용). 양 템플릿 동일 인식.
  const REPRESENTATIVE: Array<{ expr: string; operator: string; params: Record<string, string> }> = [
    { expr: '{{ _global.isDeleting }}', operator: 'valueTruthy', params: { path: '_global.isDeleting' } }, // P1
    { expr: '{{ !_local.success }}', operator: 'valueFalsy', params: { path: '_local.success' } }, // P1
    { expr: '{{ _global.cartCount > 0 }}', operator: 'numGt', params: { path: '_global.cartCount', n: '0' } }, // P2
    { expr: '{{ (admin_menu.data ?? []).length > 0 }}', operator: 'listNonEmpty', params: { path: 'admin_menu.data' } }, // P3
    { expr: '{{ recent_posts.data && recent_posts.data.length > 0 }}', operator: 'listNonEmpty', params: { path: 'recent_posts.data' } }, // P3 alias(P4 흡수)
    { expr: '{{ category?.required === true }}', operator: 'valueIsTrue', params: { path: 'category.required' } }, // P5
    { expr: '{{ _global.selectedMenu?.parent_id === null }}', operator: 'valueIsNull', params: { path: '_global.selectedMenu.parent_id' } }, // 단계 4
    { expr: '{{ item.has_review !== false }}', operator: 'valueNotFalse', params: { path: 'item.has_review' } }, // 단계 4
  ];

  it('단계별 대표 미커버식이 출고 recipes 로 의도한 프리셋·파라미터로 역해석된다 (admin)', () => {
    for (const { expr, operator, params } of REPRESENTATIVE) {
      const r = parseConditionExpr(expr, adminOps);
      expect(r?.clauses).toEqual([{ operator, params }]);
    }
  });

  it('단계별 대표 미커버식이 출고 recipes 로 의도한 프리셋·파라미터로 역해석된다 (basic)', () => {
    for (const { expr, operator, params } of REPRESENTATIVE) {
      const r = parseConditionExpr(expr, basicOps);
      expect(r?.clauses).toEqual([{ operator, params }]);
    }
  });

  it('compound 식은 출고 recipes 에서도 advanced(null) 유지 (false-positive 0 게이트)', () => {
    const COMPOUND = [
      '{{ _local.forgotError && !_local.forgotErrors }}',
      "{{ _global.actionType === 'rejected' && report_detail.data?.target_status === 'blinded' }}",
      '{{ _global.previewVersion?.seo_meta?.title || _global.previewVersion?.seo_meta?.description }}',
    ];
    for (const expr of COMPOUND) {
      expect(parseConditionExpr(expr, adminOps)).toBeNull();
      expect(parseConditionExpr(expr, basicOps)).toBeNull();
    }
  });
});
