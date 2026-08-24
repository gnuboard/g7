// e2e:allow 3면 패리티는 정규화 문자열 대조 Vitest 로 잠근다(#597 재작성) — 라이프사이클 전이
// 브라우저 흐름은 코어 면 대표로 tests/Playwright/specs/admin/template-lifecycle.spec.ts 가 담당.
/**
 * 게시판·이커머스 알림 설정 '비즈뿌리오' 통합 탭 오버레이 — 3면 패리티 검증 (#597)
 *
 * 시나리오 매니페스트 exclusions 근거: "3면은 동일 오버레이 패턴(파일 diff 는 id·target 뿐)
 * — 라이프사이클 전이는 코어 면으로 대표하고 면 axis 는 렌더 패리티(Vitest)로 잠근다".
 *
 * 검증 방식: comment 필드 제거 후 JSON 문자열에서 면 고유 토큰(target_layout·target_id·
 * 컴포넌트 id 접두)만 코어 형으로 치환하면 코어 오버레이와 완전 동일해야 한다.
 * 반대로 데이터소스 id 는 치환 없이도 3면 동일해야 한다 — notification_row_footer.json 과
 * notification_template_form_sections.json(전역 매칭, 3면 공유 1본)이 이 이름들을 공유 참조하기 때문이다.
 * 편집 UI(작성 폼·SMS·저장)는 코어 [편집] 모달의 extension_point 에 주입되는 공유 1본이라
 * 면 패리티 대상이 아니다(PO 결정 2026-08-23).
  *
 * 세 오버레이가 전문 동일하다는 것이 board/ecommerce 면의 화면 효과를 떠받치는 근거다 —
 * core 면에서 단언한 것들(탭 통합·행 하단 UI·업로드 잠금·SMS 언어 탭)이 두 면에도 있다는
 * 보장은 이 패리티뿐이고, 그래서 이 파일이 사라지면 두 면은 미측정으로 남는다.
 *
 * @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, row_footer_shows_status_summary_only
*/

import { describe, it, expect } from 'vitest';
import coreOverlay from '../../../extensions/notification_tab_core.json';
import boardOverlay from '../../../extensions/notification_tab_board.json';
import ecommerceOverlay from '../../../extensions/notification_tab_ecommerce.json';
import { type AnyNode } from './helpers';

type OverlayFixture = {
    label: string;
    overlay: typeof boardOverlay;
    targetLayout: string;
    targetId: string;
    idPrefix: string;
};

const FIXTURES: OverlayFixture[] = [
    {
        label: '게시판',
        overlay: boardOverlay,
        targetLayout: 'admin_board_settings',
        targetId: 'board_notif_channel_content',
        idPrefix: 'bizppurio_board_',
    },
    {
        label: '이커머스',
        overlay: ecommerceOverlay,
        targetLayout: 'admin_ecommerce_settings',
        targetId: 'ecommerce_notif_channel_content',
        idPrefix: 'bizppurio_ecommerce_',
    },
];

/** comment 필드를 재귀 제거한다(설명문은 면마다 달라도 되는 유일한 자유 축). */
const stripComments = (node: unknown): unknown => {
    if (Array.isArray(node)) return node.map(stripComments);
    if (node && typeof node === 'object') {
        const out: Record<string, unknown> = {};
        for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
            if (k === 'comment') continue;
            out[k] = stripComments(v);
        }
        return out;
    }
    return node;
};

const normalizedCore = JSON.stringify(stripComments(coreOverlay));

/** 3면 공유 이름(치환 없이 동일해야 하는 축) */
const SHARED_DATA_SOURCE_IDS = ['bizppurioTemplates', 'bizppurioCategories', 'bizppurioProfiles'];
// 모달·전역 상태(bz_*)는 오버레이가 아니라 3면 공유 1본(notification_template_form_sections.json /
// _footer_actions.json)이 소유한다(PO 결정 2026-08-23 — 편집 모달 통합). 오버레이는 모달을 등록하지 않는다.

describe.each(FIXTURES)('$label 오버레이 — 3면 패리티', ({ label, overlay, targetLayout, targetId, idPrefix }) => {
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

    it('면 고유 토큰(target_layout/target_id/id 접두)만 치환하면 코어 오버레이와 완전 동일하다(comment 제외)', () => {
        const normalized = JSON.stringify(stripComments(overlay))
            .split(targetLayout).join('admin_settings')
            .split(targetId).join('notif_channel_content')
            .split(idPrefix).join('bizppurio_');
        expect(normalized, `${label} 오버레이가 코어와 구조 불일치`).toBe(normalizedCore);
    });

    it('데이터소스 id 3종이 코어와 완전 동일하다(순서 포함 — 행 footer 공유 참조)', () => {
        const ids = ((overlay as { data_sources?: Array<{ id: string }> }).data_sources ?? []).map((d) => d.id);
        expect(ids).toEqual(SHARED_DATA_SOURCE_IDS);
    });

    it('오버레이는 모달을 등록하지 않고, 면 전용 전역 상태(bz_board_* 등)도 만들지 않는다', () => {
        expect((overlay as { modals?: AnyNode[] }).modals, `${label} 오버레이 modals`).toBeUndefined();
        const raw = JSON.stringify(overlay);
        expect(raw).not.toContain(`bz_${idPrefix.replace('bizppurio_', '')}`);
        expect(raw).not.toContain('openModal');
    });
});

describe('3면 패리티 — 공유 축 교차 검증', () => {
    it('코어 오버레이도 동일한 공유 데이터소스 id 집합을 갖고 모달은 없다(패리티 기준점 고정)', () => {
        const dsIds = ((coreOverlay as { data_sources?: Array<{ id: string }> }).data_sources ?? []).map((d) => d.id);
        expect(dsIds).toEqual(SHARED_DATA_SOURCE_IDS);
        expect((coreOverlay as { modals?: AnyNode[] }).modals).toBeUndefined();
    });

    it('세 오버레이의 init_actions(탭 진입 요약 맵 조회)가 comment 제외 완전 동일하다', () => {
        const pick = (o: unknown) => JSON.stringify(stripComments((o as { init_actions?: unknown }).init_actions ?? []));
        expect(pick(boardOverlay)).toBe(pick(coreOverlay));
        expect(pick(ecommerceOverlay)).toBe(pick(coreOverlay));
    });
});
