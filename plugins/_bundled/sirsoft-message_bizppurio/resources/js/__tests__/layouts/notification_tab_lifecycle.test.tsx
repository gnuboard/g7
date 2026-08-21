// e2e:allow 구조 단언 + 조건식 실평가 Vitest(#597) — 브라우저 흐름은
// tests/Playwright/specs/admin/template-lifecycle.spec.ts 가 담당(발송 인프라 의존 축은 별도 계획).
//
// 행 하단 버튼의 노출 조건은 문자열 동일성이 아니라 `new Function` 실평가로 판정한다
// (§14.2 T7) — 리터럴 비교는 조건을 잘못 고쳐도 기대값을 함께 고치면 green 이라 회귀를
// 잡지 못한다.
/**
 * 알림 설정 '비즈뿌리오' 통합 탭 — 알림톡 템플릿 라이프사이클 UI 구조 검증 (#597)
 *
 * @effects row_footer_shows_status_badge_and_lifecycle_actions, compose_modal_switches_conditional_fields_by_type, sms_modal_saves_body_via_delivery_upsert, sms_modal_edits_body_per_locale_tab
 *
 * 두 확장 파일로 분리 구현:
 * - notification_tab_core.json (Overlay): 상태 배너(injections) + 안내 박스 + 모달 4종
 *   (작성/신청·SMS 본문·승인 취소·반려 사유) + data_sources 3종. target_layout=admin_settings.
 * - notification_row_footer.json (ExtensionPoint): 코어 목록 각 행 하단 슬롯
 *   (notification_definition_row_footer)에 알림톡 라이프사이클 줄 + SMS 설정 줄.
 *
 * 라이프사이클: 미작성(unwritten=행 없음/내용 없음) → 작성(draft) → 검수 신청(requested)
 * → 승인(approved) / 반려(rejected) / 휴면(dormant). 발송 판정은 DB 가 유일한 근거.
 */

import { describe, it, expect } from 'vitest';
import coreOverlay from '../../../extensions/notification_tab_core.json';
import boardOverlay from '../../../extensions/notification_tab_board.json';
import ecommerceOverlay from '../../../extensions/notification_tab_ecommerce.json';
import footer from '../../../extensions/notification_row_footer.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, findAllByName, type AnyNode, evalBinding } from './helpers';

const overlay = coreOverlay;
const overlayModalRoot = { children: (overlay as { modals?: AnyNode[] }).modals ?? [] } as AnyNode;
const bannerRoot = {
    children: ((overlay as { injections?: Array<{ components?: AnyNode[] }> }).injections ?? []).flatMap((i) => i.components ?? []),
} as AnyNode;
const footerRoot = { children: (footer as { components?: AnyNode[] }).components ?? [] } as AnyNode;

/** 요약 맵의 def.type 행 접근 경로(행 하단 UI 전반이 공유하는 데이터 근거) */
const MAP_PATH = "bizppurioTemplates?.data?.templates?.[extensionPointProps.definition?.type]";

/** footer 에서 text 키로 버튼 노드를 찾는다. */
const findButtonByTextKey = (root: AnyNode, key: string): AnyNode | undefined =>
    findAllByName(root, 'Button').find((b) => b.text === `$t:sirsoft-message_bizppurio.${key}`);

/** overlay 텍스트에서 $t:key 및 $t('key'...) 형태의 플러그인 i18n 정적 키를 모두 수집한다. */
const collectPluginKeys = (json: unknown): string[] => {
    const text = JSON.stringify(json);
    const prefixed = text.match(/\$t:sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+/g) ?? [];
    // $t('key') / $t('key', {...}) 정적 호출 — 동적 접두('... .' + expr)는 끝의 '.' 로 걸러
    // 접두 가족 검증(아래 별도 테스트)으로 넘긴다.
    const called = text.match(/\$t\('sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+'/g) ?? [];
    return Array.from(new Set([
        ...prefixed.map((m) => m.replace('$t:', '')),
        ...called.map((m) => m.replace(/^\$t\('/, '').replace(/'$/, '')),
    ])).filter((k) => !k.endsWith('.'));
};

const resolve = (root: unknown, path: string): unknown =>
    path.split('.').slice(1).reduce<unknown>((acc, seg) => (acc as Record<string, unknown>)?.[seg], root);

describe('lifecycle UI — 파일 분리(Overlay vs ExtensionPoint)', () => {
    it('overlay 는 target_layout=admin_settings 이고 extension_point 키가 없다', () => {
        expect((overlay as { target_layout?: string }).target_layout).toBe('admin_settings');
        expect((overlay as Record<string, unknown>).extension_point).toBeUndefined();
    });

    it('footer 는 extension_point=notification_definition_row_footer 이고 target_layout 이 없다', () => {
        expect((footer as { extension_point?: string }).extension_point).toBe('notification_definition_row_footer');
        expect((footer as Record<string, unknown>).target_layout).toBeUndefined();
    });
});

describe('lifecycle UI — 데이터소스 3종', () => {
    const sources = ((overlay as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? []);

    it('bizppurioTemplates / bizppurioCategories / bizppurioProfiles 3종을 등록한다', () => {
        expect(sources.map((d) => d.id)).toEqual([
            'bizppurioTemplates',
            'bizppurioCategories',
            'bizppurioProfiles',
        ]);
    });

    it('3종 모두 auto_fetch:false — 설정 페이지 다른 탭에서 자동 호출되지 않는다', () => {
        for (const ds of sources) {
            expect(ds.auto_fetch, `${ds.id} auto_fetch`).toBe(false);
        }
    });

    it('요약 맵은 GET templates/map 이고 탭 진입 init_actions 로만 조회된다', () => {
        const map = sources.find((d) => d.id === 'bizppurioTemplates');
        expect(map?.endpoint).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates/map');
        expect(map?.method).toBe('GET');
        const inits = (overlay as { init_actions?: AnyNode[] }).init_actions ?? [];
        const refetch = inits.find((a) => a.handler === 'refetchDataSource');
        expect((refetch?.params as { dataSourceId?: string })?.dataSourceId).toBe('bizppurioTemplates');
    });

    it('카테고리·발신프로필은 실패를 suppress 하고 load_failed fallback 을 둔다(자격증명 미설정 422 방어)', () => {
        for (const id of ['bizppurioCategories', 'bizppurioProfiles']) {
            const ds = sources.find((d) => d.id === id);
            expect(JSON.stringify(ds?.errorHandling), `${id} suppress`).toContain('suppress');
            const fallback = (ds?.fallback as { data?: Record<string, unknown> })?.data ?? {};
            expect(fallback.load_failed, `${id} load_failed`).toBe(true);
        }
    });
});

describe('lifecycle UI — 상태 배너 + 안내 박스', () => {
    it('배너는 sms·alimtalk 탭 정체성에서 문제(readiness 미충족 / test_mode)일 때만 노출된다', () => {
        const banner = findById(bannerRoot, 'bizppurio_status_banner');
        expect(banner).toBeTruthy();
        const cond = (banner as { if?: string }).if ?? '';
        expect(cond).toContain("'sms'");
        expect(cond).toContain("'alimtalk'");
        expect(cond).toContain('readiness?.ready === false');
        expect(cond).toContain('is_test_mode === true');
    });

    it('readiness 미충족 배너에 설정하기 이동 버튼이 있다', () => {
        const raw = JSON.stringify(findById(bannerRoot, 'bizppurio_banner_not_ready'));
        expect(raw).toContain('banner.not_ready');
        expect(raw).toContain('banner.setup_action');
        expect(raw).toContain('/admin/plugins/sirsoft-message_bizppurio/settings');
    });

    it('검수 모드 배너는 readiness 와 무관하게 is_test_mode 만으로 노출된다(회귀 유지)', () => {
        const banner = findById(bannerRoot, 'bizppurio_banner_test_mode');
        expect(banner).toBeTruthy();
        const cond = (banner as { if?: string }).if ?? '';
        expect(cond).not.toContain('readiness');
        expect(cond).toContain('is_test_mode === true');
    });

    it('알림톡 탭 상시 안내 박스가 라이프사이클 안내(tab_guide)를 노출한다', () => {
        const guide = findById(bannerRoot, 'bizppurio_alimtalk_guide');
        expect(guide).toBeTruthy();
        expect((guide as { if?: string }).if).toContain("=== 'alimtalk'");
        expect(JSON.stringify(guide)).toContain('template.tab_guide');
    });
});

describe('lifecycle UI — 행 하단(extension_point) 라이프사이클 줄', () => {
    const row = findById(footerRoot, 'bizppurio_row_lifecycle');

    it('행 UI 는 activeChannel === alimtalk 일 때만 노출된다', () => {
        expect(row).toBeTruthy();
        expect((row as { if?: string }).if).toBe("{{extensionPointProps.activeChannel === 'alimtalk'}}");
    });

    it('상태 배지는 has_content 로 미작성(unwritten)/저장 상태를 분기해 template.status.* 로 표기한다', () => {
        const badge = findAllByName(row as AnyNode, 'Span')
            .find((s) => (s.text ?? '').includes("template.status.' +"));
        expect(badge).toBeTruthy();
        const text = badge?.text ?? '';
        expect(text).toContain(`${MAP_PATH}?.has_content`);
        expect(text).toContain("'unwritten'");
        expect(text).toContain(`${MAP_PATH}?.status`);
    });

    it('[작성] 버튼: 행이 없거나 내용이 없을 때(if) → bz_tpl_modal seed + 작성 모달 open', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_compose');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(`{{!(${MAP_PATH}) || !(${MAP_PATH}?.has_content === true)}}`);
        const raw = JSON.stringify(btn);
        expect(raw).toContain('"bz_tpl_modal"');
        expect(raw).toContain('modal_bizppurio_template');
    });

    it('[수정] 버튼: draft·rejected 에서만(if) — 상세 GET 후 모달 open', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_edit');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(
            `{{(${MAP_PATH}?.has_content === true) && ((${MAP_PATH}?.status) === 'draft' || (${MAP_PATH}?.status) === 'rejected')}}`,
        );
        const raw = JSON.stringify(btn);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/');
        expect(raw).toContain('"method":"GET"');
        expect(raw).toContain('modal_bizppurio_template');
    });

    it('[수정(승인 취소)] 버튼: approved 에서만(if) — 승인 취소 경고 모달을 연다', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_edit_approved');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(`{{(${MAP_PATH}?.status) === 'approved'}}`);
        expect(JSON.stringify(btn)).toContain('modal_bizppurio_cancel_approval');
    });

    it('[검수 신청] 버튼: draft + 내용 보유에서만(if) — POST .../request 후 요약 맵 갱신', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_request');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(`{{(${MAP_PATH}?.has_content === true) && (${MAP_PATH}?.status) === 'draft'}}`);
        const raw = JSON.stringify(btn);
        expect(raw).toContain('/request');
        expect(raw).toContain('"method":"POST"');
        expect(raw).toContain('bizppurioTemplates');
    });

    it('[신청 취소] 버튼: requested 에서만(if) — POST .../cancel-request', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_cancel_request');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(`{{(${MAP_PATH}?.status) === 'requested'}}`);
        expect(JSON.stringify(btn)).toContain('/cancel-request');
    });

    it('[휴면 해제] 버튼: dormant 에서만(if) — POST .../release', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_release');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(`{{(${MAP_PATH}?.status) === 'dormant'}}`);
        expect(JSON.stringify(btn)).toContain('/release');
    });

    it('[새로고침] 버튼: template_code 보유 행에서만(if) — POST .../sync 수동 동기화', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_refresh');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(`{{(${MAP_PATH}) && ${MAP_PATH}?.template_code}}`);
        expect(JSON.stringify(btn)).toContain('/sync');
    });

    it('[사유 보기] 버튼: rejected + 사유 보유에서만(if) — bz_reject_view seed 후 반려 모달 open', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_view_reason');
        expect(btn).toBeTruthy();
        expect(btn?.if).toBe(
            `{{(${MAP_PATH}?.status) === 'rejected' && (${MAP_PATH}?.inspection_detail ?? []).length > 0}}`,
        );
        const raw = JSON.stringify(btn);
        expect(raw).toContain('"bz_reject_view"');
        expect(raw).toContain('modal_bizppurio_rejection');
    });

    it('알림톡 발송 토글은 내용 보유 행에서만 노출되고 delivery upsert 로 즉시 저장한다', () => {
        const toggle = findAllByName(row as AnyNode, 'Toggle')[0];
        expect(toggle).toBeTruthy();
        expect(toggle?.if).toContain('has_content === true');
        const raw = JSON.stringify(toggle);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/{{extensionPointProps.definition?.type}}');
        expect(raw).toContain('"method":"PUT"');
        expect(raw).toContain('alimtalk_enabled');
    });
});

describe('lifecycle UI — SMS 설정 줄(체크박스 + 본문 미리보기)', () => {
    const row = findById(footerRoot, 'bizppurio_row_lifecycle');
    const checkboxes = findAllByName(row as AnyNode, 'Checkbox');

    it.each([
        ['fallback_sms_enabled', '대체 SMS'],
        ['sms_only', 'SMS 단독'],
    ])('%s 체크박스: autoBinding:false + checked !! 강제 + $event.target.checked 를 delivery PUT body 로 전송', (field) => {
        const cb = checkboxes.find((c) => String((c.props as { checked?: string })?.checked ?? '').includes(field));
        expect(cb).toBeTruthy();
        expect((cb?.props as { autoBinding?: boolean })?.autoBinding).toBe(false);
        expect((cb?.props as { checked?: string })?.checked).toBe(`{{!!(${MAP_PATH}?.${field})}}`);
        const change = (cb?.actions ?? []).find((a) => a.type === 'change') as AnyNode;
        expect(change?.handler).toBe('apiCall');
        expect(change?.target).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/{{extensionPointProps.definition?.type}}');
        const params = change?.params as { method?: string; body?: Record<string, string> };
        expect(params?.method).toBe('PUT');
        expect(params?.body?.[field]).toBe('{{$event.target.checked}}');
    });

    it('SMS 본문 미리보기(40자 절단)와 미설정 문구가 상호 조건으로 존재한다', () => {
        const raw = JSON.stringify(row);
        expect(raw).toContain('sms_body_prefix');
        expect(raw).toContain('.slice(0, 40)');
        expect(raw).toContain('template.row.sms_body_missing');
    });

    it('[SMS 본문 편집] 버튼이 bz_sms_modal seed 후 SMS 모달을 연다', () => {
        const btn = findButtonByTextKey(row as AnyNode, 'template.row.btn_edit_sms');
        expect(btn).toBeTruthy();
        const raw = JSON.stringify(btn);
        expect(raw).toContain('"bz_sms_modal"');
        expect(raw).toContain('modal_bizppurio_sms');
    });
});

describe('lifecycle UI — 작성/신청 모달(modal_bizppurio_template)', () => {
    const modal = findById(overlayModalRoot, 'modal_bizppurio_template');

    it('작성 모달이 3면(코어/게시판/이커머스) 오버레이 모두에 등록된다(행 footer 가 공유 참조)', () => {
        for (const [label, o] of [['core', coreOverlay], ['board', boardOverlay], ['ecommerce', ecommerceOverlay]] as const) {
            const ids = ((o as { modals?: AnyNode[] }).modals ?? []).map((m) => m.id);
            expect(ids, `${label} 오버레이 modals`).toContain('modal_bizppurio_template');
        }
    });

    it('저장 버튼은 id 유무로 POST(신규)/PUT(기존) 2벌 if 분기다', () => {
        const saves = findAllByName(modal as AnyNode, 'Button')
            .filter((b) => b.text === '$t:sirsoft-message_bizppurio.template.form.btn_save');
        expect(saves).toHaveLength(2);
        const create = saves.find((b) => b.if === '{{!(_global.bz_tpl_modal?.id)}}');
        const update = saves.find((b) => b.if === '{{_global.bz_tpl_modal?.id}}');
        expect(create).toBeTruthy();
        expect(update).toBeTruthy();
        const createRaw = JSON.stringify(create);
        expect(createRaw).toContain('"/api/plugins/sirsoft-message_bizppurio/admin/templates"');
        expect(createRaw).toContain('"method":"POST"');
        expect(createRaw).toContain('notification_type');
        const updateRaw = JSON.stringify(update);
        expect(updateRaw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_tpl_modal?.id}}');
        expect(updateRaw).toContain('"method":"PUT"');
    });

    it('[저장 후 검수 신청] 버튼은 저장 onSuccess 로 request 엔드포인트를 체인한다(신규/기존 2벌)', () => {
        const saveRequests = findAllByName(modal as AnyNode, 'Button')
            .filter((b) => b.text === '$t:sirsoft-message_bizppurio.template.form.btn_save_request');
        expect(saveRequests).toHaveLength(2);
        const create = saveRequests.find((b) => b.if === '{{!(_global.bz_tpl_modal?.id)}}');
        const update = saveRequests.find((b) => b.if === '{{_global.bz_tpl_modal?.id}}');
        // 신규: POST 저장 응답의 id 로 request
        const createRaw = JSON.stringify(create);
        expect(createRaw).toContain('"method":"POST"');
        expect(createRaw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{response.data?.template?.id}}/request');
        // 기존: PUT 저장 후 자기 id 로 request
        const updateRaw = JSON.stringify(update);
        expect(updateRaw).toContain('"method":"PUT"');
        expect(updateRaw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_tpl_modal?.id}}/request');
    });

    it('강조 유형 조건부 섹션: TEXT(타이틀/서브타이틀)·IMAGE(업로드)·ITEM_LIST(아이템) 이 if 로 전환된다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain("_global.bz_tpl_modal?.content?.templateEmphasizeType === 'TEXT'");
        expect(raw).toContain("_global.bz_tpl_modal?.content?.templateEmphasizeType === 'IMAGE'");
        expect(raw).toContain("_global.bz_tpl_modal?.content?.templateEmphasizeType === 'ITEM_LIST'");
        // TEXT 섹션 필드
        expect(raw).toContain('templateTitle');
        expect(raw).toContain('templateSubtitle');
    });

    it('부가정보(templateExtra) 는 EX·MI 메시지 유형에서만 노출된다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain("_global.bz_tpl_modal?.content?.templateMessageType === 'EX' || _global.bz_tpl_modal?.content?.templateMessageType === 'MI'");
    });

    it('버튼 그룹은 최대 5개, 바로연결은 최대 10개에서 추가 버튼이 disabled 된다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain('"{{(_global.bz_tpl_modal?.content?.buttons ?? []).length >= 5}}"');
        expect(raw).toContain('"{{(_global.bz_tpl_modal?.content?.quickReplies ?? []).length >= 10}}"');
    });

    it('이미지 업로드는 커스텀 핸들러(uploadTemplateImage)로 URL 을 상태에 기입한다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain('sirsoft-message_bizppurio.uploadTemplateImage');
        expect(raw).toContain('bz_tpl_modal.content.templateImageUrl');
    });
});

describe('lifecycle UI — 승인 취소·반려·SMS 모달', () => {
    it('승인 취소 모달: 경고 문구(cancel_approval.warning) + POST .../cancel-approval 배선', () => {
        const modal = findById(overlayModalRoot, 'modal_bizppurio_cancel_approval');
        expect(modal).toBeTruthy();
        const raw = JSON.stringify(modal);
        expect(raw).toContain('template.cancel_approval.warning');
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_cancel_approval?.id}}/cancel-approval');
        expect(raw).toContain('"method":"POST"');
    });

    it('반려 사유 모달: inspection_detail 스냅샷(comments)을 반복 렌더한다', () => {
        const modal = findById(overlayModalRoot, 'modal_bizppurio_rejection');
        expect(modal).toBeTruthy();
        const raw = JSON.stringify(modal);
        expect(raw).toContain('_global.bz_reject_view?.comments');
        expect(raw).toContain('template.rejection.modal_title');
    });

    it('SMS 본문 모달: delivery upsert(PUT) 로 sms_body 를 저장한다', () => {
        const modal = findById(overlayModalRoot, 'modal_bizppurio_sms');
        expect(modal).toBeTruthy();
        const raw = JSON.stringify(modal);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/{{_global.bz_sms_modal?.notification_type}}');
        expect(raw).toContain('"method":"PUT"');
        expect(raw).toContain('sms_body');
    });
});

describe('lifecycle UI — 코어 무오염 + i18n 정합', () => {
    it('overlay·footer 어디에도 코어 편집 모달 저장 body(notification-templates PUT)를 건드리지 않는다', () => {
        const all = JSON.stringify(overlay) + JSON.stringify(footer);
        expect(all).not.toContain('/api/admin/notification-templates/');
        expect(all).not.toContain('notification_template_form_modal');
    });

    it('참조하는 모든 플러그인 i18n 정적 키가 ko·en 에 존재한다', () => {
        const keys = [...collectPluginKeys(overlay), ...collectPluginKeys(footer)];
        expect(keys.length).toBeGreaterThan(0);
        for (const key of Array.from(new Set(keys))) {
            expect(resolve(ko, key), `ko 누락: ${key}`).toBeTruthy();
            expect(resolve(en, key), `en 누락: ${key}`).toBeTruthy();
        }
    });

    it("동적 접두 'template.status.' 가족: ko·en 에 상태 8종 키가 모두 존재한다", () => {
        const statuses = ['unwritten', 'draft', 'requested', 'approved', 'rejected', 'stopped', 'blocked', 'dormant'];
        for (const dict of [ko, en] as Array<Record<string, any>>) {
            expect(Object.keys(dict.template.status).sort()).toEqual([...statuses].sort());
        }
    });

    it("동적 접두 'templates.link_type.' 가족: 모달이 사용하는 링크 유형 코드가 ko·en 에 모두 존재한다", () => {
        // 버튼 셀렉트 13종 + 바로연결 셀렉트 6종(부분집합)
        const used = ['WL', 'AL', 'DS', 'BK', 'MD', 'AC', 'BC', 'BT', 'P1', 'P2', 'P3', 'TN', 'MP'];
        for (const dict of [ko, en] as Array<Record<string, any>>) {
            for (const code of used) {
                expect(dict.templates.link_type[code], `link_type.${code}`).toBeTruthy();
            }
        }
    });
});

describe('lifecycle UI — 바인딩 브레이스 위생 (회귀: 프리필 끝 `}}` 잔재)', () => {
    it('문자열 값 어디에도 4연속 브레이스(}}}}·{{{{)가 없다', () => {
        // 회귀 배경(#597 브라우저 실측): 작성 모달 본문 프리필 표현식이 `}}}}` 로 끝나
        // 엔진이 바인딩을 조기 종료하고 잔여 `}}` 가 본문 값에 리터럴로 붙었다.
        // 구조적 중첩 브레이스(minified JSON)와 구분하기 위해 문자열 리프만 검사한다.
        const collectStrings = (node: unknown, acc: string[] = []): string[] => {
            if (typeof node === 'string') acc.push(node);
            else if (Array.isArray(node)) node.forEach((c) => collectStrings(c, acc));
            else if (node && typeof node === 'object') Object.values(node).forEach((v) => collectStrings(v, acc));
            return acc;
        };
        for (const [name, json] of [
            ['core', coreOverlay],
            ['board', boardOverlay],
            ['ecommerce', ecommerceOverlay],
            ['footer', footer],
        ] as const) {
            const offenders = collectStrings(json).filter((v) => v.includes('}}}}') || v.includes('{{{{'));
            expect(offenders, `${name} 브레이스 잔재: ${offenders.join(' | ')}`).toEqual([]);
        }
    });

    it('표현식 $t 를 2-인자(파라미터 객체)로 호출하지 않는다 — 엔진은 단일 인자만 지원', () => {
        // 회귀 배경(#597 §6.3 실측): `$t('key', {date: …})` 의 두 번째 인자는 엔진이
        // 무시해 "{date} 신청" 플레이스홀더 원문이 화면에 노출됐다. 파라미터 치환은
        // 텍스트 문법 `$t:key|param={{expr}}` 만 지원한다 (선례: admin_activity_log_list).
        const collectStrings = (node: unknown, acc: string[] = []): string[] => {
            if (typeof node === 'string') acc.push(node);
            else if (Array.isArray(node)) node.forEach((c) => collectStrings(c, acc));
            else if (node && typeof node === 'object') Object.values(node).forEach((v) => collectStrings(v, acc));
            return acc;
        };
        for (const [name, json] of [
            ['core', coreOverlay],
            ['board', boardOverlay],
            ['ecommerce', ecommerceOverlay],
            ['footer', footer],
        ] as const) {
            const offenders = collectStrings(json).filter((v) => /\$t\([^)]*,\s*\{/.test(v));
            expect(offenders, `${name} 2-인자 $t 호출(파라미터 미치환 원문 노출): ${offenders.join(' | ')}`).toEqual([]);
        }
    });
});

describe('lifecycle UI — 행 하단 버튼 노출 조건 실평가 (#597 §14.2 T7)', () => {
    const row = findById(footerRoot, 'bizppurio_row_lifecycle') as AnyNode;

    /**
     * `{{...}}` 단일 바인딩 형태의 조건식을 실제로 평가한다.
     *
     * 문자열 동일성 단언(`expect(btn.if).toBe('{{...}}')`)은 표현식이 바뀌면 테스트도 같이
     * 바뀌므로 회귀를 잡지 못한다 — 조건을 잘못 고쳐도 기대값을 함께 고치면 green 이다.
     * 여기서는 식을 그대로 실행해 상태별 노출 결과를 판정한다.
     */
    const evaluateIf = (expr: string, templates: Record<string, unknown>, type: string): boolean => {
        const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        const fn = new Function('bizppurioTemplates', 'extensionPointProps', `return (${body});`);
        return Boolean(fn({ data: { templates } }, { definition: { type }, activeChannel: 'alimtalk' }));
    };

    /** 상태별 요약 맵 행 (bizppurioTemplates.data.templates[type]) */
    const ROWS: Record<string, Record<string, unknown> | undefined> = {
        '행 없음': undefined,
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

    /** 버튼 i18n 키 → 그 버튼이 보여야 하는 상태 이름 집합 */
    const EXPECTED_VISIBLE: Record<string, string[]> = {
        'template.row.btn_compose': ['행 없음', '행만 있고 내용 없음'],
        'template.row.btn_edit': ['draft', 'rejected', '반려(사유 없음)'],
        'template.row.btn_edit_approved': ['approved'],
        'template.row.btn_request': ['draft'],
        'template.row.btn_cancel_request': ['requested'],
        'template.row.btn_release': ['dormant'],
        'template.row.btn_refresh': ['requested', 'approved', 'rejected', '반려(사유 없음)', 'dormant', 'stopped', 'blocked'],
        'template.row.btn_view_reason': ['rejected'],
    };

    it.each(Object.keys(EXPECTED_VISIBLE))('%s 의 노출 조건이 상태별로 정확히 평가된다', (key) => {
        const btn = findButtonByTextKey(row, key);
        expect(btn, `${key} 버튼이 레이아웃에 없다`).toBeTruthy();
        const expr = (btn as { if?: string }).if;
        expect(expr, `${key} 에 if 조건이 없다`).toBeTruthy();

        const expected = EXPECTED_VISIBLE[key];
        for (const [state, rowValue] of Object.entries(ROWS)) {
            const templates = rowValue === undefined ? {} : { welcome: rowValue };
            const actual = evaluateIf(expr as string, templates, 'welcome');
            expect(actual, `${key} @ ${state}: ${expected.includes(state) ? '노출' : '숨김'} 이어야 한다`)
                .toBe(expected.includes(state));
        }
    });

    it('상태 10종 × 버튼 8종을 모두 평가한다 (커버리지 하한 고정)', () => {
        expect(Object.keys(ROWS)).toHaveLength(10);
        expect(Object.keys(EXPECTED_VISIBLE)).toHaveLength(8);
    });
});

/**
 * @effects upload_in_progress_locks_save_buttons_not_cancel
 */
describe('lifecycle UI — 이미지 업로드 상태 배선 (#597 §14.4·§14.5 V2)', () => {
    const modalRaw = JSON.stringify(overlayModalRoot);

    it('업로드 진행 중에는 저장 계열 버튼이 잠기고, 취소는 잠기지 않는다', () => {
        const buttons = findAllByName(overlayModalRoot, 'Button');
        const saveButtons = buttons.filter((b) => /template\.form\.btn_save(_request)?$/.test((b.text ?? '').replace('$t:sirsoft-message_bizppurio.', '')));
        expect(saveButtons.length, '작성 모달의 저장 계열 버튼(신규/수정 × 저장/저장후신청) 4개').toBe(4);

        // 문자열 포함 확인이 아니라 두 상태를 실제로 태운다 — `... && false` 로 바꿔도
        // toContain 은 통과하지만 아래 평가는 red 가 된다.
        for (const b of saveButtons) {
            const disabled = String((b.props as { disabled?: string } | undefined)?.disabled ?? '');
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: {}, bz_tpl_upload: { uploading: true } } }),
                '업로드 in-flight 중 저장이 열려 있으면 빈 이미지 URL 로 저장된다').toBe(true);
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: {}, bz_tpl_upload: { uploading: false } } }),
                '업로드가 끝났는데도 저장이 잠겨 있으면 저장할 방법이 없다').toBe(false);
        }

        const cancel = buttons.find((b) => b.text === '$t:common.cancel');
        const cancelDisabled = String((cancel?.props as { disabled?: string } | undefined)?.disabled ?? '');
        expect(evalBinding(cancelDisabled, { _global: { bz_tpl_modal: {}, bz_tpl_upload: { uploading: true } } }),
            '취소까지 잠그면 업로드가 매달렸을 때 모달을 빠져나갈 수 없다').toBe(false);
    });

    it('업로드 실패 배너는 상태에서만 읽고 fallback 을 가진다', () => {
        expect(modalRaw).toContain("_global.bz_tpl_upload?.error ?? ''");
    });
});

describe('lifecycle UI — SMS 본문 다국어 (#597 §14.3)', () => {
    const modalRaw = JSON.stringify(overlayModalRoot);

    it('SMS 모달은 로케일 탭을 제공하고 본문을 로케일별로 읽는다', () => {
        const tabs = findById(overlayModalRoot, 'bz_sms_lang_tabs');
        expect(tabs, 'SMS 본문 언어 탭이 없으면 ko 한 벌만 입력된다').toBeTruthy();
        expect(JSON.stringify(tabs)).toContain('{{$locales}}');
        expect(modalRaw).toContain('bz_sms_modal?.body?.[_global.bz_sms_modal?.editLang ?? $locale]');
    });

    it('SMS 본문 저장은 로케일 맵을 통째로 전송한다 (문자열 아님)', () => {
        expect(modalRaw).toContain('"sms_body":"{{Object.assign({}, _global.bz_sms_modal?.body)}}"');
    });

    it('변수 삽입 대상 경로가 현재 편집 로케일을 가리킨다', () => {
        expect(modalRaw).toContain("'bz_sms_modal.body.' + (_global.bz_sms_modal?.editLang ?? $locale)");
    });
});

describe('compose modal — 유형 전환·반복 입력 실평가 (#597 §14.2 T7 / §6.2)', () => {
    /**
     * `{{...}}` 조건식을 모달 폼 상태에 대해 실제로 평가한다 (바인딩 프로브).
     *
     * 화면을 렌더하지 않고도 "이 상태에서 이 블록이 보이는가 / 이 버튼이 잠기는가" 를
     * 판정할 수 있다 — 조건식이 소비하는 자유변수는 `_global` 하나뿐이기 때문이다.
     * substring 존재 확인은 조건을 잘못 고쳐도 통과하므로 회귀를 잡지 못한다.
     */
    const evalWithModal = (expr: string, modal: Record<string, unknown>): boolean => {
        const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        const fn = new Function('_global', `return (${body});`);
        return Boolean(fn({ bz_tpl_modal: modal, bz_tpl_upload: {} }));
    };

    /** 강조유형별 조건부 블록의 if 식을 레이아웃에서 수집한다. */
    const conditionalBlocks = (): Record<string, string> => {
        const found: Record<string, string> = {};
        const walk = (node: unknown): void => {
            if (Array.isArray(node)) { node.forEach(walk); return; }
            if (!node || typeof node !== 'object') return;
            const rec = node as Record<string, unknown>;
            const cond = typeof rec.if === 'string' ? rec.if : '';
            const m = cond.match(/templateEmphasizeType === '(TEXT|IMAGE|ITEM_LIST)'/);
            if (m && !found[m[1]]) found[m[1]] = cond;
            Object.values(rec).forEach(walk);
        };
        walk(overlayModalRoot);
        return found;
    };

    it.each(['TEXT', 'IMAGE', 'ITEM_LIST'])('강조유형 %s 블록은 그 유형에서만 노출된다', (type) => {
        const blocks = conditionalBlocks();
        const expr = blocks[type];
        expect(expr, `${type} 조건부 블록이 모달에 없다`).toBeTruthy();

        for (const current of ['NONE', 'TEXT', 'IMAGE', 'ITEM_LIST']) {
            const visible = evalWithModal(expr, { content: { templateEmphasizeType: current } });
            expect(visible, `강조유형 ${current} 일 때 ${type} 블록은 ${current === type ? '노출' : '숨김'}`)
                .toBe(current === type);
        }
    });

    it('강조유형 미선택(undefined) 상태에서는 조건부 블록이 모두 숨는다', () => {
        const blocks = conditionalBlocks();
        for (const [type, expr] of Object.entries(blocks)) {
            expect(evalWithModal(expr, { content: {} }), `${type} 블록`).toBe(false);
        }
    });

    /** 반복 입력의 "추가" 버튼 상한 조건 — [상태 경로, 상한] */
    const REPEAT_CAPS: Array<[string, number]> = [
        ['templateItem?.list', 10],
        ['buttons', 5],
        ['quickReplies', 10],
    ];

    it.each(REPEAT_CAPS)('반복 입력 %s 는 상한 %i 에 도달하면 추가가 잠긴다', (pathFragment, cap) => {
        const buttons = findAllByName(overlayModalRoot, 'Button');
        const addBtn = buttons.find((b) => {
            const d = String((b.props as { disabled?: string } | undefined)?.disabled ?? '');
            return d.includes(pathFragment) && d.includes(`>= ${cap}`);
        });
        expect(addBtn, `${pathFragment} 상한 ${cap} 을 거는 추가 버튼이 없다`).toBeTruthy();

        const expr = String((addBtn?.props as { disabled?: string }).disabled);
        const key = pathFragment.includes('templateItem') ? 'templateItem' : pathFragment;
        const makeContent = (n: number): Record<string, unknown> => {
            const arr = Array.from({ length: n }, () => ({}));
            return key === 'templateItem' ? { templateItem: { list: arr } } : { [key]: arr };
        };

        // 경계 3점: 상한-1(열림) / 상한(잠김) / 상한+1(잠김)
        expect(evalWithModal(expr, { content: makeContent(cap - 1) }), `${cap - 1}개: 열림`).toBe(false);
        expect(evalWithModal(expr, { content: makeContent(cap) }), `${cap}개: 잠김`).toBe(true);
        expect(evalWithModal(expr, { content: makeContent(cap + 1) }), `${cap + 1}개: 잠김`).toBe(true);
        // 비어 있을 때(키 자체 부재)도 열려 있어야 한다 — ?? [] fallback 회귀 방지
        expect(evalWithModal(expr, { content: {} }), '항목 0개: 열림').toBe(false);
    });

    it('버튼 linkType 별 조건부 링크 필드가 선택한 유형에서만 노출된다', () => {
        /**
         * 기대값을 조건식에서 역산하지 않는다 — `linkType === 'X'` 를 조건식 자신에서 뽑아
         * 그 X 로 참이 되는지 보는 형태는 어떤 등가식이든 무조건 통과하는 준-항등식이고,
         * "어느 입력칸이 어느 linkType 의 필드인가" 를 전혀 고정하지 못한다.
         * 대응표는 부록 A-3(WL→linkMo/linkPc, AL→linkAnd+linkIos, TN→telNumber,
         * P1~P3→pluginId)에서 가져와 **필드 바인딩 기준으로** 적는다.
         */
        const EXPECTED_FOR_FIELD: Record<string, string[]> = {
            linkMo: ['WL'],
            linkPc: ['WL'],
            linkAnd: ['AL'],
            linkIos: ['AL'],
            telNumber: ['TN'],
            pluginId: ['P1', 'P2', 'P3'],
        };
        const ALL_LINK_TYPES = ['WL', 'AL', 'DS', 'BK', 'MD', 'AC', 'BC', 'BT', 'P1', 'P2', 'P3', 'TN', 'MP'];

        const inputs = findAllByName(overlayModalRoot, 'Input')
            .filter((n) => /buttonsItem\.linkType === '/.test(String((n as { if?: string }).if ?? '')));

        // 조건식이 아니라 value 바인딩으로 필드 정체를 식별한다.
        const seen = new Set<string>();
        for (const node of inputs) {
            const value = String((node.props as { value?: string } | undefined)?.value ?? '');
            const field = (value.match(/buttonsItem\.([A-Za-z]+)/) ?? [])[1];
            expect(field, `value 바인딩에서 필드명을 못 읽었다: ${value}`).toBeTruthy();
            const expected = EXPECTED_FOR_FIELD[field as string];
            expect(expected, `부록 A-3 에 없는 조건부 필드: ${field}`).toBeTruthy();
            seen.add(field as string);

            const cond = String((node as { if?: string }).if);
            for (const lt of ALL_LINK_TYPES) {
                expect(evalBinding(cond, { buttonsItem: { linkType: lt } }), `${field} @ ${lt}`)
                    .toBe(expected!.includes(lt));
            }
        }

        // 부록 A-3 의 조건부 필드가 하나라도 화면에서 사라지면 red (하한 단언이 아니라 전수 대조)
        expect([...seen].sort()).toEqual(Object.keys(EXPECTED_FOR_FIELD).sort());
    });
});
