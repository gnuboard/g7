/**
 * 쿠폰 다운로드 기능 레이아웃 구조 검증 테스트
 *
 * - 쿠폰 다운로드 모달: 3-상태 분기(로딩/빈상태/데이터), iteration, 다운로드 버튼
 * - 상품 상세 쿠폰 배지: 조건부 표시, iteration, 로그인 분기
 * - 체크아웃 쿠폰 다운로드 버튼: 로그인 조건부 표시
 * - checkout.json 모달/initLocal 연동
 * - show.json 데이터소스/모달 연동
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import couponDownloadModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_coupon_download.json';
import checkoutDiscount from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_discount.json';
import infoSummary from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_info_summary.json';
import loginRequiredModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_login_required.json';
import downloadConfirmModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_coupon_download_confirm.json';
import checkoutLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/checkout.json';
import showLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/shop/show.json';

// ========== 쿠폰 다운로드 모달 구조 검증 ==========

describe('쿠폰 다운로드 모달 (_modal_coupon_download.json)', () => {
    it('Modal 컴포넌트이며 올바른 ID를 가진다', () => {
        expect(couponDownloadModal.name).toBe('Modal');
        expect(couponDownloadModal.type).toBe('composite');
        expect(couponDownloadModal.id).toBe('coupon_download_modal');
    });

    it('is_partial 메타를 가진다', () => {
        expect(couponDownloadModal.meta.is_partial).toBe(true);
    });

    const children = couponDownloadModal.children;

    it('3가지 상태 분기가 있다 (로딩/빈상태/데이터)', () => {
        expect(children).toHaveLength(3);

        // 로딩 상태
        expect(children[0].if).toContain('downloadableCouponsLoading');

        // 빈 상태
        expect(children[1].if).toContain('downloadableCouponsLoading');
        expect(children[1].if).toContain('length === 0');

        // 데이터 상태
        expect(children[2].if).toContain('length > 0');
    });

    it('쿠폰 카드에 iteration이 있다', () => {
        const dataSection = children[2]; // 데이터 상태
        const gridChildren = dataSection.children[0].children; // grid > [loading overlay, iteration wrapper]
        const iterationEl = gridChildren[1]; // index 1 (0은 로딩 오버레이)

        expect(iterationEl.iteration).toBeDefined();
        expect(iterationEl.iteration.source).toContain('downloadableCoupons');
        expect(iterationEl.iteration.item_var).toBe('coupon');
    });

    it('쿠폰 카드에 3가지 버튼 상태가 있다 (다운로드완료/진행중/가능)', () => {
        const dataSection = children[2];
        const cardChildren = dataSection.children[0].children[1].children; // index 1 (0은 로딩 오버레이)

        // is_downloaded 버튼
        const downloadedBtn = cardChildren.find((c: any) =>
            c.if?.includes('coupon.is_downloaded') && !c.if?.includes('!')
        );
        expect(downloadedBtn).toBeDefined();
        expect(downloadedBtn.props.disabled).toBe(true);

        // 다운로드 중 버튼
        const downloadingBtn = cardChildren.find((c: any) =>
            c.if?.includes('downloadingCouponId === coupon.id')
        );
        expect(downloadingBtn).toBeDefined();

        // 다운로드 가능 버튼
        const downloadBtn = cardChildren.find((c: any) =>
            c.if?.includes('!coupon.is_downloaded') && c.if?.includes('downloadingCouponId !== coupon.id')
        );
        expect(downloadBtn).toBeDefined();
        expect(downloadBtn.actions).toBeDefined();
    });

    it('다운로드 버튼이 apiCall을 호출한다', () => {
        const dataSection = children[2];
        const cardChildren = dataSection.children[0].children[1].children; // index 1 (0은 로딩 오버레이)
        const downloadBtn = cardChildren.find((c: any) =>
            c.if?.includes('!coupon.is_downloaded') && c.if?.includes('downloadingCouponId !== coupon.id')
        );

        const clickAction = downloadBtn.actions[0];
        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');

        const apiCallAction = clickAction.actions.find((a: any) => a.handler === 'apiCall');
        expect(apiCallAction).toBeDefined();
        expect(apiCallAction.target).toContain('/download');
        expect(apiCallAction.auth_mode).toBe('required');
        expect(apiCallAction.params.method).toBe('POST');
    });

    it('다운로드 성공 시 is_downloaded를 true로 업데이트한다', () => {
        const dataSection = children[2];
        const cardChildren = dataSection.children[0].children[1].children; // index 1 (0은 로딩 오버레이)
        const downloadBtn = cardChildren.find((c: any) =>
            c.if?.includes('!coupon.is_downloaded') && c.if?.includes('downloadingCouponId !== coupon.id')
        );
        const apiCallAction = downloadBtn.actions[0].actions.find((a: any) => a.handler === 'apiCall');

        // onSuccess에서 모달 자체 _local의 downloadableCoupons 업데이트
        const setStateOnSuccess = apiCallAction.onSuccess.find((a: any) =>
            a.handler === 'setState' && a.params?.downloadableCoupons
        );
        expect(setStateOnSuccess).toBeDefined();
        expect(setStateOnSuccess.params.target).toBe('local');
        expect(setStateOnSuccess.params.downloadableCoupons).toContain('is_downloaded: true');
    });

    it('페이지네이션이 있다', () => {
        const dataSection = children[2];
        const paginationSection = dataSection.children[1]; // 두 번째 자식이 페이지네이션

        expect(paginationSection.if).toContain('last_page > 1');

        // 이전/다음 버튼
        const buttons = paginationSection.children.filter((c: any) => c.name === 'Button');
        expect(buttons.length).toBeGreaterThanOrEqual(2);
    });

    it('페이지네이션 버튼이 모달 자체 _local에 상태를 저장한다 (closeModal 없음)', () => {
        const dataSection = children[2];
        const paginationSection = dataSection.children[1];
        const buttons = paginationSection.children.filter((c: any) => c.name === 'Button');
        const nextBtn = buttons[1]; // 다음 페이지 버튼

        const clickAction = nextBtn.actions[0];
        expect(clickAction.handler).toBe('sequence');

        // closeModal이 없어야 함 (모달 열린 상태 유지)
        const closeModalAction = clickAction.actions.find((a: any) => a.handler === 'closeModal');
        expect(closeModalAction).toBeUndefined();

        // setState target이 local (모달 자체 상태)
        const setStateAction = clickAction.actions.find((a: any) => a.handler === 'setState');
        expect(setStateAction.params.target).toBe('local');

        // apiCall onSuccess에서도 target: local
        const apiCallAction = clickAction.actions.find((a: any) => a.handler === 'apiCall');
        expect(apiCallAction).toBeDefined();
        const successSetState = apiCallAction.onSuccess.find((a: any) => a.handler === 'setState');
        expect(successSetState.params.target).toBe('local');

        // openModal이 없어야 함
        const openModalAction = apiCallAction.onSuccess.find((a: any) => a.handler === 'openModal');
        expect(openModalAction).toBeUndefined();
    });

    it('모달 내 상태 참조가 _local ?? $parent._local 패턴을 사용한다', () => {
        // 로딩 상태 if 조건
        expect(children[0].if).toContain('_local.downloadableCouponsLoading ?? $parent._local.downloadableCouponsLoading');

        // 데이터 상태 iteration source
        const dataSection = children[2];
        const iterationSource = dataSection.children[0].children[1].iteration.source; // index 1 (0은 로딩 오버레이)
        expect(iterationSource).toContain('_local.downloadableCoupons ?? $parent._local.downloadableCoupons');
    });
});

// ========== 체크아웃 할인 섹션 검증 ==========

describe('체크아웃 할인 섹션 쿠폰 다운로드 버튼 (_checkout_discount.json)', () => {
    const sectionHeader = checkoutDiscount.children[0]; // 섹션 헤더

    it('섹션 헤더가 justify-between flex 레이아웃이다', () => {
        expect(sectionHeader.props.className).toContain('justify-between');
    });

    it('쿠폰 다운로드 버튼이 로그인 사용자만 표시된다', () => {
        const downloadBtn = sectionHeader.children[1]; // 우측 버튼
        // currentUser?.uuid 패턴 사용 (currentUser 객체는 항상 truthy 가능 → uuid 로 진위 확인)
        expect(downloadBtn.if).toBe('{{_global.currentUser?.uuid}}');
        expect(downloadBtn.name).toBe('Button');
    });

    it('다운로드 버튼 클릭 시 sequence(setState → apiCall)를 실행하고 onSuccess에서 openModal한다', () => {
        const downloadBtn = sectionHeader.children[1];
        const clickAction = downloadBtn.actions[0];

        expect(clickAction.type).toBe('click');
        expect(clickAction.handler).toBe('sequence');

        const actions = clickAction.actions;
        expect(actions[0].handler).toBe('setState');
        expect(actions[0].params.downloadableCouponsLoading).toBe(true);

        expect(actions[1].handler).toBe('apiCall');
        expect(actions[1].target).toContain('downloadable');
        expect(actions[1].auth_mode).toBe('required');

        // openModal은 apiCall onSuccess에서 실행 ($parent._local 스냅샷 이슈 방지)
        const openModalInSuccess = actions[1].onSuccess.find((a: any) => a.handler === 'openModal');
        expect(openModalInSuccess).toBeDefined();
        expect(openModalInSuccess.target).toBe('coupon_download_modal');
    });
});

// ========== 상품 상세 쿠폰 배지 섹션 검증 ==========

describe('상품 상세 쿠폰 배지 (_info_summary.json)', () => {
    // 쿠폰 배지 섹션 찾기 (comment로 식별)
    const couponSection = (infoSummary as any).children.find(
        (c: any) => c.comment?.includes('다운로드 가능 쿠폰')
    );

    it('쿠폰 배지 섹션이 존재한다', () => {
        expect(couponSection).toBeDefined();
    });

    it('productDownloadableCoupons 데이터가 있을 때만 표시된다', () => {
        expect(couponSection.if).toContain('productDownloadableCoupons');
        expect(couponSection.if).toContain('length > 0');
    });

    it('최대 3개까지 slice하여 표시한다', () => {
        const badgeContainer = couponSection.children[0]; // flex container
        const iterationEl = badgeContainer.children[1]; // iteration element
        expect(iterationEl.iteration.source).toContain('slice(0, 3)');
    });

    it('benefit_formatted로 할인 혜택을 표시한다', () => {
        const badgeContainer = couponSection.children[0];
        const iterationEl = badgeContainer.children[1];
        const buttonChildren = iterationEl.children[0].children;

        const benefitSpan = buttonChildren.find((c: any) =>
            c.text?.includes('benefit_formatted')
        );

        expect(benefitSpan).toBeDefined();
    });

    it('쿠폰 배지 클릭 시 로그인/비로그인 분기 처리한다', () => {
        const badgeContainer = couponSection.children[0];
        const iterationEl = badgeContainer.children[1];
        const badge = iterationEl.children[0];

        const clickAction = badge.actions[0];
        expect(clickAction.handler).toBe('conditions');

        // 로그인 시 다운로드 확인 모달
        const loggedInCondition = clickAction.conditions[0];
        expect(loggedInCondition.if).toContain('_global.currentUser');
        expect(loggedInCondition.then.handler).toBe('sequence');

        // 비로그인 fallback (conditions 핸들러는 if 없는 항목을 else 로 처리,
        // 동작 자체는 then 키로 표현)
        const fallbackCondition = clickAction.conditions[1];
        expect(fallbackCondition.then.handler).toBe('openModal');
        expect(fallbackCondition.then.target).toBe('login_required_modal');
    });

    it('3개 초과 시 더보기 버튼을 표시한다', () => {
        const badgeContainer = couponSection.children[0];
        const moreBtn = badgeContainer.children[2]; // 더보기 버튼

        expect(moreBtn.if).toContain('length > 3');
        expect(moreBtn.name).toBe('Button');
    });
});

// ========== 로그인 필요 모달 검증 ==========

describe('로그인 필요 모달 (_modal_login_required.json)', () => {
    it('Modal 컴포넌트이며 올바른 ID를 가진다', () => {
        expect(loginRequiredModal.name).toBe('Modal');
        expect(loginRequiredModal.id).toBe('login_required_modal');
    });

    it('로그인 버튼이 /login 페이지로 이동한다', () => {
        const content = loginRequiredModal.children[0]; // center div
        const buttonsDiv = content.children[2]; // buttons container
        const loginBtn = buttonsDiv.children[1]; // 로그인 버튼

        const clickAction = loginBtn.actions[0];
        expect(clickAction.handler).toBe('sequence');

        const navigateAction = clickAction.actions.find((a: any) => a.handler === 'navigate');
        expect(navigateAction).toBeDefined();
        expect(navigateAction.params.path).toBe('/login');
    });
});

// ========== 다운로드 확인 모달 검증 ==========

describe('쿠폰 다운로드 확인 모달 (_modal_coupon_download_confirm.json)', () => {
    it('Modal 컴포넌트이며 올바른 ID를 가진다', () => {
        expect(downloadConfirmModal.name).toBe('Modal');
        expect(downloadConfirmModal.id).toBe('coupon_download_confirm_modal');
    });

    it('선택된 쿠폰 정보를 $parent._local에서 참조한다', () => {
        const content = JSON.stringify(downloadConfirmModal);
        expect(content).toContain('$parent._local.selectedCouponForDownload');
    });

    it('다운로드 버튼이 apiCall로 쿠폰을 다운로드한다', () => {
        const contentDiv = downloadConfirmModal.children[0];
        const buttonsDiv = contentDiv.children[2]; // buttons container
        const downloadBtn = buttonsDiv.children.find((c: any) =>
            c.if?.includes('!_local.isDownloading')
        );

        expect(downloadBtn).toBeDefined();
        const clickAction = downloadBtn.actions[0];
        expect(clickAction.handler).toBe('sequence');

        const apiCallAction = clickAction.actions.find((a: any) => a.handler === 'apiCall');
        expect(apiCallAction).toBeDefined();
        expect(apiCallAction.target).toContain('/download');
        expect(apiCallAction.auth_mode).toBe('required');
    });

    it('다운로드 성공 시 데이터소스를 갱신한다', () => {
        const contentDiv = downloadConfirmModal.children[0];
        const buttonsDiv = contentDiv.children[2];
        const downloadBtn = buttonsDiv.children.find((c: any) =>
            c.if?.includes('!_local.isDownloading')
        );
        const apiCallAction = downloadBtn.actions[0].actions.find((a: any) => a.handler === 'apiCall');

        const refreshAction = apiCallAction.onSuccess.find((a: any) =>
            a.handler === 'refetchDataSource'
        );
        expect(refreshAction).toBeDefined();
        expect(refreshAction.params.dataSourceId).toBe('productDownloadableCoupons');
    });
});

// ========== checkout.json 연동 검증 ==========

describe('checkout.json 쿠폰 다운로드 연동', () => {
    it('initLocal에 쿠폰 다운로드 관련 상태가 있다', () => {
        const initLocal = (checkoutLayout as any).initLocal;
        expect(initLocal).toHaveProperty('downloadableCoupons', null);
        expect(initLocal).toHaveProperty('downloadableCouponsLoading', false);
        expect(initLocal).toHaveProperty('downloadableCouponsPage', 1);
    });

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
    it('productDownloadableCoupons 데이터소스가 등록되어 있다', () => {
        const dataSources = (showLayout as any).data_sources;
        const couponDs = dataSources.find((ds: any) => ds.id === 'productDownloadableCoupons');
        expect(couponDs).toBeDefined();
        expect(couponDs.endpoint).toContain('/downloadable-coupons');
        expect(couponDs.auth_mode).toBe('optional');
    });

    it('init_actions에서 쿠폰 다운로드 관련 상태를 초기화한다', () => {
        const initActions = (showLayout as any).init_actions;
        const setStateAction = initActions.find((a: any) =>
            a.handler === 'setState' && a.params?.downloadableCoupons !== undefined
        );
        expect(setStateAction).toBeDefined();
        expect(setStateAction.params.downloadableCoupons).toBeNull();
    });

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
