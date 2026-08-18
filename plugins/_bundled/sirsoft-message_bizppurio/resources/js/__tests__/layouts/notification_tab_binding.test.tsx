// e2e:allow 검수 모드 배너(readiness 무관 노출)·배너 간격은 Chrome MCP로 알림톡 탭 실제 화면
// 확인·수정까지 완료(2026-07-24). 정식 E2E는 비즈뿌리오 발송 인프라 의존이 커서 별도 계획에서 다룸.
/**
 * 알림 설정 알림톡 탭 연동 UI 구조 검증 (Phase 6 재설계, §6-2)
 *
 * 두 확장 파일로 분리 구현:
 * - notification_tab_core.json (Overlay): 상태 배너(injections) + 안내 박스 + 연결 모달(modals)
 *   + data_sources. target_layout=admin_settings.
 * - notification_row_footer.json (ExtensionPoint): 코어 목록 각 행 하단 슬롯
 *   (notification_definition_row_footer)에 연결 상태 줄 + [연결/변경] 버튼.
 *
 * 코어 편집 모달·저장 버튼은 건드리지 않는다(무오염). 연결은 편집 모달과 분리된 우리 전용
 * 모달에서 하며, 변경 즉시가 아니라 [저장] 버튼으로 명확히 저장한다. 카카오 API 422 는
 * errorHandling.suppress 로 조용히 처리(안내는 배너·문구가 담당).
 *
 * 검증: 파일 분리(overlay vs extension_point), 배너/안내/모달 구조, 행 슬롯 UI, 저장 배선,
 * 무오염(코어 저장 body 미개입), i18n 정합.
 */

import { describe, it, expect } from 'vitest';
import overlay from '../../../extensions/notification_tab_core.json';
import footer from '../../../extensions/notification_row_footer.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, type AnyNode } from './helpers';

const overlayRoot = { children: (overlay as { modals?: AnyNode[] }).modals ?? [] } as AnyNode;
const bannerRoot = {
    children: ((overlay as { injections?: Array<{ components?: AnyNode[] }> }).injections ?? []).flatMap((i) => i.components ?? []),
} as AnyNode;
const footerRoot = { children: (footer as { components?: AnyNode[] }).components ?? [] } as AnyNode;

/** overlay 텍스트에서 $t:key 및 $t('key') 형태의 플러그인 i18n 키를 모두 수집한다. */
const collectPluginKeys = (json: unknown): string[] => {
    const text = JSON.stringify(json);
    const prefixed = text.match(/\$t:sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+/g) ?? [];
    const called = text.match(/\$t\('sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+'\)/g) ?? [];
    return Array.from(new Set([
        ...prefixed.map((m) => m.replace('$t:', '')),
        ...called.map((m) => m.replace(/^\$t\('/, '').replace(/'\)$/, '')),
    ]));
};

describe('binding UI — 파일 분리(Overlay vs ExtensionPoint)', () => {
    it('overlay 는 target_layout=admin_settings 이고 extension_point 키가 없다', () => {
        expect((overlay as { target_layout?: string }).target_layout).toBe('admin_settings');
        expect((overlay as Record<string, unknown>).extension_point).toBeUndefined();
    });

    it('footer 는 extension_point=notification_definition_row_footer 이고 target_layout 이 없다', () => {
        expect((footer as { extension_point?: string }).extension_point).toBe('notification_definition_row_footer');
        expect((footer as Record<string, unknown>).target_layout).toBeUndefined();
    });

    it('overlay 는 연결 맵·승인 템플릿 데이터소스를 등록한다', () => {
        const ids = ((overlay as { data_sources?: Array<{ id: string }> }).data_sources ?? []).map((d) => d.id);
        expect(ids).toContain('bizppurioBindings');
        expect(ids).toContain('bizppurioApprovedTemplates');
    });

    it('승인 템플릿 데이터소스는 auto_fetch:false 이고 422 를 suppress 한다(전 탭 에러 방지)', () => {
        const ds = ((overlay as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? [])
            .find((d) => d.id === 'bizppurioApprovedTemplates');
        expect(ds?.auto_fetch).toBe(false);
        expect(JSON.stringify(ds?.errorHandling)).toContain('suppress');
    });
});

describe('binding UI — 상태 배너 + 안내 박스', () => {
    it('배너는 sms·alimtalk 탭에서 문제(readiness 미충족 / test_mode)일 때만 노출된다', () => {
        const banner = findById(bannerRoot, 'bizppurio_status_banner');
        expect(banner).toBeTruthy();
        const cond = (banner as { if?: string }).if ?? '';
        expect(cond).toContain("'sms'");
        expect(cond).toContain("'alimtalk'");
        expect(cond).toContain('readiness?.ready === false');
        expect(cond).toContain('is_test_mode === true');
    });

    it('빨강(설정 미완료)·노랑(검수 모드) 배너가 동시에 뜰 때 간격이 있다(회귀: 두 배너가 붙어 보이던 문제)', () => {
        const banner = findById(bannerRoot, 'bizppurio_status_banner');
        const className = (banner as { props?: { className?: string } }).props?.className ?? '';
        expect(className).toMatch(/space-y-\d/);
    });

    it('readiness 미충족 배너에 설정하기 이동 버튼이 있다', () => {
        const raw = JSON.stringify(findById(bannerRoot, 'bizppurio_banner_not_ready'));
        expect(raw).toContain('banner.not_ready');
        expect(raw).toContain('banner.setup_action');
        expect(raw).toContain('/admin/plugins/sirsoft-message_bizppurio/settings');
    });

    it('검수 모드 배너는 readiness 충족 여부와 무관하게 is_test_mode 만으로 노출된다', () => {
        // readiness 실패(예: 알림톡 API 키 미설정) + 검수 모드가 동시에 참인 상황에서도
        // 검수 안내가 가려지면 안 된다(회귀: 과거 readiness?.ready !== false 조건이 배너를 숨겼음).
        const banner = findById(bannerRoot, 'bizppurio_banner_test_mode');
        expect(banner).toBeTruthy();
        const cond = (banner as { if?: string }).if ?? '';
        expect(cond).not.toContain('readiness');
        expect(cond).toContain('is_test_mode === true');
    });

    it('알림톡 탭 상시 안내 박스가 있다(무엇을 하는 화면인지)', () => {
        const guide = findById(bannerRoot, 'bizppurio_alimtalk_guide');
        expect(guide).toBeTruthy();
        expect((guide as { if?: string }).if).toContain("=== 'alimtalk'");
        expect(JSON.stringify(guide)).toContain('binding.list_guide');
    });
});

describe('binding UI — 행 연결(extension_point)', () => {
    it('행 연결 UI 는 channel === alimtalk 일 때만 노출된다', () => {
        const row = findById(footerRoot, 'bizppurio_row_binding');
        expect(row).toBeTruthy();
        expect((row as { if?: string }).if).toContain("extensionPointProps.activeChannel === 'alimtalk'");
    });

    it('연결 상태를 bizppurioBindings 에서 def.type 으로 읽어 표시한다(연결됨/미연결)', () => {
        const raw = JSON.stringify(findById(footerRoot, 'bizppurio_row_binding'));
        expect(raw).toContain('bizppurioBindings?.data?.bindings?.[extensionPointProps.definition?.type]');
        expect(raw).toContain('binding.unbound');
        expect(raw).toContain('binding.btn_connect');
        expect(raw).toContain('binding.btn_change');
    });

    it('[연결] 클릭 시 모달 상태를 seed 하고 우리 연결 모달을 연다', () => {
        const raw = JSON.stringify(findById(footerRoot, 'bizppurio_row_binding'));
        expect(raw).toContain('bizppurio_binding_modal');
        expect(raw).toContain('"openModal"');
        expect(raw).toContain('modal_bizppurio_binding');
    });

    it('연결된 카카오 템플릿이 소실(is_unavailable)이면 빨간 경고 배지를 표시한다(결함 2)', () => {
        const raw = JSON.stringify(findById(footerRoot, 'bizppurio_row_binding'));
        // 연결됨(template_code 있음) + is_unavailable === true 일 때만 경고
        expect(raw).toContain('is_unavailable === true');
        expect(raw).toContain('binding.unavailable');
        // 소실 경고는 red 배지로 표시(연결됨 초록과 구분)
        expect(raw).toContain('bg-red-100');
    });

    it('모달 열기(openModal)가 승인 템플릿 조회(refetch)보다 먼저 실행된다(조회 실패가 모달 표시를 막지 않도록)', () => {
        const row = findById(footerRoot, 'bizppurio_row_binding');
        const raw = JSON.stringify(row);
        const openIdx = raw.indexOf('"openModal"');
        const refetchIdx = raw.indexOf('bizppurioApprovedTemplates');
        expect(openIdx).toBeGreaterThan(-1);
        expect(refetchIdx).toBeGreaterThan(-1);
        expect(openIdx).toBeLessThan(refetchIdx);
    });
});

describe('binding UI — 연결 모달(우리 소유, 코어 편집 모달과 분리)', () => {
    const modal = findById(overlayRoot, 'modal_bizppurio_binding');

    it('연결 전용 모달이 modals 로 등록된다', () => {
        expect(modal).toBeTruthy();
        expect((modal as { name?: string }).name).toBe('Modal');
    });

    it('안내 카드 + 연결 템플릿 드롭다운 + SMS 대체 토글 + 변수 안내를 담는다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain('binding.section_hint');
        expect(raw).toContain('binding.connected_template');
        expect(raw).toContain('binding.fallback_sms');
        expect(raw).toContain('binding.variables_hint');
    });

    it('SMS 대체 토글은 연결 템플릿이 없으면 비활성이다', () => {
        const raw = JSON.stringify(findById(overlayRoot, 'bizppurio_binding_modal_body'));
        expect(raw).toContain('"disabled"');
        expect(raw).toContain("=== ''");
    });

    it('[저장] 은 우리 API store 로 저장하고 toast + 모달 닫힘 + 목록 갱신한다', () => {
        const raw = JSON.stringify(modal);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/notification-bindings');
        expect(raw).toContain('"method":"POST"');
        expect(raw).toContain('binding.saved');
        expect(raw).toContain('binding.save_error');
        expect(raw).toContain('"closeModal"');
        expect(raw).toContain('bizppurioBindings');
    });
});

describe('binding UI — 드롭다운 조회 실패 vs 0건 구분(결함 3)', () => {
    const modal = findById(overlayRoot, 'modal_bizppurio_binding');

    it('승인 템플릿 데이터소스 fallback 에 load_failed:true 마커가 있다', () => {
        const ds = ((overlay as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? [])
            .find((d) => d.id === 'bizppurioApprovedTemplates');
        const fallback = (ds?.fallback as { data?: Record<string, unknown> })?.data ?? {};
        // 조회 실패 시 이 마커가 상태에 실려 '0건'과 구분된다. 정상 응답에는 이 필드가 없다.
        expect(fallback.load_failed).toBe(true);
        expect(Array.isArray(fallback.templates)).toBe(true);
        expect((fallback.templates as unknown[]).length).toBe(0);
    });

    it('드롭다운이 비었을 때 조회 실패(load_failed)면 설정 확인 문구를 노출한다', () => {
        const raw = JSON.stringify(modal);
        // 조회 실패 분기: length===0 && load_failed===true → templates_load_failed
        expect(raw).toContain('binding.templates_load_failed');
        expect(raw).toContain('load_failed === true');
    });

    it('드롭다운이 비었을 때 조회 정상(0건)이면 승인 템플릿 없음 문구를 노출한다', () => {
        const raw = JSON.stringify(modal);
        // 0건 분기: length===0 && !load_failed → no_approved_templates
        expect(raw).toContain('binding.no_approved_templates');
        expect(raw).toContain('!(bizppurioApprovedTemplates?.data?.load_failed)');
    });

    it('두 문구는 상호배타 조건이라 동시에 뜨지 않는다(조회실패=빨강 / 0건=amber)', () => {
        const raw = JSON.stringify(modal);
        // 조회 실패 문구는 red, 0건 문구는 amber 로 시각 구분
        expect(raw).toContain('text-red-600');
        expect(raw).toContain('text-amber-600');
    });
});

describe('binding UI — 코어 무오염 + i18n', () => {
    it('overlay·footer 어디에도 코어 편집 모달 저장 body(notification-templates PUT)를 건드리지 않는다', () => {
        const all = JSON.stringify(overlay) + JSON.stringify(footer);
        expect(all).not.toContain('/api/admin/notification-templates/');
        expect(all).not.toContain('notification_template_form_modal');
    });

    it('참조하는 모든 플러그인 i18n 키가 ko·en 에 존재한다', () => {
        const keys = [...collectPluginKeys(overlay), ...collectPluginKeys(footer)];
        expect(keys.length).toBeGreaterThan(0);
        const resolve = (root: unknown, path: string): unknown =>
            path.split('.').slice(1).reduce<unknown>((acc, seg) => (acc as Record<string, unknown>)?.[seg], root);
        for (const key of Array.from(new Set(keys))) {
            expect(resolve(ko, key), `ko 누락: ${key}`).toBeTruthy();
            expect(resolve(en, key), `en 누락: ${key}`).toBeTruthy();
        }
    });
});
