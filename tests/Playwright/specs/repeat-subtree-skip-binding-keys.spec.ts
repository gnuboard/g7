/**
 * E2E: 반복 서브트리 진입 키의 `skipBindingKeys` 선언 정합 (engine-v1.56.1 / 템플릿)
 *
 * 배경: `cardColumns[].cellChildren` / `footerCells[].children` / `footerCardChildren` 은
 * **반복 렌더 경로**(`renderItemChildren`)가 항목 컨텍스트와 함께 해석하는 자리다. 따라서
 * 컴포넌트 선언(`components.json`)의 `skipBindingKeys` 에 그 키가 들어 있어야 props 선평가를
 * 건너뛴다. 선언이 빠지거나 이름이 틀리면(P5 이전 상태: `cardChildren` 오기, `footerCells`·
 * `footerCardChildren` 누락) 항목 변수가 없는 시점에 표현식이 먼저 평가되어
 * **셀이 비거나 `{{...}}` 원본이 화면에 남는다**. 예외는 발생하지 않는다.
 *
 * 계획서 「브라우저 검증」의 "skipBindingKeys 키 변동은 반복 서브트리 전부가 영향권 →
 * 대표 화면 시각 확인 필수" 항목을 재현 가능한 형태로 고정한다. 정적 검사(audit 룰
 * `component-skipbinding-keys-complete`)는 **선언과 구현의 일치**만 보므로, 실제 화면에서
 * 값이 채워지는지는 여기서만 잡힌다.
 *
 * 대상은 `cardColumns` 를 실제로 쓰면서 데이터가 항상 존재하는 화면을 골랐다
 * (모듈·플러그인은 번들 확장이 있어 목록이 비지 않는다).
 *
 * @scenario repeat_subtree_card_columns_render
 * @effects item_values_rendered_no_raw_binding
 */
import { test, expect, issueToken, authenticatePage } from '../fixtures/auth';

const SCREENS = [
  {
    name: '모듈 목록 (카드 뷰)',
    path: '/admin/modules',
    permissions: ['core.modules.read'],
    /** 번들 모듈이 반드시 하나는 노출된다 */
    expectText: '이커머스',
  },
  {
    name: '플러그인 목록 (카드 뷰)',
    path: '/admin/plugins',
    permissions: ['core.plugins.read'],
    expectText: 'CKEditor',
  },
] as const;

test.describe('반복 서브트리 — cardColumns 항목이 실제 값으로 렌더된다', () => {
  for (const screen of SCREENS) {
    // @scenario repeat_subtree_card_columns_render
    // @effects item_values_rendered_no_raw_binding
    test(`${screen.name}: 카드 셀이 채워지고 원본 바인딩이 남지 않는다`, async ({ page }) => {
      test.setTimeout(120_000);

      const token = issueToken(...screen.permissions);
      await authenticatePage(page, token);

      await page.goto(screen.path, { waitUntil: 'domcontentloaded', timeout: 60_000 });

      // ① 목록이 실제로 그려질 때까지 기다린다. 이 대기 없이 아래 부재 단언만 두면
      //    화면이 비어 있어도 "원본 바인딩 없음" 으로 조용히 통과한다(공허한 통과).
      await expect(page.getByText(screen.expectText).first()).toBeVisible({ timeout: 60_000 });

      // ② 반복 서브트리(cardColumns[].cellChildren) 안의 표현식이 해석되지 않은 채
      //    남으면 화면에 `{{` 가 그대로 보인다.
      const bodyText = (await page.locator('body').innerText()) ?? '';
      expect(bodyText).not.toContain('{{');

      // ③ 번역 면제 마커(Unicode Noncharacter)도 화면으로 새지 않아야 한다
      //    — 반복 경로의 raw 마커 unwrap 회귀선(engine-v1.56.3).
      expect(bodyText).not.toContain('﷐');
      expect(bodyText).not.toContain('﷑');
    });
  }
});
