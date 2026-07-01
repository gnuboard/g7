/**
 * 상품폼 추가옵션 선택지 round-trip + 생성 후 id 기반 리다이렉트 회귀 (skeleton, placeholder).
 *
 * 세션 D 정밀 점검에서 발견·수정한 2건의 관리자 폼 회귀를 커버한다.
 *
 * @scenario product-additional-options
 * @effects edit_form_loads_existing_option_values,
 *          save_persists_option_values_without_loss,
 *          create_redirects_to_numeric_id_edit_url,
 *          redirected_edit_form_loads_data
 *
 * e2e:allow 세션 D 회귀 수정 — 단위/구조 테스트로 1차 차단하고, 브라우저 회귀는 본 placeholder
 *           (test.describe.skip)가 data-testid 보강 후 활성화될 때 검증한다.
 *           현재 커버리지:
 *           (1) 회귀#1 (관리자 수정폼이 기존 추가옵션 선택지(values)를 미로드 → 저장 시 선택지 영구 소실):
 *               findWithOptions 가 additionalOptions.values 를 eager-load 하고 ProductResource 가 values 를
 *               round-trip 형식으로 노출함을 PHPUnit
 *               tests/Unit/Resources/ProductResourceAdditionalOptionsTest.php
 *               (test_get_detail_eager_loads_additional_option_values / test_resource_exposes_additional_option_values)
 *               가 검증한다. 수정 전 fail → eager-load 추가 후 green.
 *           (2) 회귀#2 (저장 onSuccess navigate 가 product_code URL 사용 → detail API 405 → 폼 빈 로드):
 *               생성모드 navigate path 가 result.data.id 를 사용(product_code 미사용)함을 레이아웃 구조 테스트
 *               resources/js/__tests__/layouts/productOptionsAdditionalToggle.test.tsx
 *               ("회귀: 상품 생성/저장 후 navigate 는 id 기반") 가 검증한다.
 *           라이브 재검(Playwright MCP, 실 도메인 g7_2.dev):
 *             - 회귀#1: 상품 306 edit 재로드 시 각인(없음+0/각인추가+5000)·선물포장(기본포장+3000/고급포장 off)
 *               선택지가 form.additional_options[].values 로 정상 로드됨을 확인.
 *             - 회귀#2: 신규 상품 생성 → /admin/ecommerce/products/314/edit (숫자 id) 로 리다이렉트되고
 *               리다이렉트된 폼이 데이터를 로드(isDataLoaded 진입, name/code/options 표시)함을 확인.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 상품옵션 탭 버튼에 data-testid="product-tab-options"
 *   2. 추가옵션 선택지 행(name/추가금)에 data-testid="additional-value-{groupIdx}-{valueIdx}"
 *   3. 신규 등록 폼 필수 필드(상품코드/상품명/재고/카테고리/옵션) data-testid + 저장 버튼 data-testid="product-save"
 *   4. test.describe.skip → test.describe 변경 + 추가옵션 선택지 보유 상품 시드(§12.B 전제)
 *
 * 매트릭스 (시나리오 매니페스트 product-additional-options.yaml 와 1:1):
 *   - 수정폼 진입: 기존 선택지(이름/추가금/기본/활성/정렬)가 round-trip 로드된다 (회귀#1)
 *   - 저장 후 재로드: 선택지가 소실 없이 영속된다 (회귀#1)
 *   - 신규 생성 저장: /products/{숫자 id}/edit 로 리다이렉트된다 (회귀#2)
 *   - 리다이렉트된 폼: 데이터가 로드된다(405 빈 로드 아님) (회귀#2)
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 추가옵션 그룹 + 선택지를 보유한 시드 상품의 수정폼 (숫자 id 경로 — product_code 직접 진입은 detail API 405)
const EDIT_URL = '/admin/ecommerce/products/306/edit';
const CREATE_URL = '/admin/ecommerce/products/create';

test.describe.skip('상품폼 추가옵션 round-trip + 생성 리다이렉트 (placeholder — data-testid 보강 후 활성화)', () => {
  test('수정폼 진입 — 기존 추가옵션 선택지가 round-trip 로드된다 (회귀#1)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(EDIT_URL);

    await page.getByTestId('product-tab-options').click();

    // 그룹의 첫 선택지가 이름·추가금을 보유한 채 렌더되어야 한다 (values 미로드 시 0행 → 저장 시 소실)
    await expect(page.getByTestId('additional-value-0-0')).toBeVisible({ timeout: 10_000 });
  });

  test('신규 생성 저장 — 숫자 id 기반 edit URL 로 리다이렉트되고 폼이 로드된다 (회귀#2)', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto(CREATE_URL);

    // (필수 필드 입력 — data-testid 보강 후 구현)
    await page.getByTestId('product-save').click();

    // product_code 가 아닌 숫자 id 경로로 이동해야 한다 (회귀: code → detail API 405 빈 로드)
    await expect(page).toHaveURL(/\/admin\/ecommerce\/products\/\d+\/edit/, { timeout: 15_000 });
  });
});
