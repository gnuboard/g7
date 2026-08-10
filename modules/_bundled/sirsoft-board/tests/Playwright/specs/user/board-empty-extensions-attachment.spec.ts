/**
 * 허용 확장자 미지정 게시판의 첨부 선택 (#78 R3).
 *
 * 백엔드는 Phase 2 #7 로 이미 고쳐졌지만(빈 확장자 → 기본 확장자 폴백), 프런트에 동일한
 * `[]`-truthiness 쌍둥이가 남아 있어 사용자는 **파일 선택 단계에서** 막혔다. 요청 자체가
 * 발생하지 않으므로 서버측 회귀 테스트로는 잡히지 않는다.
 *
 * 연쇄: DB `NULL` → API 가 `allowed_extensions: []` 로 직렬화(BoardResource 계약) →
 * 레이아웃 `ext ? '.' + ext.join(',.') : fallback` 에서 `[]` 가 truthy → `accept="."` →
 * `useFileUploader` 확장자 게이트가 전 파일 거부 + 안내문이 `(.)` 로 깨짐.
 *
 * 브라우저 실측 대조군(2026-07-22): `free`(ext=NULL) accept=`"."` → 첨부 차단 /
 * `gallery`(ext 5개) accept=`".jpg,..."` → 정상. 서버측 curl 은 `free`+`.txt` 201 로 정상이었다.
 *
 * @scenario board-empty-extensions-attachment
 * @axes allowed_extensions=empty allowed_extensions=specified surface=user_write surface=admin_write
 * @effects empty_extensions_accept_falls_back_to_defaults,
 *          empty_extensions_file_selection_succeeds,
 *          specified_extensions_accept_reflects_list
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const DEFAULT_ACCEPT = '.jpg,.jpeg,.png,.gif,.pdf,.zip';

/**
 * 글쓰기 화면의 파일 입력에서 accept 속성을 읽습니다.
 */
async function readAcceptAttribute(page: import('@playwright/test').Page): Promise<string | null> {
  return page.evaluate(() => {
    const input = document.querySelector<HTMLInputElement>('input[type="file"]');

    return input ? input.getAttribute('accept') : null;
  });
}

test.describe.skip('허용 확장자 미지정 게시판의 첨부 (#78 R3)', () => {
  // @scenario allowed_extensions=empty, surface=user_write
  // @effects empty_extensions_accept_falls_back_to_defaults
  test('확장자 미지정 게시판의 사용자 글쓰기 accept 는 기본 확장자로 폴백된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto('/board/free/write');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const accept = await readAcceptAttribute(page);

    expect(accept).not.toBe('.');
    expect(accept).toBe(DEFAULT_ACCEPT);
  });

  // @scenario allowed_extensions=empty, surface=user_write
  // @effects empty_extensions_file_selection_succeeds
  test('확장자 미지정 게시판에서 파일을 선택하면 거부되지 않는다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto('/board/free/write');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.setInputFiles('input[type="file"]', {
      name: 'probe.png',
      mimeType: 'image/png',
      buffer: Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        'base64'
      ),
    });

    // 확장자 게이트에 걸리면 "허용되지 않는 파일 형식입니다. (허용: .)" 가 노출된다
    await expect(page.getByText(/허용되지 않는 파일 형식/)).toHaveCount(0, { timeout: 10_000 });
    await expect(page.getByText(/1\s*\/\s*\d+개 첨부됨|첨부됨/)).toBeVisible({ timeout: 10_000 });
  });

  // @scenario allowed_extensions=specified, surface=user_write
  // @effects specified_extensions_accept_reflects_list
  test('확장자를 지정한 게시판은 accept 에 그 목록이 반영된다 (회귀 방지)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto('/board/gallery/write');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const accept = await readAcceptAttribute(page);

    expect(accept).toContain('.jpg');
    expect(accept).not.toBe('.');
    expect(accept).not.toBe(DEFAULT_ACCEPT);
  });
});
