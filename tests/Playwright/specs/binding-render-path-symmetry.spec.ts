/**
 * E2E: 단일 바인딩 식이 렌더 경로와 무관하게 같은 값으로 해석되는지 (배포 번들 기준)
 *
 * 배경: "이 문자열이 단일 바인딩인가", "이 식이 표현식인가 경로인가" 를 판정하는
 * 로직이 엔진 곳곳에 복제되면서 복사본마다 인식 문자 집합이 갈라졌고, 그 결과
 * **같은 작성이 어느 렌더 경로를 타느냐에 따라 다른 결과**를 냈다.
 * (`{{query['sales_status[]']}}` 가 prop 에서는 undefined, `if` 에서는 정상 등)
 *
 * 단위 테스트가 소스 레벨을 잠그지만 실제 사용자가 보는 것은 빌드된 번들이므로
 * 브라우저에서 배포 번들의 공개 API 를 그대로 호출해 결과를 잠근다.
 *
 * 파일 헤더의 시나리오 목록은 아래 test 들이 실제로 다는 `@scenario` 의 합집합이어야 한다.
 * 반복 렌더의 항목별 조건 평가(`iteration_item_condition`)는 별개 축이라
 * `iteration-condition-render.spec.ts` 가 담당한다.
 *
 * @scenario binding_quoted_bracket_symmetry, binding_pipe_type_preservation, binding_brace_in_pipe, binding_empty_expression
 * @effects same_expression_same_value_across_paths
 */
import { test, expect } from '../fixtures/auth';

test.describe('렌더 경로 간 바인딩 해석 대칭', () => {
  test.beforeEach(async ({ page }) => {
    // 코어 번들이 부팅된 화면이면 충분하다(로그인 화면도 엔진을 부팅한다).
    await page.goto('/admin/login');
    await page.waitForFunction(
      () => Boolean((window as any).G7Core?.renderItemChildren),
      null,
      { timeout: 30_000 },
    );
  });

  // @scenario binding_quoted_bracket_symmetry
  // @effects same_expression_same_value_across_paths
  test('따옴표 키 인덱싱이 반복 렌더와 조건 판정에서 같은 값을 본다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const Span = function Span() { return null; };
      const context = { query: { 'sales_status[]': ['selling', 'soldout'] } };

      const nodes = g7.renderItemChildren(
        [{ type: 'basic', name: 'Span', props: { title: "{{query['sales_status[]']}}" } }],
        context,
        { Span },
        'e2e-symmetry',
      );

      return {
        hasEvaluateCondition: typeof g7.evaluateCondition === 'function',
        viaRepeat: (nodes as any[])[0]?.props?.title ?? null,
        viaCondition: typeof g7.evaluateCondition === 'function'
          ? g7.evaluateCondition("{{query['sales_status[]']}}", context)
          : null,
      };
    });

    expect(result.hasEvaluateCondition, 'G7Core.evaluateCondition 이 노출되지 않음').toBe(true);
    // 수정 전: 좁은 방언이 "단순 경로" 로 오판해 경로 탐색 → undefined
    expect(result.viaRepeat).toEqual(['selling', 'soldout']);
    expect(result.viaCondition).toBe(true);
  });

  // @scenario binding_pipe_type_preservation
  // @effects same_expression_same_value_across_paths
  test('파이프 결과가 원본 타입으로 전달된다 (문자열 서식 강제 없음)', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const Span = function Span() { return null; };
      const nodes = g7.renderItemChildren(
        [
          { type: 'basic', name: 'Span', props: { title: '{{row.meta | keys}}' } },
          { type: 'basic', name: 'Span', props: { title: '{{row.flags | first}}' } },
          { type: 'basic', name: 'Span', props: { title: '{{row.tags | length}}' } },
        ],
        { row: { meta: { a: 1, b: 2 }, flags: [false, true], tags: ['a', 'b', 'c'] } },
        { Span },
        'e2e-pipe-type',
      );
      return (nodes as any[]).map((n) => ({
        value: n?.props?.title ?? null,
        kind: Array.isArray(n?.props?.title) ? 'array' : typeof n?.props?.title,
      }));
    });

    // 수정 전: 전부 문자열(`"[\"a\",\"b\"]"`, `"false"`, `"3"`)
    expect(result[0].kind).toBe('array');
    expect(result[0].value).toEqual(['a', 'b']);
    expect(result[1].kind).toBe('boolean');
    expect(result[1].value).toBe(false);
    expect(result[2].kind).toBe('number');
    expect(result[2].value).toBe(3);
  });

  // @scenario binding_brace_in_pipe
  // @effects same_expression_same_value_across_paths
  test('파이프 식 안의 중괄호가 원본 문자열로 새지 않는다', async ({ page }) => {
    const value = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const Span = function Span() { return null; };
      const nodes = g7.renderItemChildren(
        [{ type: 'basic', name: 'Span', text: '{{(row.meta ?? {}) | json}}' }],
        { row: {} },
        { Span },
        'e2e-brace',
      );
      return (nodes as any[])[0]?.props?.children ?? null;
    });

    // 수정 전: 정규식이 `}` 를 포함한 식을 매칭하지 못해 `{{...}}` 가 그대로 노출됐다.
    expect(String(value)).toBe('{}');
    expect(String(value)).not.toContain('{{');
  });

  // @scenario binding_literal_symmetry
  // @effects same_expression_same_value_across_paths
  test('리터럴이 반복 렌더와 조건 판정에서 같은 값을 본다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const Span = function Span() { return null; };
      const context = { row: { id: 1 } };
      const viaRepeat = (expr: string) =>
        (g7.renderItemChildren(
          [{ type: 'basic', name: 'Span', props: { title: expr } }],
          context,
          { Span },
          'e2e-literal',
        ) as any[])[0]?.props?.title;

      return {
        repeatTrue: viaRepeat('{{true}}'),
        repeatFalse: viaRepeat('{{false}}'),
        repeatNull: viaRepeat('{{null}}'),
        condTrue: g7.evaluateCondition('{{true}}', context),
        condFalse: g7.evaluateCondition('{{false}}', context),
      };
    });

    // 수정 전: 반복 렌더 경로만 리터럴을 몰라 경로 탐색으로 새어 전부 undefined 였다.
    // 조건 경로는 리터럴을 알았으므로 같은 작성이 자리에 따라 갈렸다.
    expect(result.repeatTrue).toBe(true);
    expect(result.repeatFalse).toBe(false);
    expect(result.repeatNull).toBeNull();
    expect(result.repeatTrue).toBe(result.condTrue);
    expect(result.repeatFalse).toBe(result.condFalse);
  });

  // @scenario binding_empty_expression
  // @effects same_expression_same_value_across_paths
  test('빈 바인딩이 컨텍스트 객체 전체를 노출하지 않는다', async ({ page }) => {
    const value = await page.evaluate(() => {
      const g7 = (window as any).G7Core;
      const Span = function Span() { return null; };
      const nodes = g7.renderItemChildren(
        [{ type: 'basic', name: 'Span', props: { title: '{{}}' } }],
        { row: { secret: 'do-not-leak' } },
        { Span },
        'e2e-empty',
      );
      return (nodes as any[])[0]?.props?.title ?? null;
    });

    expect(value == null || value === '').toBe(true);
    expect(String(value ?? '')).not.toContain('do-not-leak');
  });
});
