// e2e:allow 게시판·이커머스 배너/버튼/모달 노출은 Chrome MCP 로 실브라우저 확인·수정까지 완료
// (2026-07-28). 정식 E2E는 비즈뿌리오 발송 인프라 의존이 커서 별도 계획에서 다룸(코어와 동일 사유).
/**
 * 게시판·이커머스 알림 설정 알림톡 탭 연동 UI 구조 검증 (이슈 #28 후속)
 *
 * 배경: notification_tab_core.json(target_layout=admin_settings) + notification_row_footer.json
 * (extension_point, 전역 매칭)은 코어 알림 설정 화면에만 연동 버튼·배너를 노출했다.
 * extension_point 는 이름만 같으면 어느 레이아웃에서도 매칭되지만, target_id 기반 overlay
 * injection 은 레이아웃별로 독립이라 게시판·이커머스는 배너/안내박스/연결모달이 뜨지 않았다.
 *
 * notification_tab_board.json / notification_tab_ecommerce.json 을 신설해
 * target_layout=admin_board_settings / admin_ecommerce_settings 로 각각 등록했다.
 * notification_row_footer.json 은 그대로(전역 매칭)이므로 재사용된다.
 */

import { describe, it, expect } from 'vitest';
import boardOverlay from '../../../extensions/notification_tab_board.json';
import ecommerceOverlay from '../../../extensions/notification_tab_ecommerce.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, type AnyNode } from './helpers';

type OverlayFixture = {
    label: string;
    overlay: typeof boardOverlay;
    targetLayout: string;
    targetId: string;
    bannerId: string;
    notReadyId: string;
    testModeId: string;
    guideId: string;
    modalBodyId: string;
};

const FIXTURES: OverlayFixture[] = [
    {
        label: '게시판',
        overlay: boardOverlay,
        targetLayout: 'admin_board_settings',
        targetId: 'board_notif_channel_content',
        bannerId: 'bizppurio_board_status_banner',
        notReadyId: 'bizppurio_board_banner_not_ready',
        testModeId: 'bizppurio_board_banner_test_mode',
        guideId: 'bizppurio_board_alimtalk_guide',
        modalBodyId: 'bizppurio_board_binding_modal_body',
    },
    {
        label: '이커머스',
        overlay: ecommerceOverlay,
        targetLayout: 'admin_ecommerce_settings',
        targetId: 'ecommerce_notif_channel_content',
        bannerId: 'bizppurio_ecommerce_status_banner',
        notReadyId: 'bizppurio_ecommerce_banner_not_ready',
        testModeId: 'bizppurio_ecommerce_banner_test_mode',
        guideId: 'bizppurio_ecommerce_alimtalk_guide',
        modalBodyId: 'bizppurio_ecommerce_binding_modal_body',
    },
];

const collectPluginKeys = (json: unknown): string[] => {
    const text = JSON.stringify(json);
    const prefixed = text.match(/\$t:sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+/g) ?? [];
    const called = text.match(/\$t\('sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+'\)/g) ?? [];
    return Array.from(new Set([
        ...prefixed.map((m) => m.replace('$t:', '')),
        ...called.map((m) => m.replace(/^\$t\('/, '').replace(/'\)$/, '')),
    ]));
};

describe.each(FIXTURES)('$label 알림톡 연동 overlay', ({
    overlay, targetLayout, targetId, bannerId, notReadyId, testModeId, guideId, modalBodyId,
}) => {
    const injectionRoot = {
        children: ((overlay as { injections?: Array<{ components?: AnyNode[] }> }).injections ?? [])
            .flatMap((i) => i.components ?? []),
    } as AnyNode;
    const modalRoot = { children: (overlay as { modals?: AnyNode[] }).modals ?? [] } as AnyNode;

    it(`target_layout=${targetLayout} 이고 extension_point 키가 없다(overlay 전용)`, () => {
        expect((overlay as { target_layout?: string }).target_layout).toBe(targetLayout);
        expect((overlay as Record<string, unknown>).extension_point).toBeUndefined();
    });

    it(`배너·안내박스는 target_id=${targetId} 에 prepend_child 로 주입된다`, () => {
        const injections = (overlay as { injections?: Array<Record<string, unknown>> }).injections ?? [];
        expect(injections).toHaveLength(1);
        expect(injections[0].target_id).toBe(targetId);
        expect(injections[0].position).toBe('prepend_child');
    });

    it('연결 맵·승인 템플릿 데이터소스를 등록한다(코어와 동일 endpoint)', () => {
        const ids = ((overlay as { data_sources?: Array<{ id: string }> }).data_sources ?? []).map((d) => d.id);
        expect(ids).toContain('bizppurioBindings');
        expect(ids).toContain('bizppurioApprovedTemplates');
    });

    it('상태 배너는 sms·alimtalk 탭에서 문제(readiness 미충족 / test_mode)일 때만 노출된다', () => {
        const banner = findById(injectionRoot, bannerId);
        expect(banner).toBeTruthy();
        const cond = (banner as { if?: string }).if ?? '';
        expect(cond).toContain("'sms'");
        expect(cond).toContain("'alimtalk'");
        expect(cond).toContain('readiness?.ready === false');
        expect(cond).toContain('is_test_mode === true');
    });

    it('readiness 미충족 배너에 설정하기 이동 버튼이 있다', () => {
        const raw = JSON.stringify(findById(injectionRoot, notReadyId));
        expect(raw).toContain('banner.not_ready');
        expect(raw).toContain('banner.setup_action');
        expect(raw).toContain('/admin/plugins/sirsoft-message_bizppurio/settings');
    });

    it('검수 모드 배너는 readiness 와 무관하게 is_test_mode 만으로 노출된다', () => {
        const banner = findById(injectionRoot, testModeId);
        expect(banner).toBeTruthy();
        const cond = (banner as { if?: string }).if ?? '';
        expect(cond).not.toContain('readiness');
        expect(cond).toContain('is_test_mode === true');
    });

    it('알림톡 탭 상시 안내 박스가 있다', () => {
        const guide = findById(injectionRoot, guideId);
        expect(guide).toBeTruthy();
        expect((guide as { if?: string }).if).toContain("=== 'alimtalk'");
        expect(JSON.stringify(guide)).toContain('binding.list_guide');
    });

    it('연결 전용 모달(modal_bizppurio_binding)이 modals 로 등록된다', () => {
        const modal = findById(modalRoot, 'modal_bizppurio_binding');
        expect(modal).toBeTruthy();
        expect((modal as { name?: string }).name).toBe('Modal');
    });

    it('[저장] 은 우리 API store 로 저장하고 toast + 모달 닫힘 + 목록 갱신한다', () => {
        const modal = findById(modalRoot, 'modal_bizppurio_binding');
        const raw = JSON.stringify(modal);
        expect(raw).toContain('/api/plugins/sirsoft-message_bizppurio/admin/notification-bindings');
        expect(raw).toContain('"method":"POST"');
        expect(raw).toContain('binding.saved');
        expect(raw).toContain('"closeModal"');
        expect(raw).toContain('bizppurioBindings');
    });

    it('연결 템플릿이 없으면 SMS 대체 토글이 비활성이다(코어와 동일 규칙)', () => {
        const raw = JSON.stringify(findById(modalRoot, modalBodyId));
        expect(raw).toContain('"disabled"');
        expect(raw).toContain("=== ''");
    });

    it('overlay 는 코어 편집 모달 저장 body(notification-templates PUT)를 건드리지 않는다', () => {
        const raw = JSON.stringify(overlay);
        expect(raw).not.toContain('/api/admin/notification-templates/');
        expect(raw).not.toContain('notification_template_form_modal');
    });

    it('참조하는 모든 플러그인 i18n 키가 ko·en 에 존재한다', () => {
        const keys = collectPluginKeys(overlay);
        expect(keys.length).toBeGreaterThan(0);
        const resolve = (root: unknown, path: string): unknown =>
            path.split('.').slice(1).reduce<unknown>((acc, seg) => (acc as Record<string, unknown>)?.[seg], root);
        for (const key of Array.from(new Set(keys))) {
            expect(resolve(ko, key), `ko 누락: ${key}`).toBeTruthy();
            expect(resolve(en, key), `en 누락: ${key}`).toBeTruthy();
        }
    });
});
