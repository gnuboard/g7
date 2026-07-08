/**
 * 페이지 폼 취소 버튼 이동 경로 — 수정 모드는 상세로, 등록 모드는 목록으로
 * (placeholder — data-testid 보강 후 활성화).
 *
 * @scenario form_mode=create, form_mode=edit
 * @effects cancel_in_edit_mode_navigates_to_detail,
 *          cancel_in_create_mode_navigates_to_list
 *
 * e2e:allow 취소 버튼이 navigateBack(history.back)이던 회귀 — 직전 위치(다른 관리 메뉴)로
 *           튀거나 URL 직접 진입 시 멈추던 문제를, navigate + route.id 분기(수정=상세 /admin/pages/{id},
 *           등록=목록 /admin/pages)로 교체했다.
 *           레이아웃 렌더링 테스트(admin-page-layouts.test.ts > "[M6] 취소 버튼이 navigateBack 이 아니라
 *           navigate 로 목록/상세를 명시함")가 핸들러가 navigateBack 이 아니라 navigate 이고 path 표현식이
 *           route.id 분기를 가짐을 구조적으로 회귀 차단한다. 본 placeholder spec(test.describe.skip)은
 *           취소 버튼에 data-testid 보강 후 활성화되어 실제 라우트 이동(직접 진입 → 취소 → 목록/상세)을 확인한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다:
 *   1. 취소 버튼(footer_cancel_button)에 data-testid="page-form-cancel"
 *   2. seededPage fixture(playwright:seed-page) 실 시드 로직 구현
 *   3. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 form_mode axis 와 1:1):
 *   - edit 모드 직접 진입 → 취소 → /admin/pages/{id} (상세)
 *   - create 모드 직접 진입 → 취소 → /admin/pages (목록)
 */
import { test, expect, authenticatePage } from '../../fixtures/page-auth';

const EDIT_URL = '/admin/pages/1/edit';
const CREATE_URL = '/admin/pages/create';

test.describe.skip('페이지 폼 취소 이동 경로 (placeholder — data-testid 보강 후 활성화)', () => {
  test('수정 폼에 직접 진입 후 취소하면 해당 페이지 상세로 이동한다', async ({
    page,
    pageManageToken,
  }) => {
    await authenticatePage(page, pageManageToken);

    // URL 직접 진입 (직전 history 없음 — navigateBack 이면 목록/상세로 못 감)
    await page.goto(EDIT_URL);
    await page.getByTestId('page-form-cancel').click();

    // 수정 모드 → 상세로 이동
    await expect(page).toHaveURL(new RegExp('/admin/pages/1$'));
  });

  test('등록 폼에 직접 진입 후 취소하면 목록으로 이동한다', async ({
    page,
    pageCreateToken,
  }) => {
    await authenticatePage(page, pageCreateToken);

    await page.goto(CREATE_URL);
    await page.getByTestId('page-form-cancel').click();

    // 등록 모드 → 목록으로 이동
    await expect(page).toHaveURL(new RegExp('/admin/pages$'));
  });
});
