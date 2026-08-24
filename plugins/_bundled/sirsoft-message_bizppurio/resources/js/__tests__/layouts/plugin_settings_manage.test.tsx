// e2e:allow 관리 화면 mergeQuery 왕복·SMS 모달 저장 왕복 브라우저 흐름은
// tests/Playwright/specs/admin/template-lifecycle.spec.ts(bizppurio_manage_round_trip_e2e)가 담당.
/**
 * 플러그인 설정 — 알림 템플릿 관리 탭(DB 목록) 구조 검증 (#597 §4.3)
 *
 * @effects manage_screen_lists_db_rows_with_merge_query_round_trip, inspection_request_carries_reviewer_comment
 *
 * templates 탭은 DB 목록 관리 화면이다:
 * - 데이터소스 bizppurio_templates_list(GET /admin/templates) — 필터·검색·페이지는
 *   URL 쿼리 SSoT(bz_status/bz_search/bz_page, mergeQuery 왕복 규약)
 * - templates_manage_view — readiness 충족 시에만, 미충족이면 readiness 안내가 담당(배타)
 * - 행 액션은 알림 설정 탭 행 하단과 동일 엔드포인트(request/cancel-request/cancel-approval/
 *   release/sync/delivery) + 관리 전용 삭제(modal_bizppurio_delete, DELETE)
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';
import sections from '../../../extensions/notification_template_form_sections.json';
import footerActions from '../../../extensions/notification_template_form_footer_actions.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, findAllByName, evalBinding, type AnyNode } from './helpers';

const root = layout as unknown as AnyNode;
const manageView = findById(root, 'templates_manage_view') as AnyNode;
const modalRoot = { children: (layout as { modals?: AnyNode[] }).modals ?? [] } as AnyNode;

describe('manage — 목록 데이터소스(URL 쿼리 SSoT)', () => {
    const ds = ((root as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? [])
        .find((d) => d.id === 'bizppurio_templates_list');

    it('bizppurio_templates_list 가 GET /admin/templates 를 조회한다(auto_fetch:false)', () => {
        expect(ds).toBeTruthy();
        expect(ds?.endpoint).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates');
        expect(ds?.method).toBe('GET');
        expect(ds?.auto_fetch).toBe(false);
    });

    it('params 가 query.bz_status/bz_search/bz_page 바인딩이다(목록 상태의 SSoT 는 URL)', () => {
        const params = ds?.params as Record<string, unknown>;
        expect(params.status).toBe("{{query.bz_status ?? ''}}");
        expect(params.search).toBe("{{query.bz_search ?? ''}}");
        expect(params.page).toBe('{{query.bz_page ?? 1}}');
        expect(params.per_page).toBe(20);
    });

    it('templates 탭 진입 시 init_actions 가 목록을 조회한다(새로고침 복원)', () => {
        const inits = (root as { init_actions?: AnyNode[] }).init_actions ?? [];
        const refetch = inits.find(
            (a) => a.handler === 'refetchDataSource'
                && (a.params as { dataSourceId?: string })?.dataSourceId === 'bizppurio_templates_list',
        );
        expect(refetch).toBeTruthy();
        expect((refetch as { if?: string }).if).toContain("query.tab ?? 'connection') === 'templates'");
    });

    it('tab_templates 탭 버튼이 navigate(mergeQuery) 후 목록을 refetch 한다', () => {
        const raw = JSON.stringify(findById(root, 'tab_templates'));
        expect(raw).toContain('"handler":"navigate"');
        expect(raw).toContain('"tab":"templates"');
        expect(raw).toContain('"mergeQuery":true');
        expect(raw).toContain('bizppurio_templates_list');
    });
});

describe('manage — readiness 게이트(배타 전환)', () => {
    it('관리 뷰는 readiness 충족 시에만 노출된다', () => {
        expect(manageView).toBeTruthy();
        expect(manageView.if).toBe('{{templates_readiness?.data?.ready}}');
    });

    it('readiness 미충족 안내는 !ready 조건으로 관리 뷰와 상호배타다', () => {
        const notice = findById(root, 'templates_readiness');
        expect(notice).toBeTruthy();
        expect((notice as { if?: string }).if).toBe('{{templates_readiness?.data && !(templates_readiness?.data?.ready)}}');
    });
});

describe('manage — 필터·검색·페이지 (mergeQuery 왕복 규약)', () => {
    it('상태 필터 Select: value=query.bz_status, 옵션은 template.status.* 어휘, 변경 시 bz_page 리셋 + refetch', () => {
        const select = findAllByName(manageView, 'Select')
            .find((s) => (s.props as { value?: string })?.value === "{{query.bz_status ?? ''}}");
        expect(select).toBeTruthy();
        const options = String((select?.props as { options?: string })?.options ?? '');
        expect(options).toContain("template.status.' + s");
        expect(options).toContain("'draft','requested','approved','rejected','stopped','blocked','dormant'");
        const raw = JSON.stringify(select);
        expect(raw).toContain('"mergeQuery":true');
        expect(raw).toContain('"bz_status":"{{$event.target.value}}"');
        expect(raw).toContain('"bz_page":""');
        expect(raw).toContain('bizppurio_templates_list');
    });

    it('검색: 입력은 로컬 초안(bzSearchDraft), 실행 버튼이 bz_search 반영 + bz_page 리셋 + refetch', () => {
        const input = findAllByName(manageView, 'Input')
            .find((i) => String((i.props as { value?: string })?.value ?? '').includes('bzSearchDraft'));
        expect(input).toBeTruthy();
        const searchBtn = findAllByName(manageView, 'Button')
            .find((b) => b.text === '$t:sirsoft-message_bizppurio.manage.btn_search');
        const raw = JSON.stringify(searchBtn);
        expect(raw).toContain('"mergeQuery":true');
        expect(raw).toContain('"bz_search":"{{_local.bzSearchDraft ?? \'\'}}"');
        expect(raw).toContain('"bz_page":""');
        expect(raw).toContain('bizppurio_templates_list');
    });

    it('새로고침 버튼은 URL 을 건드리지 않고 목록만 refetch 한다(보던 목록 유지)', () => {
        const refreshBtn = findAllByName(manageView, 'Button')
            .find((b) => (b.props as Record<string, unknown>)?.['aria-label'] === '$t:sirsoft-message_bizppurio.templates.list.refresh');
        expect(refreshBtn).toBeTruthy();
        const raw = JSON.stringify(refreshBtn);
        expect(raw).toContain('bizppurio_templates_list');
        expect(raw).not.toContain('"handler":"navigate"');
    });

    it('Pagination 이 last_page 를 사용하고 페이지 이동은 bz_page 를 mergeQuery 로 나른 뒤 refetch 한다', () => {
        const pagination = findAllByName(manageView, 'Pagination')[0];
        expect(pagination).toBeTruthy();
        const props = pagination.props as { currentPage?: string; totalPages?: string; hasMorePages?: string };
        expect(props.currentPage).toBe('{{bizppurio_templates_list?.data?.pagination?.current_page ?? 1}}');
        // last_page 는 대용량 목록 계약상 미상(null)일 수 있어 1 로 채우지 않는다 (pagination.md)
        expect(props.totalPages).toBe('{{bizppurio_templates_list?.data?.pagination?.last_page ?? null}}');
        expect(props.hasMorePages).toBe('{{bizppurio_templates_list?.data?.pagination?.has_more_pages ?? false}}');
        const raw = JSON.stringify(pagination);
        expect(raw).toContain('"mergeQuery":true');
        expect(raw).toContain('"bz_page":"{{$args[0]}}"');
        expect(raw).toContain('bizppurio_templates_list');
    });

    it('총 건수와 빈 목록 안내가 존재한다', () => {
        const raw = JSON.stringify(manageView);
        expect(raw).toContain('manage.total_count');
        expect(raw).toContain('bizppurio_templates_list?.data?.pagination?.total');
        expect(raw).toContain('manage.empty');
    });
});

/**
 * @effects manage_row_actions_match_row_footer_visibility
 */
describe('manage — 행 액션(알림 설정 탭 행 하단과 동일 엔드포인트)', () => {
    /** 노드 JSON 에서 템플릿 admin API 경로만 추출한다({{...}} 표현식 부분은 정규화). */
    const collectEndpoints = (json: unknown): Set<string> => {
        const text = JSON.stringify(json);
        const matches = text.match(/\/api\/plugins\/sirsoft-message_bizppurio\/admin\/templates[^"]*/g) ?? [];
        return new Set(matches.map((m) => m.replace(/\{\{[^}]+\}\}/g, '{id}')));
    };

    it('상태 전이 4종(request/cancel-request/release/sync)이 목록 갱신과 함께 배선된다', () => {
        const raw = JSON.stringify(manageView);
        for (const suffix of ['/request', '/cancel-request', '/release', '/sync']) {
            expect(raw, `${suffix} 누락`).toContain(
                `/api/plugins/sirsoft-message_bizppurio/admin/templates/{{bzRow.id}}${suffix}`,
            );
        }
        // 전이 후에는 map 이 아니라 이 화면의 목록을 갱신한다
        expect(raw).toContain('bizppurio_templates_list');
    });

    /**
     * 관리 화면(bzRow)과 코어 [편집] 모달 섹션(_global.bz_tpl_modal)의 노출 조건을 같은 상태
     * 집합에 **실제로 태워** 대조한다(제품 결정 2026-08-23 — 행 하단 버튼 폐지, 섹션이 SSoT).
     *
     * 문자열 동일성 단언은 두 면의 식이 실제로 같은 결과를 내는지 증명하지 못한다 — 두 식을
     * 각자의 컨텍스트로 평가해 상태별 결과가 일치하는지를 본다.
     */
    const MANAGE_ROWS: Record<string, Record<string, unknown>> = {
        '행만 있고 내용 없음': { has_content: false, status: 'draft', template_code: null },
        draft: { has_content: true, status: 'draft', template_code: null },
        requested: { has_content: true, status: 'requested', template_code: 'g7_a_1' },
        approved: { has_content: true, status: 'approved', template_code: 'g7_a_1' },
        rejected: { has_content: true, status: 'rejected', template_code: 'g7_a_1', inspection_detail: [{ content: '반려 사유' }] },
        '반려(사유 없음)': { has_content: true, status: 'rejected', template_code: 'g7_a_1', inspection_detail: [] },
        dormant: { has_content: true, status: 'dormant', template_code: 'g7_a_1' },
        stopped: { has_content: true, status: 'stopped', template_code: 'g7_a_1' },
        blocked: { has_content: true, status: 'blocked', template_code: 'g7_a_1' },
    };

    /** manage 화면 식을 bzRow 컨텍스트로 평가 */
    const evalManage = (expr: string, bzRow: Record<string, unknown>): boolean => {
        const body = expr.trim().replace(/^{{/, '').replace(/}}$/, '');
        // eslint-disable-next-line no-new-func
        return Boolean(new Function('bzRow', `return (${body});`)(bzRow));
    };

    /** 편집 모달 섹션 식을 _global.bz_tpl_modal 컨텍스트로 평가(섹션은 상세 GET 으로 status/template_code 를 시딩한다) */
    const evalSection = (expr: string, bzRow: Record<string, unknown>): boolean => {
        const body = expr.trim().replace(/^{{/, '').replace(/}}$/, '');
        // eslint-disable-next-line no-new-func
        // 섹션은 READY(자기 알림 유형으로 시딩 + 상세 GET 완료) 컨텍스트에서 평가한다
        const fn = new Function('_global', 'extensionPointProps', `return (${body});`);
        return Boolean(fn(
            { bz_tpl_modal: { notification_type: 'welcome', loading: false, status: bzRow.status, template_code: bzRow.template_code ?? null }, bz_tpl_ui: { confirmCancelApproval: false } },
            { definition: { type: 'welcome' }, channel: 'alimtalk' },
        ));
    };

    const sectionsRoot = { children: (sections as { components?: AnyNode[] }).components ?? [] } as AnyNode;
    const byTextKey = (root: AnyNode, key: string) => findAllByName(root, 'Button')
        .find((b) => b.text === `$t:sirsoft-message_bizppurio.${key}`);

    /** 관리 화면 ↔ 편집 모달 섹션에서 같은 의미를 갖는 버튼 쌍 */
    const PAIRS = [
        'template.row.btn_cancel_request',
        'template.row.btn_release',
        'template.row.btn_refresh',
        'template.row.btn_edit_approved',
    ];

    it.each(PAIRS)('%s 의 노출 조건이 관리 화면과 편집 모달 섹션에서 상태별로 같은 결과를 낸다', (key) => {
        const m = byTextKey(manageView, key);
        const f = byTextKey(sectionsRoot, key);
        expect(m?.if, `관리 화면에 ${key} if 가 없다`).toBeTruthy();
        expect(f?.if, `편집 모달 섹션에 ${key} if 가 없다`).toBeTruthy();

        for (const [state, bzRow] of Object.entries(MANAGE_ROWS)) {
            expect(evalManage(m!.if as string, bzRow), `${key} @ ${state}`)
                .toBe(evalSection(f!.if as string, bzRow));
        }
    });

    it('관리 화면 [검수 신청]은 draft + 내용 보유에서만 노출된다(편집 모달은 [저장 후 검수 신청]이 본문 입력으로 판정)', () => {
        const m = byTextKey(manageView, 'template.row.btn_request');
        expect(m?.if).toBeTruthy();
        for (const [state, bzRow] of Object.entries(MANAGE_ROWS)) {
            expect(evalManage(m!.if as string, bzRow), `검수 신청 @ ${state}`).toBe(bzRow.status === 'draft' && bzRow.has_content === true);
        }
    });

    it('작성/수정 버튼은 편집 모달의 작성 폼이 열리는 상태(draft/rejected)에서만 노출된다', () => {
        const m = findAllByName(manageView, 'Button')
            .find((b) => typeof b.text === 'string' && b.text.includes("sirsoft-message_bizppurio.manage.' + (bzRow.has_content"));
        expect(m?.if, '관리 화면 작성/수정 버튼을 찾지 못했다').toBeTruthy();

        const editor = findById(sectionsRoot, 'bizppurio_tpl_editor');
        expect(editor?.if).toBeTruthy();
        for (const [state, bzRow] of Object.entries(MANAGE_ROWS)) {
            expect(evalManage(m!.if as string, bzRow), `작성/수정 @ ${state}`).toBe(evalSection(editor!.if as string, bzRow));
        }
    });

    it('작성/수정 버튼의 라벨이 내용 유무로 갈린다(미작성 행에 "수정" 이 뜨지 않는다)', () => {
        const m = findAllByName(manageView, 'Button')
            .find((b) => typeof b.text === 'string' && b.text.includes("sirsoft-message_bizppurio.manage.' + (bzRow.has_content"));
        const text = m?.text as string;
        expect(text).toContain('bzRow.has_content');
        expect(text).toContain('btn_edit');
        expect(text).toContain('btn_compose');
        expect(ko.manage.btn_compose).toBeTruthy();
        expect(en.manage.btn_compose).toBeTruthy();
    });

    it('상태 전이·delivery upsert 엔드포인트 계열이 편집 모달 섹션·푸터 액션과 같다', () => {
        const layoutEndpoints = collectEndpoints(layout);
        const sharedEndpoints = collectEndpoints([sections, footerActions]);
        // 편집 모달이 쓰는 계열(map 조회·상세 GET 제외 — 관리 화면은 DB 목록을 쓴다)이 관리 화면에도 존재한다
        for (const ep of sharedEndpoints) {
            if (ep.endsWith('/map') || ep === '/api/plugins/sirsoft-message_bizppurio/admin/templates/{id}') continue;
            expect(layoutEndpoints, `관리 화면에 ${ep} 누락`).toContain(ep);
        }
        expect(JSON.stringify(layout)).toContain(
            '/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_cancel_approval?.id}}/cancel-approval',
        );
        expect(JSON.stringify(layout)).toContain(
            '/api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/{{_global.bz_sms_modal?.notification_type}}',
        );
    });

    it('모달 5종(작성/SMS/승인취소/반려/삭제)이 이 레이아웃에 등록된다', () => {
        const ids = ((layout as { modals?: AnyNode[] }).modals ?? []).map((m) => m.id);
        expect(ids).toEqual([
            'modal_bizppurio_template',
            'modal_bizppurio_sms',
            'modal_bizppurio_cancel_approval',
            'modal_bizppurio_rejection',
            'modal_bizppurio_delete',
        ]);
    });
});

describe('manage — 삭제 모달(modal_bizppurio_delete)', () => {
    const modal = findById(modalRoot, 'modal_bizppurio_delete');

    it('행 삭제 버튼이 bz_delete_modal seed 후 삭제 확인 모달을 연다', () => {
        const deleteBtn = findAllByName(manageView, 'Button')
            .find((b) => b.text === '$t:sirsoft-message_bizppurio.manage.btn_delete');
        expect(deleteBtn).toBeTruthy();
        const raw = JSON.stringify(deleteBtn);
        expect(raw).toContain('"bz_delete_modal"');
        expect(raw).toContain('modal_bizppurio_delete');
    });

    it('확정 시 DELETE /admin/templates/{id} 후 목록 갱신 + 모달 닫힘', () => {
        expect(modal).toBeTruthy();
        const raw = JSON.stringify(modal);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_delete_modal?.id}}');
        expect(raw).toContain('"method":"DELETE"');
        expect(raw).toContain('bizppurio_templates_list');
        expect(raw).toContain('"closeModal"');
    });

    it('카카오측 동반 삭제 여부(kakao_deleted)에 따라 완료 문구를 분기한다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain('kakao_deleted');
        expect(raw).toContain('manage.delete.done_with_kakao');
        expect(raw).toContain('manage.delete.done_db_only');
    });
});

describe('manage — i18n 정합(manage.* 키 가족)', () => {
    it("동적 접두 'manage.owner.' 가족: core/module/plugin 라벨이 ko·en 에 존재한다", () => {
        for (const dict of [ko, en] as Array<Record<string, any>>) {
            for (const owner of ['core', 'module', 'plugin']) {
                expect(dict.manage.owner[owner], `manage.owner.${owner}`).toBeTruthy();
            }
        }
    });

    it('목록 컬럼 헤더 키 7종이 ko·en 에 존재한다', () => {
        const columns = ['notification', 'owner', 'status', 'sms', 'requested_at', 'synced_at', 'actions'];
        for (const dict of [ko, en] as Array<Record<string, any>>) {
            for (const col of columns) {
                expect(dict.manage.columns[col], `manage.columns.${col}`).toBeTruthy();
            }
        }
    });
});

/**
 * @effects upload_in_progress_locks_save_buttons_not_cancel, sms_modal_edits_body_per_locale_tab
 */
describe('manage 모달 — 이미지 업로드 배선·SMS 언어 탭 (#597 §15.2 U1 / §15.1)', () => {
    /**
     * 관리 화면(plugin_settings.json)은 3면 오버레이(notification_tab_*.json)의 패리티 대상이
     * **아니다** — 세 오버레이는 서로 전문 동일성으로 묶여 있지만 이 면은 별도 파일이다.
     * 그래서 §15.2 U1(업로드 중 저장 잠금)·§15.1(SMS 언어 탭)이 이 면에서 되돌려져도
     * 다른 어떤 테스트도 red 가 되지 않는다. 여기서 직접 고정한다.
     */
    const modalRaw = JSON.stringify(modalRoot);

    it('작성 모달의 저장 계열 4버튼이 업로드 중 잠기고, 취소는 잠기지 않는다', () => {
        const composeModal = findById(modalRoot, 'modal_bizppurio_template') as AnyNode;
        expect(composeModal, '관리 화면에 작성 모달이 없다').toBeTruthy();

        const buttons = findAllByName(composeModal, 'Button');
        const saveButtons = buttons.filter((b) => /template\.form\.btn_save(_request)?$/
            .test((b.text ?? '').replace('$t:sirsoft-message_bizppurio.', '')));
        expect(saveButtons.length, '신규/수정 × 저장/저장후신청 = 4').toBe(4);

        for (const b of saveButtons) {
            const disabled = String((b.props as { disabled?: string } | undefined)?.disabled ?? '');
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: {}, bz_tpl_upload: { uploading: true } } }),
                '업로드 중 저장이 열려 있으면 빈 이미지 URL 로 저장된다').toBe(true);
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: {}, bz_tpl_upload: { uploading: false } } }),
                '업로드가 끝났는데 저장이 잠겨 있으면 저장할 방법이 없다').toBe(false);
        }

        const cancel = buttons.find((b) => b.text === '$t:common.cancel');
        const cancelDisabled = String((cancel?.props as { disabled?: string } | undefined)?.disabled ?? '');
        expect(evalBinding(cancelDisabled, { _global: { bz_tpl_modal: {}, bz_tpl_upload: { uploading: true } } }),
            '취소까지 잠그면 업로드가 매달렸을 때 모달을 빠져나갈 수 없다').toBe(false);
    });

    it('업로드 실패 배너는 상태에서만 읽고 fallback 을 가진다', () => {
        expect(modalRaw).toContain("_global.bz_tpl_upload?.error ?? ''");
    });

    it('SMS 모달이 로케일 탭을 제공하고 본문을 로케일 맵으로 읽고 쓴다', () => {
        const tabs = findById(modalRoot, 'bz_sms_lang_tabs');
        expect(tabs, '관리 화면 SMS 모달에 언어 탭이 없으면 ko 한 벌만 입력된다').toBeTruthy();
        expect(JSON.stringify(tabs)).toContain('{{$locales}}');
        expect(modalRaw).toContain('bz_sms_modal?.body?.[_global.bz_sms_modal?.editLang ?? $locale]');
        expect(modalRaw).toContain('"sms_body":"{{Object.assign({}, _global.bz_sms_modal?.body)}}"');
        expect(modalRaw).toContain("'bz_sms_modal.body.' + (_global.bz_sms_modal?.editLang ?? $locale)");
    });
});

describe('manage 모달 — 검수자 전달 의견 (#597 §18.7, 제품 결정 2026-08-23)', () => {
    const composeModal = findById(modalRoot, 'modal_bizppurio_template') as AnyNode;
    const sectionsRoot = { children: (sections as { components?: AnyNode[] }).components ?? [] } as AnyNode;
    const collectHandlers = (node: unknown, acc: AnyNode[] = []): AnyNode[] => {
        if (Array.isArray(node)) node.forEach((c) => collectHandlers(c, acc));
        else if (node && typeof node === 'object') {
            const rec = node as AnyNode;
            if (typeof rec.handler === 'string') acc.push(rec);
            Object.values(rec).forEach((v) => collectHandlers(v, acc));
        }
        return acc;
    };

    it('작성 모달에 bz_manage_request_comment 입력란(≤500)이 있고 편집 모달 섹션과 같은 상태 키·바인딩을 쓴다', () => {
        const block = findById(composeModal, 'bz_manage_request_comment') as AnyNode;
        expect(block).toBeTruthy();
        const ta = findAllByName(block, 'Textarea')[0] as AnyNode;
        expect((ta.props as { name: string }).name).toBe('bz_request_comment');
        expect((ta.props as { maxLength: number }).maxLength).toBe(500);
        const sectionsTa = findAllByName(findById(sectionsRoot, 'bizppurio_tpl_request_comment') as AnyNode, 'Textarea')[0] as AnyNode;
        expect((ta.props as { value: string }).value).toBe((sectionsTa.props as { value: string }).value);
        expect(JSON.stringify(ta.actions)).toBe(JSON.stringify(sectionsTa.actions));
    });

    it('작성 모달의 request 2노드는 comment 를 싣고, 행 단위 [검수 신청](입력란 없음)은 싣지 않는다', () => {
        const all = collectHandlers(root).filter((a) => a.handler === 'apiCall' && String(a.target).endsWith('/request'));
        const modalReqs = all.filter((a) => !String(a.target).includes('{{bzRow.id}}'));
        const rowReqs = all.filter((a) => String(a.target).includes('{{bzRow.id}}'));
        expect(modalReqs).toHaveLength(2);
        expect(rowReqs).toHaveLength(1);
        for (const r of modalReqs) {
            expect((r.params as { body: { comment: string } }).body.comment).toBe("{{_global.bz_tpl_modal?.request_comment ?? ''}}");
        }
        for (const r of rowReqs) expect((r.params as { body?: unknown }).body).toBeUndefined();
    });

    it('바로연결 블록(헤더+목록) 바로 뒤 형제로 놓이고, 라벨+[바로연결 추가] flex 행 안에 끼지 않는다 (회귀: 화면 검수 지적 2026-08-23)', () => {
        const ancestorsOf = (root: AnyNode, id: string, anc: AnyNode[] = []): AnyNode[] | null => {
            for (const c of (root.children as AnyNode[] | undefined) ?? []) {
                if (c.id === id) return anc.concat(root);
                const r = ancestorsOf(c, id, anc.concat(root));
                if (r) return r;
            }
            return null;
        };
        const chain = ancestorsOf(composeModal, 'bz_manage_request_comment') as AnyNode[];
        expect(chain).toBeTruthy();
        const parent = chain[chain.length - 1];
        const siblings = parent.children as AnyNode[];
        const idx = siblings.findIndex((c) => c.id === 'bz_manage_request_comment');
        const prev = siblings[idx - 1];
        expect(JSON.stringify(prev)).toContain('template.form.quick_reply_add');
        expect(JSON.stringify(prev)).toContain('quickRepliesItem');
        for (const a of chain) {
            expect(String((a.props as { className?: string } | undefined)?.className ?? ''), `ancestor ${a.id ?? a.name}`).not.toMatch(/\bflex\b/);
        }
    });

    it('행에서 작성 모달을 열 때 request_comment 를 빈 문자열로 시딩한다(다른 행의 의견 이월 금지)', () => {
        const seeds = collectHandlers(root)
            .filter((a) => a.handler === 'setState' && (a.params as Record<string, unknown>)?.bz_tpl_modal);
        expect(seeds.length).toBeGreaterThan(0);
        for (const s of seeds) {
            expect(((s.params as Record<string, unknown>).bz_tpl_modal as Record<string, unknown>).request_comment).toBe('');
        }
    });

    it('라벨·placeholder·안내 키가 ko/en 에 존재한다', () => {
        const pick = (json: unknown, key: string): unknown => key.split('.')
            .reduce<unknown>((acc, seg) => (acc as Record<string, unknown>)?.[seg], json);
        for (const key of ['request_comment', 'request_comment_placeholder', 'request_comment_hint']) {
            expect(pick(ko, `template.form.${key}`), `ko ${key}`).toBeTruthy();
            expect(pick(en, `template.form.${key}`), `en ${key}`).toBeTruthy();
        }
    });
});

describe('manage — 목록 SMS 열은 상태 배지 (행 하단과 같은 규칙, 제품 결정 2026-08-23)', () => {
    // iteration(bzRow) 안이라 정적 id 를 둘 수 없다 — 텍스트 식으로 찾는다
    const badge = findAllByName(manageView, 'Span').find((n) => String(n.text).includes("manage.sms_fallback') : $t('sirsoft-message_bizppurio.template.row.off')")) as AnyNode;
    const evalCell = (expr: string, row: Record<string, unknown>): unknown => {
        const body = String(expr).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        return new Function('$t', 'bzRow', `return (${body});`)((k: string) => k, row);
    };

    it('단독/대체는 초록, 미사용은 회색 배지이며 평문 "-" 는 남지 않는다', () => {
        expect(badge).toBeTruthy();
        const cls = String((badge.props as { className: string }).className);
        expect(String(evalCell(cls, { sms_only: true }))).toContain('bg-green-100');
        expect(String(evalCell(cls, { fallback_sms_enabled: true }))).toContain('bg-green-100');
        expect(String(evalCell(cls, {}))).toContain('bg-gray-100');
        expect(String(evalCell(cls, {}))).toContain('rounded');
        expect(evalCell(String(badge.text), { sms_only: true })).toBe('sirsoft-message_bizppurio.manage.sms_only');
        expect(evalCell(String(badge.text), { fallback_sms_enabled: true })).toBe('sirsoft-message_bizppurio.manage.sms_fallback');
        expect(evalCell(String(badge.text), {})).toBe('sirsoft-message_bizppurio.template.row.off');
        expect(JSON.stringify(manageView)).not.toContain("sms_fallback') : '-')");
    });
});

describe('manage — 목록 모바일 카드 전환 + 도구줄 버튼 일관성 (제품 결정 2026-08-24)', () => {
    const header = findById(manageView, 'bz_manage_list_header') as AnyNode;
    const rowsWrap = findById(manageView, 'bz_manage_list_rows') as AnyNode;
    const findIterationRow = (n: AnyNode | null): AnyNode | null => {
        if (!n) return null;
        if (n.iteration?.item_var === 'bzRow') return n;
        for (const c of n.children ?? []) {
            const found = findIterationRow(c);
            if (found) return found;
        }
        return null;
    };
    const portableClass = (n: AnyNode | null | undefined): string => String(
        ((n?.responsive as Record<string, { props?: { className?: string } }> | undefined)?.portable?.props?.className) ?? '',
    );

    it('Table 마크업이 사라지고 grid 목록으로 렌더된다(헤더 7컬럼 키 유지, portable 에서 숨김)', () => {
        expect(findAllByName(manageView, 'Table').length).toBe(0);
        expect(header).toBeTruthy();
        expect(String((header.props as { className?: string })?.className)).toContain('grid-cols-12');
        expect(portableClass(header)).toBe('hidden');
        const raw = JSON.stringify(header);
        for (const c of ['notification', 'owner', 'status', 'sms', 'requested_at', 'synced_at', 'actions']) {
            expect(raw, `columns.${c}`).toContain('manage.columns.' + c);
        }
    });

    it('행은 데스크톱 grid, portable 에서 카드(flex-wrap)로 전환된다 — responsive 는 반복 노드가 아니라 내부 래퍼에 둔다', () => {
        const row = findIterationRow(rowsWrap);
        expect(row).toBeTruthy();
        expect(row!.responsive, '반복 노드 자체에 responsive 를 두지 않는다(내부 래퍼 담당)').toBeUndefined();
        const inner = (row!.children ?? [])[0] as AnyNode;
        expect(String((inner.props as { className?: string })?.className)).toContain('grid grid-cols-12');
        expect(portableClass(inner)).toContain('flex flex-wrap');
    });

    it('소속·신청일·동기화일 셀은 portable 에서 숨고, 카드 메타 줄이 대신 노출된다', () => {
        const row = findIterationRow(rowsWrap) as AnyNode;
        const rowInner = (row.children ?? [])[0] as AnyNode;
        const hiddenCells = (rowInner.children ?? []).filter((c) => portableClass(c) === 'hidden');
        expect(hiddenCells.length).toBeGreaterThanOrEqual(3);
        // 메타 줄: 신청일+동기화일을 한 텍스트에 담고, 데스크톱(hidden)→portable 에서만 보인다
        const all: AnyNode[] = [];
        const walk = (n: AnyNode) => { all.push(n); (n.children ?? []).forEach(walk); };
        walk(row);
        const meta = all.find((n) => typeof n.text === 'string'
            && n.text.includes("requested_at ?? '').slice(0, 10)")
            && n.text.includes('last_synced_at'));
        expect(meta, '카드 메타 줄(신청·동기화 합본)이 없다').toBeTruthy();
        expect(String((meta!.props as { className?: string })?.className)).toContain('hidden');
        expect(portableClass(meta)).not.toContain('hidden');
        expect(portableClass(meta).length).toBeGreaterThan(0);
    });

    it('행 액션 묶음은 카드 전환 후에도 단일 인스턴스다(삭제 버튼 1개)', () => {
        const delBtns = findAllByName(manageView, 'Button')
            .filter((b) => b.text === '$t:sirsoft-message_bizppurio.manage.btn_delete');
        expect(delBtns.length).toBe(1);
    });

    it('검색 버튼이 btn btn-primary 계열로 통일된다(구 미니 버튼 조합 제거)', () => {
        const searchBtn = findAllByName(manageView, 'Button')
            .find((b) => b.text === '$t:sirsoft-message_bizppurio.manage.btn_search') as AnyNode;
        expect(searchBtn).toBeTruthy();
        const cls = String((searchBtn.props as { className?: string })?.className);
        expect(cls).toContain('btn btn-primary');
        expect(cls).not.toContain('text-xs');
    });

    it('새로고침이 btn-icon 아이콘 버튼으로 통일된다(fa-arrows-rotate + aria-label, refetch 전용 유지)', () => {
        const refreshBtn = findAllByName(manageView, 'Button')
            .find((b) => (b.props as Record<string, unknown>)?.['aria-label'] === '$t:sirsoft-message_bizppurio.templates.list.refresh') as AnyNode;
        expect(refreshBtn).toBeTruthy();
        expect(String((refreshBtn.props as { className?: string })?.className)).toContain('btn-icon');
        const raw = JSON.stringify(refreshBtn);
        expect(raw).toContain('fa-arrows-rotate');
        expect(raw).toContain('bizppurio_templates_list');
        expect(raw).not.toContain('"handler":"navigate"');
    });

    it('검색 입력·상태 필터가 portable 에서 전폭 계열로 전환된다', () => {
        const input = findAllByName(manageView, 'Input')
            .find((i) => String((i.props as { value?: string })?.value ?? '').includes('bzSearchDraft')) as AnyNode;
        expect(portableClass(input)).toContain('w-full');
        const select = findAllByName(manageView, 'Select')
            .find((sel) => (sel.props as { value?: string })?.value === "{{query.bz_status ?? ''}}") as AnyNode;
        expect(portableClass(select)).toContain('flex-1');
    });
});
