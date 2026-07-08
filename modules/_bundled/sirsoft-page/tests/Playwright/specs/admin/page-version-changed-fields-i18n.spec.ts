/**
 * 페이지 상세 버전 이력의 변경 필드명 다국어 라벨 렌더
 * (placeholder — data-testid 보강 후 활성화).
 *
 * @scenario page_change=update
 * @effects page_version_changed_fields_render_localized_labels
 *
 * e2e:allow 페이지 상세의 버전 이력 "변경 항목" 이 필드 키(title/slug/content 등) 원문 대신
 *           다국어 라벨로 표시되는 동작을 레이아웃 렌더 테스트가 구조적으로 커버한다. 본
 *           placeholder spec(test.describe.skip)은 data-testid 보강 후 활성화된다.
 *           레이아웃 렌더링 테스트(admin-page-layouts.test.ts > "변경내역 필드명 다국어 매핑")가
 *           changes_summary.changed_fields 를
 *           $t('sirsoft-page.admin.page.detail.versions.field_labels.<field>') 로 매핑함을
 *           구조적으로 회귀 차단한다. 브라우저 실제 로케일 렌더만 본 spec 이 확인한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 버전 이력 행의 변경 항목 셀에 data-testid="page-version-changed-fields"
 *   2. seededPage fixture(playwright:seed-page) 실 시드 로직 구현 (버전 이력 있는 페이지)
 *   3. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 page-attachment-order-and-seo-cache.yaml 의 page_change=update 와 1:1):
 *   - update 후 상세 진입: 변경 항목이 필드 키 원문이 아닌 다국어 라벨로 표시
 */
import { test, expect, authenticatePage } from '../../fixtures/page-auth';

const DETAIL_URL = '/admin/pages/1';

test.describe.skip('페이지 버전 변경 필드 다국어 라벨 (placeholder — data-testid 보강 후 활성화)', () => {
  test('버전 이력의 변경 항목이 필드 키 원문 대신 다국어 라벨로 표시된다', async ({
    page,
    pageReadToken,
  }) => {
    await authenticatePage(page, pageReadToken);
    await page.goto(DETAIL_URL);

    const changedFields = page.getByTestId('page-version-changed-fields').first();
    // 필드 키 원문(title/slug/content)이 그대로 노출되지 않아야 함
    await expect(changedFields).not.toHaveText(/\b(title|slug|content)\b/);
    // 다국어 라벨(예: 제목/주소/내용)로 렌더 — 활성화 시 구체 라벨 assert
    await expect(changedFields).not.toHaveText('');
  });
});