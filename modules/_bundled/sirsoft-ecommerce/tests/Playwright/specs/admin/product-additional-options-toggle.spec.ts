/**
 * 상품폼 추가옵션 "사용/미사용" 토글 + 비우기 확인 모달.
 *
 * @scenario admin-product-additional-options-toggle
 * 효과 요약(마커 아님 — 평문): 미사용 클릭 시 확인 모달과 두 버튼, 확인 시 비우기와 토글 전환, 취소 시 유지.
 *   효과 마킹은 각 테스트 주석에 있다.
 *
 * §13-D-FAIL 회귀(확인 모달 footer 버튼 미렌더 + 비우기 후 토글 미갱신)를 브라우저에서 직접 차단한다.
 * 같은 회귀를 구조적으로 고정하는 하위 테스트는 그대로 유지된다 —
 * 레이아웃 렌더링 테스트(productOptionsAdditionalToggle.test.tsx)가 footer 버튼의 children 배치와
 * 확인 버튼의 clearAdditionalOptions 핸들러 호출을, 핸들러 단위 테스트
 * (optionHandlers.test.ts > clearAdditionalOptionsHandler)가 form 객체 통째 교체를 검증한다.
 *
 * 추가옵션 행 컨테이너는 testid 가 아니라 레이아웃이 이미 가진 DOM id(#additional_options_content)로
 * 잡는다 — 그 노드에 props 를 새로 만들어 붙이면 서빙 단계에서 props 가 [] 로 버려진다(실측).
 *
 * 대상 상품은 spec 이 API 로 만들고 끝나면 지운다(additional-option-product-seed.ts) — 상품 목록 앞쪽을
 * 뒤져 추가옵션 보유 상품을 찾던 방식은 공유 DB 상태에 따라 30초 제한을 넘기거나 대상이 없었다.
 *
 * 탭 클릭이 없는 이유: 상품폼 탭 네비게이션은 enableScrollSpy 방식이고 추가옵션 섹션에는
 * 조건부 렌더(if)가 없다 — 모든 섹션이 처음부터 DOM 에 있고 탭은 스크롤만 이동시킨다.
 *
 * 매트릭스(시나리오 매니페스트 admin-product-additional-options-toggle.yaml 와 1:1):
 *   - "미사용" 클릭(N행)      : 확인 모달 노출(취소/확인 버튼 렌더)
 *   - 확인                    : 옵션 비워짐 + 토글 "미사용" active 전환 + 행 소멸 + 모달 닫힘
 *   - 취소                    : 옵션 유지 + 모달만 닫힘
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { seedAdditionalOptionProduct, deleteSeededProduct } from '../../fixtures/additional-option-product-seed';

// 숫자 id 경로로 진입한다 — product_code 직접 진입은 detail API 405.
const editUrl = (id: number) => `/admin/ecommerce/products/${id}/edit`;

test.describe('상품폼 추가옵션 토글 + 비우기 확인 모달', () => {
  // 테스트마다 시험 상품을 API 로 만든다 — 상품 생성 POST 1건이 실측 17초(공유 개발 서버)라
  // 기본 30초 제한 안에서는 화면 단계가 시작하자마자 시간이 다 된다.
  test.describe.configure({ timeout: 120_000 });

  let productId = 0;

  test.beforeEach(async ({ request, productSeedToken }) => {
    productId = (await seedAdditionalOptionProduct(request, productSeedToken)).id;
  });

  test.afterEach(async ({ request, productSeedToken }) => {
    if (productId) await deleteSeededProduct(request, productSeedToken, productId);
    productId = 0;
  });

  // @scenario action=click_not_use
  // @effects not_use_opens_clear_confirm_modal, clear_modal_renders_cancel_and_confirm_buttons
  test('미사용 클릭 — 확인 모달이 열리고 취소/확인 버튼이 렌더된다 (§13-D-FAIL footer)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(editUrl(productId));

    await page.getByTestId('additional-option-not-use').click();

    // footer 버튼이 children 으로 이동했으므로 취소/확인 모두 렌더되어야 한다 (slots.footer 였을 땐 미렌더)
    await expect(page.getByTestId('additional-clear-cancel')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('additional-clear-confirm')).toBeVisible();
  });

  // @scenario action=confirm_clear
  // @effects confirm_clears_options_and_toggle_switches_to_not_use
  test('확인 — 옵션이 비워지고 토글이 "미사용" active 로 전환된다 (§13-D-FAIL 토글 미갱신)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(editUrl(productId));

    await page.getByTestId('additional-option-not-use').click();
    await page.getByTestId('additional-clear-confirm').click();

    // clearAdditionalOptions 가 form 을 통째 교체 → 행 소멸 + "미사용" active
    await expect(page.locator('#additional_options_content')).not.toBeVisible();
    await expect(page.getByTestId('additional-option-not-use')).toHaveClass(/active/);
    await expect(page.getByTestId('additional-option-use')).not.toHaveClass(/active/);
  });

  // @scenario action=cancel_clear
  // @effects cancel_keeps_options_and_modal_closes
  test('취소 — 옵션이 유지되고 모달만 닫힌다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(editUrl(productId));

    await page.getByTestId('additional-option-not-use').click();
    await page.getByTestId('additional-clear-cancel').click();

    // 비우기 미실행 — 행 유지 + 모달 닫힘
    await expect(page.getByTestId('additional-clear-confirm')).not.toBeVisible();
    await expect(page.locator('#additional_options_content')).toBeVisible();
  });
});
