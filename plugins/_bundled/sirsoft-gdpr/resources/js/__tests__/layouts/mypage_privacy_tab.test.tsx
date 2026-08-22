/**
 * extensions/mypage_privacy_tab.json 구조 검증
 *
 * 마이페이지 프로필 페이지에 주입되는 GDPR 동의 관리 카드 (F-04).
 * 동의 매트릭스 + 철회 모달만 검증.
 */

import { describe, it, expect } from 'vitest';
import extension from '../../../extensions/mypage_privacy_tab.json';
import { findById, type AnyNode, serializeForSearch } from './helpers';

interface Modal extends AnyNode {
    id?: string;
}

interface Extension {
    target_layout?: string;
    priority?: number;
    data_sources?: AnyNode[];
    modals?: Modal[];
    injections?: Array<{
        target_id?: string;
        position?: string;
        components?: AnyNode[];
    }>;
}

describe('extensions/mypage_privacy_tab.json — 마이페이지 GDPR 동의 매트릭스 카드', () => {
    const root = extension as unknown as Extension;
    const card = (root.injections?.[0]?.components ?? []).find(
        (c) => (c as { id?: string }).id === 'gdpr_mypage_privacy_card'
    ) as AnyNode | undefined;

    describe('Extension 메타데이터', () => {
        it('target_layout 이 mypage/profile 이다', () => {
            expect(root.target_layout).toBe('mypage/profile');
        });

        it('priority 는 90 (sirsoft-marketing 의 100 보다 앞서 처리되어 마케팅 카드 뒤에 배치)', () => {
            expect(root.priority).toBe(90);
        });
    });

    describe('데이터 소스', () => {
        it('데이터소스 3종 포함 (gdprMyConsents / gdprMeConsents / gdprPublicSettings)', () => {
            // gdprPublicSettings 는 GDPR Art.13(1)(a)(f) 의무 고지를 카드 헤더 메타 라인으로 보강하기 위함.
            const ids = (root.data_sources ?? []).map((d) => d.id);
            expect(ids).toContain('gdprMyConsents');
            expect(ids).toContain('gdprMeConsents');
            expect(ids).toContain('gdprPublicSettings');
        });

        it('gdprPublicSettings 는 게스트 응답이 가능해야 한다 (auth_required:false)', () => {
            // 마이페이지 카드 자체는 회원 한정이지만, 데이터소스 자체는 공개 API. /api/plugins/sirsoft-gdpr/settings.
            const ds = (root.data_sources ?? []).find((d) => d.id === 'gdprPublicSettings');
            expect((ds as { auth_required?: boolean } | undefined)?.auth_required).toBe(false);
            expect((ds as { endpoint?: string } | undefined)?.endpoint).toBe('/api/plugins/sirsoft-gdpr/settings');
        });

        it('제거된 gdprMyRequests 데이터소스는 존재하지 않는다', () => {
            const ids = (root.data_sources ?? []).map((d) => d.id);
            expect(ids).not.toContain('gdprMyRequests');
        });

        it('마이페이지 카드 노출 토글 제거 — gdprPublicSettingsForMypage 데이터소스 미사용 (GDPR Art.7(3) 대칭성 의무: 카드는 플러그인 활성 시 항상 노출)', () => {
            const ids = (root.data_sources ?? []).map((d) => d.id);
            expect(ids).not.toContain('gdprPublicSettingsForMypage');
        });
    });

    describe('운영 주체·저장 위치 메타 라인 (GDPR Art.13(1)(a)(f))', () => {
        const meta = findById(card ?? null, 'gdpr_card_operator_meta');

        it('gdpr_card_operator_meta 메타 라인이 카드 안에 존재한다', () => {
            // GDPR Art.13(1)(a) 컨트롤러 신원 + Art.13(1)(f) 저장 위치 의무 고지를 마이페이지에서도
            // 접근 가능하도록 보강. EDPB 권고: 동의 1차 화면 외에도 사용자가 자기 정보의 행방을
            // 언제든 재확인할 수 있어야 함.
            expect(meta).toBeTruthy();
        });

        it('카드 헤더(타이틀 + 구분선) 다음 형제 위치에 배치 (헤더 내부 X)', () => {
            // UX 결정: 메타 라인은 H2 타이틀 + 구분선 다음에 자연어 문장으로 노출.
            // 헤더 내부(border-b 위)에 두면 타이틀과 같은 영역으로 보여 시각적 혼잡 → 헤더 외부로 분리.
            const header = findById(card ?? null, 'gdpr_card_header');
            const headerChildren = (header?.children ?? []) as AnyNode[];
            const metaInsideHeader = headerChildren.find((c) => c.id === 'gdpr_card_operator_meta');
            expect(metaInsideHeader).toBeUndefined();

            // card.children 에서 헤더 다음 형제로 존재해야 한다.
            const cardChildren = (card?.children ?? []) as AnyNode[];
            const headerIdx = cardChildren.findIndex((c) => c.id === 'gdpr_card_header');
            const metaIdx = cardChildren.findIndex((c) => c.id === 'gdpr_card_operator_meta');
            expect(headerIdx).toBeGreaterThanOrEqual(0);
            expect(metaIdx).toBe(headerIdx + 1);
        });

        it('두 값 중 하나라도 있으면 노출, 둘 다 비면 블록 전체 숨김', () => {
            expect(meta?.if).toBe(
                '{{!!gdprPublicSettings?.data?.legal_entity_name || !!gdprPublicSettings?.data?.data_storage_location}}'
            );
        });

        it('3-case 자연어 분기 — 한국어 조사 회피를 위해 둘 다 / 운영자만 / 저장위치만 각각 별도 P 태그', () => {
            const children = (meta?.children ?? []) as AnyNode[];
            const both = children.find((c) =>
                String(c.text ?? '').includes('operator_and_storage_meta')
            );
            const operatorOnly = children.find((c) =>
                String(c.text ?? '').includes('operator_only_meta')
            );
            const storageOnly = children.find((c) =>
                String(c.text ?? '').includes('storage_only_meta')
            );

            expect(both?.if).toBe(
                '{{!!gdprPublicSettings?.data?.legal_entity_name && !!gdprPublicSettings?.data?.data_storage_location}}'
            );
            expect(operatorOnly?.if).toBe(
                '{{!!gdprPublicSettings?.data?.legal_entity_name && !gdprPublicSettings?.data?.data_storage_location}}'
            );
            expect(storageOnly?.if).toBe(
                '{{!gdprPublicSettings?.data?.legal_entity_name && !!gdprPublicSettings?.data?.data_storage_location}}'
            );

            // 자연어 한 줄은 블록 단위 P
            expect(both?.name).toBe('P');
            expect(operatorOnly?.name).toBe('P');
            expect(storageOnly?.name).toBe('P');
        });

        it('가운데 점(·) 구분자 미사용 (자연어 한 줄 패턴이라 구분자 불필요)', () => {
            // 회귀 가드: 이전 디자인은 가로 인라인 + · 구분자였으나, 설계 결정으로 자연어 한 줄로 재설계.
            const children = (meta?.children ?? []) as AnyNode[];
            const separator = children.find((c) => String(c.text ?? '') === '·');
            expect(separator).toBeUndefined();
        });

        it('다국어 키 + 토큰 보간 사용 (operator_and_storage / operator_only / storage_only)', () => {
            const text = serializeForSearch(meta);
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.operator_and_storage_meta|entity=');
            expect(text).toContain('|location=');
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.operator_only_meta|entity=');
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.storage_only_meta|location=');
        });

        it('시각적 무게 최소화 — 작은 보조 텍스트 (공통 Tailwind 코어 클래스)', () => {
            // 이슈 #430 — 이 카드는 user 페이지(sirsoft-basic)에 렌더되므로 admin 전용 시맨틱(.text-tertiary)은
            // CSS 미해석. 동등한 공통 Tailwind 코어 클래스로 치환 (회귀 수정).
            const className = (meta?.props as { className?: string })?.className ?? '';
            expect(className).toContain('text-xs');
            expect(className).toContain('text-gray-500');
            expect(className).not.toContain('text-tertiary');
        });
    });

    describe('모달', () => {
        it('revoke_confirm_modal 이 modals 배열에 포함', () => {
            const modal = (root.modals ?? []).find((m) => m.id === 'revoke_confirm_modal');
            expect(modal).toBeTruthy();
            expect(modal?.name).toBe('Modal');
        });

        it('이슈 #430 — grant_confirm_modal(동의 확인 모달)이 modals 배열에 포함', () => {
            const modal = (root.modals ?? []).find((m) => m.id === 'grant_confirm_modal');
            expect(modal).toBeTruthy();
            expect(modal?.name).toBe('Modal');
            const text = serializeForSearch(modal);
            // 동의 확인 문구 + grant API + 대상 _global.grantTargetKey
            expect(text).toContain('mypage.privacy.grant_confirm_message');
            expect(text).toContain('"target": "/api/plugins/sirsoft-gdpr/consent/grant"');
            expect(text).toContain('_global.grantTargetKey');
            // 확인 후 refetch + syncConsent + closeModal
            expect(text).toContain('"dataSourceId": "gdprMeConsents"');
            expect(text).toContain('"handler": "sirsoft-gdpr.syncConsent"');
            expect(text).toContain('"handler": "closeModal"');
        });

        it('제거된 데이터 요청·자가 취소 모달은 존재하지 않는다', () => {
            const ids = (root.modals ?? []).map((m) => m.id);
            expect(ids).not.toContain('gdpr_data_request_modal');
            expect(ids).not.toContain('gdpr_cancel_confirm_modal');
        });
    });

    describe('주입 위치 (Injection)', () => {
        it('profile_view_bio_card 에 append 로 주입', () => {
            const inj = root.injections?.[0];
            expect(inj?.target_id).toBe('profile_view_bio_card');
            expect(inj?.position).toBe('append');
        });

        it('주입 컴포넌트의 첫 컴포넌트가 gdpr_mypage_privacy_card', () => {
            expect(card).toBeTruthy();
        });
    });

    describe('카드 표시 조건', () => {
        it('데이터 기반 가드 — 회원에게 동의 데이터가 있을 때만 노출. Art.7(3) 보장 (기존 동의자 철회권) + UX 노이즈 제거 (빈 카드 미노출)', () => {
            const guard = (card as { if?: string }).if;
            expect(guard).toBe(
                '{{(gdprMyConsents?.data?.length ?? 0) > 0 || (gdprMeConsents?.data?.consents?.length ?? 0) > 0}}'
            );
        });
    });

    describe('내 동의 현황 섹션 (gdpr_section_consents)', () => {
        const section = card ? findById(card, 'gdpr_section_consents') : null;

        it('섹션이 존재한다', () => {
            expect(section).toBeTruthy();
        });

        it('제거된 「내 요청 내역」 섹션 (gdpr_section_my_requests) 미존재', () => {
            expect(card ? findById(card, 'gdpr_section_my_requests') : null).toBeNull();
        });

        it('철회 버튼 클릭 시 _global 에 키+라벨+설명 세팅 후 revoke_confirm_modal 오픈', () => {
            const text = serializeForSearch(section);
            expect(text).toContain('"target": "revoke_confirm_modal"');
            expect(text).toContain('"revokeTargetKey": "{{consent?.consent_key}}"');
            // raw key (cookie_functional 등) 대신 사용자 친화 description 을 모달에 표시하기 위해
            // 행 핸들러에서 consent_description 을 _global 에 세팅한다.
            expect(text).toContain('"revokeTargetDescription": "{{consent?.consent_description ?? \'\'}}"');
            // 이슈 #430 — 철회 모달에서 마지막 동의일을 보여주기 위해 status_at_formatted 를 _global 로 전달.
            expect(text).toContain('"revokeTargetDate": "{{consent?.status_at_formatted ?? \'\'}}"');
        });

        it('동의 항목 컬럼이 라벨 우선 표시 (consent_label fallback consent_key)', () => {
            const text = serializeForSearch(section);
            expect(text).toContain('consent?.consent_label ?? consent?.consent_key');
        });

        it('동의 항목 컬럼에 카탈로그 description 1줄 노출 (회원이 영문 식별자 대신 카테고리 의미를 즉시 인지)', () => {
            const text = serializeForSearch(section);
            // GdprUserConsentResource 가 consent_description 필드 노출 + layout 이 그 값을 보조 Span 으로 표시.
            expect(text).toContain('consent?.consent_description');
            // raw key 보조 표시 (`font-mono`) 는 회원 화면 노이즈로 제거됨 — description 으로 교체된 회귀 가드.
            expect(text).not.toContain('font-mono break-all');
        });
    });

    describe('동의 철회 모달 (revoke_confirm_modal)', () => {
        const modal = (root.modals ?? []).find((m) => m.id === 'revoke_confirm_modal');

        it('모달 본문에 raw key (cookie_functional 등) 대신 사용자 친화 description 노출', () => {
            // 피드백 — raw key 는 사용자에게 무의미하여 모달 본문에서 제거.
            // 라벨 + description 2줄 구조로 변경됨.
            const modal = (root.modals ?? []).find((m) => m.id === 'revoke_confirm_modal');
            const text = serializeForSearch(modal);
            // description 바인딩이 모달 본문에 존재
            expect(text).toContain('_global.revokeTargetDescription');
            // 옛 raw key 표시 (font-mono break-all 클래스) 가 회귀하지 않음
            expect(text).not.toContain('font-mono break-all');
        });

        it('이슈 #430 — 모달에 철회 대상의 마지막 동의일 노출 (revokeTargetDate 있을 때만)', () => {
            const text = serializeForSearch(modal);
            // 사용자가 언제 동의한 것을 철회하는지 인지하도록 마지막 동의일을 표시.
            expect(text).toContain('_global.revokeTargetDate');
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.revoke_target_consented_at|date=');
        });

        it('확인 버튼 → POST /consent/revoke + onSuccess 에 refetch + closeModal + toast', () => {
            const text = serializeForSearch(modal);
            expect(text).toContain('"target": "/api/plugins/sirsoft-gdpr/consent/revoke"');
            expect(text).toContain('"method": "POST"');
            expect(text).toContain('"dataSourceId": "gdprMeConsents"');
            expect(text).toContain('"handler": "closeModal"');
        });

        it('철회 대상 + 확인 버튼 disabled 조건 모두 _global 참조 (모달 컨텍스트 분리)', () => {
            const text = serializeForSearch(modal);
            expect(text).toContain('_global.revokeTargetKey');
        });

        it('철회 onSuccess 에서 sirsoft-gdpr.syncConsent 핸들러를 호출한다', () => {
            // 회귀 가드: 마이페이지 철회 후 functional 카테고리 cleanup 이 즉시 동작하려면
            // syncConsent 핸들러가 호출되어야 함 (storage/cookie interceptor config 갱신 +
            // functionalCleaner 호출). 누락 시 새로고침해야 cleanup 됨 (EDPB §117 즉시성 약화).
            // 배너 ([cookie_banner.test.tsx:261] — 3개 동의 버튼) 와 동일 패턴.
            const text = serializeForSearch(modal);
            expect(text).toContain('"handler": "sirsoft-gdpr.syncConsent"');
        });

        it('확인 버튼에 isRevoking 동안 spinner 아이콘 + "철회 중" 라벨 노출', () => {
            // 회귀 가드: 사용자가 클릭 후 "클릭이 먹혔는지" 의심하지 않도록 시각 피드백 제공.
            // admin 저장 버튼과 동일 패턴 (Icon spinner spin=true + 텍스트 분기).
            const text = serializeForSearch(modal);
            expect(text).toContain('"name": "spinner"');
            expect(text).toContain('"spin": true');
            expect(text).toContain('sirsoft-gdpr.consent.revoking');
        });
    });

    describe('토글 UI 구조 (이슈 #430 재설계 — 업계 표준 CMP 패턴)', () => {
        const section = card ? findById(card, 'gdpr_section_consents') : null;

        /**
         * @scenario entry=reject, subject=member, category=required
         * @effects mypage_status_date_line_per_row
         */
        it('옛 표 헤더 컬럼 방식은 미사용 (col_consented_at / col_action 헤더 없음)', () => {
            // 회귀 가드: 재설계로 표 헤더(col_consent_key/col_consented/col_consented_at/col_action) 방식은 제거.
            // 날짜는 컬럼이 아니라 각 행의 상태별 한 줄(status_at_formatted)로 노출한다.
            const text = serializeForSearch(section);
            expect(text).not.toContain('mypage.privacy.col_consented_at');
            expect(text).not.toContain('mypage.privacy.col_action');
        });

        /**
         * @scenario entry=reject, subject=member, category=optional
         * @effects mypage_status_date_line_consented_only
         */
        it('이슈 #430/#509 — 동의·철회 이력이 있는 항목만 날짜 줄 노출 (서버가 status_at_formatted 제공)', () => {
            // 프로젝트 결정(2026-07-20) + 이슈 #509 16번 갱신: 날짜는 "동의한 항목" + "철회 이력이 있는 항목".
            // 거부·신규 미설정은 서버가 status_at_formatted 를 null 로 내려주므로 날짜 줄이 렌더되지 않는다.
            // 프론트는 status_label && status_at_formatted 가 둘 다 있을 때만 날짜 줄을 그리며, 값 자체는
            // 서버가 상태별(동의일/철회일)로 계산해 내려주므로 프론트 조건식은 상태 무관 동일하게 유지된다.
            const text = serializeForSearch(section);
            expect(text).toContain('consent?.status_label');
            expect(text).toContain('consent?.status_at_formatted');
            // 날짜 줄은 둘 다 있을 때만 (거부/미설정은 null → 미노출, 철회는 이제 값이 채워짐)
            expect(text).toContain('!!consent?.status_label && !!consent?.status_at_formatted');
        });

        /**
         * @scenario entry=reject, subject=member, category=optional
         * @effects mypage_status_date_line_revoked_color_distinction
         */
        it('이슈 #509 16번 — 동의·철회 날짜 줄을 서로 다른 색으로 균형있게 강조 (동의=초록/철회=빨강, 배지·관리자 이력 화면과 동일 톤)', () => {
            // 철회만 강조되고 동의는 밋밋해 보인다는 사용성 피드백 반영 — 동의도 철회와 대등하게 강조한다.
            // status === 'revoked' 면 text-red-600/dark:text-red-400, 그 외(동의)는 text-green-600/dark:text-green-400.
            const text = serializeForSearch(section);
            expect(text).toContain("consent?.status === 'revoked'");
            expect(text).toContain('text-red-600');
            expect(text).toContain('dark:text-red-400');
            expect(text).toContain('text-green-600');
            expect(text).toContain('dark:text-green-400');
        });

        /**
         * @scenario entry=reject, subject=member, category=required
         * @effects mypage_required_shows_locked_button
         */
        it('필수 카테고리 — 상태 배지 + 잠금 버튼 (액션 없음, lock 아이콘 + not-allowed)', () => {
            const text = serializeForSearch(section);
            expect(text).toContain('consent?.is_required === true');
            // 상태 배지는 서버 status_badge_label 사용
            expect(text).toContain('consent?.status_badge_label');
            // 잠금 버튼: lock 아이콘 + not-allowed + locked_label
            expect(text).toContain('"name": "lock"');
            expect(text).toContain('cursor-not-allowed');
            expect(text).toContain('mypage.privacy.locked_label');
            // 필수 행 라벨 옆 required_label 배지도 유지
            expect(text).toContain('mypage.privacy.required_label');
        });

        /**
         * @scenario entry=reject, subject=member, category=optional
         * @effects mypage_optional_consented_shows_revoke_button
         */
        it('선택형 동의함 — 철회 버튼, 클릭 시 철회 확인 모달 (즉시 철회 아님)', () => {
            const text = serializeForSearch(section);
            expect(text).toContain('consent?.is_required !== true && consent?.is_consented === true');
            expect(text).toContain('mypage.privacy.revoke');
            // 클릭 시 철회 확인 모달 오픈 + 대상 키를 global 로 세팅
            expect(text).toContain('"target": "revoke_confirm_modal"');
            expect(text).toContain('"revokeTargetKey"');
        });

        /**
         * @scenario entry=reject, subject=member, category=optional
         * @effects mypage_optional_unset_shows_grant_button
         */
        it('선택형 거부함/미설정 — 동의 버튼, 클릭 시 동의 확인 모달 (즉시 grant 아님)', () => {
            const text = serializeForSearch(section);
            expect(text).toContain('consent?.is_required !== true && consent?.is_consented !== true');
            expect(text).toContain('mypage.privacy.grant');
            // 토글 즉시 grant 가 아니라 동의 확인 모달 경유 (grant_confirm_modal)
            expect(text).toContain('"target": "grant_confirm_modal"');
            expect(text).toContain('grantTargetKey');
        });

        it('이슈 #430 (3안) — 상태 배지는 라벨 줄에, 날짜 줄은 날짜+동의 순서 (배지↔날짜 동의 중복 제거)', () => {
            const text = serializeForSearch(section);
            // 배지가 라벨 옆 flex-wrap 줄에 위치 (라벨 label_suffix 와 같은 컨테이너)
            expect(text).toContain('items-center gap-2 flex-wrap');
            expect(text).toContain('consent?.status_badge_label');
            // 날짜 줄은 날짜가 먼저, 그 뒤에 동의 라벨 (동사 중복 회피 — 상태는 배지가 말함)
            const idxDate = text.indexOf('status_at_formatted');
            const idxLabelAfter = text.indexOf('status_label', idxDate);
            expect(idxDate).toBeGreaterThan(-1);
            expect(idxLabelAfter).toBeGreaterThan(idxDate);
        });

        it('「켜짐/꺼짐」 토글 표현 및 스위치 마크업이 부활하지 않는다 (회귀 가드)', () => {
            // 토글 제거 회귀 가드: user 템플릿 w-11 safelist 붕괴 + '켜짐/꺼짐' 표현 어색함으로 버튼 전환.
            const text = serializeForSearch(section);
            expect(text).not.toContain('mypage.privacy.toggle_on');
            expect(text).not.toContain('mypage.privacy.toggle_off');
            // 스위치 knob 마크업(translate-x-6 / translate-x-1 + rounded-full 스위치 트랙)이 없어야 함
            expect(text).not.toContain('h-6 w-11');
        });

        /**
         * @scenario entry=reject, subject=member, category=optional
         * @effects mypage_enable_all_bar_when_optional_off
         */
        it('동의 안 한 선택 항목이 있을 때만 「모두 동의」 바 노출 + POST /consent/cookie 로 일괄 동의', () => {
            const text = serializeForSearch(section);
            // 노출 조건: 선택형(is_required !== true) 중 미동의(is_consented !== true) 항목 존재
            expect(text).toContain("c?.is_required !== true && c?.is_consented !== true");
            expect(text).toContain('mypage.privacy.enable_all');
            expect(text).toContain('mypage.privacy.enable_all_hint');
            // 일괄 동의는 배너 「모두 동의」와 동일한 consent/cookie 경로 (필수 제외, prefixed key)
            expect(text).toContain('"target": "/api/plugins/sirsoft-gdpr/consent/cookie"');
            expect(text).toContain('c.consent_key');
            expect(text).toContain('mypage.privacy.enable_all_success');
        });

        it('grant/revoke onSuccess 에서 refetch + syncConsent 호출 (동의 즉시성)', () => {
            // 회귀 가드: 동의/철회 시 interceptor 즉시 갱신 — 모달의 sequence onSuccess 에 syncConsent 존재.
            const text = serializeForSearch(root);
            const matches = text.match(/"handler":\s*"sirsoft-gdpr\.syncConsent"/g) ?? [];
            expect(matches.length).toBeGreaterThanOrEqual(2);
        });

        it('제거된 amber 갱신 분기 / rejected_label 이 부활하지 않는다 (회귀 가드)', () => {
            const text = serializeForSearch(section);
            expect(text).not.toContain('mypage.privacy.rejected_label');
            expect(text).not.toContain('needs_renewal_this_item');
        });
    });

    describe('정책 갱신 amber 안내 박스 (마이페이지 카드 내 needs_renewal 안내)', () => {
        // 설계 결정 — 마이페이지 카드 안에 amber 박스 (gdpr_needs_renewal_banner) 유지.
        // 조건: gdprMeConsents.data.needs_renewal === true 일 때만 노출.
        // 본문: 현 정책 버전 안내 (#23 informed consent) + 필수 쿠키 면제 hint
        // 액션: 「전체 항목 다시 동의」 (POST /consent/renew-all)
        // 제거된 acknowledge-policy 흐름은 본 박스에 부활하지 않음.
        it('마이페이지 카드 안에 amber 박스 (gdpr_needs_renewal_banner) 가 존재한다', () => {
            const banner = card ? findById(card, 'gdpr_needs_renewal_banner') : null;
            expect(banner).not.toBeNull();
        });

        it('amber 박스는 gdprMeConsents.data.needs_renewal === true 조건으로만 노출된다', () => {
            const banner = card ? findById(card, 'gdpr_needs_renewal_banner') : null;
            expect(banner?.if).toBe('{{gdprMeConsents?.data?.needs_renewal === true}}');
        });

        it('amber 박스가 현 정책 버전 + 필수 쿠키 면제 hint 를 노출한다', () => {
            const banner = card ? findById(card, 'gdpr_needs_renewal_banner') : null;
            const text = serializeForSearch(banner);
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.needs_renewal_with_version');
            expect(text).toContain('current_policy_version');
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.needs_renewal_hint');
        });

        it('amber 박스의 「전체 항목 다시 동의」 버튼이 POST /consent/renew-all 을 호출한다', () => {
            const banner = card ? findById(card, 'gdpr_needs_renewal_banner') : null;
            const text = serializeForSearch(banner);
            expect(text).toContain('sirsoft-gdpr.mypage.privacy.btn_renew_all');
            expect(text).toContain('/api/plugins/sirsoft-gdpr/consent/renew-all');
            expect(text).toContain('renew_all_success');
        });

        it('renew-all onSuccess 에서 sirsoft-gdpr.syncConsent 핸들러를 호출한다', () => {
            // 회귀 가드: renew-all 직후 functional 동의가 갱신되거나 신규 카테고리 (cookie_functional)
            // 가 활성 동의 매트릭스에 추가되는 경우, interceptor config 와 blocker 캐시를
            // 동기화하지 않으면 새로고침 전까지 차단 상태가 유지됨 (동의 후 즉시 미반영 회귀).
            const banner = card ? findById(card, 'gdpr_needs_renewal_banner') : null;
            const text = serializeForSearch(banner);
            expect(text).toContain('"handler": "sirsoft-gdpr.syncConsent"');
        });

        it('「전체 다시 동의」 버튼에 isRenewingAll 동안 spinner + "갱신 중" 라벨 노출', () => {
            // 회귀 가드: 클릭 즉시 시각 피드백 + 중복 클릭 차단. admin 저장 버튼 패턴 재사용.
            const banner = card ? findById(card, 'gdpr_needs_renewal_banner') : null;
            const text = serializeForSearch(banner);
            expect(text).toContain('isRenewingAll');
            expect(text).toContain('"name": "spinner"');
            expect(text).toContain('sirsoft-gdpr.consent.renewing');
        });

        it('제거된 acknowledge-policy 흐름은 amber 박스에 부활하지 않는다 (회귀 가드)', () => {
            const text = serializeForSearch(card);
            expect(text).not.toContain('btn_acknowledge_policy');
            expect(text).not.toContain('/api/plugins/sirsoft-gdpr/consent/acknowledge-policy');
            expect(text).not.toContain('acknowledge_policy_success');
        });
    });

    // 「최신 정책으로 갱신」 항목별 amber 분기(#21)는 토글 재설계(#430)로 제거됨.
    // 정책 갱신은 카드 상단 amber 박스(gdpr_needs_renewal_banner)의 「전체 항목 다시 동의」로 통합되고,
    // 개별 항목은 단순 on/off 토글로 켜면 최신 정책 버전으로 grant 된다. (grant 는 항상 현재 정책 버전 기록)
});
