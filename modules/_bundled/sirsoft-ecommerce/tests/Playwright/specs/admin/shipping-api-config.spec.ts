/**
 * 관리자 배송정책 — 계산 API 연동 고급 설정 (skeleton, placeholder).
 *
 * @scenario http_method GET POST × auth_type none bearer custom_header × response_type json text
 * @effects method_get_sends_query_string, bearer_attaches_authorization_header,
 *          custom_header_requires_header_name, field_map_renames_request_keys,
 *          json_nested_path_extracted, text_currency_stripped_to_number,
 *          auth_token_masked_in_response, test_call_returns_preview_and_fee,
 *          options_from_backend_enum_ssot
 *
 * 배경(MP12): 배송정책 부과정책 "외부 API 연동" 선택 시 HTTP 메서드/인증/필드매핑/응답형식을
 *   설정하고, "테스트 호출" 버튼으로 실제 API 응답·추출 배송비를 미리볼 수 있다. 인증 토큰은
 *   저장 후 마스킹되어 표시된다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 부과정책 Select 에 data-testid="charge-policy-select"
 *   2. API 엔드포인트 Input 에 data-testid="api-endpoint"
 *   3. HTTP 메서드 Select 에 data-testid="api-http-method"
 *   4. 인증 방식 Select 에 data-testid="api-auth-type"
 *   5. 인증 헤더명/토큰 Input 에 data-testid="api-auth-header-name" / "api-auth-token"
 *   6. 응답 형식 Select / 경로 Input 에 data-testid="api-response-type" / "api-response-path"
 *   7. 테스트 호출 버튼에 data-testid="api-test-call"
 *   8. 테스트 결과 영역에 data-testid="api-test-result"
 *   9. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 mp12-shipping-api-integration.yaml 와 1:1):
 *   - 인증 custom_header 선택 → 헤더명 입력란 노출, 토큰 입력란 노출
 *   - 응답형식 text 선택 → 응답 경로 입력란 숨김
 *   - 테스트 호출 → 요청 메서드 + 응답 상태 + 추출 배송비 표시
 *   - 저장 후 재진입 → 토큰 입력란이 마스킹 placeholder 로 표시(평문 미노출)
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const NEW_POLICY_URL = '/admin/ecommerce/shipping-policies/create';

test.describe.skip('관리자 배송정책 — 계산 API 연동 고급 설정 (placeholder — data-testid 보강 후 활성화)', () => {
  test('인증 custom_header 선택 시 헤더명 입력란이 노출된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(NEW_POLICY_URL);

    await page.getByTestId('charge-policy-select').selectOption('api');
    await page.getByTestId('api-auth-type').selectOption('custom_header');

    await expect(page.getByTestId('api-auth-header-name')).toBeVisible();
    await expect(page.getByTestId('api-auth-token')).toBeVisible();
  });

  test('응답 형식 text 선택 시 응답 경로 입력란이 숨겨진다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(NEW_POLICY_URL);

    await page.getByTestId('charge-policy-select').selectOption('api');
    await page.getByTestId('api-response-type').selectOption('text');

    await expect(page.getByTestId('api-response-path')).not.toBeVisible();
  });

  test('테스트 호출 → 요청 메서드 + 응답 상태 + 추출 배송비가 표시된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(NEW_POLICY_URL);

    await page.getByTestId('charge-policy-select').selectOption('api');
    await page.getByTestId('api-endpoint').fill('https://shipping.example.com/calc');
    await page.getByTestId('api-test-call').click();

    await expect(page.getByTestId('api-test-result')).toBeVisible({ timeout: 5_000 });
  });
});
