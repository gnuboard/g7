/**
 * 페이지 첨부 — 첨부 업로더 확장점 기본 경로의 업로드 → 저장 → 재진입.
 *
 * 레이아웃의 FileUploader 는 `attachment_uploader` 확장점의 default 안으로 옮겨졌다. 대체 업로더가 없는 기본 경로에서
 * 저장 버튼의 `upload:page_attachments` 신호와 결과(`_eventResult`) 모양이 그대로여야 첨부가 페이지에 붙는다. 이 스펙은
 * 등록 폼에서 첨부 1개를 골라 저장하고, 수정 화면에 다시 들어가 ① 서버 상세의 첨부와 화면 첨부가 같고 ② 업로더 DOM 이
 * 하나뿐(확장점 감싸기로 중복 렌더되지 않음)인지 잰다. 만든 페이지는 끝에 지운다(첨부는 페이지 삭제와 함께 정리된다).
 */
import { test, expect, authenticatePage } from '../../fixtures/page-auth';

const CREATE_URL = '/admin/pages/create';
const API = '/api/modules/sirsoft-page/admin/pages';

/** 서버는 내용으로 형식을 판정하므로 PDF 머리말을 붙인다 */
const FILE = { name: 'e2e-uploader-reentry.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%e2e-uploader-reentry\n') };

test.describe('페이지 첨부 업로더 확장점 — 업로드 → 저장 → 재진입', () => {
  test.beforeEach(async ({ page }) => {
    // 업로드 → 저장 → 재진입 세 화면 동작이라 기본 30초를 넘는다(개발 서버 실측: 업로드와 저장만 20초대)
    test.setTimeout(120_000);
    // 동작 대기 상한 — 저장소 설정에 actionTimeout 이 없어 틀린 선택자 하나가 시험 제한을 통째로 쓰지 않게 한다
    page.setDefaultTimeout(20_000);
    page.setDefaultNavigationTimeout(60_000);
  });

  // @scenario case=create_save_reenter
  // @effects page_attachment_saved_via_default_uploader_event_result, page_attachment_reentry_matches_server_single_uploader
  test('등록 폼에서 첨부를 올려 저장하면 수정 화면 재진입 때 서버 첨부와 같은 한 개의 업로더로 보인다', async ({ page, pageManageToken }) => {
    await authenticatePage(page, pageManageToken);
    await page.goto(CREATE_URL, { waitUntil: 'domcontentloaded' });

    const slug = `e2e-uploader-reentry-${Date.now()}`;
    await page.locator('input[placeholder="url-slug"]').fill(slug);
    await page.getByRole('button', { name: '중복 확인' }).click();
    await expect(page.getByText('사용 가능한 슬러그입니다.')).toBeVisible();
    await page.locator('input[name="title[ko]"]').fill('E2E 업로더 재진입');

    // 등록 화면에서도 업로더는 하나다
    // 엔진 스코프: 확장점 호스트 아래(기본 내용 포함)의 정적 노드 id 는 DOM 에 {id}__{호스트 id} 로 나가므로 접두어 [id^="{id}__"] 로 찾는다.
    await expect(page.locator('[id^="file_uploader__"]')).toHaveCount(1);

    const [chooser] = await Promise.all([
      page.waitForEvent('filechooser'),
      page.getByText('파일을 드래그하거나 클릭하여 업로드').first().click(),
    ]);
    await chooser.setFiles(FILE);

    const created = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(API), { timeout: 90_000 });
    await page.getByRole('button', { name: '저장', exact: true }).click();
    const response = await created;
    expect(response.status()).toBe(201);
    const pageId = (await response.json()).data.id as number;
    const headers = { Authorization: `Bearer ${pageManageToken}`, Accept: 'application/json' };

    try {
      // 서버 값 — 저장 흐름이 업로더 결과(_eventResult)로 첨부를 붙였는지
      const detail = await page.request.get(`${API}/${pageId}`, { headers });
      expect(detail.status()).toBe(200);
      const attachments = ((await detail.json()).data.attachments ?? []) as Array<{ id: number; original_filename: string }>;
      expect(attachments.map((a) => a.original_filename)).toEqual([FILE.name]);

      // 재진입 — 수정 화면을 새로 연다
      await page.goto(`/admin/pages/${pageId}/edit`, { waitUntil: 'domcontentloaded' });
      // 수정 화면은 상세 데이터를 받은 뒤 그린다 — 첨부 이름이 보일 때까지 기다린 다음 센다
      const uploader = page.locator('[id^="file_uploader__"]');
      await expect(uploader.getByText(FILE.name, { exact: true })).toBeVisible({ timeout: 45_000 });
      await expect(uploader).toHaveCount(1);
      await expect(uploader.getByText(FILE.name, { exact: true })).toHaveCount(1);

      // 화면에 보이는 첨부 이름 목록이 서버 값과 같다(다른 첨부가 섞이거나 빠지지 않음)
      const shown = await uploader.getByText(/\.pdf$/).allInnerTexts();
      expect(shown.map((s) => s.trim())).toEqual(attachments.map((a) => a.original_filename));
    } finally {
      await page.request.delete(`${API}/${pageId}`, { headers });
    }
  });
});
