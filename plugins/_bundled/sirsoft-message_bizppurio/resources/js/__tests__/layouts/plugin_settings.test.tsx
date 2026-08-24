/**
 * 비즈뿌리오 메시징 플러그인 환경설정 레이아웃 구조 검증 (§6-1)
 *
 * 3 섹션:
 * 1. section_api (연동 환경 + 비즈뿌리오 아이디 + 비밀번호 + API 키)
 * 2. section_sending (발신번호 + 알림톡 발신프로필 키)
 * 3. section_integration (webhook 수신 주소 안내)
 *
 * 크리덴셜(password/api_key/sender_key)은 type=password 로 마스킹, 저장은
 * 자동바인딩(_local.form) → 코어 /api/admin/plugins/{id}/settings PUT.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import {
    findById,
    findInputByName,
    collectHandlers,
    collectI18nKeys,
    type AnyNode,
} from './helpers';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const root = layout as unknown as AnyNode;

describe('plugin_settings.json — 레이아웃 메타/권한', () => {
    it('layout_name 이 plugin_settings 이다', () => {
        expect((root as { layout_name?: string }).layout_name).toBe('plugin_settings');
    });

    it('_admin_base 를 상속한다', () => {
        expect((root as { extends?: string }).extends).toBe('_admin_base');
    });

    it('core.plugins.update 권한을 요구한다', () => {
        expect((root as { permissions?: string[] }).permissions).toContain('core.plugins.update');
    });

    it('settings 데이터소스가 코어 플러그인 설정 API 를 조회한다', () => {
        const sources = (root as { data_sources?: AnyNode[] }).data_sources ?? [];
        const settings = sources.find((s) => s.id === 'settings');
        expect(settings).toBeTruthy();
        expect(settings?.endpoint).toBe('/api/admin/plugins/{{route.identifier}}/settings');
        expect(settings?.initLocal).toBe('form');
    });
});

describe('plugin_settings.json — 자동바인딩', () => {
    it('환경설정 탭 패널이 dataKey=form + trackChanges 로 자동바인딩한다', () => {
        const container = findById(root, 'connection_tab_panel');
        expect(container).toBeTruthy();
        expect((container as { dataKey?: string }).dataKey).toBe('form');
        expect((container as { trackChanges?: boolean }).trackChanges).toBe(true);
    });
});

describe('plugin_settings.json — 섹션', () => {
    it.each([
        ['preparation_notice', '사용 전 준비 안내(상단)'],
        ['section_api', 'API 연동'],
        ['section_sending', '발송 설정'],
        ['report_section', '리포트 수신 설정'],
    ])('%s 섹션이 존재한다', (id) => {
        expect(findById(root, id)).toBeTruthy();
    });

    it('준비 안내 박스는 총괄 안내(intro)와 콘솔 링크를 구분선 위에 먼저 노출한다', () => {
        const notice = findById(root, 'preparation_notice');
        const raw = JSON.stringify(notice);
        // 총괄 안내 문구 키 + 콘솔 링크가 존재한다
        expect(raw).toContain('sirsoft-message_bizppurio.settings.preparation.intro');
        expect(raw).toContain('sirsoft-message_bizppurio.settings.preparation.console_link');
        // 총괄 안내가 채널별 준비 목록(sms_label)보다 먼저 배치된다 (이미지1 구조)
        const introIdx = raw.indexOf('preparation.intro');
        const smsIdx = raw.indexOf('preparation.sms_label');
        expect(introIdx).toBeGreaterThanOrEqual(0);
        expect(smsIdx).toBeGreaterThanOrEqual(0);
        expect(introIdx).toBeLessThan(smsIdx);
    });

    it('채널별 준비 목록은 PC(lg 이상) 2단, 모바일 1단 그리드로 배치된다', () => {
        // 문자·카카오 블록을 감싼 컨테이너가 grid grid-cols-1 lg:grid-cols-2 여야 한다.
        // sms_label 을 담은 블록의 부모(구분선 아래 컨테이너)에서 grid 클래스를 확인.
        const notice = findById(root, 'preparation_notice');
        const raw = JSON.stringify(notice);
        // 세로 1단 고정(flex-col) 이 아니라 반응형 grid 여야 한다.
        expect(raw).toContain('grid-cols-1');
        expect(raw).toContain('lg:grid-cols-2');
    });

    it('문자·카카오 준비 블록은 카드(배경+테두리)로 감싸고 채널 아이콘을 라벨에 붙인다', () => {
        const notice = findById(root, 'preparation_notice');
        const raw = JSON.stringify(notice);
        // 각 열이 카드로 감싸짐 (다크모드 쌍 포함 solid 배경)
        expect(raw).toContain('bg-blue-100');
        expect(raw).toContain('dark:bg-blue-900');
        // 채널별 아이콘 (문자=envelope, 카카오=comment-dots)
        expect(raw).toContain('"envelope"');
        expect(raw).toContain('"comment-dots"');
    });

    it('검수 모드 카드에 is_test_mode Toggle 이 있다', () => {
        const card = findById(root, 'test_mode_card');
        expect(card).toBeTruthy();
        const raw = JSON.stringify(card);
        expect(raw).toContain('"Toggle"');
        expect(raw).toContain('is_test_mode');
    });

    it('검수 모드 카드에 검수/운영 계정 분리 권장 안내가 상시 노출된다', () => {
        // 검수 켜짐/꺼짐과 무관하게(if 조건 없이) 카드 안에 계정 분리 권장 문구가 있어야 한다.
        const card = findById(root, 'test_mode_card') as { if?: string } | null;
        expect(card).toBeTruthy();
        expect(card?.if).toBeUndefined(); // 카드 자체가 조건부가 아님 → 상시 노출
        const raw = JSON.stringify(card);
        expect(raw).toContain('settings.test_mode.account_notice');
    });

    it('운영 모드(검수 off) 경고 박스가 조건부로 존재한다', () => {
        const warning = findById(root, 'live_mode_warning');
        expect(warning).toBeTruthy();
        expect((warning as { if?: string }).if).toContain('!_local.form.is_test_mode');
    });
});

describe('plugin_settings.json — 입력 필드 6종', () => {
    it.each([
        'bizppurio_id',
        'password',
        'api_key',
        'sender_number',
        'sender_key',
    ])('%s 입력 필드가 자동바인딩 name 으로 존재한다', (name) => {
        expect(findInputByName(root, name)).toBeTruthy();
    });

    it('크리덴셜(password/api_key/sender_key)은 type=password 로 마스킹된다', () => {
        for (const cred of ['password', 'api_key', 'sender_key']) {
            const input = findInputByName(root, cred);
            expect((input?.props as { type?: string } | undefined)?.type).toBe('password');
        }
    });

    it('bizppurio_id/sender_number 는 일반 text 입력이다', () => {
        for (const field of ['bizppurio_id', 'sender_number']) {
            const input = findInputByName(root, field);
            expect((input?.props as { type?: string } | undefined)?.type).toBe('text');
        }
    });

});

describe('plugin_settings.json — 연결 확인 (§529)', () => {
    it('field_connection_check 필드가 field_password 와 별개 노드로 존재한다', () => {
        const field = findById(root, 'field_connection_check');
        expect(field).toBeTruthy();
        expect(field).not.toBe(findById(root, 'field_password'));
    });

    it('연결 확인 버튼이 설정 행과 동일한 grid-cols-12(4/8) + 우측 정렬 패턴을 따른다', () => {
        const field = findById(root, 'field_connection_check');
        const raw = JSON.stringify(field);
        expect(raw).toContain('grid-cols-1');
        expect(raw).toContain('lg:grid-cols-12');
        expect(raw).toContain('lg:col-span-4');
        expect(raw).toContain('lg:col-span-8');
        expect(raw).toContain('lg:justify-end');
    });

    it('버튼은 대기 상태 plug, 로딩 상태 spinner(animate-spin) 아이콘을 조건부로 갖는다', () => {
        const btn = findById(root, 'connection_check_button');
        const raw = JSON.stringify(btn);
        expect(raw).toContain('"plug"');
        expect(raw).toContain('"spinner"');
        expect(raw).toContain('animate-spin');
        expect(raw).toContain('_local.tokenChecking');
    });

    it('버튼 클릭은 hasChanges=false 일 때만 apiCall 로 /admin/token/check 를 POST 한다', () => {
        const btn = findById(root, 'connection_check_button');
        const raw = JSON.stringify(btn);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/token/check');
        expect(raw).toContain('"method":"POST"');
        // apiCall 액션 자체가 !hasChanges 가드를 갖는다
        const actions = (btn as { actions?: AnyNode[] } | null)?.actions ?? [];
        const sequence = actions.find((a) => a.handler === 'sequence');
        const inner = ((sequence as { actions?: AnyNode[] } | undefined)?.actions ?? []) as AnyNode[];
        const apiCallAction = inner.find((a) => a.handler === 'apiCall');
        expect(apiCallAction?.if).toContain('!_local.hasChanges');
    });

    it('hasChanges=true 일 때는 apiCall 없이 unsaved_changes toast 만 실행한다', () => {
        const btn = findById(root, 'connection_check_button');
        const actions = (btn as { actions?: AnyNode[] } | null)?.actions ?? [];
        const sequence = actions.find((a) => a.handler === 'sequence');
        const inner = ((sequence as { actions?: AnyNode[] } | undefined)?.actions ?? []) as AnyNode[];
        const guardToast = inner.find((a) => a.handler === 'toast' && a.if === '{{_local.hasChanges}}');
        expect(guardToast).toBeTruthy();
        expect(JSON.stringify(guardToast)).toContain('connection_check.unsaved_changes');
    });

    it('성공/실패 결과는 화면 상시 표시 없이 toast 로만 안내한다', () => {
        const btn = findById(root, 'connection_check_button');
        const raw = JSON.stringify(btn);
        expect(raw).toContain('connection_check.success');
        expect(raw).toContain('connection_check.failed');
    });
});

/**
 * hasChanges 초기값(런타임) 검증 — §529 비판적 재검토에서 발견한 공백.
 *
 * 위 describe 블록은 레이아웃 JSON의 if 조건 문자열만 정적으로 확인한다. 하지만
 * "페이지를 막 열고 아무것도 바꾸지 않은 상태(_local.hasChanges 가 아직 세팅 전)"
 * 에서 실제로 어떻게 평가되는지는 런타임 값 — Boolean(undefined) 규칙에 따라
 * `{{!_local.hasChanges}}` 는 true(API 호출 진행), `{{_local.hasChanges}}` 는
 * false(경고 미노출) 가 되어야 정상이다. 실제 엔진(createLayoutTest)으로 렌더해
 * 이 가정을 증명한다(추정이 아니라 확인).
 */
describe('plugin_settings.json — 연결 확인 버튼의 hasChanges 초기 상태 (§529 런타임 검증)', () => {
    const connectionCheckButton = findById(root, 'connection_check_button') as AnyNode & {
        actions: AnyNode[];
    };
    const clickAction = connectionCheckButton.actions.find((a) => a.handler === 'sequence') as AnyNode;

    function buildProbe() {
        return {
            version: '1.0.0',
            layout_name: 'test/connection-check-initial-state',
            components: [connectionCheckButton],
        };
    }

    afterEach(() => {
        vi.clearAllMocks();
    });

    /**
     * toast 핸들러는 ActionDispatcher 내장 처리(handleToast)라 커스텀 registerHandler
     * 로 가로챌 수 없다 — 실제로는 globalStateUpdater 를 통해 `_global.toasts` 배열에
     * 쌓인다(createLayoutTest 의 getToasts() 는 이 경로를 타지 않는 죽은 유틸리티임을
     * 최소 재현으로 확인). 따라서 getState()._global.toasts 를 직접 읽는다.
     */
    function lastToastMessages(utils: ReturnType<typeof createLayoutTest>): string[] {
        const toasts = (utils.getState()._global?.toasts ?? []) as Array<{ message: string }>;
        return toasts.map((t) => t.message);
    }

    it('로드 직후(hasChanges 미설정) 클릭하면 hasChanges 가드를 통과해 실제 API 응답까지 도달한다', async () => {
        const registry = createMockComponentRegistryWithBasics();
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('token_check_probe', { response: { success: true } });
        await utils.render();

        // 초기 상태: hasChanges 는 아직 세팅되지 않음(undefined) — 저장 폼을 만지지 않은 상태.
        // Boolean(undefined) === false 이므로 미저장 가드({{_local.hasChanges}})는 통과해야 한다.
        expect(utils.getState()._local?.hasChanges).toBeFalsy();

        await utils.triggerAction(clickAction);

        // hasChanges 가드를 통과했다면 apiCall 이 실행되어 onSuccess/onError 중
        // 하나가 반드시 toast 를 남긴다 — unsaved_changes 경고는 뜨지 않고,
        // success 또는 failed(원격 호출 실패 응답 처리) 중 하나만 떠야 한다.
        const messages = lastToastMessages(utils);
        expect(messages.some((m) => m.includes('connection_check.unsaved_changes'))).toBe(false);
        expect(messages.length).toBeGreaterThan(0);

        utils.cleanup();
    });

    it('hasChanges=true 로 세팅된 뒤 클릭하면 API 호출 없이 미저장 경고 toast 만 뜬다', async () => {
        const registry = createMockComponentRegistryWithBasics();
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        await utils.render();

        utils.setState('hasChanges', true, 'local');
        await utils.triggerAction(clickAction);

        const messages = lastToastMessages(utils);
        expect(messages.some((m) => m.includes('connection_check.unsaved_changes'))).toBe(true);
        expect(messages.some((m) => m.includes('connection_check.success'))).toBe(false);

        utils.cleanup();
    });
});

describe('plugin_settings.json — 비밀번호 필드 라벨 (§529)', () => {
    it('비밀번호 필드 라벨이 "비즈뿌리오 모듈 비밀번호"로 G7 로그인 비밀번호와 구분된다', () => {
        expect((ko as Record<string, any>).settings.fields.password.label).toBe('비즈뿌리오 모듈 비밀번호');
        expect((en as Record<string, any>).settings.fields.password.label).toBe('Bizppurio Module Password');
    });
});

describe('plugin_settings.json — 리포트 수신 설정', () => {
    it('report_url 데이터소스가 조회 엔드포인트를 호출한다', () => {
        const sources = (root as { data_sources?: AnyNode[] }).data_sources ?? [];
        const reportUrl = sources.find((s) => s.id === 'report_url');
        expect(reportUrl).toBeTruthy();
        expect(reportUrl?.endpoint).toBe('/api/plugins/sirsoft-message_bizppurio/admin/report-url');
    });

    it('리포트 섹션에 조회값(fallback 웹훅 경로) readonly 표시 + 복사 버튼이 있다', () => {
        const section = findById(root, 'report_section');
        const raw = JSON.stringify(section);
        expect(raw).toContain('report_url?.data?.url');
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/webhook');
        expect(raw).toContain('"readOnly":true');
        expect(raw).toContain('copyToClipboard');
    });
});

describe('plugin_settings.json — 필드 인라인 에러', () => {
    it.each([
        'bizppurio_id',
        'password',
        'api_key',
        'sender_number',
        'sender_key',
    ])('%s 필드에 인라인 에러 노드가 존재한다', (name) => {
        const errorNode = findById(root, `field_${name}_error`);
        expect(errorNode).toBeTruthy();
        expect((errorNode as { if?: string }).if).toContain(`_local.errors?.${name}`);
    });
});

describe('plugin_settings.json — 저장 버튼', () => {
    it('저장 버튼이 hasChanges 없으면 비활성화된다', () => {
        const save = findById(root, 'save_button');
        expect((save?.props as { disabled?: string } | undefined)?.disabled).toContain('!_local.hasChanges');
    });

    it('저장은 코어 설정 API 로 PUT 한다', () => {
        const text = JSON.stringify(findById(root, 'save_button'));
        expect(text).toContain('/api/admin/plugins/{{route.identifier}}/settings');
        expect(text).toContain('"method":"PUT"');
        expect(text).toContain('{{_local.form}}');
    });

    it('등록된 핸들러만 사용한다 (오탈자 핸들러 없음)', () => {
        const handlers = collectHandlers(layout);
        const allowed = [
            'apiCall', 'setState', 'toast', 'navigate', 'sequence', 'switch',
            'refetchDataSource', 'scrollIntoView', 'copyToClipboard',
            'openModal', 'closeModal', 'replaceUrl', 'suppress',
            'sirsoft-message_bizppurio.uploadTemplateImage',
            'sirsoft-message_bizppurio.insertVariable',
        ];
        for (const h of handlers) {
            expect(allowed).toContain(h);
        }
    });
});

describe('plugin_settings.json — i18n 키 정합', () => {
    it('레이아웃이 참조하는 $t: 키가 ko/en 다국어 파일에 모두 존재한다', () => {
        const keys = collectI18nKeys(layout);
        expect(keys.length).toBeGreaterThan(0);

        const resolve = (dict: Record<string, unknown>, path: string): unknown =>
            path.split('.').reduce<unknown>((acc, seg) => {
                if (acc && typeof acc === 'object') {
                    return (acc as Record<string, unknown>)[seg];
                }
                return undefined;
            }, dict);

        for (const raw of keys) {
            // "$t:sirsoft-message_bizppurio.settings.title" → "settings.title"
            const path = raw.replace('$t:sirsoft-message_bizppurio.', '');
            expect(resolve(ko, path), `ko 누락: ${path}`).toBeTruthy();
            expect(resolve(en, path), `en 누락: ${path}`).toBeTruthy();
        }
    });
});

describe('plugin_settings.json — 탭 전환', () => {
    // 탭 버튼은 query.tab 을 바꾸며 화면(if 조건부 패널)을 다시 그려야 하므로
    // replaceUrl(URL만 변경, if 재평가 없음) 이 아니라 navigate 를 써야 한다.
    const tabButtonHandlers = (id: string): string[] => {
        const btn = findById(layout, id);
        const handlers: string[] = [];
        const walk = (node: unknown): void => {
            if (!node || typeof node !== 'object') return;
            const n = node as Record<string, unknown>;
            if (typeof n.handler === 'string') handlers.push(n.handler);
            for (const v of Object.values(n)) {
                if (Array.isArray(v)) v.forEach(walk);
                else if (v && typeof v === 'object') walk(v);
            }
        };
        walk((btn as { actions?: unknown })?.actions);
        return handlers;
    };

    it('환경설정 탭 버튼은 navigate 로 화면을 전환한다 (replaceUrl 금지)', () => {
        const handlers = tabButtonHandlers('tab_connection');
        expect(handlers).toContain('navigate');
        expect(handlers).not.toContain('replaceUrl');
    });

    it('알림톡 템플릿 탭 버튼은 navigate 로 화면을 전환한다 (replaceUrl 금지)', () => {
        const handlers = tabButtonHandlers('tab_templates');
        expect(handlers).toContain('navigate');
        expect(handlers).not.toContain('replaceUrl');
    });
});

describe('plugin_settings.json — templates 탭 readiness 게이트 (#597 재편)', () => {
    // 구 조회 전용 목록(alimtalk_templates + templateListError 배너)은 #597 에서
    // DB 목록 관리 화면(bizppurio_templates_list)으로 교체됐다. 관리 화면 상세 배선은
    // plugin_settings_manage.test.tsx 가 담당하고, 여기서는 탭 골격만 잠근다.
    it('templates_readiness 데이터소스가 templates 탭에서만 조회된다', () => {
        const sources = (root as { data_sources?: AnyNode[] }).data_sources ?? [];
        const ds = sources.find((s) => s.id === 'templates_readiness') as
            | Record<string, unknown>
            | undefined;
        expect(ds).toBeTruthy();
        expect(ds?.endpoint).toBe('/api/plugins/sirsoft-message_bizppurio/admin/templates-readiness');
        expect(ds?.if).toContain("query.tab ?? 'connection') === 'templates'");
    });

    it('readiness 안내(!ready)와 관리 뷰(ready)가 상호배타로 존재한다', () => {
        const notice = findById(layout, 'templates_readiness') as Record<string, unknown> | null;
        const manageView = findById(layout, 'templates_manage_view') as Record<string, unknown> | null;
        expect(notice).toBeTruthy();
        expect(manageView).toBeTruthy();
        expect(notice?.if).toBe('{{templates_readiness?.data && !(templates_readiness?.data?.ready)}}');
        expect(manageView?.if).toBe('{{templates_readiness?.data?.ready}}');
    });
});
