/**
 * 페이지 첨부 다중 업로드 순서 / 드래그 재정렬 → form.attachments 동기화
 * (placeholder — data-testid 보강 후 활성화).
 *
 * @scenario upload_count=single, upload_count=multiple, form_mode=create, form_mode=edit
 * @effects temp_attachments_linked_in_upload_order_1_to_n,
 *          edit_mode_drag_persists_via_reorder_api
 *
 * e2e:allow 다중 업로드 시 첨부가 올린 순서대로 저장되고(생성 모드 temp_key 연결),
 *           수정 모드 드래그 재정렬이 reorder API 로 즉시 저장되어 form.attachments 에 반영되는
 *           동작을 백엔드 Feature/Unit 테스트와 레이아웃 렌더 테스트가 커버한다. 본 placeholder
 *           spec(test.describe.skip)은 data-testid 보강 후 활성화된다.
 *           - 생성 모드 업로드 순서 1..N 재부여: PageAttachmentServiceTest (unit)
 *           - onUploadComplete/onReorder/onRemove → form.attachments 동기화:
 *             admin-page-layouts.test.ts > "첨부 순서 — form.attachments 동기화" (레이아웃 렌더)
 *           브라우저 실제 드래그 인터랙션의 순서 영속만 본 spec 이 확인한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. FileUploader 첨부 아이템에 data-testid="page-attachment-item" (순서 assert 용)
 *   2. 첨부 아이템 드래그 핸들에 data-testid="page-attachment-drag-handle"
 *   3. seededPage fixture(playwright:seed-page) 실 시드 로직 구현 (첨부 N건 order 1..N)
 *   4. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 page-attachment-order-and-seo-cache.yaml 의 upload_count × form_mode 와 1:1):
 *   - create × multiple: 업로드(조회) 순서대로 저장 후 목록 순서 유지
 *   - edit × multiple:   드래그로 순서 변경 후 재조회 시 변경 순서 영속
 */
import { mergeTests } from '@playwright/test';
import { test as authTest, expect, authenticatePage } from '../../fixtures/page-auth';
import { test as seedTest } from '../../fixtures/page-seed';

const test = mergeTests(authTest, seedTest);

const CREATE_URL = '/admin/pages/create';
const EDIT_URL = '/admin/pages/1/edit';

test.describe.skip('페이지 첨부 순서 동기화 (placeholder — data-testid 보강 후 활성화)', () => {
  test('등록 폼에서 여러 첨부를 올리면 올린 순서대로 목록에 표시된다', async ({
    page,
    pageCreateToken,
  }) => {
    await authenticatePage(page, pageCreateToken);
    await page.goto(CREATE_URL);

    // (data-testid 보강 후) 파일 3건을 순차 업로드하고 목록 순서가 업로드 순서와 일치하는지 확인
    await expect(page.getByTestId('page-attachment-item')).toHaveCount(3);
  });

  test('수정 폼에서 첨부를 드래그로 재정렬하면 변경된 순서가 영속된다', async ({
    page,
    pageManageToken,
    seededPage,
  }) => {
    await authenticatePage(page, pageManageToken);
    await page.goto(EDIT_URL);

    const items = page.getByTestId('page-attachment-item');
    await expect(items).toHaveCount(seededPage.attachmentIds.length);

    // (data-testid 보강 후) 첫 아이템을 마지막으로 드래그 → reorder API 발화 → 재조회 시 순서 영속
    // 드래그 인터랙션 + 재조회 후 순서 assert 는 활성화 시 구현
  });
});