/**
 * 페이지 첨부 다중 업로드 순서 / 드래그 재정렬 → form.attachments 동기화.
 *
 * @scenario upload_count=single, upload_count=multiple, form_mode=create, form_mode=edit
 * @effects temp_attachments_linked_in_upload_order_1_to_n,
 *          edit_mode_drag_persists_via_reorder_api
 *
 * 등록 폼(create × multiple)은 활성 상태다. 파일 선택창에서 여러 파일을 한 번에 고르면 업로더가 동시에
 * 올려 먼저 끝난 파일이 앞 번호를 받던 결함(7.1.0 전수 실측 영역 L, O-L1)을 브라우저에서 잠근다 —
 * 가장 큰 파일을 맨 앞에 골라, 동시에 올리면 그 파일이 마지막에 끝나는 조건을 만든다. 순서는 화면 DOM 이
 * 아니라 저장 결과(관리자 상세 API 의 attachments 순서)로 판정하므로 업로더 항목 data-testid 가 필요 없다.
 * 만든 페이지는 테스트 끝에 지운다.
 *
 * e2e:allow 수정 폼 드래그 재정렬(edit × multiple)은 아직 placeholder(test.skip)다. 업로더 항목
 *           data-testid 와 seededPage fixture(playwright:seed-page 실 시드)가 없어 드래그 대상과 시작 첨부를
 *           만들 수 없다. 그 동작은 백엔드 Feature/Unit 테스트와 레이아웃 렌더 테스트가 커버한다.
 *           - 생성 모드 업로드 순서 1..N 재부여·수정 모드 기존 첨부 뒤 연결: PageAttachmentServiceTest,
 *             TempAttachmentLinkOrderTest
 *           - onUploadComplete/onReorder/onRemove → form.attachments 동기화:
 *             admin-page-layouts.test.ts > "첨부 순서 — form.attachments 동기화" (레이아웃 렌더)
 *           - 업로더가 고른 순서대로 올리고 결과를 고른 순서로 돌려줌: sirsoft-admin_basic
 *             FileUploaderSelectionOrder.test.tsx
 *
 * 수정 폼 spec 활성화 절차:
 *   1. FileUploader 첨부 아이템에 data-testid="page-attachment-item" (순서 assert 용)
 *   2. 첨부 아이템 드래그 핸들에 data-testid="page-attachment-drag-handle"
 *   3. seededPage fixture(playwright:seed-page) 실 시드 로직 구현 (첨부 N건 order 1..N)
 *   4. test.skip → test 변경
 *
 * 매트릭스(시나리오 매니페스트 page-attachment-order-and-seo-cache.yaml 의 upload_count × form_mode 와 1:1):
 *   - create × multiple: 고른 순서대로 저장(활성)
 *   - edit × multiple:   드래그로 순서 변경 후 재조회 시 변경 순서 영속(placeholder)
 */
import { mergeTests } from '@playwright/test';
import { test as authTest, expect, authenticatePage } from '../../fixtures/page-auth';
import { test as seedTest } from '../../fixtures/page-seed';

const test = mergeTests(authTest, seedTest);

const CREATE_URL = '/admin/pages/create';
const EDIT_URL = '/admin/pages/1/edit';
const API = '/api/modules/sirsoft-page/admin/pages';

/** 가장 큰 파일을 맨 앞에 둔다 — 동시에 올리면 이 파일이 마지막에 끝난다. 서버는 내용으로 형식을 판정하므로 PDF 머리말을 붙인다. */
const FILES = [
  { name: 'e2e-order-1-large.pdf', mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(3 * 1024 * 1024, 0x20)]) },
  { name: 'e2e-order-2-small.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%e2e-order-2\n') },
  { name: 'e2e-order-3-small.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%e2e-order-3\n') },
];

test.describe('페이지 첨부 순서 동기화', () => {
  // @scenario upload_count=multiple,form_mode=create
  // @effects temp_attachments_linked_in_upload_order_1_to_n
  test('등록 폼에서 여러 첨부를 한 번에 고르면 고른 순서대로 저장된다', async ({ page, pageCreateToken }) => {
    await authenticatePage(page, pageCreateToken);
    await page.goto(CREATE_URL);

    const slug = `e2e-attach-order-${Date.now()}`;
    await page.locator('input[placeholder="url-slug"]').fill(slug);
    await page.getByRole('button', { name: '중복 확인' }).click();
    await expect(page.getByText('사용 가능한 슬러그입니다.')).toBeVisible();
    await page.locator('input[name="title[ko]"]').fill('E2E 첨부 순서');

    const [chooser] = await Promise.all([
      page.waitForEvent('filechooser'),
      page.getByText('파일을 드래그하거나 클릭하여 업로드').first().click(),
    ]);
    await chooser.setFiles(FILES);

    const created = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(API));
    await page.getByRole('button', { name: '저장', exact: true }).click();
    const response = await created;
    expect(response.status()).toBe(201);
    const pageId = (await response.json()).data.id as number;

    try {
      const detail = await page.request.get(`${API}/${pageId}`, {
        headers: { Authorization: `Bearer ${pageCreateToken}`, Accept: 'application/json' },
      });
      const attachments = ((await detail.json()).data.attachments ?? []) as Array<{ original_filename: string; order: number }>;
      const ordered = [...attachments].sort((a, b) => a.order - b.order).map((a) => a.original_filename);
      expect(ordered).toEqual(FILES.map((f) => f.name));
    } finally {
      await page.request.delete(`${API}/${pageId}`, {
        headers: { Authorization: `Bearer ${pageCreateToken}`, Accept: 'application/json' },
      });
    }
  });

  // @scenario upload_count=multiple,form_mode=edit
  // @effects edit_mode_drag_persists_via_reorder_api
  test.skip('수정 폼에서 첨부를 드래그로 재정렬하면 변경된 순서가 영속된다 (placeholder — data-testid·시드 보강 후 활성화)', async ({
    page,
    pageManageToken,
    seededPage,
  }) => {
    await authenticatePage(page, pageManageToken);
    await page.goto(EDIT_URL);

    const items = page.getByTestId('page-attachment-item');
    await expect(items).toHaveCount(seededPage.attachment_ids.length);

    // (data-testid 보강 후) 첫 아이템을 마지막으로 드래그 → reorder API 발화 → 재조회 시 순서 영속
  });
});
