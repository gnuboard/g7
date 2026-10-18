/**
 * 선택 건수는 화면에 한 번만 뜬다 (제보 2026-09-15, 1:1 문의 게시글 관리).
 *
 * 왜 E2E 여야 하는가: 중복의 두 원천이 **서로 다른 층**에 있다. 하나는 레이아웃이 적은
 * 일괄 바의 「N개 선택됨」이고, 다른 하나는 `DataGrid` 컴포넌트가 `selectable` + 선택 행이
 * 있을 때 스스로 그리는 줄이다. 레이아웃 JSON 을 읽는 단위 테스트는 «레이아웃에 한 번만
 * 적혀 있다» 까지만 볼 수 있어 컴포넌트가 얹는 두 번째 줄을 영영 보지 못한다 — 실제로
 * 그래서 4벌이 초록 아래에서 중복을 내고 있었다. **행을 골랐을 때 화면에 몇 번 뜨는가**는
 * 실브라우저로만 잴 수 있다.
 *
 * 저장소 전역 정합(22벌이 관례를 지키는가)은 코어 vitest 가드
 * `DataGrid.selectedCountParity.test.ts` 가 정적으로 덮는다. 이 spec 은 그 관례가 실제
 * 렌더에서 의도한 결과를 내는지를 대표 화면 하나로 잰다.
 *
 * @scenario surface=admin_list_selection
 * @effects selected_count_renders_once_after_selecting_a_row
 */
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

/** 대표 화면 — 게시판 관리자 목록. 중복이 제보된 그 화면 부류다. */
const LIST_URL = '/admin/board/free';

test.describe('관리자 목록 선택 건수 (#524)', () => {
  // @scenario surface=admin_list_selection
  // @effects selected_count_renders_once_after_selecting_a_row
  test('행을 하나 고르면 「N개 선택됨」이 한 번만 뜬다', async ({ page }) => {
    // admin 역할(함께 부여)이 게시판 권한을 모두 가진다. 예전 인자(`sirsoft-board.posts.*`)는 게시판 이름이
    // 빠진 없는 권한이라 발급 커맨드가 가짜 행을 만들던 것이다 — 이제는 발급 때 거절된다(7.1.0 O-D2).
    const token = issueToken();
    await authenticatePage(page, token);
    await page.goto(LIST_URL);

    // 목록이 그려질 때까지 기다린다 — 행이 없으면 선택 자체를 잴 수 없다.
    const rowCheckbox = page.locator('table tbody tr input[type="checkbox"]').first();
    await expect(rowCheckbox).toBeVisible({ timeout: 20_000 });

    // `check()` 는 네이티브 상태 변화를 기대하지만 이 체크박스는 `selectedIds` 가 모는
    // 제어 컴포넌트라 클릭으로 연다.
    await rowCheckbox.click();
    await expect(page.getByText(/\d+\s*개\s*선택됨/).first()).toBeVisible({ timeout: 10_000 });

    // 「N개 선택됨」 문장이 화면에 몇 번 있는가. 종전에는 2였다(일괄 바 + DataGrid 내장 줄).
    const occurrences = await page.evaluate(() => {
      const text = document.body.innerText;
      return (text.match(/\d+\s*개\s*선택됨/g) ?? []).length;
    });

    expect(occurrences).toBe(1);
  });
});
