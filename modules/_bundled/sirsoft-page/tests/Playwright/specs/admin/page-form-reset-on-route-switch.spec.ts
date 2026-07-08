/**
 * 페이지 수정→등록 라우트 전환 시 폼/첨부/검증상태 잔존 차단 + 슬러그 미리보기 fallback
 * (placeholder — data-testid 보강 후 활성화).
 *
 * @scenario form_mode=create, form_mode=edit
 * @effects create_form_clears_stale_attachments_via_form_attachments_binding,
 *          create_form_clears_stale_title_slug_and_slug_check_state,
 *          slug_hint_url_preview_falls_back_to_example_on_empty_slug
 *
 * e2e:allow 수정→등록 SPA 라우트 전환 시 이전 페이지의 제목/슬러그/첨부/검증상태가 등록 폼에
 *           잔존하던 회귀와, 빈 슬러그에서 미리보기가 "/page/{{slug}}" 처럼 미치환되던 회귀를
 *           레이아웃 렌더 테스트가 구조적으로 차단한다. 본 placeholder spec(test.describe.skip)은
 *           data-testid 보강 후 활성화된다.
 *           레이아웃 렌더링 테스트(admin-page-layouts.test.ts > "수정→등록 SPA 전환 시 폼/첨부 잔존 차단")가
 *           (1) 생성 모드 init_actions 가 _local.form 을 null→빈 기본값으로 초기화해 이전
 *               id/title/slug/attachments 참조를 끊고,
 *           (2) FileUploader initialFiles 가 _local.form.attachments 에 바인딩되며,
 *           (3) slug_hint 가 빈 슬러그('')에서 || 'example' fallback 으로 {{slug}} 미치환을 차단함을
 *           구조적으로 회귀 차단한다. 브라우저 실 전환(라우트 A→B)의 리마운트 동작만 본 spec 이 확인한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 페이지 제목 입력에 data-testid="page-title-input"
 *   2. 슬러그 입력에 data-testid="page-slug-input"
 *   3. 슬러그 URL 미리보기 텍스트에 data-testid="page-slug-hint"
 *   4. 첨부 목록 컨테이너/아이템에 data-testid="page-attachment-item"
 *   5. seededPage fixture(playwright:seed-page) 실 시드 로직 구현
 *   6. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 page-attachment-order-and-seo-cache.yaml 의 form_mode axis 와 1:1):
 *   - edit→create 전환: 이전 제목/슬러그/첨부가 등록 폼에 비워진 상태로 진입
 *   - create 진입 직후: 슬러그 미입력 상태에서 미리보기가 "/page/example"
 *   - create 에서 슬러그 입력: 미리보기가 입력값으로 즉시 치환
 */
import { test, expect, authenticatePage } from '../../fixtures/page-auth';

const EDIT_URL = '/admin/pages/1/edit';
const CREATE_URL = '/admin/pages/create';

test.describe.skip('페이지 수정→등록 전환 잔존 차단 (placeholder — data-testid 보강 후 활성화)', () => {
  test('수정 폼에서 등록 폼으로 전환하면 이전 제목/슬러그/첨부가 잔존하지 않는다', async ({
    page,
    pageManageToken,
  }) => {
    await authenticatePage(page, pageManageToken);

    // 1) 수정 폼 진입 — 기존 페이지의 제목/슬러그/첨부가 채워진 상태
    await page.goto(EDIT_URL);
    await expect(page.getByTestId('page-title-input')).not.toHaveValue('');

    // 2) 등록 폼으로 SPA 전환 (같은 layout_name 재사용 경로)
    await page.getByTestId('page-nav-create').click();
    await expect(page).toHaveURL(new RegExp('/admin/pages/create$'));

    // 3) 이전 값이 잔존하지 않아야 함
    await expect(page.getByTestId('page-title-input')).toHaveValue('');
    await expect(page.getByTestId('page-slug-input')).toHaveValue('');
    await expect(page.getByTestId('page-attachment-item')).toHaveCount(0);
  });

  test('등록 폼 진입 직후 슬러그 미입력이면 미리보기가 /page/example 로 표시된다', async ({
    page,
    pageCreateToken,
  }) => {
    await authenticatePage(page, pageCreateToken);
    await page.goto(CREATE_URL);

    // 빈 슬러그에서 {{slug}} 미치환이 아니라 example fallback
    await expect(page.getByTestId('page-slug-hint')).toContainText('/page/example');
    await expect(page.getByTestId('page-slug-hint')).not.toContainText('{{slug}}');
  });

  test('등록 폼에서 슬러그를 입력하면 미리보기가 입력값으로 즉시 치환된다', async ({
    page,
    pageCreateToken,
  }) => {
    await authenticatePage(page, pageCreateToken);
    await page.goto(CREATE_URL);

    await page.getByTestId('page-slug-input').fill('my-new-page');
    await expect(page.getByTestId('page-slug-hint')).toContainText('/page/my-new-page');
  });
});