/**
 * 게시판 환경설정 "게시판 설정" 탭 — 숫자 필드 범위 힌트 렌더 (이슈 #413).
 *
 * description 의 `({{min}}~{{max}})` 파라미터가 `_local.form._meta.limits`(config SSoT)
 * 로부터 실제 숫자로 치환되어야 한다. 데이터소스 ID `settings` 가 코어 전역 `_global.settings`
 * 와 충돌하던 회귀(파라미터 미치환 → `{{min}}`/`{{max}}` raw 노출)를 브라우저에서 차단한다.
 *
 * 단위(Vitest board-settings-range-hints) 는 레이아웃 JSON 의 표현식 존재만 검증하므로,
 * 실제 데이터 바인딩 + 치환 결과는 브라우저 렌더로만 확인 가능 (위지윅 발행 회귀 #238 교훈).
 *
 * @scenario board-settings-range-hints
 * @effects per_page_hint_renders_config_range,
 *          title_length_hints_render_config_range,
 *          reply_depth_hint_renders_config_max_10,
 *          file_size_hint_renders_config_max_200,
 *          range_placeholders_not_raw
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SETTINGS_URL = '/admin/boards/settings?tab=basic_defaults';

test.describe.skip('게시판 환경설정 — 범위 힌트 동적 렌더 (#413)', () => {
  test('숫자 필드 description 이 config 범위로 치환된다 (raw 플레이스홀더 없음)', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 데이터소스 settings 로드 후 힌트가 채워질 때까지 대기
    await expect(
      page.getByText('PC 환경에서 한 페이지에 표시할 게시글 수입니다 (5~100)'),
    ).toBeVisible({ timeout: 10_000 });

    // config('sirsoft-board.limits') SSoT 와 일치하는 실제 범위 치환 확인
    await expect(
      page.getByText('모바일 환경에서 한 페이지에 표시할 게시글 수입니다 (5~100)'),
    ).toBeVisible();
    await expect(
      page.getByText('게시글 제목의 최소 글자 수를 설정합니다 (0~200)'),
    ).toBeVisible();
    await expect(
      page.getByText('게시글 제목의 최대 글자 수를 설정합니다 (1~1000)'),
    ).toBeVisible();
    // #413 검수 오류: max_reply_depth 상한 5 → 10
    await expect(
      page.getByText('답변글의 최대 중첩 깊이를 설정합니다 (1~10)'),
    ).toBeVisible();
    // #413 검수 오류: max_file_size max 누락 → 200 보완
    await expect(
      page.getByText('업로드 가능한 최대 파일 크기입니다 (MB 단위, 1~200)'),
    ).toBeVisible();
  });

  test('raw 플레이스홀더({{min}}/{{max}})가 화면에 노출되지 않는다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(
      page.getByText('PC 환경에서 한 페이지에 표시할 게시글 수입니다 (5~100)'),
    ).toBeVisible({ timeout: 10_000 });

    // 미치환 회귀 차단: `{{min}}` / `{{max}}` 리터럴이 본문에 남으면 안 됨
    await expect(page.getByText('{{min}}', { exact: false })).toHaveCount(0);
    await expect(page.getByText('{{max}}', { exact: false })).toHaveCount(0);
  });

  test('숫자 Input 의 min/max 속성이 config 범위로 설정된다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const perPage = page.locator('input[name="basic_defaults.per_page"]');
    await expect(perPage).toHaveAttribute('min', '5', { timeout: 10_000 });
    await expect(perPage).toHaveAttribute('max', '100');

    // max_reply_depth: 하드코딩 5 → config 10
    const replyDepth = page.locator('input[name="basic_defaults.max_reply_depth"]');
    await expect(replyDepth).toHaveAttribute('max', '10');

    // max_file_size: max 누락 → config 200
    const fileSize = page.locator('input[name="basic_defaults.max_file_size"]');
    await expect(fileSize).toHaveAttribute('max', '200');
  });
});