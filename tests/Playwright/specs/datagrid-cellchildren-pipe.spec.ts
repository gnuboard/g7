/**
 * E2E: 반복 렌더 컨텍스트(DataGrid cellChildren 등)의 파이프 표현식 해석
 *
 * 회귀(#87): `renderItemChildren` 이 단일 바인딩을 해석할 때 `|` 를 복잡 표현식
 * 문자로 분류해 `evaluateExpression`(JS 비트 OR)으로 보냈다. 그 결과
 * `{{row.created_at | datetime('YYYY-MM-DD HH:mm')}}` 는 예외로 값이 사라져 셀이
 * 비었고, `{{row.code | uppercase}}` 는 `0` 이라는 조용한 오답이 되었다. 같은
 * 표현식이 일반 컴포넌트 `text`(DynamicRenderer 경로)에서는 정상이라 재현
 * 위치를 특정하기 어려웠다.
 *
 * 단위 테스트(renderItemChildren-pipe.test.ts)가 소스 레벨을 잠그지만, 실제
 * 결함은 빌드된 번들이 브라우저에서 그리는 결과였으므로 브라우저 레벨에서도
 * 잠근다 — 셀에 파이프를 넣은 화면이 코어 기본 레이아웃에 없으므로, 배포된
 * 번들의 `G7Core.renderItemChildren` 를 그대로 호출해 결과를 검증한다.
 *
 * 축 요약(마커 아님 — 평문): cellchildren_pipe_with_args, cellchildren_pipe_without_args, cellchildren_props_pipe.
 * 효과 요약(마커 아님 — 평문): pipe_formatted_value_rendered.
 */
import { test, expect } from '../fixtures/auth';

/** 반복 렌더 경로에 넘길 컴포넌트 정의 + 컨텍스트를 브라우저에서 해석한다. */
async function resolveViaItemRenderer(
  page: import('@playwright/test').Page,
  defs: unknown[],
  context: Record<string, unknown>
) {
  return page.evaluate(
    ({ defs, context }) => {
      const g7 = (window as any).G7Core;
      if (!g7?.renderItemChildren) {
        return { available: false, results: [] as any[] };
      }
      // 템플릿 컴포넌트 레지스트리 등록 시점에 의존하지 않도록 검증 전용 맵을 넘긴다.
      // renderItemChildren 이 내부에서 createElement 하므로 반환 노드의 props 로
      // 해석 결과를 그대로 읽을 수 있다(컴포넌트 구현 자체는 무관).
      const Span = function Span() {
        return null;
      };
      const nodes = g7.renderItemChildren(defs, context, { Span }, 'e2e-pipe');
      return {
        available: true,
        results: (nodes as any[]).map((n) => ({
          children: n?.props?.children ?? null,
          title: n?.props?.title ?? null,
        })),
      };
    },
    { defs, context }
  );
}

test.describe('반복 렌더 컨텍스트의 파이프 표현식', () => {
  test.beforeEach(async ({ page }) => {
    // 코어 번들이 로드된 임의 화면이면 충분하다(로그인 화면도 엔진을 부팅한다).
    await page.goto('/admin/login');
    await page.waitForFunction(() => Boolean((window as any).G7Core?.renderItemChildren), null, {
      timeout: 30_000,
    });
  });

  // @scenario cellchildren_pipe_with_args
  // @effects pipe_formatted_value_rendered
  test('인자 있는 파이프(datetime)가 포맷된 값을 그린다', async ({ page }) => {
    const { available, results } = await resolveViaItemRenderer(
      page,
      [
        {
          type: 'basic',
          name: 'Span',
          text: "{{row.created_at | datetime('YYYY-MM-DD HH:mm')}}",
        },
      ],
      { row: { created_at: '2026-07-26T05:42:00.000000Z' } }
    );

    expect(available).toBe(true);
    // 수정 전에는 예외로 undefined 가 되어 셀이 비었다.
    expect(String(results[0].children)).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
  });

  // @scenario cellchildren_pipe_without_args
  // @effects pipe_formatted_value_rendered
  test('인자 없는 파이프(number/uppercase)가 비트 연산으로 오염되지 않는다', async ({ page }) => {
    const { available, results } = await resolveViaItemRenderer(
      page,
      [
        { type: 'basic', name: 'Span', text: '{{row.price | number}}' },
        { type: 'basic', name: 'Span', text: '{{row.code | uppercase}}' },
      ],
      { row: { price: 1234567, code: 'abc' } }
    );

    expect(available).toBe(true);
    // 수정 전: 1234567(구분자 없음) / 0(문자열이 비트 연산으로 붕괴)
    expect(results[0].children).toBe('1,234,567');
    expect(results[1].children).toBe('ABC');
  });

  // @scenario cellchildren_props_pipe
  // @effects pipe_formatted_value_rendered
  test('props 값의 파이프도 해석된다', async ({ page }) => {
    const { available, results } = await resolveViaItemRenderer(
      page,
      [{ type: 'basic', name: 'Span', text: 'x', props: { title: '{{row.price | number}}' } }],
      { row: { price: 12345 } }
    );

    expect(available).toBe(true);
    expect(results[0].title).toBe('12,345');
  });
});
