/**
 * 마이페이지 상품 1:1 문의내역 레이아웃 구조 검증 테스트
 *
 * @description
 * - inquiries.json 데이터소스 및 API 엔드포인트 검증
 * - _list.json 문의 목록 UI, 필드명 바인딩, 상태 뱃지 검증
 * - 비밀글 처리, 답변 조건부 렌더링, 페이지네이션 검증
 */

import { describe, it, expect } from 'vitest';

import inquiriesLayout from '../../../layouts/mypage/inquiries.json';
import listPartial from '../../../layouts/partials/mypage/inquiries/_list.json';
import { asArray, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

// ========================================================================
// inquiries.json (문의내역 목록 페이지)
// ========================================================================

describe('마이페이지 문의내역 레이아웃 검증 (inquiries.json)', () => {
    // @scenario area=page_shell
    // @effects inquiries_page_uses_mypage_shell
    it('_user_base 레이아웃을 상속해야 함', () => {
        expect(inquiriesLayout.extends).toBe('_user_base');
    });

    // @scenario area=page_shell
    // @effects inquiries_page_uses_mypage_shell
    it('computed.currentTab이 "inquiries"여야 함', () => {
        expect((inquiriesLayout as any).computed?.currentTab).toBe('inquiries');
    });

    // @scenario area=page_shell
    // @effects inquiries_fetched_from_module_user_endpoint
    it('데이터소스가 올바른 모듈 API 엔드포인트를 사용해야 함', () => {
        const ds = inquiriesLayout.data_sources[0];
        expect(ds.id).toBe('myInquiries');
        expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/inquiries');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.auth_required).toBe(true);
    });

    // @scenario area=page_shell
    // @effects inquiries_page_follows_query_page
    it('데이터소스에 페이지네이션 파라미터가 있어야 함', () => {
        const ds = inquiriesLayout.data_sources[0];
        const dsAny = ds as any;
        expect(dsAny.params?.page).toContain('query.page');
    });

    // @scenario area=page_shell
    // @effects inquiries_page_follows_query_page
    it('init_actions에서 inquiriesPage 초기값을 설정해야 함', () => {
        const initActions = (inquiriesLayout as any).init_actions ?? [];
        const initAction = initActions.find((a: any) => a.handler === 'setState' && a.params?.target === 'local');
        expect(initAction).toBeDefined();
        expect(initAction.params.inquiriesPage).toBeDefined();
    });

    // @scenario area=page_shell
    // @effects inquiries_page_uses_mypage_shell
    it('transition_overlay 스켈레톤 설정이 있어야 함', () => {
        const overlay = (inquiriesLayout as any).transition_overlay;
        expect(overlay).toBeDefined();
        expect(overlay.enabled).toBe(true);
        expect(overlay.style).toBe('skeleton');
        expect(overlay.target).toBe('mypage_tab_content');
    });

    // @scenario area=page_shell
    // @effects inquiries_page_uses_mypage_shell
    it('_tab_navigation partial을 참조해야 함', () => {
        const layoutStr = JSON.stringify(inquiriesLayout);
        expect(layoutStr).toContain('partials/mypage/_tab_navigation.json');
    });

    // @scenario area=page_shell
    // @effects inquiries_page_uses_mypage_shell
    it('_list.json partial을 참조해야 함', () => {
        const layoutStr = JSON.stringify(inquiriesLayout);
        expect(layoutStr).toContain('partials/mypage/inquiries/_list.json');
    });

    // @scenario area=page_shell
    // @effects inquiries_fetched_from_module_user_endpoint
    it('/api/shop/ 경로를 사용하지 않아야 함', () => {
        const ds = inquiriesLayout.data_sources[0];
        expect(ds.endpoint).not.toContain('/api/shop/');
    });

    // @scenario area=page_shell
    // @effects inquiries_unavailable_shows_404
    it('데이터소스 onSuccess에서 inquiry_available 기반 404 처리를 해야 함', () => {
        const ds = (inquiriesLayout as any).data_sources[0];
        // `onSuccess` 는 콜백 배열이 정본이고 단일 원소는 객체로 접힌다 — 양쪽을 받는다.
        const onSuccess = asArray(ds.onSuccess)[0];
        expect(onSuccess).toBeDefined();
        expect(onSuccess.handler).toBe('conditions');
        const condition = callbacksOf(onSuccess, 'conditions')[0];
        expect(condition).toBeDefined();
        expect(condition.if).toContain('inquiry_available');
        expect(condition.then?.handler).toBe('showErrorPage');
        expect(condition.then?.params?.errorCode).toBe(404);
    });
});

// ========================================================================
// _list.json (문의 목록 partial)
// ========================================================================

describe('문의내역 목록 partial 구조 검증 (_list.json)', () => {
    const listStr = JSON.stringify(listPartial);

    describe('Partial 기본 구조', () => {
        // @scenario area=list_card
        // @effects inquiries_list_blurred_until_loaded
        it('is_partial 메타 속성이 있어야 함', () => {
            expect((listPartial as any).meta?.is_partial).toBe(true);
        });

        // @scenario area=list_card
        // @effects inquiries_list_blurred_until_loaded
        it('blur_until_loaded가 myInquiries 데이터소스를 참조해야 함', () => {
            const blur = (listPartial as any).blur_until_loaded;
            expect(blur?.enabled).toBe(true);
            expect(blur?.data_sources).toBe('myInquiries');
        });
    });

    describe('데이터 바인딩 필드명', () => {
        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('문의 목록 데이터 경로로 myInquiries.data.items를 사용해야 함', () => {
            expect(listStr).toContain('myInquiries.data.items');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('총 개수 경로로 myInquiries.data.meta.total을 사용해야 함', () => {
            expect(listStr).toContain('myInquiries.data.meta.total');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('상품명 필드로 item.product_name을 사용해야 함', () => {
            expect(listStr).toContain('item.product_name');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('답변 여부 필드로 item.is_answered를 사용해야 함', () => {
            expect(listStr).toContain('item.is_answered');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('문의 내용 필드로 item.content를 사용해야 함', () => {
            expect(listStr).toContain('item.content');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('답변 내용 필드로 item.reply를 사용해야 함', () => {
            expect(listStr).toContain('item.reply');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('작성일 필드로 item.created_at을 사용해야 함', () => {
            expect(listStr).toContain('item.created_at');
        });
    });

    describe('반복 렌더링 (iteration)', () => {
        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('iteration source가 myInquiries.data.items를 사용해야 함', () => {
            expect(listStr).toContain('"source"');
            expect(listStr).toContain('myInquiries.data.items');
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('iteration 변수명이 item_var: "item"을 사용해야 함 (item/index 금지)', () => {
            expect(listStr).toContain('"item_var"');
            const hasItemVar = listStr.includes('"item_var":"item"') || listStr.includes('"item_var": "item"');
            expect(hasItemVar).toBe(true);
        });

        // @scenario area=list_card
        // @effects inquiry_card_binds_item_fields
        it('iteration에서 "index" 변수명을 사용하지 않아야 함', () => {
            const iterationMatch = listStr.match(/"item_var"\s*:\s*"([^"]+)"/);
            if (iterationMatch) {
                expect(iterationMatch[1]).not.toBe('index');
            }
        });
    });

    describe('비밀글 처리', () => {
        // @scenario area=list_card
        // @effects own_secret_inquiry_shown_unmasked
        it('본인 글이므로 item.content를 항상 표시해야 함 (비밀글 마스킹 없음)', () => {
            expect(listStr).toContain('item.content');
        });

        // @scenario area=list_card
        // @effects own_secret_inquiry_shown_unmasked
        it('비밀글 마스킹(secret_content)을 사용하지 않아야 함', () => {
            // item.is_secret은 수정 폼 초기값(qnaForm.is_secret)으로 사용되므로 파일에 존재함
            // 마스킹 목적(secret_content 다국어 키)은 사용하지 않아야 함
            expect(listStr).not.toContain('mypage.inquiries.secret_content');
        });
    });

    describe('답변 상태', () => {
        // @scenario area=list_card
        // @effects answer_status_badge_shown
        it('답변 완료 상태 다국어 키를 사용해야 함', () => {
            expect(listStr).toContain('mypage.inquiries.status.answered');
        });

        // @scenario area=list_card
        // @effects answer_status_badge_shown
        it('답변 대기 상태 다국어 키를 사용해야 함', () => {
            expect(listStr).toContain('mypage.inquiries.status.pending');
        });

        // @scenario area=list_card
        // @effects reply_shown_when_answered_without_toggle
        it('답변 내용은 is_answered && reply 조건으로만 표시해야 함', () => {
            expect(listStr).toContain('item.is_answered');
            expect(listStr).toContain('item.reply');
        });

        // @scenario area=list_card
        // @effects reply_shown_when_answered_without_toggle
        it('답변은 토글 없이 항상 표시되어야 함 (openedReplyId 토글 없음)', () => {
            expect(listStr).not.toContain('openedReplyId');
        });

        // @scenario area=list_card
        // @effects reply_shown_when_answered_without_toggle
        it('답변 내용 접근 시 item.reply.content를 사용해야 함', () => {
            expect(listStr).toContain('item.reply?.content');
        });
    });

    describe('빈 상태 처리', () => {
        // @scenario area=empty_state
        // @effects empty_state_offers_shopping_link
        it('문의 없음 메시지에 mypage.inquiries.empty 키를 사용해야 함', () => {
            expect(listStr).toContain('mypage.inquiries.empty');
        });

        // @scenario area=empty_state
        // @effects empty_state_offers_shopping_link
        it('빈 상태에서 쇼핑 이동 버튼이 있어야 함', () => {
            expect(listStr).toContain('mypage.inquiries.go_shopping');
        });

        // @scenario area=empty_state
        // @effects empty_state_offers_shopping_link
        it('쇼핑 이동 버튼이 navigate 핸들러를 사용해야 함', () => {
            const listSection = (listPartial as any).children?.find(
                (c: any) => c.comment?.includes('문의 목록')
            );
            const emptySection = listSection?.children?.find(
                (c: any) => c.comment?.includes('문의 없음')
            );
            // 이동 자리는 링크다 — 주소는 href, click 은 경로 없는 navigate
            const link = emptySection?.children?.find((c: any) => c.name === 'A');
            expect(link?.props?.href).toBeTruthy();
            const navAction = link?.actions?.[0];
            expect(navAction?.handler).toBe('navigate');
        });
    });

    describe('페이지네이션', () => {
        // @scenario area=pagination
        // @effects pagination_refetches_inquiries
        it('Pagination 컴포넌트가 있어야 함', () => {
            expect(listStr).toContain('"name":"Pagination"');
        });

        // @scenario area=pagination
        // @effects pagination_refetches_inquiries
        it('페이지네이션이 myInquiries.data.meta 경로를 사용해야 함', () => {
            expect(listStr).toContain('myInquiries.data.meta.last_page');
            expect(listStr).toContain('myInquiries.data.meta.current_page');
        });

        // @scenario area=pagination
        // @effects pagination_refetches_inquiries
        it('페이지 변경 시 sequence 핸들러로 setState + refetchDataSource를 호출해야 함', () => {
            const listChildren = (listPartial as any).children;
            const listSection = listChildren?.find((c: any) => c.comment?.includes('문의 목록'));
            const paginationChildren = listSection?.children;
            const pagination = paginationChildren?.find((c: any) => c.name === 'Pagination');

            expect(pagination).toBeDefined();
            const pageChangeAction = pagination?.actions?.find(
                (a: any) => a.event === 'onPageChange'
            );
            expect(pageChangeAction).toBeDefined();
            expect(pageChangeAction?.handler).toBe('sequence');

            const setStateAction = pageChangeAction?.actions?.find(
                (a: any) => a.handler === 'setState'
            );
            expect(setStateAction?.params?.target).toBe('local');

            const refetchAction = pageChangeAction?.actions?.find(
                (a: any) => a.handler === 'refetchDataSource'
            );
            expect(refetchAction?.params?.dataSourceId).toBe('myInquiries');
        });
    });

    describe('날짜 표시', () => {
        // @scenario area=list_card
        // @effects dates_formatted_with_date_pipe
        it('질문일에 date 파이프를 사용해야 함', () => {
            expect(listStr).toContain('item.created_at | date');
        });

        // @scenario area=list_card
        // @effects dates_formatted_with_date_pipe
        it('답변일에 date 파이프를 사용해야 함', () => {
            expect(listStr).toContain('item.reply?.created_at | date');
        });
    });

    describe('상품 이미지', () => {
        // @scenario area=list_card
        // @effects product_thumbnail_shown_when_present
        it('상품 썸네일이 있을 때 Img 컴포넌트로 표시해야 함', () => {
            expect(listStr).toContain('item.product.thumbnail_url');
            expect(listStr).toContain('"name":"Img"');
        });

        // @scenario area=list_card
        // @effects product_thumbnail_shown_when_present
        it('상품 썸네일은 조건부로 표시해야 함 (thumbnail_url 있을 때만)', () => {
            const hasCondition =
                listStr.includes('item.product && item.product.thumbnail_url') ||
                listStr.includes('item.product?.thumbnail_url');
            expect(hasCondition).toBe(true);
        });
    });

    describe('검색 및 필터', () => {
        // @scenario area=status_filter
        // @effects search_input_removed
        it('SearchBar 컴포넌트를 사용하지 않아야 함 (검색 입력 제거됨)', () => {
            expect(listStr).not.toContain('"name":"SearchBar"');
        });

        // @scenario area=status_filter
        // @effects search_input_removed
        it('inquiriesSearch 상태를 사용하지 않아야 함 (검색 입력 제거됨)', () => {
            expect(listStr).not.toContain('inquiriesSearch');
        });

        // @scenario area=status_filter
        // @effects answer_status_filter_tabs_refetch
        it('필터 변경 시 refetchDataSource를 호출해야 함', () => {
            expect(listStr).toContain('refetchDataSource');
        });

        // @scenario area=status_filter
        // @effects answer_status_filter_tabs_refetch
        it('답변 상태 필터가 탭 버튼 형태여야 함 (Select 대신 Button)', () => {
            expect(listStr).not.toContain('"name":"Select"');
            expect(listStr).toContain('inquiriesFilter');
        });

        // @scenario area=status_filter
        // @effects answer_status_filter_tabs_refetch
        it('필터가 inquiriesFilter 상태와 바인딩되어야 함', () => {
            expect(listStr).toContain('inquiriesFilter');
        });

        // @scenario area=status_filter
        // @effects answer_status_filter_tabs_refetch
        it('필터 옵션에 mypage.inquiries.filter 다국어 키를 사용해야 함', () => {
            expect(listStr).toContain('mypage.inquiries.filter.all');
            expect(listStr).toContain('mypage.inquiries.filter.answered');
            expect(listStr).toContain('mypage.inquiries.filter.pending');
        });

        // @scenario area=status_filter
        // @effects search_input_removed
        it('검색 placeholder 다국어 키를 사용하지 않아야 함 (placeholder 제거됨)', () => {
            expect(listStr).not.toContain('search_placeholder');
        });
    });

    describe('다국어 키 형식', () => {
        // @scenario area=list_card
        // @effects layout_keys_use_template_mypage_namespace
        it('mypage 네임스페이스 키를 사용해야 함 ($t:mypage.inquiries.*)', () => {
            expect(listStr).toContain('$t:mypage.inquiries.');
        });

        // @scenario area=list_card
        // @effects layout_keys_use_template_mypage_namespace
        it('sirsoft-ecommerce 모듈 네임스페이스를 레이아웃 키에서 사용하지 않아야 함', () => {
            expect(listStr).not.toContain('$t:sirsoft-ecommerce.mypage.inquiries');
        });
    });

    describe('수정됨 표시 (Issue #208 개편)', () => {
        // @scenario area=list_card
        // @effects edited_label_when_updated_after_created
        it('item.updated_at 필드를 사용해야 함', () => {
            expect(listStr).toContain('item.updated_at');
        });

        // @scenario area=list_card
        // @effects edited_label_when_updated_after_created
        it('updated_at !== created_at 조건으로 수정됨을 판별해야 함', () => {
            expect(listStr).toContain('item.updated_at !== item.created_at');
        });

        // @scenario area=list_card
        // @effects edited_label_when_updated_after_created
        it('수정됨 표시에 mypage.inquiries.updated_label_at 다국어 키를 사용해야 함', () => {
            expect(listStr).toContain('mypage.inquiries.updated_label_at');
        });
    });

    describe('삭제 모달 방식 (Issue #208 개편)', () => {
        // @scenario area=item_actions
        // @effects delete_opens_confirm_modal_with_context
        it('삭제 버튼이 apiCall confirm 방식을 사용하지 않아야 함', () => {
            expect(listStr).not.toContain('"confirm"');
        });

        // @scenario area=item_actions
        // @effects delete_opens_confirm_modal_with_context
        it('삭제 버튼이 openModal 핸들러를 사용해야 함', () => {
            expect(listStr).toContain('"openModal"');
        });

        // @scenario area=item_actions
        // @effects delete_opens_confirm_modal_with_context
        it('삭제 버튼이 inquiry_delete_modal을 열어야 함', () => {
            expect(listStr).toContain('inquiry_delete_modal');
        });

        // @scenario area=item_actions
        // @effects delete_opens_confirm_modal_with_context
        it('삭제 시 qnaDeleteSource가 "mypage"로 설정되어야 함', () => {
            expect(listStr).toContain('"mypage"');
        });

        // @scenario area=item_actions
        // @effects delete_opens_confirm_modal_with_context
        it('삭제 시 qnaDeleteId가 설정되어야 함', () => {
            expect(listStr).toContain('qnaDeleteId');
        });

        // @scenario area=item_actions
        // @effects delete_opens_confirm_modal_with_context
        it('삭제 시 qnaDeleteIsAnswered가 설정되어야 함', () => {
            expect(listStr).toContain('qnaDeleteIsAnswered');
        });
    });

    describe('수정 버튼 모달 방식 (Issue #208 개편)', () => {
        // @scenario area=item_actions
        // @effects edit_opens_write_modal_with_context
        it('수정 버튼이 qna_write_modal을 열어야 함', () => {
            expect(listStr).toContain('qna_write_modal');
        });

        // @scenario area=item_actions
        // @effects edit_opens_write_modal_with_context
        it('수정 버튼 클릭 시 qnaCreatedAt이 설정되어야 함', () => {
            expect(listStr).toContain('qnaCreatedAt');
        });

        // @scenario area=item_actions
        // @effects edit_opens_write_modal_with_context
        it('수정 버튼 클릭 시 qnaModalBoardSettings이 설정되어야 함', () => {
            expect(listStr).toContain('qnaModalBoardSettings');
        });
    });

    describe('카드 UI 구조 (Issue #208 개편)', () => {
        // @scenario area=list_card
        // @effects inquiry_rendered_as_card_with_header_actions
        it('개별 카드 레이아웃으로 rounded 클래스를 사용해야 함', () => {
            expect(listStr).toContain('rounded');
        });

        // @scenario area=item_actions
        // @effects inquiry_rendered_as_card_with_header_actions
        it('수정/삭제 버튼이 펼침 조건(openedInquiryId)에 종속되지 않아야 함', () => {
            // 버튼은 카드 헤더에 항상 노출, openedInquiryId는 상세 패널 토글에만 사용
            // 버튼 자체의 if 조건에 openedInquiryId가 없어야 함
            const parsed = JSON.parse(listStr);
            const iterationDiv = parsed?.children
                ?.find((c: any) => c.comment?.includes('문의 목록'))
                ?.children?.find((c: any) => c.comment?.includes('카드 목록'))
                ?.children?.[0]; // iteration Div
            const cardDiv = iterationDiv?.children?.[0]; // 개별 카드
            const headerDiv = cardDiv?.children?.find((c: any) => c.comment?.includes('카드 헤더'));
            const rightDiv = headerDiv?.children?.find((c: any) => c.comment?.includes('오른쪽'));
            const editDeleteDiv = rightDiv?.children?.find((c: any) => c.comment?.includes('미답변'));
            // 수정/삭제 버튼 영역 자체에 openedInquiryId 조건이 없어야 함
            expect(editDeleteDiv?.if ?? '').not.toContain('openedInquiryId');
        });
    });
});