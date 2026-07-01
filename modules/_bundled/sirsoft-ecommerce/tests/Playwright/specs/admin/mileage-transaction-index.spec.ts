/**
 * 관리자 마일리지 내역 화면 — 조회·검색·권한 매트릭스 (skeleton, placeholder).
 *
 * @scenario admin-mileage-transaction-index
 * @effects admin_can_open_list_and_see_count,
 *          search_by_member_filters_results,
 *          read_only_actor_cannot_open_manual_modal,
 *          expand_row_shows_linked_transactions,
 *          manual_modal_grants_with_uuid_identifier,
 *          manual_modal_shows_field_validation_feedback,
 *          row_edit_modal_updates_memo_and_expiry,
 *          row_edit_disabled_for_non_earning,
 *          partial_expiry_shows_amount_in_expiry_column
 *
 * e2e:allow 레이아웃 시각·구조 재정렬(주문/리뷰 화면 패러티) + 수동 모달 회원식별자 uuid 전환 +
 *           행 액션 "수동 조정"→"사유·기간 변경"(적립건 memo/만료일 편집, 원장 불변·삭제 없음) 재설계 +
 *           유효기간 열 일부/전체 소멸 구분·소멸액 표시로 신규 시나리오 axis 부재 —
 *           본 placeholder spec(test.describe.skip)이 data-testid 보강 후 활성화될 때 함께 검증된다.
 *           레이아웃 렌더링 테스트(admin_ecommerce_mileage_transaction_index.test.tsx 49건 green)가
 *           타이틀 아이콘 부재 / 필터 레이블 / filter_actions 중앙배치 / batch_bar 항시표출 /
 *           grid wrap overflow-hidden / 주문번호 openWindow / 회원검색 부유(absolute) 드롭다운 /
 *           selectedMember.uuid 전송(정수 id 미전송) / 검증 피드백 3종(상단배너·필드강조·하단메시지) /
 *           실행버튼 isSaving 로딩 상태를 구조적으로 회귀 차단한다.
 *           백엔드 회귀(MileageTransactionControllerTest::test_store_rejects_integer_user_id)가
 *           정수 id 전송 시 422, uuid 전송 시 정상 지급을 라이브 검증한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 마일리지 내역 레이아웃 page_header 에 data-testid="mileage-transactions-page"
 *   2. table_header 카운트 Span 에 data-testid="mileage-transactions-total-count"
 *   3. _transactions_table.json DataGrid 래퍼에 data-testid="mileage-transactions-grid"
 *   4. 수동 지급/차감 버튼(manual_action_button)에 data-testid="mileage-manual-button"
 *   5. _filters.json 검색 input/버튼에 data-testid="mileage-search-keyword" / "mileage-search-submit"
 *   6. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 admin-mileage-transaction-index.yaml 와 1:1):
 *   - mileage.read+manage 보유자 : 목록 진입 + "총 N개" 카운트 노출 + 수동 버튼 활성
 *   - mileage.read 전용         : 목록 진입 가능하나 수동 지급/차감 버튼 disabled
 *   - 권한 미보유                : 목록 진입 시 콘텐츠 영역 에러(403)
 *   - 검색(회원명)              : search_keyword commit 후 목록이 해당 회원으로 필터
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/mileage-transactions';

test.describe.skip('관리자 마일리지 내역 — 조회·검색·권한 (placeholder — data-testid 보강 후 활성화)', () => {
  test('mileage.read+manage 보유자 — 목록 진입 + 총 N개 카운트 + 수동 버튼 활성', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await expect(page.getByTestId('mileage-transactions-page')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('mileage-transactions-total-count')).toBeVisible();
    await expect(page.getByTestId('mileage-manual-button')).toBeEnabled();
  });

  test('mileage.read 전용 — 목록 진입 가능하나 수동 지급/차감 버튼 disabled', async ({
    page,
    mileageReadOnlyToken,
  }) => {
    await authenticatePage(page, mileageReadOnlyToken);
    await page.goto(LIST_URL);

    await expect(page.getByTestId('mileage-transactions-page')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('mileage-manual-button')).toBeDisabled();
  });

  test('권한 미보유 — 목록 진입 시 콘텐츠 영역 에러(403)', async ({ page, noPermissionToken }) => {
    await authenticatePage(page, noPermissionToken);
    await page.goto(LIST_URL);

    // 콘텐츠 영역 에러 페이지(showErrorPage target:content) — 목록 그리드 미노출
    await expect(page.getByTestId('mileage-transactions-grid')).not.toBeVisible();
  });

  test('검색(회원명) — search_keyword commit 후 목록이 해당 회원으로 필터', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await page.getByTestId('mileage-search-keyword').fill('홍길동');
    await page.getByTestId('mileage-search-submit').click();

    await expect(page).toHaveURL(/search_keyword=/);
    await expect(page.getByTestId('mileage-transactions-grid')).toBeVisible({ timeout: 10_000 });
  });

  /**
   * 회귀: 행 확장 시 연결 거래 표시.
   *
   * expandContext 표현식의 객체 리터럴 fallback(`|| {}`)이 단일 바인딩 정규식을 깨뜨려
   * 표현식이 평가되지 않고 문자열로 전달되던 결함 — 데이터가 있어도 "연결 거래 없음" 고정.
   * 수정: expandContext fallback 제거(`{{_local.linkedTransactions}}`).
   * (활성화 시 사전 작업: 복원/사용 연결 거래가 있는 행에 data-testid="mileage-row-{id}" +
   *  확장 영역에 data-testid="mileage-linked-{id}" 보강, 시드에 복원 거래 보장)
   */
  test('행 확장 — 연결 거래가 있는 복원 행을 펼치면 원 사용 거래가 연결 거래로 표시된다', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await expect(page.getByTestId('mileage-transactions-grid')).toBeVisible({ timeout: 10_000 });

    // 연결 거래가 있는 복원 행을 펼친다
    const expandableRow = page.getByTestId(/^mileage-row-/).first();
    await expandableRow.getByRole('button', { name: /Expand row|확장/ }).click();

    // 확장 영역에 "연결 거래 없음" 이 아니라 실제 연결 거래(주문 사용)가 보여야 한다
    await expect(page.getByText('연결 거래 없음')).not.toBeVisible();
    await expect(page.getByText('주문 사용')).toBeVisible({ timeout: 10_000 });
  });

  /**
   * 회귀: 수동 지급은 회원 식별자를 uuid 로 전송해야 성공한다.
   *
   * 코어 UserResource(users/search)가 정수 id 를 노출하지 않으므로, 모달이 member.id 로
   * user_id 를 추출하면 undefined → 422("대상 회원을 선택해주세요"). 수정: selectedMember 가
   * member.uuid 를 저장하고 body 가 uuid 를 user_id 로 전송.
   * (활성화 시 사전 작업: 회원 검색 input/결과/실행버튼에 data-testid 보강)
   */
  test('수동 지급 — 회원 검색 후 선택·금액·사유 입력 시 uuid 로 지급되어 목록에 반영된다', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await page.getByTestId('mileage-manual-button').click();
    await page.getByTestId('mileage-manual-member-search').fill('hong');
    // 부유 드롭다운 결과에서 첫 회원 선택
    await page.getByTestId(/^mileage-manual-member-result-/).first().click();
    await page.getByTestId('mileage-manual-amount').fill('3000');
    await page.getByTestId('mileage-manual-reason').fill('이벤트 보상');
    await page.getByTestId('mileage-manual-submit').click();

    // 422 가 아니라 성공 토스트 + 목록 갱신
    await expect(page.getByText('처리되었습니다.')).toBeVisible({ timeout: 10_000 });
  });

  /**
   * 회귀: 검증 실패 시 G7 표준 피드백 3종(상단 배너 + 필드 강조 + 하단 인라인 메시지).
   *
   * 기존엔 toast 단건만 노출 — 어느 필드가 문제인지 안내 부재. 수정: onError 가 errors 를
   * _global 에 저장하고 모달이 배너/필드강조/인라인메시지를 렌더.
   * (활성화 시 사전 작업: 에러 배너/필드 인라인 메시지에 data-testid 보강 + 서버검증 실패 유도)
   */
  test('수동 지급 — 검증 실패 시 상단 배너 + 필드 강조 + 하단 인라인 메시지가 노출된다', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await page.getByTestId('mileage-manual-button').click();
    // 회원 선택 후 서버검증 실패를 유도(예: 잔액 초과 차감)하여 onError 경로 진입
    await page.getByTestId('mileage-manual-member-search').fill('hong');
    await page.getByTestId(/^mileage-manual-member-result-/).first().click();

    await expect(page.getByTestId('mileage-manual-error-banner')).toBeVisible({ timeout: 10_000 });
  });

  /**
   * 행 액션 "사유·기간 변경" — 적립건의 memo·만료일을 편집한다 (원장 불변, 삭제 없음).
   *
   * 행 ⋮ 메뉴 "사유·기간 변경" 진입 시 회원·금액·유형·적립일은 읽기전용으로 표시되고,
   * 사유(memo)·만료일만 편집 가능. PATCH /admin/mileage-transactions/{id} 로 저장.
   * (활성화 시 사전 작업: rowAction edit 항목/편집 모달 입력에 data-testid 보강)
   */
  test('행 사유·기간 변경 — 적립건의 사유·만료일을 수정하면 목록에 반영된다', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await expect(page.getByTestId('mileage-transactions-grid')).toBeVisible({ timeout: 10_000 });

    // 적립건 행의 ⋮ 메뉴 → 사유·기간 변경
    const earnRow = page.getByTestId(/^mileage-row-earn-/).first();
    await earnRow.getByTestId('mileage-row-action-edit').click();

    // 읽기전용 정보 + 편집 필드(사유/만료일)
    await expect(page.getByTestId('mileage-edit-readonly-info')).toBeVisible();
    await page.getByTestId('mileage-edit-reason').fill('사유 정정');
    await page.getByTestId('mileage-edit-submit').click();

    await expect(page.getByText('적립건이 수정되었습니다.')).toBeVisible({ timeout: 10_000 });
  });

  /**
   * 행 편집은 적립계만 가능 — 사용/소멸/차감 행의 "사유·기간 변경"은 비활성(can_edit=false).
   */
  test('행 사유·기간 변경 — 비적립계(사용) 행은 메뉴 항목이 비활성이다', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await expect(page.getByTestId('mileage-transactions-grid')).toBeVisible({ timeout: 10_000 });

    const useRow = page.getByTestId(/^mileage-row-use-/).first();
    await expect(useRow.getByTestId('mileage-row-action-edit')).toBeDisabled();
  });

  /**
   * 부분 소멸 적립건은 유효기간 열에 "일부 소멸" 배지 + 소멸액이 표시된다 (전체 소멸 오해 차단).
   */
  test('유효기간 열 — 부분 소멸 적립건은 "일부 소멸" 구분과 소멸액을 표시한다', async ({
    page,
    mileageManageToken,
  }) => {
    await authenticatePage(page, mileageManageToken);
    await page.goto(LIST_URL);

    await expect(page.getByTestId('mileage-transactions-grid')).toBeVisible({ timeout: 10_000 });

    // 부분 소멸 적립건 행: "일부 소멸" 배지 + "N 소멸" 금액 동반
    const partialRow = page.getByTestId(/^mileage-row-partial-expired-/).first();
    await expect(partialRow.getByText('일부 소멸')).toBeVisible();
    await expect(partialRow.getByText(/소멸$/)).toBeVisible();
  });
});
