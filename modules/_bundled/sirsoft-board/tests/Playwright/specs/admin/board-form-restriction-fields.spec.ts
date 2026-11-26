/**
 * 게시판 생성/수정 폼 — 제한 키워드/허용 확장자 입력 (item 24).
 *
 * 24-(2)/Q4 통일안: 제한 키워드(blocked_keywords)와 허용 확장자(allowed_extensions)를
 * 둘 다 배열 데이터 + TagInput UI 로 통일한다. 두 필드 모두 "게시글 설정" 탭에 유지하되
 * 입력 UI 를 TagInput 으로 교체했다.
 *
 * 단위(Vitest admin-board-form) 는 레이아웃 JSON 구조(탭 위치/TagInput 타입)만 검증하고,
 * Feature(BoardManagementTest) 는 form-data 가 두 필드를 배열로 반환하는지 검증하므로,
 * 브라우저 수준(태그 입력 → 저장 → 재진입 시 칩 복원)은 이 spec 이 담당.
 *
 * 선택자는 화면의 접근성 이름(콤보박스 「금지어」·「허용 확장자」, 칩 단추 「… 지우기」, 단추 「저장」)으로 잡는다 —
 * TagInput 의 `name` 은 숨은 값 칸이라 보이지 않는다. 탭은 한 폼의 구획 이동(scroll-spy)이라 모든 구획이 늘 렌더되므로
 * 「어느 탭 소속인가」는 구획(`#section_basic_card`·`#section_post_card`) 안으로 범위를 좁혀 잰다(2026-11-22 skip 해제 시 실측 —
 * 종전 「기본 탭에 없음」 단언은 화면이 그려지기 전에 0개를 세어 우연히 통과했다).
 *
 * @scenario board-form-restriction-fields
 * @axes field=blocked_keywords field=allowed_extensions tab=basic tab=post mode=create mode=update
 * 효과 요약(마커 아님 — 평문, 시험별 마킹은 각 test 위에 있다):
 *          blocked_keywords_renders_as_taginput_on_post_tab,
 *          allowed_extensions_renders_as_taginput_on_post_tab,
 *          blocked_keywords_absent_from_basic_tab,
 *          taginput_values_persist_as_array_chips_on_reentry
 * 사용자 화면 댓글 차단어 반영은 아직 이 spec 에 시험이 없다(댓글 차단은 CommentCrudScenarioTest 가 잰다).
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';
import type { Page } from '@playwright/test';

const CREATE_URL = '/admin/boards/create';
const BOARD_API = '/api/modules/sirsoft-board/admin/boards';

/**
 * 페이지 문맥에서 인증 API 를 부릅니다.
 *
 * @param page 페이지
 * @param method HTTP 메서드
 * @param path API 경로
 * @returns 상태 코드
 */
async function api(page: Page, method: string, path: string): Promise<number> {
  return page.evaluate(
    async ({ method, path }) => {
      const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const res = await fetch(path, {
        method,
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          'X-XSRF-TOKEN': xsrf,
        },
      });
      return res.status;
    },
    { method, path },
  );
}

/**
 * 태그 입력 칸에 값을 하나 넣습니다(입력 후 Enter).
 *
 * @param page 페이지
 * @param label 콤보박스 접근성 이름
 * @param value 넣을 값
 */
async function addTag(page: Page, label: string, value: string): Promise<void> {
  const box = page.locator('#section_post_card').getByRole('combobox', { name: label, exact: true });
  await box.click();
  await box.pressSequentially(value);
  await box.press('Enter');
}

test.describe('게시판 폼 — 제한 키워드/허용 확장자 TagInput', () => {
  // 생성 저장 → 수정 화면 다시 불러오기까지 한 시험에서 하므로 느린 개발 PC 에서도 끝나도록 넉넉히 둔다
  test.describe.configure({ timeout: 180_000 });

  // @scenario field=blocked_keywords, tab=post
  // @effects blocked_keywords_renders_as_taginput_on_post_tab
  test('제한 키워드는 게시글 설정 탭에서 TagInput 으로 노출되고 태그 입력이 가능하다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.getByRole('tab', { name: '게시글 설정' }).click();
    await expect(page.locator('#section_post_card').getByRole('combobox', { name: '금지어', exact: true })).toBeVisible({ timeout: 20_000 });

    await addTag(page, '금지어', 'e2e금지어');
    await expect(page.getByRole('button', { name: 'e2e금지어 지우기' })).toBeVisible({ timeout: 5_000 });
  });

  // @scenario field=allowed_extensions, tab=post
  // @effects allowed_extensions_renders_as_taginput_on_post_tab
  test('허용 확장자는 게시글 설정 탭의 파일 업로드 켜짐 시 TagInput 으로 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.getByRole('tab', { name: '게시글 설정' }).click();
    const upload = page.getByRole('switch', { name: '첨부 파일 설정' });
    await expect(upload).toBeVisible({ timeout: 20_000 });
    if ((await upload.getAttribute('aria-checked')) !== 'true') {
      await upload.click();
    }

    await expect(page.locator('#section_post_card').getByRole('combobox', { name: '허용 확장자', exact: true })).toBeVisible({ timeout: 10_000 });
    await addTag(page, '허용 확장자', 'e2ext');
    await expect(page.getByRole('button', { name: 'e2ext 지우기' })).toBeVisible({ timeout: 5_000 });
  });

  // @scenario field=blocked_keywords, tab=basic
  // @effects blocked_keywords_absent_from_basic_tab
  test('제한 키워드는 기본 설정 탭에 존재하지 않는다 (게시글 설정 탭 소속)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 기본 설정 구획 — 화면이 그려진 뒤(슬러그 칸 보임) 그 구획 안에 금지어 칸이 없고, 게시글 설정 구획에는 있다(대조군)
    const basic = page.locator('#section_basic_card');
    await expect(basic.getByRole('textbox', { name: '슬러그' })).toBeVisible({ timeout: 20_000 });
    await expect(basic.getByRole('combobox', { name: '금지어', exact: true })).toHaveCount(0);
    await expect(page.locator('#section_post_card').getByRole('combobox', { name: '금지어', exact: true })).toHaveCount(1);
  });

  // (mode=update — 수정 폼 재진입. mode 는 매니페스트 미선언 축이라 평문으로 남긴다)
  // @scenario field=blocked_keywords, tab=post
  // @effects taginput_values_persist_as_array_chips_on_reentry
  test('저장한 제한 키워드가 수정 폼 재진입 시 칩으로 복원된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));

    const slug = `e2e-restrict-${Date.now()}`;
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    try {
      // 1) 생성: 기본 설정 탭에서 슬러그·이름, 게시글 설정 탭에서 금지어 2건
      await page.getByRole('textbox', { name: '슬러그' }).fill(slug);
      await page.getByRole('textbox', { name: '게시판명 (한국어)' }).fill('E2E 제한어');
      await page.getByRole('tab', { name: '게시글 설정' }).click();
      await addTag(page, '금지어', 'e2e금지A');
      await addTag(page, '금지어', 'e2e금지B');

      const created = page.waitForResponse(
        (res) => res.request().method() === 'POST' && new URL(res.url()).pathname === BOARD_API,
        { timeout: 30_000 },
      );
      await page.getByRole('button', { name: '저장', exact: true }).click();
      expect((await created).status()).toBeLessThan(300);

      // 2) 수정 폼 재진입(새로 불러오기) → 칩 2건 복원
      await page.goto(`/admin/boards/${slug}/edit`);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
      await page.getByRole('tab', { name: '게시글 설정' }).click();
      await expect(page.getByRole('button', { name: 'e2e금지A 지우기' })).toBeVisible({ timeout: 20_000 });
      await expect(page.getByRole('button', { name: 'e2e금지B 지우기' })).toBeVisible();
    } finally {
      await api(page, 'DELETE', `${BOARD_API}/${slug}`);
    }
  });
});
