/**
 * 관리자 게시글 폼 — 첨부 안내가 게시판 설정값으로 렌더 (공개 #81 L-01).
 *
 * 관리자 게시글 폼의 첨부 영역에는 안내 문구가 없었고, 대신 사장된 lang 키
 * ("최대 5개 파일, 각 10MB 이하")만 남아 있었다. 그 문구는 어디에도 렌더되지 않는데
 * 설령 렌더되었어도 게시판마다 다른 실제 제한과 무관한 고정 숫자였다.
 *
 * 안내는 `form_meta.data.board.max_file_count/max_file_size` 를 파라미터로 받아야 한다.
 * 단위(Vitest admin-board-post-form-attachments-desc) 는 안내 노드만 떼어 렌더하므로,
 * 실제 폼에서 form_meta 데이터소스가 도착한 뒤의 치환 결과는 브라우저로만 확인 가능하다
 * (위지윅 발행 회귀 #238 교훈).
 *
 * @scenario board-post-form-attachments-desc
 * @effects admin_attachment_hint_renders_board_limits,
 *          admin_attachment_hint_placeholders_not_raw
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

/** 첨부 기능이 켜진 게시판의 관리자 글쓰기 화면 */
const WRITE_URL = '/admin/board/free/write';

test.describe.skip('관리자 게시글 폼 — 첨부 안내 (#81 L-01)', () => {
  test('첨부 안내가 게시판 설정의 개수·용량으로 렌더된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(WRITE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // form_meta 도착 후 안내가 채워질 때까지 대기 — 숫자는 게시판 설정에서 온다
    const hint = page.getByText(/^최대 \d+개, 각 \d+MB 이하$/);
    await expect(hint).toBeVisible({ timeout: 10_000 });
  });

  test('raw 플레이스홀더({{maxFiles}}/{{maxSize}})가 노출되지 않는다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(WRITE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByText(/^최대 \d+개, 각 \d+MB 이하$/)).toBeVisible({ timeout: 10_000 });

    await expect(page.getByText('{{maxFiles}}', { exact: false })).toHaveCount(0);
    await expect(page.getByText('{{maxSize}}', { exact: false })).toHaveCount(0);
  });

  test('사장된 고정 숫자 안내가 다시 나타나지 않는다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(WRITE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByText(/^최대 \d+개, 각 \d+MB 이하$/)).toBeVisible({ timeout: 10_000 });

    // 종전 사장 키의 고정 문구 (jpg, jpeg, ... 확장자 나열 포함) 는 제거되었다
    await expect(
      page.getByText('최대 5개 파일, 각 10MB 이하', { exact: false }),
    ).toHaveCount(0);
  });
});
