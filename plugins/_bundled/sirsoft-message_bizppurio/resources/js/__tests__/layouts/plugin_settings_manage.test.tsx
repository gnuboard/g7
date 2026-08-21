// e2e:allow 관리 화면 mergeQuery 왕복·SMS 모달 저장 왕복 브라우저 흐름은
// tests/Playwright/specs/admin/template-lifecycle.spec.ts(bizppurio_manage_round_trip_e2e)가 담당.
/**
 * 플러그인 설정 — 알림 템플릿 관리 탭(DB 목록) 구조 검증 (#597 §4.3)
 *
 * @effects manage_screen_lists_db_rows_with_merge_query_round_trip
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
import footer from '../../../extensions/notification_row_footer.json';
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
            .find((b) => b.text === '$t:sirsoft-message_bizppurio.templates.list.refresh');
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
     * 관리 화면(bzRow)과 행 하단(요약 맵)의 노출 조건을 같은 상태 집합에 **실제로 태워** 대조한다.
     *
     * 문자열 동일성 단언은 두 면의 식이 실제로 같은 결과를 내는지 증명하지 못한다 — 실제로
     * `btn_refresh` 는 footer 가 `(row) && row?.template_code`, manage 는 `bzRow.template_code`
     * 로 서로 다른 모양이고, `=== true` 와 truthy 도 갈린다. 두 식을 각자의 컨텍스트로 평가해
     * 상태별 결과가 일치하는지를 본다.
     */
    const MANAGE_ROWS: Record<string, Record<string, unknown>> = {
        '행만 있고 내용 없음': { has_content: false, status: 'draft' },
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
        const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        return Boolean(new Function('bzRow', `return (${body});`)(bzRow));
    };

    /** footer 식을 요약 맵 컨텍스트로 평가 */
    const evalFooter = (expr: string, bzRow: Record<string, unknown>): boolean => {
        const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        const fn = new Function('bizppurioTemplates', 'extensionPointProps', `return (${body});`);
        return Boolean(fn({ data: { templates: { welcome: bzRow } } }, { definition: { type: 'welcome' } }));
    };

    const footerRoot = { children: (footer as { components?: AnyNode[] }).components ?? [] } as AnyNode;
    const footerRow = findById(footerRoot, 'bizppurio_row_lifecycle') as AnyNode;
    const byTextKey = (root: AnyNode, key: string) => findAllByName(root, 'Button')
        .find((b) => b.text === `$t:sirsoft-message_bizppurio.${key}`);

    /** 관리 화면 ↔ 행 하단에서 같은 의미를 갖는 버튼 쌍 */
    const PAIRS = [
        'template.row.btn_request',
        'template.row.btn_cancel_request',
        'template.row.btn_release',
        'template.row.btn_refresh',
        'template.row.btn_edit_approved',
    ];

    it.each(PAIRS)('%s 의 노출 조건이 관리 화면과 행 하단에서 상태별로 같은 결과를 낸다', (key) => {
        const m = byTextKey(manageView, key);
        const f = byTextKey(footerRow, key);
        expect(m?.if, `관리 화면에 ${key} if 가 없다`).toBeTruthy();
        expect(f?.if, `행 하단에 ${key} if 가 없다`).toBeTruthy();

        for (const [state, bzRow] of Object.entries(MANAGE_ROWS)) {
            expect(evalManage(m!.if as string, bzRow), `${key} @ ${state}`)
                .toBe(evalFooter(f!.if as string, bzRow));
        }
    });

    it('작성/수정 버튼은 행 하단의 작성+수정 두 버튼을 합친 것과 같은 상태에서 노출된다', () => {
        // 관리 화면은 DB 목록이라 "행 없음" 이 없다 — footer 의 btn_compose(!row || !has_content)와
        // btn_edit(has_content && draft|rejected)를 합집합으로 놓고 대조한다.
        const m = findAllByName(manageView, 'Button')
            .find((b) => typeof b.text === 'string' && b.text.includes("sirsoft-message_bizppurio.manage.' + (bzRow.has_content"));
        expect(m?.if, '관리 화면 작성/수정 버튼을 찾지 못했다').toBeTruthy();

        const compose = byTextKey(footerRow, 'template.row.btn_compose');
        const edit = byTextKey(footerRow, 'template.row.btn_edit');
        expect(compose?.if).toBeTruthy();
        expect(edit?.if).toBeTruthy();

        for (const [state, bzRow] of Object.entries(MANAGE_ROWS)) {
            const footerVisible = evalFooter(compose!.if as string, bzRow) || evalFooter(edit!.if as string, bzRow);
            expect(evalManage(m!.if as string, bzRow), `작성/수정 @ ${state}`).toBe(footerVisible);
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

    it('승인 취소·SMS 본문·delivery upsert 는 행 하단(footer)과 같은 엔드포인트 계열을 쓴다', () => {
        const layoutEndpoints = collectEndpoints(layout);
        const footerEndpoints = collectEndpoints(footer);
        // footer 가 쓰는 계열(map 조회 제외 — 관리 화면은 DB 목록을 쓴다)이 관리 화면에도 존재한다
        for (const ep of footerEndpoints) {
            if (ep.endsWith('/map')) continue;
            expect(layoutEndpoints, `관리 화면에 ${ep} 누락`).toContain(ep);
        }
        // 승인 취소 모달 공유 확인
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
