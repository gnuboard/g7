/**
 * 레이아웃 문자열 번역의 인자 식 — 리터럴만 있는 식·구분자를 담은 문자열 리터럴이 그대로 치환된다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 홈 — 레이아웃 응답에 시험용 Span 을 주입).
 *
 * 결함: 수정 뒤 `$t:attachment.attached_count|count={{1}}|max={{3}}` 처럼 인자 식이 리터럴뿐이면 치환되지 않아
 *   「{{count}}/{{max}}개 첨부됨」 이 그대로 보였다(`BindingShape.isLiteralExpression`·`TranslationEngine.resolveParamValue`).
 * 결함: 인자 값에 `&`·`|`·`=` 가 있으면 그 자리에서 잘렸다 — 문자열 리터럴 안의 구분자도 값의 일부여야 한다.
 *
 * 데이터: 홈 레이아웃 응답(`GET /api/layouts/{템플릿}/home.json`)을 서버에서 받아 이 브라우저에서만 Span 을 앞에 덧붙인다
 *   (서버·DB 레이아웃 불변). 비회원 화면이라 계정이 필요 없고 쓰기 요청은 가로챈다.
 *
 * @scenario-manifest tests/scenarios/e2e-translation-literal-params.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- translation-literal-params --workers=1
 */
import { test, expect } from '@playwright/test';
import { guardWrites, waitForSpa } from '../fixtures/env-account';

/** 주입할 번역 식과 기대 문구(ko) */
const PROBES: Array<{ id: string; text: string; expected: string }> = [
  { id: 'e2e-f43-count-max', text: '$t:attachment.attached_count|count={{1}}|max={{3}}', expected: '1/3개 첨부됨' },
  { id: 'e2e-f43-sum', text: '$t:common.select_item|name={{1 + 1}}', expected: '2 선택' },
  { id: 'e2e-f43-zero', text: '$t:common.select_item|name={{0}}', expected: '0 선택' },
  { id: 'e2e-f43-nullish', text: '$t:attachment.attached_count|count={{_local.__e2eMissing ?? 0}}|max=5', expected: '0/5개 첨부됨' },
  { id: 'e2e-f01-literal', text: "$t:common.select_item|name={{'Tom & Jerry | a=b'}}", expected: 'Tom & Jerry | a=b 선택' },
];

test.describe('레이아웃 문자열 번역 — 리터럴 인자 식', () => {
  /**
   * @scenario case=user_home_injected
   * @effects literal_param_expressions_resolve, literal_string_keeps_separators
   */
  test('주입한 번역 식이 모두 값으로 치환된다', async ({ page }) => {
    await guardWrites(page);
    let injected = 0;
    await page.route(
      (url) => /^\/api\/layouts\/[^/]+\/home\.json$/.test(url.pathname),
      async (route) => {
        const res = await route.fetch();
        const body = await res.json();
        const layout = body?.data ?? body;
        const spans = PROBES.map((p) => ({ id: p.id, type: 'basic', name: 'Span', props: { 'data-testid': p.id }, text: p.text }));
        if (Array.isArray(layout?.components)) {
          layout.components = [...spans, ...layout.components];
          injected += 1;
        }

        return route.fulfill({ response: res, json: body });
      },
    );

    await page.goto('/');
    await waitForSpa(page);
    await expect.poll(() => injected, { message: '홈 레이아웃 응답에 주입했다', timeout: 20_000 }).toBeGreaterThan(0);

    for (const p of PROBES) {
      await expect(page.getByTestId(p.id), p.text).toHaveText(p.expected, { timeout: 20_000 });
    }
  });
});
