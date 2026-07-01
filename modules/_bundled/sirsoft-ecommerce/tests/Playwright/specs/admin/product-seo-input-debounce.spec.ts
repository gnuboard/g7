/**
 * 상품 폼 SEO 설정 입력 디바운스 — 타이핑 지연 수정 (skeleton, placeholder).
 *
 * @scenario product-seo-input-debounce
 * @effects debounce_300_on_meta_title,
 *          debounce_300_on_meta_description,
 *          value_via_event_target_value,
 *          setstate_target_local,
 *          has_changes_only_in_edit_mode
 *
 * e2e:allow SEO 제목/설명 입력 디바운스(타이핑 지연 수정) 회귀를 레이아웃 구조 테스트가
 *           구조적으로 차단한다. 본 placeholder spec(test.describe.skip)은 data-testid
 *           보강 후 활성화된다.
 *           레이아웃 렌더링 테스트(productFormLayouts.test.tsx > "SEO 설정 텍스트 입력 디바운스")가
 *           (1) meta_title_custom Input · meta_desc_custom Textarea 의 change 핸들러에
 *               debounce:300 이 부여되고,
 *           (2) 값은 {{$event.target.value}} 로 전달되며(=$event 객체 직접 저장 회피),
 *           (3) target=local + hasChanges={{!!route?.itemCode}} 임을
 *           구조적으로 회귀 차단한다. debounce 동작 자체는 코어 템플릿 엔진의 기존
 *           action-level debounce 검증 대상이라 본 spec 은 입력 반영/미리보기 갱신만 확인한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. SEO 설정 아코디언 헤더에 data-testid="product-seo-accordion"
 *   2. SEO 제목 커스텀 입력에 data-testid="seo-meta-title-input"
 *   3. SEO 설명 커스텀 입력에 data-testid="seo-meta-description-input"
 *   4. 검색결과 미리보기 제목/설명에 data-testid="seo-preview-title" / "seo-preview-description"
 *   5. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 product-seo-input-debounce.yaml 와 1:1):
 *   - 제목 입력(create/edit)   : debounce 후 미리보기 제목이 입력값으로 갱신
 *   - 설명 입력(create/edit)   : debounce 후 미리보기 설명이 입력값으로 갱신
 *   - 빠른 연속 입력           : 입력 도중 값 유실/원복 없이 최종값이 반영
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 옵션 보유 시드 상품의 수정폼 (숫자 id 경로 — product_code 직접 진입은 detail API 405)
const EDIT_URL = '/admin/ecommerce/products/306/edit';

test.describe.skip('상품 폼 SEO 입력 디바운스 (placeholder — data-testid 보강 후 활성화)', () => {
  test('SEO 제목을 입력하면 debounce 후 검색결과 미리보기 제목이 갱신된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-seo-accordion').click();
    const titleInput = page.getByTestId('seo-meta-title-input');
    await titleInput.fill('새 SEO 제목');

    // debounce(300ms) 이후 미리보기에 반영 — 입력 도중 원복 없이 최종값
    await expect(page.getByTestId('seo-preview-title')).toHaveText('새 SEO 제목', {
      timeout: 10_000,
    });
  });

  test('SEO 설명을 입력하면 debounce 후 검색결과 미리보기 설명이 갱신된다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-seo-accordion').click();
    const descInput = page.getByTestId('seo-meta-description-input');
    await descInput.fill('새 SEO 설명');

    await expect(page.getByTestId('seo-preview-description')).toHaveText('새 SEO 설명', {
      timeout: 10_000,
    });
  });

  test('빠른 연속 입력 후에도 최종값이 유실 없이 반영된다 (debounce 플리커 회귀)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-seo-accordion').click();
    const titleInput = page.getByTestId('seo-meta-title-input');
    await titleInput.pressSequentially('빠른연속입력최종', { delay: 30 });

    // 입력 도중 stale value 로 되돌아가지 않고 최종값이 입력칸·미리보기에 동일하게 유지
    await expect(titleInput).toHaveValue('빠른연속입력최종');
    await expect(page.getByTestId('seo-preview-title')).toHaveText('빠른연속입력최종', {
      timeout: 10_000,
    });
  });
});
