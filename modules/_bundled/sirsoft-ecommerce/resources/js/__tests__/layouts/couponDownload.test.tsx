// e2e:allow 단위 테스트 단언만 신구조(창 고유 전역 이름 공간 `_global.couponDownloadModal` · 받기 번호 `coupon_id`, 7.1.1)에 재정합 — 레이아웃 동작은 sirsoft-basic 실구조 시험(coupon-download-modal-real-structure)이 렌더로 잠근다
/**
 * 쿠폰 다운로드 기능 레이아웃 구조 검증 테스트
 *
 * - 쿠폰 다운로드 모달: 3-상태 분기(로딩/빈상태/데이터), iteration, 다운로드 버튼
 * - 상품 상세 쿠폰 배지: 조건부 표시, iteration, 로그인 분기
 * - 체크아웃 쿠폰 다운로드 버튼: 로그인 조건부 표시
 * - checkout.json 모달/initLocal 연동
 * - show.json 데이터소스/모달 연동
 *
 * 창 상태 계약(7.1.1): 창(목록·불러오는 중·더보기 중·받는 중인 쿠폰)은 창 고유 전역 이름 공간
 * `_global.couponDownloadModal = { coupons, loading, loadingMore, downloadingCouponId }` 를 읽고 쓴다.
 * modals 섹션 창은 영구 마운트라 페이지 `_local` 에 두면 창이 앞서 쥔 목록이 다시 열 때 새 목록을 가렸다.
 * 여는 쪽(주문서 「쿠폰 다운로드」·상품 상세 「+N」)은 이 키를 null 로 비운 뒤 객체로 다시 쓴다.
 * 받기 번호는 두 목록 공통 필드 `coupon_id` 다(7.1.1 — 상품 상세 목록에는 `id` 가 없다).
 *
 * @scenario-manifest tests/scenarios/coupon-download-layouts.yaml
 */

import { describe, it, expect } from 'vitest';

import couponDownloadModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_coupon_download.json';
import checkoutDiscount from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_discount.json';
import infoSummary from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_info_summary.json';
import loginRequiredModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_login_required.json';
import downloadConfirmModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_coupon_download_confirm.json';
import checkoutLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/checkout.json';
import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/show.json';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/** 창 고유 전역 이름 공간 */
const NS = '_global.couponDownloadModal';

/** 다운로드 가능 버튼 판정 — 받지 않았고, 누른 카드(coupon_id)가 받는 중이 아님 */
const isDownloadableButton = (c: any) =>
    c.if?.includes('!coupon.is_downloaded') && c.if?.includes('!(coupon.coupon_id != null');

/** 여는 쪽 sequence 가 창 이름 공간을 null 로 비운 뒤 객체로 다시 쓰는지 판정해 그 객체를 돌려준다 */
function resetThenSeed(actions: any[]): any {
    const nullIdx = actions.findIndex(
        (a: any) => a.handler === 'setState' && a.params?.target === 'global' && a.params?.couponDownloadModal === null
    );
    const seedIdx = actions.findIndex(
        (a: any) =>
            a.handler === 'setState' &&
            a.params?.target === 'global' &&
            a.params?.couponDownloadModal !== null &&
            typeof a.params?.couponDownloadModal === 'object'
    );
    expect(nullIdx).toBeGreaterThanOrEqual(0);
    expect(seedIdx).toBeGreaterThan(nullIdx);
    return actions[seedIdx].params.couponDownloadModal;
}

// ========== 쿠폰 다운로드 모달 구조 검증 ==========

describe('쿠폰 다운로드 모달 (_modal_coupon_download.json)', () => {
    // @scenario source=static
    // @effects coupon_modal_registered_as_partial
    it('Modal 컴포넌트이며 올바른 ID를 가진다', () => {
        expect(couponDownloadModal.name).toBe('Modal');
        expect(couponDownloadModal.type).toBe('composite');
        expect(couponDownloadModal.id).toBe('coupon_download_modal');
    });

    // @scenario source=static
    // @effects coupon_modal_registered_as_partial
    it('is_partial 메타를 가진다', () => {
        expect(couponDownloadModal.meta.is_partial).toBe(true);
    });

    const children = couponDownloadModal.children;

    // @scenario source=static
    // @effects modal_three_state_branch
    it('3가지 상태 분기가 있다 (로딩/빈상태/데이터)', () => {
        expect(children).toHaveLength(3);

        // 로딩 상태 — 불러오는 중이고 아직 목록이 없을 때
        expect(children[0].if).toContain(`${NS}?.loading`);
        expect(children[0].if).toContain(`!${NS}?.coupons?.data`);

        // 빈 상태 — 불러오기가 끝났고 목록이 0장
        expect(children[1].if).toContain(`!${NS}?.loading`);
        expect(children[1].if).toContain('length === 0');

        // 데이터 상태
        expect(children[2].if).toContain(`${NS}?.coupons?.data`);
        expect(children[2].if).toContain('length > 0');
    });

    // @scenario source=static
    // @effects coupon_cards_iterate_window_list
    it('쿠폰 카드에 iteration이 있다', () => {
        const dataSection = children[2]; // 데이터 상태
        // U10: 페이지전환 로딩 오버레이 제거 → 그리드 첫 자식이 곧 iteration 카드
        const iterationEl = dataSection.children[0].children[0];

        expect(iterationEl.iteration).toBeDefined();
        expect(iterationEl.iteration.source).toContain(`${NS}?.coupons?.data`);
        expect(iterationEl.iteration.item_var).toBe('coupon');
    });

    // @scenario source=static
    // @effects infinite_scroll_appends_next_page
    it('U10: 스크롤 영역(max-h + overflow-y-auto)으로 일원화되어 있다', () => {
        const dataSection = children[2];
        const grid = dataSection.children[0];
        expect(grid.props.className).toContain('max-h-96');
        expect(grid.props.className).toContain('overflow-y-auto');
    });

    // @scenario source=static
    // @effects card_button_three_states
    it('쿠폰 카드에 3가지 버튼 상태가 있다 (다운로드완료/진행중/가능)', () => {
        const dataSection = children[2];
        const cardChildren = dataSection.children[0].children[0].children; // U10: 오버레이 제거 → 카드가 index 0

        // is_downloaded 버튼
        const downloadedBtn = cardChildren.find((c: any) =>
            c.if?.includes('coupon.is_downloaded') && !c.if?.includes('!')
        );
        expect(downloadedBtn).toBeDefined();
        expect(downloadedBtn.props.disabled).toBe(true);

        // 다운로드 중 버튼 — 누른 카드 하나만(받기 번호 coupon_id, 번호 없는 카드는 잠기지 않는다)
        const downloadingBtn = cardChildren.find((c: any) =>
            c.if?.includes(`${NS}?.downloadingCouponId === coupon.coupon_id`) && !c.if?.includes('!(')
        );
        expect(downloadingBtn).toBeDefined();
        expect(downloadingBtn.if).toContain('coupon.coupon_id != null');
        expect(downloadingBtn.props.disabled).toBe(true);

        // 다운로드 가능 버튼 — 진행 중 판정의 정확한 부정
        const downloadBtn = cardChildren.find(isDownloadableButton);
        expect(downloadBtn).toBeDefined();
        expect(downloadBtn.if).toContain(`!(coupon.coupon_id != null && ${NS}?.downloadingCouponId === coupon.coupon_id)`);
        expect(downloadBtn.actions).toBeDefined();
    });

    // @scenario source=static
    // @effects download_button_posts_coupon_id
    it('다운로드 버튼이 apiCall을 호출한다', () => {
        const dataSection = children[2];
        const cardChildren = dataSection.children[0].children[0].children; // U10: 오버레이 제거 → 카드가 index 0
        const downloadBtn = cardChildren.find(isDownloadableButton);

        const clickAction = asArray(downloadBtn?.actions)[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');

        // 먼저 누른 카드를 받는 중으로 표시
        const markDownloading = asArray(clickAction?.actions)[0];
        expect(markDownloading.handler).toBe('setState');
        expect(markDownloading.params.target).toBe('global');
        expect(markDownloading.params['couponDownloadModal.downloadingCouponId']).toBe('{{coupon.coupon_id}}');

        const apiCallAction = asArray(clickAction?.actions).find((a: any) => a.handler === 'apiCall');
        expect(apiCallAction).toBeDefined();
        expect(apiCallAction.target).toContain('/coupons/{{coupon.coupon_id}}/download');
        expect(apiCallAction.auth_mode).toBe('required');
        expect(apiCallAction.params.method).toBe('POST');
    });

    // @scenario source=static
    // @effects download_success_marks_downloaded
    it('다운로드 성공 시 is_downloaded를 true로 업데이트한다', () => {
        const dataSection = children[2];
        const cardChildren = dataSection.children[0].children[0].children; // U10: 오버레이 제거 → 카드가 index 0
        const downloadBtn = cardChildren.find(isDownloadableButton);
        const apiCallAction = asArray(asArray(downloadBtn?.actions)[0]?.actions).find((a: any) => a.handler === 'apiCall');

        // onSuccess에서 창 이름 공간의 목록을 받음으로 표시하고 받는 중 표시를 푼다
        const setStateOnSuccess = asArray(apiCallAction?.onSuccess).find((a: any) =>
            a.handler === 'setState' && a.params?.['couponDownloadModal.coupons']
        );
        expect(setStateOnSuccess).toBeDefined();
        expect(setStateOnSuccess.params.target).toBe('global');
        expect(setStateOnSuccess.params['couponDownloadModal.coupons']).toContain('is_downloaded: true');
        expect(setStateOnSuccess.params['couponDownloadModal.coupons']).toContain('c.coupon_id === coupon.coupon_id');
        expect(setStateOnSuccess.params['couponDownloadModal.downloadingCouponId']).toBeNull();

        // 실패 시에도 받는 중 표시를 푼다
        const setStateOnError = asArray(apiCallAction?.onError).find((a: any) => a.handler === 'setState');
        expect(setStateOnError.params.target).toBe('global');
        expect(setStateOnError.params['couponDownloadModal.downloadingCouponId']).toBeNull();
    });

    // @scenario source=static
    // @effects infinite_scroll_appends_next_page
    it('U10 무한스크롤: 그리드에 scroll 핸들러가 있고 하단 근접 시 다음 페이지를 apiCall 한다', () => {
        const dataSection = children[2];
        const grid = dataSection.children[0]; // max-h-96 overflow-y-auto 스크롤 그리드
        expect(grid.props.className).toContain('overflow-y-auto');

        const scrollAction = (grid.actions || []).find((a: any) => a.type === 'scroll');
        expect(scrollAction).toBeDefined();
        // 연속 스크롤 이벤트 중복 호출 방지 debounce
        expect(scrollAction.debounce).toBeGreaterThan(0);

        const branch = asArray(scrollAction?.conditions)[0];
        // 하단 근접 + 로딩 아님 + 다음 페이지 존재 가드
        expect(branch.if).toContain('scrollTop');
        expect(branch.if).toContain('clientHeight');
        expect(branch.if).toContain('scrollHeight');
        expect(branch.if).toContain(`!${NS}?.loadingMore`);
        expect(branch.if).toContain('current_page');
        expect(branch.if).toContain('last_page');

        const branchJson = JSON.stringify(branch.then);
        // 다음 페이지 apiCall (page = current_page + 1)
        expect(branchJson).toContain('/user/coupons/downloadable');
        expect(branchJson).toContain('current_page');
        // 더보기 중 표시를 켜고(요청 전) 끈다(응답 뒤)
        expect(branchJson).toContain('couponDownloadModal.loadingMore');
        const apiCall = asArray(branch.then?.actions).find((a: any) => a.handler === 'apiCall');
        // 기존 목록에 누적 (append)
        const appendSetState = asArray(apiCall?.onSuccess).find(
            (a: any) => a.handler === 'setState' && a.params?.['couponDownloadModal.coupons']
        );
        expect(appendSetState.params.target).toBe('global');
        expect(appendSetState.params['couponDownloadModal.coupons']).toContain('...');
        expect(appendSetState.params['couponDownloadModal.loadingMore']).toBe(false);
    });

    // @scenario source=static
    // @effects infinite_scroll_appends_next_page
    it('U10 무한스크롤: 다음 페이지 로딩 인디케이터(스피너)가 loadingMore 일 때 표시된다', () => {
        const dataSection = children[2];
        // 데이터 상태 = 그리드 + 로딩 인디케이터 두 블록
        const indicator = dataSection.children.find(
            (c: any) => typeof c.if === 'string' && c.if.includes(`${NS}?.loadingMore`)
        );
        expect(indicator).toBeDefined();
        const spinner = JSON.stringify(indicator);
        expect(spinner).toContain('animate-spin');
    });

    // @scenario source=static
    // @effects infinite_scroll_appends_next_page
    it('U10 회귀: 페이지전환 로딩 오버레이가 제거되었다 (그리드 첫 자식이 iteration 카드)', () => {
        const dataSection = children[2];
        const grid = dataSection.children[0];
        // 그리드의 첫 자식은 곧바로 iteration 카드여야 함 (오버레이 Div 없음)
        expect(grid.children[0].iteration).toBeDefined();
    });

    // @scenario source=static
    // @effects window_state_in_global_namespace
    it('모달 내 창 상태 참조가 창 고유 전역 이름 공간(_global.couponDownloadModal)을 쓰고 페이지·창 _local 의 옛 키를 읽지 않는다', () => {
        // 로딩 상태 if 조건
        expect(children[0].if).toContain(`${NS}?.loading`);

        // 데이터 상태 iteration source (U10: 오버레이 제거 → 카드가 index 0)
        const dataSection = children[2];
        const iterationSource = dataSection.children[0].children[0].iteration.source;
        expect(iterationSource).toContain(`${NS}?.coupons?.data`);

        // 창 상태를 `_local`/`$parent._local` 에서 읽던 옛 키(영구 마운트 창 A 가 새 목록을 가리던 갈래)가 남지 않는다
        const whole = JSON.stringify(couponDownloadModal);
        for (const legacy of ['downloadableCoupons', 'downloadableCouponsLoading', 'downloadingMore', '_local.downloadingCouponId']) {
            expect(whole).not.toContain(legacy);
        }
        // 창 안의 쓰기는 모두 전역 이름 공간 점 경로다(target local 쓰기 없음)
        const localWrites = whole.match(/"target":"local"/g) ?? [];
        expect(localWrites).toHaveLength(0);
    });
});

// ========== 체크아웃 할인 섹션 검증 ==========

describe('체크아웃 할인 섹션 쿠폰 다운로드 버튼 (_checkout_discount.json)', () => {
    const sectionHeader = checkoutDiscount.children[0]; // 섹션 헤더

    // @scenario source=static
    // @effects checkout_opener_resets_fetches_then_opens
    it('섹션 헤더가 좌우 양끝 정렬 시맨틱 클래스(flex-between)를 사용한다', () => {
        // 시맨틱화 정책: 원시 Tailwind 'flex items-center justify-between' → 시맨틱 토큰 'flex-between'
        // (main.css 의 .flex-between = display:flex; align-items:center; justify-content:space-between).
        expect(sectionHeader.props.className).toContain('flex-between');
    });

    // @scenario source=static
    // @effects checkout_opener_resets_fetches_then_opens
    it('쿠폰 다운로드 버튼이 로그인 사용자만 표시된다', () => {
        const downloadBtn = sectionHeader.children[1]; // 우측 버튼
        // currentUser?.uuid 패턴 사용 (currentUser 객체는 항상 truthy 가능 → uuid 로 진위 확인)
        expect(downloadBtn.if).toBe('{{_global.currentUser?.uuid}}');
        expect(downloadBtn.name).toBe('Button');
    });

    // @scenario source=static
    // @effects checkout_opener_resets_fetches_then_opens
    it('다운로드 버튼 클릭 시 sequence(창 이름 공간 null → 불러오는 중 객체 → apiCall)를 실행하고 onSuccess에서 openModal한다', () => {
        const downloadBtn = sectionHeader.children[1];
        const clickAction = asArray(downloadBtn?.actions)[0];

        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');

        const actions = clickAction.actions;
        // 창 이름 공간을 비운 뒤 「불러오는 중」 으로 다시 쓴다(앞서 쥔 목록·받는 중 표시를 버린다)
        const seed = resetThenSeed(actions);
        expect(seed.loading).toBe(true);
        expect(seed.coupons).toBeNull();
        expect(seed.loadingMore).toBe(false);
        expect(seed.downloadingCouponId).toBeNull();

        const apiCallIdx = actions.findIndex((a: any) => a.handler === 'apiCall');
        const apiCall = actions[apiCallIdx];
        expect(apiCallIdx).toBeGreaterThan(actions.findIndex((a: any) => a.params?.couponDownloadModal?.loading === true));
        expect(apiCall.target).toContain('downloadable');
        expect(apiCall.target).toContain('page=1');
        expect(apiCall.auth_mode).toBe('required');

        // 첫 페이지를 창 이름 공간에 담고 불러오는 중을 끈 뒤 창을 연다
        const onSuccess = asArray(apiCall?.onSuccess);
        const fill = onSuccess.find((a: any) => a.handler === 'setState');
        expect(fill.params.target).toBe('global');
        expect(fill.params['couponDownloadModal.coupons']).toBe('{{response.data}}');
        expect(fill.params['couponDownloadModal.loading']).toBe(false);

        // openModal은 apiCall onSuccess에서 실행 (목록이 담긴 뒤 연다)
        const openModalIdx = onSuccess.findIndex((a: any) => a.handler === 'openModal');
        expect(openModalIdx).toBeGreaterThan(onSuccess.indexOf(fill));
        expect(onSuccess[openModalIdx].target).toBe('coupon_download_modal');

        // 실패 시 불러오는 중을 끈다
        const onError = asArray(apiCall?.onError).find((a: any) => a.handler === 'setState');
        expect(onError.params['couponDownloadModal.loading']).toBe(false);
    });
});

// ========== 상품 상세 쿠폰 배지 섹션 검증 ==========

describe('상품 상세 쿠폰 배지 (_info_summary.json)', () => {
    // 쿠폰 배지 섹션 찾기 (comment로 식별)
    const couponSection = (infoSummary as any).children.find(
        (c: any) => c.comment?.includes('다운로드 가능 쿠폰')
    );

    // @scenario source=static
    // @effects product_coupon_badges_render
    it('쿠폰 배지 섹션이 존재한다', () => {
        expect(couponSection).toBeDefined();
    });

    // @scenario source=static
    // @effects product_coupon_badges_render
    it('productDownloadableCoupons 데이터가 있을 때만 표시된다', () => {
        expect(couponSection.if).toContain('productDownloadableCoupons');
        expect(couponSection.if).toContain('length > 0');
    });

    // @scenario source=static
    // @effects product_coupon_badges_render
    it('최대 3개까지 slice하여 표시한다', () => {
        const badgeContainer = couponSection.children[0]; // flex container
        const iterationEl = badgeContainer.children[1]; // iteration element
        expect(iterationEl.iteration.source).toContain('slice(0, 3)');
    });

    // @scenario source=static
    // @effects product_coupon_badges_render
    it('benefit_formatted로 할인 혜택을 표시한다', () => {
        const badgeContainer = couponSection.children[0];
        const iterationEl = badgeContainer.children[1];
        const buttonChildren = iterationEl.children[0].children;

        const benefitSpan = buttonChildren.find((c: any) =>
            c.text?.includes('benefit_formatted')
        );

        expect(benefitSpan).toBeDefined();
    });

    // @scenario source=static
    // @effects badge_click_branches_on_login
    it('쿠폰 배지 클릭 시 로그인/비로그인 분기 처리한다', () => {
        const badgeContainer = couponSection.children[0];
        const iterationEl = badgeContainer.children[1];
        const badge = iterationEl.children[0];

        const clickAction = asArray(badge?.actions)[0];
        expect(clickAction.handler).toBe('conditions');

        // 로그인 시 다운로드 확인 모달
        const loggedInCondition = asArray(clickAction?.conditions)[0];
        expect(loggedInCondition.if).toContain('_global.currentUser');
        expect(loggedInCondition.then.handler).toBe('sequence');

        // 비로그인 fallback (conditions 핸들러는 if 없는 항목을 else 로 처리,
        // 동작 자체는 then 키로 표현)
        const fallbackCondition = asArray(clickAction?.conditions)[1];
        expect(fallbackCondition.then.handler).toBe('openModal');
        expect(fallbackCondition.then.target).toBe('login_required_modal');
    });

    // @scenario source=static
    // @effects product_opener_seeds_window_namespace
    it('3개 초과 시 더보기 버튼을 표시하고, 로그인 회원이 누르면 창 이름 공간을 비운 뒤 이 상품 목록으로 다시 써 창을 연다', () => {
        const badgeContainer = couponSection.children[0];
        const moreBtn = badgeContainer.children[2]; // 더보기 버튼

        expect(moreBtn.if).toContain('length > 3');
        expect(moreBtn.name).toBe('Button');

        const clickAction = asArray(moreBtn?.actions)[0];
        expect(clickAction.handler).toBe('conditions');
        const loggedIn = asArray(clickAction?.conditions)[0];
        expect(loggedIn.if).toContain('_global.currentUser');
        expect(loggedIn.then.handler).toBe('sequence');

        const actions = loggedIn.then.actions;
        const seed = resetThenSeed(actions);
        // 「+N」 배지와 같은 목록(이 상품에 적용되는 쿠폰)을 창에 넣는다
        expect(seed.coupons).toContain('productDownloadableCoupons.data');
        expect(seed.loading).toBe(false);
        expect(seed.loadingMore).toBe(false);
        expect(seed.downloadingCouponId).toBeNull();

        const openIdx = actions.findIndex((a: any) => a.handler === 'openModal');
        expect(openIdx).toBeGreaterThan(actions.findIndex((a: any) => a.params?.couponDownloadModal === seed));
        expect(actions[openIdx].target).toBe('coupon_download_modal');

        // 비회원은 로그인 안내 창
        const fallback = asArray(clickAction?.conditions)[1];
        expect(fallback.then.target).toBe('login_required_modal');
    });
});

// ========== 로그인 필요 모달 검증 ==========

describe('로그인 필요 모달 (_modal_login_required.json)', () => {
    // @scenario source=static
    // @effects login_required_modal_navigates_to_login
    it('Modal 컴포넌트이며 올바른 ID를 가진다', () => {
        expect(loginRequiredModal.name).toBe('Modal');
        expect(loginRequiredModal.id).toBe('login_required_modal');
    });

    // @scenario source=static
    // @effects login_required_modal_navigates_to_login
    it('로그인 버튼이 /login 페이지로 이동한다', () => {
        const content = loginRequiredModal.children[0]; // center div
        const buttonsDiv = content.children[2]; // buttons container
        const loginBtn = buttonsDiv.children[1]; // 로그인 버튼

        const clickAction = asArray(loginBtn?.actions)[0];
        expect(clickAction.handler).toBe('sequence');

        const navigateAction = asArray(clickAction?.actions).find((a: any) => a.handler === 'navigate');
        expect(navigateAction).toBeDefined();
        expect(navigateAction.params.path).toBe('/login');
    });
});

// ========== 다운로드 확인 모달 검증 ==========

describe('쿠폰 다운로드 확인 모달 (_modal_coupon_download_confirm.json)', () => {
    // @scenario source=static
    // @effects confirm_modal_downloads_and_refetches
    it('Modal 컴포넌트이며 올바른 ID를 가진다', () => {
        expect(downloadConfirmModal.name).toBe('Modal');
        expect(downloadConfirmModal.id).toBe('coupon_download_confirm_modal');
    });

    // @scenario source=static
    // @effects confirm_modal_downloads_and_refetches
    it('선택된 쿠폰 정보를 $parent._local에서 참조한다', () => {
        const content = JSON.stringify(downloadConfirmModal);
        expect(content).toContain('$parent._local.selectedCouponForDownload');
    });

    // @scenario source=static
    // @effects confirm_modal_downloads_and_refetches
    it('다운로드 버튼이 apiCall로 쿠폰을 다운로드한다', () => {
        const contentDiv = downloadConfirmModal.children[0];
        const buttonsDiv = contentDiv.children[2]; // buttons container
        const downloadBtn = buttonsDiv.children.find((c: any) =>
            c.if?.includes('!_local.isDownloading')
        );

        expect(downloadBtn).toBeDefined();
        const clickAction = asArray(downloadBtn?.actions)[0];
        expect(clickAction.handler).toBe('sequence');

        const apiCallAction = asArray(clickAction?.actions).find((a: any) => a.handler === 'apiCall');
        expect(apiCallAction).toBeDefined();
        expect(apiCallAction.target).toContain('/download');
        expect(apiCallAction.auth_mode).toBe('required');
    });

    // @scenario source=static
    // @effects confirm_modal_downloads_and_refetches
    it('다운로드 성공 시 데이터소스를 갱신한다', () => {
        const contentDiv = downloadConfirmModal.children[0];
        const buttonsDiv = contentDiv.children[2];
        const downloadBtn = buttonsDiv.children.find((c: any) =>
            c.if?.includes('!_local.isDownloading')
        );
        const apiCallAction = asArray(asArray(downloadBtn?.actions)[0]?.actions).find((a: any) => a.handler === 'apiCall');

        const refreshAction = asArray(apiCallAction?.onSuccess).find((a: any) =>
            a.handler === 'refetchDataSource'
        );
        expect(refreshAction).toBeDefined();
        expect(refreshAction.params.dataSourceId).toBe('productDownloadableCoupons');
    });
});

// ========== checkout.json 연동 검증 ==========

describe('checkout.json 쿠폰 다운로드 연동', () => {
    // @scenario source=static
    // @effects window_state_not_in_page_local
    it('initLocal에 쿠폰 받기 창 상태를 두지 않는다 (창 고유 전역 이름 공간으로 옮김)', () => {
        const initLocal = (checkoutLayout as any).initLocal;
        expect(initLocal).toBeDefined();
        // 페이지 _local 에 두면 영구 마운트 창이 앞서 쥔 목록이 다시 열 때 새 목록을 가린다(7.1.1)
        for (const key of ['downloadableCoupons', 'downloadableCouponsLoading', 'downloadableCouponsPage', 'downloadingMore', 'downloadingCouponId', 'couponDownloadModal']) {
            expect(initLocal).not.toHaveProperty(key);
        }
        // 칩 확인 창이 읽는 선택 쿠폰은 페이지 상태로 남는다
        expect(JSON.stringify(checkoutLayout)).not.toContain('downloadableCoupons');
    });

    // @scenario source=static
    // @effects coupon_modals_registered_in_pages
    it('modals에 쿠폰 다운로드 모달 partial이 등록되어 있다', () => {
        const modals = (checkoutLayout as any).modals;
        const couponModal = modals.find((m: any) =>
            m.partial?.includes('_modal_coupon_download.json')
        );
        expect(couponModal).toBeDefined();
    });
});

// ========== show.json 연동 검증 ==========

describe('show.json 쿠폰 다운로드 연동', () => {
    // @scenario source=static
    // @effects product_coupon_badges_render
    it('productDownloadableCoupons 데이터소스가 등록되어 있다', () => {
        const dataSources = (showLayout as any).data_sources;
        const couponDs = dataSources.find((ds: any) => ds.id === 'productDownloadableCoupons');
        expect(couponDs).toBeDefined();
        expect(couponDs.endpoint).toContain('/downloadable-coupons');
        expect(couponDs.auth_mode).toBe('optional');
    });

    // @scenario source=static
    // @effects window_state_not_in_page_local
    it('init_actions 는 쿠폰 받기 창 상태를 페이지 _local 에 두지 않고, 칩 확인 창의 선택 쿠폰만 초기화한다', () => {
        const initActions = (showLayout as any).init_actions;
        const localWrites = initActions.filter((a: any) => a.handler === 'setState' && a.params?.target === 'local');
        expect(localWrites.length).toBeGreaterThan(0);
        for (const action of localWrites) {
            for (const key of ['downloadableCoupons', 'downloadableCouponsLoading', 'downloadingMore', 'downloadingCouponId', 'couponDownloadModal']) {
                expect(action.params).not.toHaveProperty(key);
            }
        }
        // 칩 확인 창(_modal_coupon_download_confirm)은 $parent._local.selectedCouponForDownload 를 읽는다 — 그 초기화는 유지
        const selectionReset = localWrites.find((a: any) => a.params?.selectedCouponForDownload !== undefined);
        expect(selectionReset).toBeDefined();
        expect(selectionReset.params.selectedCouponForDownload).toBeNull();
    });

    // @scenario source=static
    // @effects coupon_modals_registered_in_pages
    it('modals에 쿠폰 관련 모달 partial들이 등록되어 있다', () => {
        const modals = (showLayout as any).modals;
        const couponDownloadModal = modals.find((m: any) =>
            m.partial?.includes('_modal_coupon_download.json')
        );
        const loginRequiredModal = modals.find((m: any) =>
            m.partial?.includes('_modal_login_required.json')
        );
        const confirmModal = modals.find((m: any) =>
            m.partial?.includes('_modal_coupon_download_confirm.json')
        );

        expect(couponDownloadModal).toBeDefined();
        expect(loginRequiredModal).toBeDefined();
        expect(confirmModal).toBeDefined();
    });
});
