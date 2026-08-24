// e2e:allow 구조 단언 + 조건식 실평가 Vitest(#597) — 브라우저 흐름은
// tests/Playwright/specs/admin/template-lifecycle*.spec.ts 가 담당(발송 인프라 의존 축은 별도 계획).
//
// 노출 조건·저장 분기는 문자열 동일성이 아니라 `new Function` 실평가로 판정한다
// (§14.2 T7) — 리터럴 비교는 조건을 잘못 고쳐도 기대값을 함께 고치면 green 이라 회귀를
// 잡지 못한다.
/**
 * 알림 설정 '비즈뿌리오' 통합 탭 — [편집] 모달 통합 UI 구조 검증 (#597, 제품 결정 2026-08-23)
 *
 * @effects row_footer_shows_status_summary_only, approval_badge_two_tier, edit_modal_hosts_alimtalk_and_sms_sections,
 *          unified_save_chain_alimtalk_then_core_recipients, compose_modal_switches_conditional_fields_by_type,
 *          sms_modal_saves_body_via_delivery_upsert, sms_modal_edits_body_per_locale_tab,
 *          inspection_request_carries_reviewer_comment
 *
 * 파일 구성(플러그인 resources/extensions):
 * - notification_tab_core/board/ecommerce.json (Overlay): 상태 배너(injections) + 안내 박스 + data_sources 3종.
 *   모달은 더 이상 등록하지 않는다(작성/SMS/승인취소/반려 모달 폐지).
 * - notification_row_footer.json (ExtensionPoint notification_definition_row_footer): 행 하단 **상태 요약만**
 *   (승인 2단 배지 + 신청/동기화일 + SMS 설정 요약). 버튼 없음 — 조작은 코어 [편집] 모달로 일원화.
 * - notification_template_form_sections.json (ExtensionPoint notification_template_form_sections): 코어 [편집]
 *   모달 본문에 주입되는 알림톡 템플릿 섹션(작성 폼/잠금 요약/상태 액션) + 문자(SMS) 섹션. 3면 공유 1본.
 * - notification_template_form_footer_actions.json (ExtensionPoint notification_template_form_footer_actions):
 *   통합 [저장] / [저장 후 검수 신청] — 알림톡 upsert(또는 delivery upsert) → (검수 신청) → 수신자 규칙이
 *   바뀐 경우에만 코어 PUT → 마무리.
 *
 * 라이프사이클: 미작성(unwritten=행 없음/내용 없음) → 작성(draft) → 검수 신청(requested)
 * → 승인(approved) / 반려(rejected) / 휴면(dormant). 발송 판정은 DB 가 유일한 근거.
 */

import { describe, it, expect } from 'vitest';
import coreOverlay from '../../../extensions/notification_tab_core.json';
import boardOverlay from '../../../extensions/notification_tab_board.json';
import ecommerceOverlay from '../../../extensions/notification_tab_ecommerce.json';
import footer from '../../../extensions/notification_row_footer.json';
import sections from '../../../extensions/notification_template_form_sections.json';
import footerActions from '../../../extensions/notification_template_form_footer_actions.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, findAllByName, type AnyNode, evalBinding } from './helpers';

const overlay = coreOverlay;
const bannerRoot = {
    children: ((overlay as { injections?: Array<{ components?: AnyNode[] }> }).injections ?? []).flatMap((i) => i.components ?? []),
} as AnyNode;
const footerRoot = { children: (footer as { components?: AnyNode[] }).components ?? [] } as AnyNode;
const sectionsRoot = { children: (sections as { components?: AnyNode[] }).components ?? [] } as AnyNode;
const actionsRoot = { children: (footerActions as { components?: AnyNode[] }).components ?? [] } as AnyNode;

/** 요약 맵의 def.type 행 접근 경로(행 하단·섹션이 공유하는 데이터 근거) */
const MAP_PATH = "bizppurioTemplates?.data?.templates?.[extensionPointProps.definition?.type]";

/** text 키로 버튼 노드를 찾는다. */
const findButtonByTextKey = (root: AnyNode, key: string): AnyNode | undefined =>
    findAllByName(root, 'Button').find((b) => b.text === `$t:sirsoft-message_bizppurio.${key}`);

/** JSON 텍스트에서 $t:key 및 $t('key') 형태의 플러그인 i18n 정적 키를 모두 수집한다. */
const collectPluginKeys = (json: unknown): string[] => {
    const text = JSON.stringify(json);
    const prefixed = text.match(/\$t:sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+/g) ?? [];
    const called = text.match(/\$t\('sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+'/g) ?? [];
    return Array.from(new Set([
        ...prefixed.map((m) => m.replace('$t:', '')),
        ...called.map((m) => m.replace(/^\$t\('/, '').replace(/'$/, '')),
    ])).filter((k) => !k.endsWith('.'));
};

const resolve = (root: unknown, path: string): unknown =>
    path.split('.').slice(1).reduce<unknown>((acc, seg) => (acc as Record<string, unknown>)?.[seg], root);

/** 문자열 리프 전수 수집 */
const collectStrings = (node: unknown, acc: string[] = []): string[] => {
    if (typeof node === 'string') acc.push(node);
    else if (Array.isArray(node)) node.forEach((c) => collectStrings(c, acc));
    else if (node && typeof node === 'object') Object.values(node).forEach((v) => collectStrings(v, acc));
    return acc;
};

/** 모든 액션 노드(handler 보유 객체)를 재귀 수집한다. */
const collectActions = (node: unknown, acc: AnyNode[] = []): AnyNode[] => {
    if (Array.isArray(node)) node.forEach((c) => collectActions(c, acc));
    else if (node && typeof node === 'object') {
        const rec = node as AnyNode;
        if (typeof rec.handler === 'string') acc.push(rec);
        Object.values(rec).forEach((v) => collectActions(v, acc));
    }
    return acc;
};

/**
 * `{{...}}` 텍스트/조건 식을 실제로 평가한다. `$t` 는 키를 그대로 돌려주는 스텁이라
 * 평가 결과로 "어느 i18n 키가 선택됐는가" 를 판정할 수 있다.
 */
const evalExpr = (expr: string, scope: Record<string, unknown>): unknown => {
    const body = String(expr).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(scope);
    // eslint-disable-next-line no-new-func
    const fn = new Function('$t', '$locale', ...names, `return (${body});`);
    return fn((k: string) => k, 'ko', ...names.map((n) => scope[n]));
};

/** 요약 맵 행 상태 픽스처 (bizppurioTemplates.data.templates[type]) */
const ROWS: Record<string, Record<string, unknown> | undefined> = {
    '행 없음': undefined,
    '행만 있고 내용 없음': { id: 1, has_content: false, status: 'draft' },
    draft: { id: 1, has_content: true, status: 'draft', template_code: null },
    requested: { id: 1, has_content: true, status: 'requested', template_code: 'g7_a_1' },
    approved: { id: 1, has_content: true, status: 'approved', template_code: 'g7_a_1' },
    rejected: { id: 1, has_content: true, status: 'rejected', template_code: 'g7_a_1', inspection_detail: [{ content: '반려 사유' }] },
    dormant: { id: 1, has_content: true, status: 'dormant', template_code: 'g7_a_1' },
    stopped: { id: 1, has_content: true, status: 'stopped', template_code: 'g7_a_1' },
    blocked: { id: 1, has_content: true, status: 'blocked', template_code: 'g7_a_1' },
};
const mapScope = (row: Record<string, unknown> | undefined) => ({
    bizppurioTemplates: { data: { templates: row ? { welcome: row } : {} } },
    extensionPointProps: { definition: { type: 'welcome' }, activeChannel: 'alimtalk', channel: 'alimtalk' },
});

describe('lifecycle UI — 파일 분리(Overlay vs ExtensionPoint 3종)', () => {
    it('overlay 는 target_layout=admin_settings 이고 extension_point·modals 키가 없다(모달 폐지)', () => {
        expect((overlay as { target_layout?: string }).target_layout).toBe('admin_settings');
        expect((overlay as Record<string, unknown>).extension_point).toBeUndefined();
        for (const [label, o] of [['core', coreOverlay], ['board', boardOverlay], ['ecommerce', ecommerceOverlay]] as const) {
            expect((o as Record<string, unknown>).modals, `${label} 오버레이는 모달을 등록하지 않는다`).toBeUndefined();
        }
    });

    it('footer 는 extension_point=notification_definition_row_footer 이고 target_layout 이 없다', () => {
        expect((footer as { extension_point?: string }).extension_point).toBe('notification_definition_row_footer');
        expect((footer as Record<string, unknown>).target_layout).toBeUndefined();
    });

    it('섹션·푸터 액션은 코어 편집 모달의 extension_point 2종에 주입된다(3면 공유 1본)', () => {
        expect((sections as { extension_point?: string }).extension_point).toBe('notification_template_form_sections');
        expect((footerActions as { extension_point?: string }).extension_point).toBe('notification_template_form_footer_actions');
        expect((sections as Record<string, unknown>).target_layout).toBeUndefined();
        expect((footerActions as Record<string, unknown>).target_layout).toBeUndefined();
    });

    it('섹션·푸터 액션 루트는 alimtalk 채널 템플릿을 편집할 때만 노출된다(메일·사이트내 알림 모달 무오염)', () => {
        for (const root of [findById(sectionsRoot, 'bizppurio_tpl_sections'), findById(actionsRoot, 'bizppurio_tpl_footer_actions')]) {
            expect(root).toBeTruthy();
            const cond = (root as { if?: string }).if ?? '';
            expect(evalBinding(cond, { extensionPointProps: { channel: 'alimtalk' } })).toBe(true);
            expect(evalBinding(cond, { extensionPointProps: { channel: 'mail' } })).toBe(false);
            expect(evalBinding(cond, { extensionPointProps: { channel: '' } })).toBe(false);
        }
    });
});

describe('lifecycle UI — 데이터소스 3종', () => {
    const sources = ((overlay as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? []);

    it('bizppurioTemplates / bizppurioCategories / bizppurioProfiles 3종을 등록한다', () => {
        expect(sources.map((d) => d.id)).toEqual(['bizppurioTemplates', 'bizppurioCategories', 'bizppurioProfiles']);
    });

    it('3종 모두 auto_fetch:false — 설정 페이지 다른 탭에서 자동 호출되지 않는다', () => {
        for (const ds of sources) expect(ds.auto_fetch, `${ds.id} auto_fetch`).toBe(false);
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

    it('알림톡 탭 상시 안내 박스가 [편집] 모달 통합 안내(tab_guide)를 노출한다', () => {
        const guide = findById(bannerRoot, 'bizppurio_alimtalk_guide');
        expect(guide).toBeTruthy();
        expect((guide as { if?: string }).if).toContain("=== 'alimtalk'");
        expect(JSON.stringify(guide)).toContain('template.tab_guide');
        expect((ko as Record<string, any>).template.tab_guide).toContain('[편집]');
    });
});

describe('lifecycle UI — 행 하단은 상태 요약만 (버튼 0)', () => {
    const row = findById(footerRoot, 'bizppurio_row_lifecycle') as AnyNode;

    it('행 UI 는 activeChannel === alimtalk 일 때만 노출된다', () => {
        expect(row).toBeTruthy();
        expect(evalBinding((row as { if?: string }).if ?? '', { extensionPointProps: { activeChannel: 'alimtalk' } })).toBe(true);
        expect(evalBinding((row as { if?: string }).if ?? '', { extensionPointProps: { activeChannel: 'mail' } })).toBe(false);
    });

    it('행 하단에는 버튼·체크박스·apiCall 이 없다 — 조작은 코어 [편집] 모달로 일원화', () => {
        expect(findAllByName(row, 'Button')).toHaveLength(0);
        expect(findAllByName(row, 'Checkbox')).toHaveLength(0);
        expect(findAllByName(row, 'Toggle')).toHaveLength(0);
        expect(collectActions(row).filter((a) => a.handler === 'apiCall')).toHaveLength(0);
        expect(JSON.stringify(row)).not.toContain('openModal');
    });

    it('SMS 요약 줄은 대체/단독 사용 여부와 서버 미리보기(has_sms_body / sms_body_preview)를 읽는다', () => {
        const raw = JSON.stringify(row);
        expect(raw).toContain(`${MAP_PATH}?.fallback_sms_enabled`);
        expect(raw).toContain(`${MAP_PATH}?.sms_only`);
        expect(raw).toContain(`${MAP_PATH}?.has_sms_body === true`);
        expect(raw).toContain(`${MAP_PATH}?.sms_body_preview`);
        expect(raw).toContain('template.row.sms_body_missing');
        expect(raw).not.toContain('.slice(0, 40)');
    });

    it('SMS 상태 3종은 문구가 아니라 배지다 — 사용=초록/미사용=회색(승인 배지와 같은 모양), 본문 미설정=외곽선 칩 (제품 결정 2026-08-23)', () => {
        const APPROVED_SET = 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-200';
        const GRAY_SET = 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300';
        for (const [id, field] of [['bizppurio_row_sms_fallback_badge', 'fallback_sms_enabled'], ['bizppurio_row_sms_only_badge', 'sms_only']] as const) {
            const badge = findById(row, id) as AnyNode;
            expect(badge, id).toBeTruthy();
            const cls = String((badge.props as { className: string }).className);
            const on = String(evalExpr(cls, mapScope({ id: 1, has_content: true, status: 'draft', [field]: true })));
            const off = String(evalExpr(cls, mapScope({ id: 1, has_content: true, status: 'draft', [field]: false })));
            expect(on).toContain('rounded');
            expect(on).toContain('px-2 py-0.5');
            expect(on).toContain(APPROVED_SET);
            expect(off).toContain(GRAY_SET);
            expect(evalExpr(String(badge.text), mapScope({ id: 1, [field]: true }))).toBe('sirsoft-message_bizppurio.template.row.on');
            expect(evalExpr(String(badge.text), mapScope({ id: 1, [field]: false }))).toBe('sirsoft-message_bizppurio.template.row.off');
            // 라벨(대체 SMS / SMS 단독)은 배지 앞 형제
            const group = findById(row, id.replace('_badge', '')) as AnyNode;
            expect((group.children as AnyNode[])[0].text).toMatch(/template\.row\.(fallback_sms|sms_only)$/);
        }
        const missing = findById(row, 'bizppurio_row_sms_body_missing') as AnyNode;
        expect(missing).toBeTruthy();
        expect(String((missing.props as { className: string }).className)).toContain('border border-gray-300');
        expect(missing.text).toBe('$t:sirsoft-message_bizppurio.template.row.sms_body_missing');
        // 평문 "라벨: 값" 조립은 남지 않는다
        expect(JSON.stringify(row)).not.toContain("+ ': ' +");
    });
});

describe('lifecycle UI — 승인 여부 2단 배지 실평가 (행 하단 + 섹션 헤더 동형)', () => {
    /** 배지 = 1차(승인됨/미승인) Span + 2차(괄호 세부) Span 을 품은 Span */
    const badgeOf = (root: AnyNode): AnyNode => {
        const badge = findAllByName(root, 'Span').find((s) => String((s.comment as string) ?? '').includes('승인 여부 2단 배지'));
        expect(badge, '2단 배지 노드').toBeTruthy();
        return badge as AnyNode;
    };

    const EXPECTED: Record<string, { primary: string; detail: string | null }> = {
        '행 없음': { primary: 'not_approved', detail: 'unwritten' },
        '행만 있고 내용 없음': { primary: 'not_approved', detail: 'unwritten' },
        draft: { primary: 'not_approved', detail: 'draft' },
        requested: { primary: 'not_approved', detail: 'requested' },
        approved: { primary: 'approved', detail: null },
        rejected: { primary: 'not_approved', detail: 'rejected' },
        dormant: { primary: 'not_approved', detail: 'dormant' },
        stopped: { primary: 'not_approved', detail: 'stopped' },
        blocked: { primary: 'not_approved', detail: 'blocked' },
    };

    /** 섹션 헤더 배지는 상세 GET 으로 갱신되는 모달 상태를 근거로 한다(요약 맵은 모달 안 액션 직후 stale — 실측) */
    const modalScope = (row: Record<string, unknown> | undefined) => ({
        _global: { bz_tpl_modal: row ? { id: row.id, status: row.status, has_content: row.has_content } : { id: null } },
    });

    it.each([
        ['행 하단', footerRoot, mapScope],
        ['섹션 헤더', sectionsRoot, modalScope],
    ] as const)('%s 배지가 상태 9종을 승인됨 / 미승인(세부) 로 표기한다', (_label, root, scopeOf) => {
        const badge = badgeOf(root);
        const [primary, detail] = badge.children as AnyNode[];
        for (const [state, row] of Object.entries(ROWS)) {
            const scope = scopeOf(row) as Record<string, unknown>;
            const want = EXPECTED[state];
            expect(evalExpr(String(primary.text), scope), `${state} 1차`).toBe(`sirsoft-message_bizppurio.template.approval.${want.primary}`);
            const detailVisible = evalBinding(String(detail.if), scope);
            expect(detailVisible, `${state} 2차 노출`).toBe(want.detail !== null);
            if (want.detail) {
                expect(evalExpr(String(detail.text), scope), `${state} 2차`).toBe(`(sirsoft-message_bizppurio.template.status.${want.detail})`);
            }
            // 색상 클래스는 승인/검수중/반려/휴면/중지·차단/기타 6계열 — 승인됨만 green
            const cls = String(evalExpr(String((badge.props as { className: string }).className), scope));
            expect(cls.includes('bg-green-100'), `${state} green`).toBe(state === 'approved');
        }
    });
});

describe('lifecycle UI — [편집] 모달 섹션: 시딩(onMount) + 편집/잠금 분기 실평가', () => {
    const root = findById(sectionsRoot, 'bizppurio_tpl_sections') as AnyNode;
    const onMount = ((root.lifecycle as { onMount?: AnyNode[] })?.onMount ?? []);
    const steps = ((onMount[0]?.params as { actions?: AnyNode[] })?.actions ?? []);

    it('마운트 시 sequence 1개: 상태 시딩 → 상세 GET(행 있을 때) → 카테고리·발신프로필 조회', () => {
        expect(onMount).toHaveLength(1);
        expect(onMount[0].handler).toBe('sequence');
        expect(steps.map((s) => s.handler)).toEqual(['setState', 'apiCall', 'refetchDataSource', 'refetchDataSource']);
        const seed = steps[0].params as Record<string, unknown>;
        for (const key of ['bz_tpl_modal', 'bz_sms_modal', 'bz_tpl_upload', 'bz_tpl_ui']) expect(seed[key], key).toBeTruthy();
        expect((steps[2].params as { dataSourceId: string }).dataSourceId).toBe('bizppurioCategories');
        expect((steps[3].params as { dataSourceId: string }).dataSourceId).toBe('bizppurioProfiles');
    });

    it('상세 GET 은 요약 맵의 행 id 로 판정·호출한다(같은 sequence 의 setState 값은 다음 액션 if 에 반영되지 않는다 — 실측)', () => {
        const detail = steps[1];
        expect(detail.target).toBe(`/api/plugins/sirsoft-message_bizppurio/admin/templates/{{${MAP_PATH}?.id}}`);
        expect(String(detail.if)).not.toContain('_global.bz_tpl_modal');
        expect(evalBinding(String(detail.if), mapScope(ROWS.draft))).toBe(true);
        expect(evalBinding(String(detail.if), mapScope(ROWS['행만 있고 내용 없음']))).toBe(true);
        expect(evalBinding(String(detail.if), mapScope(ROWS['행 없음']))).toBe(false);
        const onSuccess = JSON.stringify(detail.onSuccess);
        expect(onSuccess).toContain('bz_tpl_modal.content');
        expect(onSuccess).toContain('bz_tpl_modal.status');
        expect(onSuccess).toContain('bz_tpl_modal.has_content');
    });

    it('시딩값: SMS 본문·토글은 요약 맵에서, 알림톡 본문 프리필은 코어 alimtalk 템플릿 body 에서(#{var} 변환)', () => {
        const seed = steps[0].params as { bz_tpl_modal: Record<string, unknown>; bz_sms_modal: Record<string, unknown> };
        expect(seed.bz_sms_modal.body).toBe(`{{Object.assign({}, ${MAP_PATH}?.sms_body)}}`);
        expect(seed.bz_sms_modal.fallback_sms_enabled).toBe(`{{!!(${MAP_PATH}?.fallback_sms_enabled)}}`);
        expect(seed.bz_sms_modal.sms_only).toBe(`{{!!(${MAP_PATH}?.sms_only)}}`);
        expect(seed.bz_sms_modal.editLang).toBe('{{$locale}}');
        expect(seed.bz_tpl_modal.has_content).toBe(`{{!!(${MAP_PATH}?.has_content)}}`);
        expect(String((seed.bz_tpl_modal.content as Record<string, unknown>).templateContent)).toContain("tpl.channel === 'alimtalk'");
        expect(String((seed.bz_tpl_modal.content as Record<string, unknown>).templateContent)).toContain(".split('{').join('#{')");
    });

    const editor = findById(sectionsRoot, 'bizppurio_tpl_editor') as AnyNode;
    const locked = findById(sectionsRoot, 'bizppurio_tpl_locked') as AnyNode;
    const loadingNotice = findById(sectionsRoot, 'bizppurio_tpl_loading') as AnyNode;
    /** 자기 알림 유형으로 시딩이 끝나고 상세 GET 도 끝난 상태(READY) */
    const readyScope = (modal: Record<string, unknown>) => ({
        _global: { bz_tpl_modal: { notification_type: 'welcome', loading: false, ...modal } },
        extensionPointProps: { definition: { type: 'welcome' }, channel: 'alimtalk' },
    });

    it.each([
        ['draft', true], ['rejected', true], [undefined, true],
        ['requested', false], ['approved', false], ['dormant', false], ['stopped', false], ['blocked', false],
    ])('상태 %s → 작성 폼 노출=%s, 잠금 요약은 그 반대 (READY 상태에서)', (status, editable) => {
        const scope = readyScope(status === undefined ? {} : { status });
        expect(evalBinding(String(editor.if), scope)).toBe(editable);
        expect(evalBinding(String(locked.if), scope)).toBe(!editable);
    });

    it('READY(자기 유형 시딩 + 상세 GET 완료) 전에는 작성 폼·잠금 요약이 모두 숨고 로딩 안내만 보인다 — 시딩 전/이전 알림 상태로 저장되는 경쟁 차단', () => {
        expect(loadingNotice).toBeTruthy();
        const seed = (steps[0].params as { bz_tpl_modal: Record<string, unknown> }).bz_tpl_modal;
        expect(seed.loading).toBe(`{{!!(${MAP_PATH}?.id)}}`);
        expect(seed.notification_type).toBe('{{extensionPointProps.definition?.type}}');
        expect(JSON.stringify(steps[1].onSuccess)).toContain('"bz_tpl_modal.loading":false');
        expect(JSON.stringify(steps[1].onError)).toContain('"bz_tpl_modal.loading":false');

        const NOT_READY = [
            ['시딩 전(상태 없음)', { _global: {}, extensionPointProps: { definition: { type: 'welcome' } } }],
            ['이전 알림의 상태', { _global: { bz_tpl_modal: { notification_type: 'reset_password', status: 'draft', loading: false } }, extensionPointProps: { definition: { type: 'welcome' } } }],
            ['상세 GET 진행 중', { _global: { bz_tpl_modal: { notification_type: 'welcome', status: 'draft', loading: true } }, extensionPointProps: { definition: { type: 'welcome' } } }],
        ] as const;
        for (const [label, scope] of NOT_READY) {
            expect(evalBinding(String(editor.if), scope as Record<string, unknown>), `${label}: 폼`).toBe(false);
            expect(evalBinding(String(locked.if), scope as Record<string, unknown>), `${label}: 잠금 요약`).toBe(false);
            expect(evalBinding(String(loadingNotice.if), scope as Record<string, unknown>), `${label}: 로딩 안내`).toBe(true);
        }
        for (const status of ['draft', 'requested', 'approved']) {
            const ready = readyScope({ status });
            expect(evalBinding(String(loadingNotice.if), ready)).toBe(false);
            expect(evalBinding(String(editor.if), ready) || evalBinding(String(locked.if), ready), `${status}: READY 후 둘 중 하나`).toBe(true);
        }
    });

    it('모달이 닫히면(언마운트) 폼 상태를 비워 다음 오픈이 시딩 전에서 시작한다', () => {
        const root = findById(sectionsRoot, 'bizppurio_tpl_sections') as AnyNode;
        const onUnmount = ((root.lifecycle as { onUnmount?: AnyNode[] })?.onUnmount ?? []);
        expect(onUnmount).toHaveLength(1);
        expect(onUnmount[0].handler).toBe('setState');
        const params = onUnmount[0].params as Record<string, unknown>;
        expect(params.bz_tpl_modal).toBeNull();
        expect(params.bz_sms_modal).toBeNull();
    });

    it('작성 폼에는 구 작성 모달의 필드 전부가 이식됐다(유형·강조·본문·버튼·바로연결·업로드)', () => {
        const raw = JSON.stringify(editor);
        for (const needle of ['templateMessageType', 'templateEmphasizeType', 'bz_template_content', 'buttons', 'quickReplies', 'sirsoft-message_bizppurio.uploadTemplateImage', 'templateImageUrl', 'templateItem']) {
            expect(raw, needle).toContain(needle);
        }
    });

    it('상태 액션 4종의 노출 조건이 상태별로 정확히 평가된다', () => {
        const BUTTONS: Record<string, (s: Record<string, unknown>) => boolean> = {
            'template.row.btn_refresh': (s) => Boolean(s.template_code),
            'template.row.btn_cancel_request': (s) => s.status === 'requested',
            'template.row.btn_release': (s) => s.status === 'dormant',
            'template.row.btn_edit_approved': (s) => s.status === 'approved',
        };
        for (const [key, expected] of Object.entries(BUTTONS)) {
            const btn = findButtonByTextKey(sectionsRoot, key);
            expect(btn, key).toBeTruthy();
            for (const [state, row] of Object.entries(ROWS)) {
                if (!row) continue;
                const scope = { _global: { bz_tpl_modal: { status: row.status, template_code: row.template_code ?? null }, bz_tpl_ui: { confirmCancelApproval: false } } };
                expect(evalBinding(String(btn?.if), scope), `${key} @ ${state}`).toBe(expected(row));
            }
        }
    });

    it('승인 취소는 인라인 확인 박스(모달 중첩 없음) — 확정 시 POST .../cancel-approval 후 draft 로 되돌린다', () => {
        const confirmBtn = findButtonByTextKey(sectionsRoot, 'template.cancel_approval.btn_confirm');
        expect(confirmBtn).toBeTruthy();
        const raw = JSON.stringify(confirmBtn);
        expect(raw).toContain(`/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_tpl_modal?.id}}/cancel-approval`);
        expect(raw).toContain('"bz_tpl_modal.status":"draft"');
        expect(JSON.stringify(sectionsRoot)).not.toContain('openModal');
        expect(JSON.stringify(sectionsRoot)).toContain('template.cancel_approval.warning');
    });

    it('발송 토글(알림톡 사용·대체 SMS·SMS 단독)은 상태에만 쓰고 즉시 저장하지 않는다(통합 저장 원칙)', () => {
        const boxes = findAllByName(sectionsRoot, 'Checkbox');
        const bound = boxes.map((c) => String((c.props as { checked?: string })?.checked ?? ''));
        expect(bound.some((b) => b.includes('bz_tpl_modal?.alimtalk_enabled'))).toBe(true);
        expect(bound.some((b) => b.includes('bz_sms_modal?.fallback_sms_enabled'))).toBe(true);
        expect(bound.some((b) => b.includes('bz_sms_modal?.sms_only'))).toBe(true);
        for (const c of boxes) {
            expect((c.props as { autoBinding?: boolean }).autoBinding).toBe(false);
            const change = (c.actions as AnyNode[]).find((a) => a.type === 'change') as AnyNode;
            expect(change.handler, '토글은 setState 만').toBe('setState');
        }
    });

    it('반려 배너는 inspection_detail 스냅샷을 반복 렌더한다(반려 사유 모달 대체)', () => {
        const raw = JSON.stringify(sectionsRoot);
        expect(raw).toContain('template.form.rejected_banner');
        expect(raw).toContain('_global.bz_tpl_modal?.inspection_detail ?? []');
    });
});

describe('lifecycle UI — 통합 [저장] 체인 (푸터 액션)', () => {
    const buttons = findAllByName(actionsRoot, 'Button');
    const saveBtn = buttons.find((b) => !b.text && JSON.stringify(b).includes('$t:common.save')) as AnyNode;
    const requestBtn = findButtonByTextKey(actionsRoot, 'template.form.btn_save_request') as AnyNode;

    it('[저장] + [저장 후 검수 신청] 두 버튼이며, 검수 신청은 수정 가능 상태 + 본문 입력이 있을 때만 노출된다', () => {
        expect(buttons).toHaveLength(2);
        expect(saveBtn).toBeTruthy();
        expect(requestBtn).toBeTruthy();
        expect(saveBtn.if).toBeUndefined();
        const cond = String(requestBtn.if);
        const sc = (modal: Record<string, unknown>) => ({ _global: { bz_tpl_modal: { notification_type: 'welcome', loading: false, ...modal } }, extensionPointProps: { definition: { type: 'welcome' } } });
        expect(evalBinding(cond, sc({ status: 'draft', content: { templateContent: '본문' } }))).toBe(true);
        expect(evalBinding(cond, sc({ status: 'rejected', content: { templateContent: '본문' } }))).toBe(true);
        expect(evalBinding(cond, sc({ content: { templateContent: '본문' } })), '행 없음(신규)').toBe(true);
        expect(evalBinding(cond, sc({ status: 'draft', content: { templateContent: '   ' } })), '공백 본문').toBe(false);
        expect(evalBinding(cond, sc({ status: 'requested', content: { templateContent: '본문' } }))).toBe(false);
        expect(evalBinding(cond, sc({ status: 'approved', content: { templateContent: '본문' } }))).toBe(false);
        expect(evalBinding(cond, sc({ status: 'draft', loading: true, content: { templateContent: '본문' } })), '상세 GET 전').toBe(false);
    });

    it('두 버튼 모두 클릭 if 재진입 가드 + isSaving/uploading disabled 바인딩을 갖는다', () => {
        for (const b of [saveBtn, requestBtn]) {
            const click = (b.actions as AnyNode[])[0];
            expect(click.type).toBe('click');
            expect(click.if).toMatch(/!\(_global\.bz_tpl_modal\?\.isSaving \?\? false\)/);
            const disabled = String((b.props as { disabled?: string }).disabled);
            const ep = { definition: { type: 'welcome' } };
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: { isSaving: true, notification_type: 'welcome' }, bz_tpl_upload: { uploading: false } }, extensionPointProps: ep })).toBe(true);
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: { isSaving: false, notification_type: 'welcome' }, bz_tpl_upload: { uploading: true } }, extensionPointProps: ep })).toBe(true);
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: { isSaving: false, notification_type: 'welcome' }, bz_tpl_upload: { uploading: false } }, extensionPointProps: ep })).toBe(false);
            const ready = { _global: { bz_tpl_modal: { isSaving: false, loading: false, notification_type: 'welcome' }, bz_tpl_upload: { uploading: false } }, extensionPointProps: { definition: { type: 'welcome' } } };
            const notReady = { _global: { bz_tpl_modal: { isSaving: false, loading: true, notification_type: 'welcome' }, bz_tpl_upload: { uploading: false } }, extensionPointProps: { definition: { type: 'welcome' } } };
            const otherType = { _global: { bz_tpl_modal: { isSaving: false, loading: false, notification_type: 'reset_password' }, bz_tpl_upload: { uploading: false } }, extensionPointProps: { definition: { type: 'welcome' } } };
            expect(evalBinding(disabled, ready), 'READY 후 저장 열림').toBe(false);
            expect(evalBinding(disabled, notReady), '상세 GET 전 저장 잠금').toBe(true);
            expect(evalBinding(disabled, otherType), '이전 알림 상태로는 저장 잠금').toBe(true);
            expect(evalBinding(String(click.if), notReady), '상세 GET 전 클릭 가드').toBe(false);
            expect(evalBinding(String(click.if), ready), 'READY 후 클릭 허용').toBe(true);
        }
    });

    /** 저장 상태 매트릭스 → 실행돼야 하는 분기 번호(①POST ②PUT ③delivery) */
    const MATRIX: Array<[string, Record<string, unknown>, 1 | 2 | 3]> = [
        ['행 없음 + 본문 입력', { content: { templateContent: '본문' } }, 1],
        ['행 없음 + 본문 없음(SMS 만)', { content: { templateContent: '' } }, 3],
        ['draft + 본문 입력', { id: 1, status: 'draft', content: { templateContent: '본문' } }, 2],
        ['rejected + 본문 입력', { id: 1, status: 'rejected', content: { templateContent: '본문' } }, 2],
        ['draft + 본문 없음', { id: 1, status: 'draft', content: { templateContent: '' } }, 3],
        ['requested(잠금) + 본문 있음', { id: 1, status: 'requested', content: { templateContent: '본문' } }, 3],
        ['approved(잠금) + 본문 있음', { id: 1, status: 'approved', content: { templateContent: '본문' } }, 3],
        ['dormant(잠금)', { id: 1, status: 'dormant', content: { templateContent: '본문' } }, 3],
    ];

    it.each(MATRIX)('[저장] %s → 분기 정확히 하나만 실행된다', (_label, modal, want) => {
        const steps = ((saveBtn.actions as AnyNode[])[0].params as { actions: AnyNode[] }).actions;
        expect(steps[0].handler).toBe('setState');
        const branches = steps.slice(1);
        expect(branches).toHaveLength(3);
        const fired = branches.map((b) => evalBinding(String(b.if), { _global: { bz_tpl_modal: modal } }));
        expect(fired.filter(Boolean)).toHaveLength(1);
        expect(fired.indexOf(true) + 1).toBe(want);
    });

    it('분기 엔드포인트/메서드: ① POST templates ② PUT templates/{id} ③ PUT templates/delivery/{type} — delivery 필드 4종은 셋 다 싣는다', () => {
        const steps = ((saveBtn.actions as AnyNode[])[0].params as { actions: AnyNode[] }).actions.slice(1);
        expect([steps[0].target, (steps[0].params as { method: string }).method]).toEqual(['/api/plugins/sirsoft-message_bizppurio/admin/templates', 'POST']);
        expect([steps[1].target, (steps[1].params as { method: string }).method]).toEqual(['/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_tpl_modal?.id}}', 'PUT']);
        expect([steps[2].target, (steps[2].params as { method: string }).method]).toEqual(['/api/plugins/sirsoft-message_bizppurio/admin/templates/delivery/{{_global.bz_tpl_modal?.notification_type}}', 'PUT']);
        for (const s of steps) {
            const body = (s.params as { body: Record<string, string> }).body;
            for (const k of ['alimtalk_enabled', 'fallback_sms_enabled', 'sms_only', 'sms_body']) expect(body[k], `${s.comment} ${k}`).toBeTruthy();
            expect(body.sms_body).toBe('{{Object.assign({}, _global.bz_sms_modal?.body)}}');
        }
        expect((steps[0].params as { body: Record<string, string> }).body.content).toBeTruthy();
        expect((steps[1].params as { body: Record<string, string> }).body.content).toBeTruthy();
        expect((steps[2].params as { body: Record<string, string> }).body.content, '잠금 상태에서는 content 를 보내지 않는다(422 content_locked 회피)').toBeUndefined();
    });

    it('각 분기 성공 후: 수신자 규칙이 바뀐 경우에만 코어 PUT(extensionPointProps.saveEndpoint) → 마무리, 아니면 바로 마무리', () => {
        const steps = ((saveBtn.actions as AnyNode[])[0].params as { actions: AnyNode[] }).actions.slice(1);
        for (const s of steps) {
            const tail = s.onSuccess as AnyNode[];
            expect(tail).toHaveLength(2);
            const [corePut, skip] = tail;
            expect(corePut.handler).toBe('apiCall');
            expect(corePut.target).toBe('{{extensionPointProps.saveEndpoint}}');
            expect((corePut.params as { method: string }).method).toBe('PUT');
            expect(skip.handler).toBe('sequence');
            const changed = { _global: { notification_template_form_modal: { recipients: [{ type: 'role', role_ids: [1] }], template: { recipients: [{ type: 'trigger_user' }] } } }, extensionPointProps: { stateKey: 'notification_template_form_modal' } };
            const same = { _global: { notification_template_form_modal: { recipients: [{ type: 'trigger_user' }], template: { recipients: [{ type: 'trigger_user' }] } } }, extensionPointProps: { stateKey: 'notification_template_form_modal' } };
            expect(evalBinding(String(corePut.if), changed)).toBe(true);
            expect(evalBinding(String(skip.if), changed)).toBe(false);
            expect(evalBinding(String(corePut.if), same)).toBe(false);
            expect(evalBinding(String(skip.if), same)).toBe(true);
            // 코어 PUT 본문은 코어 모달의 저장 계약과 같은 4필드, 값은 extensionPointProps.stateKey 로 간접 참조
            const body = (corePut.params as { body: Record<string, string> }).body;
            expect(Object.keys(body).sort()).toEqual(['body', 'click_url', 'recipients', 'subject']);
            for (const v of Object.values(body)) expect(v).toContain('_global?.[extensionPointProps.stateKey]');
            // 마무리: 요약 맵 + 면별 정의 목록 재조회 → 토스트 → closeModal
            for (const fin of [corePut.onSuccess as AnyNode[], (skip.params as { actions: AnyNode[] }).actions]) {
                const handlers = fin.map((a) => a.handler);
                expect(handlers).toEqual(['setState', 'refetchDataSource', 'refetchDataSource', 'toast', 'closeModal']);
                expect((fin[1].params as { dataSourceId: string }).dataSourceId).toBe('bizppurioTemplates');
                expect((fin[2].params as { dataSourceId: string }).dataSourceId).toBe('{{extensionPointProps.refetchDataSourceId}}');
            }
        }
    });

    it('[저장 후 검수 신청]: 알림톡 upsert(①/②) 성공 → POST .../request → 코어 PUT(조건) → 마무리; delivery 전용 분기 ③ 은 없다', () => {
        const steps = ((requestBtn.actions as AnyNode[])[0].params as { actions: AnyNode[] }).actions.slice(1);
        expect(steps).toHaveLength(2);
        const [create, update] = steps;
        const createReq = (create.onSuccess as AnyNode[])[0];
        expect(createReq.target).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{response.data?.template?.id}}/request');
        const updateReq = (update.onSuccess as AnyNode[])[0];
        expect(updateReq.target).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates/{{_global.bz_tpl_modal?.id}}/request');
        for (const req of [createReq, updateReq]) {
            expect((req.params as { method: string }).method).toBe('POST');
            expect((req.onSuccess as AnyNode[])[0].target).toBe('{{extensionPointProps.saveEndpoint}}');
            expect(JSON.stringify(req.onSuccess)).toContain('template.form.requested_toast');
        }
    });

    it('[저장 후 검수 신청]의 request 2노드는 검수자 전달 의견(bz_tpl_modal.request_comment)을 comment 로 싣는다 (#597 §18.7)', () => {
        const steps = ((requestBtn.actions as AnyNode[])[0].params as { actions: AnyNode[] }).actions.slice(1);
        const reqs = steps.map((s) => (s.onSuccess as AnyNode[])[0]);
        expect(reqs).toHaveLength(2);
        for (const req of reqs) {
            const body = (req.params as { body: Record<string, string> }).body;
            expect(Object.keys(body)).toEqual(['comment']);
            expect(evalExpr(body.comment, { _global: { bz_tpl_modal: { request_comment: '변수 예시: #{name}=홍길동' } } })).toBe('변수 예시: #{name}=홍길동');
            expect(evalExpr(body.comment, { _global: { bz_tpl_modal: {} } }), '미입력 시 빈 문자열(서버가 미전달로 처리)').toBe('');
        }
        // 일반 [저장] 3분기에는 request 호출 자체가 없으므로 comment 도 없다
        const saveSteps = ((saveBtn.actions as AnyNode[])[0].params as { actions: AnyNode[] }).actions.slice(1);
        expect(JSON.stringify(saveSteps)).not.toContain('/request"');
    });

    it('실패 시 isSaving 해제 + 사유(bizppurio_message 우선)·필드 오류 객체를 상태에 싣는다(토스트가 아니라 섹션 배너가 표시)', () => {
        const fails = collectActions(actionsRoot).filter((a) => a.handler === 'apiCall').map((a) => JSON.stringify(a.onError));
        expect(fails.length).toBeGreaterThan(0);
        for (const f of fails) {
            expect(f).toContain('"bz_tpl_modal.isSaving":false');
            expect(f).toContain('error.errors?.bizppurio_message ?? error.message');
            expect(f).toContain('"bz_tpl_modal.errors"');
        }
        const raw = JSON.stringify(sectionsRoot);
        expect(raw).toContain("Object.values(_global.bz_tpl_modal?.errors ?? {}).flat()");
    });
});

describe('lifecycle UI — 검수자 전달 의견 입력란 (#597 §18.7, 제품 결정 2026-08-23)', () => {
    const editor = findById(sectionsRoot, 'bizppurio_tpl_editor') as AnyNode;
    const block = findById(editor, 'bizppurio_tpl_request_comment') as AnyNode;

    it('작성 폼(bizppurio_tpl_editor) 안에만 있다 — 잠금 요약에는 없다', () => {
        expect(block).toBeTruthy();
        expect(findById(findById(sectionsRoot, 'bizppurio_tpl_locked') as AnyNode, 'bizppurio_tpl_request_comment')).toBeFalsy();
    });

    it('Textarea(bz_request_comment, ≤500) 가 bz_tpl_modal.request_comment 를 읽고 쓰며 카운터가 /500 이다', () => {
        const ta = findAllByName(block, 'Textarea')[0] as AnyNode;
        const props = ta.props as Record<string, unknown>;
        expect(props.name).toBe('bz_request_comment');
        expect(props.maxLength).toBe(500);
        expect(props.value).toBe("{{_global.bz_tpl_modal?.request_comment ?? ''}}");
        const change = (ta.actions as AnyNode[])[0];
        expect(change.type).toBe('change');
        expect((change.params as Record<string, string>)['bz_tpl_modal.request_comment']).toBe('{{$event.target.value}}');
        const counter = findAllByName(block, 'Span').map((s) => String(s.text)).find((t) => t.endsWith('/500')) as string;
        expect(evalExpr(counter.replace('/500', ''), { _global: { bz_tpl_modal: { request_comment: '12345' } } })).toBe(5);
    });

    it('모달 시딩이 request_comment 를 빈 문자열로 초기화한다(이전 알림의 의견 이월 금지)', () => {
        const seeds = collectActions(sectionsRoot)
            .filter((a) => a.handler === 'setState' && (a.params as Record<string, unknown>)?.bz_tpl_modal);
        expect(seeds.length).toBeGreaterThan(0);
        for (const s of seeds) {
            expect(((s.params as Record<string, unknown>).bz_tpl_modal as Record<string, unknown>).request_comment).toBe('');
        }
    });

    it('라벨·placeholder·안내 키가 ko/en 에 존재한다', () => {
        for (const key of ['request_comment', 'request_comment_placeholder', 'request_comment_hint']) {
            expect(resolve(ko, `sirsoft-message_bizppurio.template.form.${key}`), `ko ${key}`).toBeTruthy();
            expect(resolve(en, `sirsoft-message_bizppurio.template.form.${key}`), `en ${key}`).toBeTruthy();
        }
    });

    /** children 경로의 조상 체인(루트→부모). id 가 없으면 null. */
    const ancestorsOf = (root: AnyNode, id: string, anc: AnyNode[] = []): AnyNode[] | null => {
        for (const c of (root.children as AnyNode[] | undefined) ?? []) {
            if (c.id === id) return anc.concat(root);
            const r = ancestorsOf(c, id, anc.concat(root));
            if (r) return r;
        }
        return null;
    };

    it('바로연결 블록(헤더+목록) 바로 뒤 형제로 놓이고, 라벨+[바로연결 추가] flex 행 안에 끼지 않는다 (회귀: 화면 검수 지적 2026-08-23)', () => {
        const chain = ancestorsOf(editor, 'bizppurio_tpl_request_comment') as AnyNode[];
        expect(chain).toBeTruthy();
        const parent = chain[chain.length - 1];
        const siblings = parent.children as AnyNode[];
        const idx = siblings.findIndex((c) => c.id === 'bizppurio_tpl_request_comment');
        const prev = siblings[idx - 1];
        // 직전 형제 = 바로연결 블록 전체(추가 버튼 + 목록 iteration 을 모두 품는다)
        expect(JSON.stringify(prev)).toContain('template.form.quick_reply_add');
        expect(JSON.stringify(prev)).toContain('quickRepliesItem');
        // 의견 블록의 조상 어디에도 flex 행이 없다(헤더 행 내부 삽입이면 라벨이 세로로 찌그러진다)
        for (const a of chain) {
            expect(String((a.props as { className?: string } | undefined)?.className ?? ''), `ancestor ${a.id ?? a.name}`).not.toMatch(/\bflex\b/);
        }
    });
});

describe('lifecycle UI — 코어 계약 준수 + i18n 정합', () => {
    it('플러그인 파일 어디에도 코어 엔드포인트·코어 모달 상태 키 리터럴이 없다(전부 extensionPointProps 간접 참조)', () => {
        const all = JSON.stringify([overlay, footer, sections, footerActions]);
        expect(all).not.toContain('/api/admin/notification-templates/');
        expect(all).not.toContain('notification_template_form_modal');
        expect(all).not.toContain('modal_notification_template_form');
    });

    it('참조하는 모든 플러그인 i18n 정적 키가 ko·en 에 존재한다', () => {
        const keys = [overlay, footer, sections, footerActions].flatMap(collectPluginKeys);
        expect(keys.length).toBeGreaterThan(0);
        for (const key of Array.from(new Set(keys))) {
            expect(resolve(ko, key), `ko 누락: ${key}`).toBeTruthy();
            expect(resolve(en, key), `en 누락: ${key}`).toBeTruthy();
        }
    });

    it("동적 접두 'template.status.' 8종 + 'template.approval.' 2종이 ko·en 에 모두 존재한다", () => {
        const statuses = ['unwritten', 'draft', 'requested', 'approved', 'rejected', 'stopped', 'blocked', 'dormant'];
        for (const dict of [ko, en] as Array<Record<string, any>>) {
            expect(Object.keys(dict.template.status).sort()).toEqual([...statuses].sort());
            expect(Object.keys(dict.template.approval).sort()).toEqual(['approved', 'not_approved']);
            expect(Object.keys(dict.template.row)).toEqual(expect.arrayContaining(['on', 'off', 'sms_label', 'alimtalk_disabled']));
        }
    });

    it("동적 접두 'templates.link_type.' 가족: 작성 폼이 사용하는 링크 유형 코드가 ko·en 에 모두 존재한다", () => {
        const used = ['WL', 'AL', 'DS', 'BK', 'MD', 'AC', 'BC', 'BT', 'P1', 'P2', 'P3', 'TN', 'MP'];
        for (const dict of [ko, en] as Array<Record<string, any>>) {
            for (const code of used) expect(dict.templates.link_type[code], `link_type.${code}`).toBeTruthy();
        }
    });
});

describe('lifecycle UI — 바인딩 브레이스 위생 (회귀: 프리필 끝 `}}` 잔재)', () => {
    const FILES = [
        ['core', coreOverlay], ['board', boardOverlay], ['ecommerce', ecommerceOverlay],
        ['footer', footer], ['sections', sections], ['footerActions', footerActions],
    ] as const;

    it('문자열 값 어디에도 4연속 브레이스(}}}}·{{{{)가 없다', () => {
        for (const [name, json] of FILES) {
            const offenders = collectStrings(json).filter((v) => v.includes('}}}}') || v.includes('{{{{'));
            expect(offenders, `${name} 브레이스 잔재: ${offenders.join(' | ')}`).toEqual([]);
        }
    });

    it('표현식 $t 를 2-인자(파라미터 객체)로 호출하지 않는다 — 엔진은 단일 인자만 지원', () => {
        for (const [name, json] of FILES) {
            const offenders = collectStrings(json).filter((v) => /\$t\([^)]*,\s*\{/.test(v));
            expect(offenders, `${name} 2-인자 $t 호출: ${offenders.join(' | ')}`).toEqual([]);
        }
    });
});

/**
 * @effects upload_in_progress_locks_save_buttons_not_cancel
 */
describe('lifecycle UI — 이미지 업로드 상태 배선', () => {
    it('업로드 진행 중에는 통합 저장 계열 버튼이 잠긴다(취소는 코어 버튼이라 플러그인이 잠글 수 없다)', () => {
        const buttons = findAllByName(actionsRoot, 'Button');
        expect(buttons).toHaveLength(2);
        for (const b of buttons) {
            const disabled = String((b.props as { disabled?: string } | undefined)?.disabled ?? '');
            const ep = { definition: { type: 'welcome' } };
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: { notification_type: 'welcome' }, bz_tpl_upload: { uploading: true } }, extensionPointProps: ep })).toBe(true);
            expect(evalBinding(disabled, { _global: { bz_tpl_modal: { notification_type: 'welcome' }, bz_tpl_upload: { uploading: false } }, extensionPointProps: ep })).toBe(false);
        }
        expect(JSON.stringify(actionsRoot)).not.toContain('$t:common.cancel');
    });

    it('업로드 실패 배너는 상태에서만 읽고 fallback 을 가진다', () => {
        expect(JSON.stringify(sectionsRoot)).toContain("_global.bz_tpl_upload?.error ?? ''");
    });
});

describe('lifecycle UI — SMS 본문 다국어', () => {
    const raw = JSON.stringify(sectionsRoot);

    it('SMS 섹션은 로케일 탭을 제공하고 본문을 로케일별로 읽는다', () => {
        const tabs = findById(sectionsRoot, 'bz_sms_lang_tabs');
        expect(tabs, 'SMS 본문 언어 탭이 없으면 ko 한 벌만 입력된다').toBeTruthy();
        expect(JSON.stringify(tabs)).toContain('{{$locales}}');
        expect(raw).toContain('bz_sms_modal?.body?.[_global.bz_sms_modal?.editLang ?? $locale]');
    });

    it('변수 삽입 대상 경로가 현재 편집 로케일을 가리킨다', () => {
        expect(raw).toContain("'bz_sms_modal.body.' + (_global.bz_sms_modal?.editLang ?? $locale)");
    });
});

describe('compose form — 유형 전환·반복 입력 실평가 (#597 §14.2 T7 / §6.2)', () => {
    const evalWithModal = (expr: string, modal: Record<string, unknown>): boolean =>
        evalBinding(expr, { _global: { bz_tpl_modal: modal, bz_tpl_upload: {} } });

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
        walk(sectionsRoot);
        return found;
    };

    it.each(['TEXT', 'IMAGE', 'ITEM_LIST'])('강조유형 %s 블록은 그 유형에서만 노출된다', (type) => {
        const expr = conditionalBlocks()[type];
        expect(expr, `${type} 조건부 블록이 섹션에 없다`).toBeTruthy();
        for (const current of ['NONE', 'TEXT', 'IMAGE', 'ITEM_LIST']) {
            expect(evalWithModal(expr, { content: { templateEmphasizeType: current } }), `${current} → ${type}`).toBe(current === type);
        }
    });

    it('부가정보(templateExtra) 는 EX·MI 메시지 유형에서만 노출된다', () => {
        expect(JSON.stringify(sectionsRoot)).toContain("_global.bz_tpl_modal?.content?.templateMessageType === 'EX' || _global.bz_tpl_modal?.content?.templateMessageType === 'MI'");
    });

    const REPEAT_CAPS: Array<[string, number]> = [['templateItem?.list', 10], ['buttons', 5], ['quickReplies', 10]];

    it.each(REPEAT_CAPS)('반복 입력 %s 는 상한 %i 에 도달하면 추가가 잠긴다', (pathFragment, cap) => {
        const addBtn = findAllByName(sectionsRoot, 'Button').find((b) => {
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
        expect(evalWithModal(expr, { content: makeContent(cap - 1) })).toBe(false);
        expect(evalWithModal(expr, { content: makeContent(cap) })).toBe(true);
        expect(evalWithModal(expr, { content: makeContent(cap + 1) })).toBe(true);
        expect(evalWithModal(expr, { content: {} })).toBe(false);
    });

    it('버튼 linkType 별 조건부 링크 필드가 선택한 유형에서만 노출된다(부록 A-3 대응표 기준)', () => {
        const EXPECTED_FOR_FIELD: Record<string, string[]> = {
            linkMo: ['WL'], linkPc: ['WL'], linkAnd: ['AL'], linkIos: ['AL'], telNumber: ['TN'], pluginId: ['P1', 'P2', 'P3'],
        };
        const ALL_LINK_TYPES = ['WL', 'AL', 'DS', 'BK', 'MD', 'AC', 'BC', 'BT', 'P1', 'P2', 'P3', 'TN', 'MP'];
        const inputs = findAllByName(sectionsRoot, 'Input').filter((n) => /buttonsItem\.linkType === '/.test(String((n as { if?: string }).if ?? '')));
        const seen = new Set<string>();
        for (const node of inputs) {
            const value = String((node.props as { value?: string } | undefined)?.value ?? '');
            const field = (value.match(/buttonsItem\.([A-Za-z]+)/) ?? [])[1] as string;
            expect(EXPECTED_FOR_FIELD[field], `부록 A-3 에 없는 조건부 필드: ${field}`).toBeTruthy();
            seen.add(field);
            for (const lt of ALL_LINK_TYPES) {
                expect(evalBinding(String((node as { if?: string }).if), { buttonsItem: { linkType: lt } }), `${field} @ ${lt}`).toBe(EXPECTED_FOR_FIELD[field].includes(lt));
            }
        }
        expect([...seen].sort()).toEqual(Object.keys(EXPECTED_FOR_FIELD).sort());
    });
});
