/**
 * 페이지 폼 취소 버튼 이동 경로 — 수정 모드는 상세로, 등록 모드는 목록으로.
 *
 * 결함: 취소 버튼이 navigateBack(history.back)이라 직전 위치(다른 관리 메뉴)로 튀거나 URL 직접
 *   진입 시 아무 데도 못 갔다. navigate + route.id 분기(수정=상세 /admin/pages/{id},
 *   등록=목록 /admin/pages)로 교체했다.
 *
 * 레이아웃 렌더링 테스트(admin-page-layouts.test.ts > "[M6] 취소 버튼이 navigateBack 이 아니라
 * navigate 로 목록/상세를 명시함")가 핸들러와 path 표현식을 구조적으로 잠그고, 본 spec 이
 * 실제 라우트 이동(직접 진입 → 취소 → 목록/상세)을 브라우저에서 잠근다.
 *
 * @scenario form_mode=create, form_mode=edit
 * @effects cancel_in_edit_mode_navigates_to_detail,
 *          cancel_in_create_mode_navigates_to_list
 *
 * 남은 개선: EDIT_URL 이 /admin/pages/1 을 하드코딩해 1번 페이지가 존재하는 환경에만 의존한다.
 *   seededPage fixture(playwright:seed-page)가 돌려주는 id 로 교체하면 환경 독립이 된다.
 *
 * 매트릭스(시나리오 매니페스트 form_mode axis 와 1:1):
 *   - edit 모드 직접 진입 → 취소 → /admin/pages/{id} (상세)
 *   - create 모드 직접 진입 → 취소 → /admin/pages (목록)
 */
import { test, expect, authenticatePage } from '../../fixtures/page-auth';

const EDIT_URL = '/admin/pages/1/edit';
const CREATE_URL = '/admin/pages/create';

test.describe('페이지 폼 취소 이동 경로', () => {
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
