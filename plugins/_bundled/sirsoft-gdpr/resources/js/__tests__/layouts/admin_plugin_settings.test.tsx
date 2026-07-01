/**
 * GDPR 플러그인 환경설정 레이아웃 구조 검증
 *
 * 3 카드 (운영자 작업 순서):
 * 1. card_operator (운영 주체 + 정책 페이지)
 * 2. card_cookie_banner (배너 + 카테고리 + 정책버전)
 * 3. card_auto_blocking_policy (자동 차단 정책 — 차단 도메인 관리)
 *
 * 마이페이지 동의 관리 카드는 GDPR Art.7(3) 대칭성 의무에 따라 플러그인 활성 시 항상 노출
 * — 운영자가 끌 수 있는 토글이 없으므로 관리자 설정 화면에 카드/필드가 존재하지 않는다.
 * auto_blocking 동작은 banner_enabled (쿠키 배너 노출) 와 단일 토글로 통합되어 별도 토글 없음.
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';
import publishModal from '../../../layouts/admin/partials/plugin_settings/_policy_version_publish_modal.json';
import { findById, type AnyNode } from './helpers';

describe('admin/plugin_settings.json — 3 카드 (operator / cookie_banner / auto_blocking_policy)', () => {
    const root = layout as unknown as AnyNode;

    describe('레이아웃 메타데이터', () => {
        it('layout_name이 "plugin_settings"이다', () => {
            expect((root as { layout_name?: string }).layout_name).toBe('plugin_settings');
        });

        it('_admin_base를 상속한다', () => {
            expect((root as { extends?: string }).extends).toBe('_admin_base');
        });
    });

    describe('데이터 소스', () => {
        const dataSources = (root as { data_sources?: AnyNode[] }).data_sources ?? [];

        it('gdprSettings 데이터소스가 정의되어 있다', () => {
            const ds = dataSources.find((d) => d.id === 'gdprSettings');
            expect(ds).toBeTruthy();
            expect((ds as { endpoint?: string }).endpoint).toBe('/api/plugins/sirsoft-gdpr/admin/settings');
        });
    });

    describe('상단 탭 스크롤 (TabNavigationScroll)', () => {
        const nav = findById(root, 'settings_tab_navigation');

        it('TabNavigationScroll 컴포넌트가 존재한다', () => {
            expect(nav).toBeTruthy();
            expect(nav?.name).toBe('TabNavigationScroll');
        });

        it('3 탭이 정의되어 있다 (operator / cookie_banner / auto_blocking_policy)', () => {
            const tabs = (nav?.props as { tabs?: { id: string }[] })?.tabs ?? [];
            const ids = tabs.map((t) => t.id);
            expect(ids).toEqual(['card_operator', 'card_cookie_banner', 'card_auto_blocking_policy']);
        });

        it('enableScrollSpy: true (스크롤 시 활성 탭 자동 갱신)', () => {
            expect((nav?.props as { enableScrollSpy?: boolean })?.enableScrollSpy).toBe(true);
        });
    });

    describe('카드 구조', () => {
        const expectedCards = ['card_operator', 'card_cookie_banner', 'card_auto_blocking_policy'];

        it.each(expectedCards)('카드 %s 가 존재한다', (cardId) => {
            expect(findById(root, cardId)).toBeTruthy();
        });

        it('카드는 settings_main_content 의 직계 children 으로 3개 모두 배치', () => {
            const main = findById(root, 'settings_main_content');
            const cardChildren = ((main?.children ?? []) as AnyNode[]).filter((c) =>
                (c.id ?? '').startsWith('card_')
            );
            const cardIds = cardChildren.map((c) => c.id);
            expect(cardIds).toEqual(expectedCards);
        });

        it.each(expectedCards)('카드 %s 의 className은 라이트/다크 모드 + scroll-mt-32 포함', (cardId) => {
            const card = findById(root, cardId);
            const className = (card?.props as { className?: string })?.className ?? '';
            expect(className).toContain('bg-white');
            expect(className).toContain('dark:bg-gray-800');
            expect(className).toContain('rounded-lg');
            expect(className).toContain('scroll-mt-32');
        });
    });

    describe('제거된 카드는 더 이상 존재하지 않는다', () => {
        it.each([
            'card_dpo',
            'card_third_party',
            'card_master',
            'card_policy',
            'card_governance',
            'card_privacy_policy',
            'card_cookie_categories',
            'card_cookie_policy_version',
            'card_mypage_tab',
            'card_mypage_data',
            'card_data_request',
            'card_dpo_info',
            'card_dpo_footer',
        ])('카드 %s 미존재', (oldCardId) => {
            expect(findById(root, oldCardId)).toBeNull();
        });

        it('third_party_edit_modal 미존재 — modals 배열에 third_party_edit_modal partial 등록 없음', () => {
            const modals = (root as { modals?: Array<{ partial?: string }> }).modals ?? [];
            const partials = modals.map((m) => m.partial ?? '');
            expect(partials.some((p) => p.includes('third_party_edit_modal'))).toBe(false);
        });
    });

    describe('card_operator (운영 주체 + 정책 페이지)', () => {
        it('legal_entity_name + data_storage_location + privacy_policy_slug 3 필드 포함', () => {
            const fields = findById(root, 'card_operator_fields');
            const ids = ((fields?.children ?? []) as AnyNode[]).map((c) => c.id);
            expect(ids).toContain('field_legal_entity_name');
            expect(ids).toContain('field_data_storage_location');
            expect(ids).toContain('field_privacy_policy_slug');
        });
    });

    describe('card_cookie_banner', () => {
        it('banner_enabled (쿠키 배너 노출 단일 토글) / banner_position 포함. auto_blocking_enabled 별도 토글 제거됨', () => {
            const fields = findById(root, 'card_cookie_banner_fields');
            const serialized = JSON.stringify(fields);
            expect(serialized).toContain('field_banner_enabled');
            expect(serialized).toContain('field_banner_position');
            // 위반 조합 (배너 ON + 차단 OFF) 구조적 차단 — auto_blocking_enabled 토글 제거
            expect(serialized).not.toContain('field_auto_blocking_enabled');
        });

        it('cookie_categories 섹션을 포함한다', () => {
            const sub = findById(root, 'subsection_cookie_categories');
            expect(sub).toBeTruthy();
        });

        /**
         * 카테고리 카드는 정보 패널이며 필수 여부는 CookieCategory enum 이 SSoT.
         * 운영자가 카드에서 required 값을 변경하는 위젯/액션은 존재하지 않는다.
         */
        it('카테고리 카드 영역에 Toggle/setState(required) 위젯이 없다', () => {
            const rows = findById(root, 'cookie_categories_rows');
            const serialized = JSON.stringify(rows);

            expect(serialized).not.toContain('"name":"Toggle"');
            expect(serialized).not.toContain('form.cookie_categories');
            expect(serialized).not.toContain('required: $event.target.checked');
        });

        it('카테고리 카드 헤더에 「필수」/「선택」 배지가 required 값으로 분기 렌더된다', () => {
            const rows = findById(root, 'cookie_categories_rows');
            const serialized = JSON.stringify(rows);

            expect(serialized).toContain('category_required_badge');
            expect(serialized).toContain('category?.required === true');
            expect(serialized).toContain('category_optional_badge');
            expect(serialized).toContain('category?.required !== true');
        });

        /**
         * Phase 1: functional 카테고리 분기 (아이콘 + scope + tools + 도메인섹션 아이콘)
         *
         * ICO/CNIL 4분류 체계 부합 — layout JSON 의 4개 위치에 functional 분기가 추가되어
         * 운영자 admin UI 에서 4번째 카테고리 카드 + 도메인 차단 섹션 노출. legal_basis 축은
         * 운영자 의사결정에 불필요하여 제거됨 (정책 버전 snapshot 에는 보존).
         */
        it('Phase 1: cookie_categories 영역에 functional 분기 (아이콘 + scope + tools) 존재', () => {
            // 실제 카테고리 카드는 cookie_categories_rows iteration 노드에 위치 (subsection 헤더 별개)
            const rows = findById(root, 'cookie_categories_rows');
            const serialized = JSON.stringify(rows);

            // 1. 카테고리 아이콘 분기 (fa-sliders / emerald 색상)
            expect(serialized).toContain("category?.key === 'functional'");
            expect(serialized).toContain('"name":"sliders"');
            expect(serialized).toContain('text-emerald-600');
            expect(serialized).toContain('dark:text-emerald-400');

            // 2. scope/tools 2축 functional 안내 (운영자 의사결정 정보)
            expect(serialized).toContain('cookie_categories.info.functional.scope');
            expect(serialized).toContain('cookie_categories.info.functional.tools');
        });

        it('admin 카테고리 펼치기 영역에 legal_basis 축은 노출되지 않는다 (운영자 친화화 회귀 가드)', () => {
            // 운영자 admin 은 법령 근거를 결정하는 사람이 아니라 사이트 운영자.
            // 법령 인용 (ePrivacy Art.5(3) / GDPR Art.6(1)(a) 등) 은 운영자 의사결정에 불필요하여
            // 펼치기 영역에서 제거되었다. 정책 버전 snapshot 에 카테고리 본문이 영속되므로
            // 감사 자료는 그쪽에서 확인 가능.
            const rows = findById(root, 'cookie_categories_rows');
            const serialized = JSON.stringify(rows);

            expect(serialized).not.toContain('cookie_categories.info.necessary.legal_basis');
            expect(serialized).not.toContain('cookie_categories.info.functional.legal_basis');
            expect(serialized).not.toContain('cookie_categories.info.analytics.legal_basis');
            expect(serialized).not.toContain('cookie_categories.info.marketing.legal_basis');
            expect(serialized).not.toContain('cookie_categories.info.legal_basis_label');
        });

        it('Phase 1: 도메인 차단 섹션의 카테고리 아이콘에 functional 분기 존재', () => {
            const wrapper = findById(root, 'blocked_domains_taginputs');
            const serialized = JSON.stringify(wrapper);

            // functional 카테고리 분기 (sliders 아이콘 + emerald 색상)
            expect(serialized).toContain("category?.key === 'functional'");
            expect(serialized).toContain('"name":"sliders"');
        });

        it('cookie_policy_version 필드는 배너 표시 설정 그룹에 통합되어 있고 별도 subsection 헤더는 없다', () => {
            // 정책 버전은 배너 메타데이터로 배너 토글·위치와 한 묶음
            expect(findById(root, 'field_cookie_policy_version')).toBeTruthy();
            // subsection_cookie_policy_version 헤더는 단일 개념 통합 후 제거됨
            expect(findById(root, 'subsection_cookie_policy_version')).toBeNull();
        });
    });

    describe('card_cookie_banner — 섹션 배치 순서 (운영자 의사결정 흐름)', () => {
        it('배너 표시 → 카테고리 순서로 배치된다 (자동 차단 정책은 별도 카드로 이동)', () => {
            const fields = findById(root, 'card_cookie_banner_fields');
            const childrenIds = ((fields?.children ?? []) as AnyNode[])
                .map((c) => c.id)
                .filter((id): id is string => typeof id === 'string');

            const idxBannerEnabled = childrenIds.indexOf('field_banner_enabled');
            const idxBannerPosition = childrenIds.indexOf('field_banner_position');
            const idxPolicyVersion = childrenIds.indexOf('field_cookie_policy_version');
            const idxCategories = childrenIds.indexOf('subsection_cookie_categories');

            // 정책 버전은 배너 표시 그룹 안 (배너 토글·위치 다음, 카테고리 이전)
            expect(idxBannerEnabled).toBeGreaterThanOrEqual(0);
            expect(idxBannerPosition).toBeGreaterThan(idxBannerEnabled);
            expect(idxPolicyVersion).toBeGreaterThan(idxBannerPosition);
            expect(idxCategories).toBeGreaterThan(idxPolicyVersion);
            // 자동 차단 섹션은 별도 카드(card_auto_blocking_policy)로 이동
            expect(childrenIds).not.toContain('subsection_auto_blocking');
            expect(childrenIds).not.toContain('subsection_blocked_domains');
        });
    });

    describe('card_auto_blocking_policy — 자동 차단 정책 별도 카드', () => {
        it('off_hint(배너 OFF분기) → subsection_blocked_domains(ON분기) 순서. 토글 노드 없음', () => {
            const fields = findById(root, 'card_auto_blocking_policy_fields');
            expect(fields).toBeTruthy();
            const childrenIds = ((fields?.children ?? []) as AnyNode[])
                .map((c) => c.id)
                .filter((id): id is string => typeof id === 'string');

            const idxOffHint = childrenIds.indexOf('blocked_domains_off_hint');
            const idxBlocked = childrenIds.indexOf('subsection_blocked_domains');

            expect(idxOffHint).toBeGreaterThanOrEqual(0);
            expect(idxBlocked).toBe(idxOffHint + 1);
            // 위반 조합 차단 — field_auto_blocking_enabled 토글 노드는 제거됨
            expect(childrenIds).not.toContain('field_auto_blocking_enabled');
        });
    });

    describe('card_auto_blocking_policy — F-02 추적 도메인 차단 섹션', () => {
        it('subsection_blocked_domains 가 banner_enabled === true 가드로 보호된다 (쿠키 배너 OFF 시 본문 노출 안 함)', () => {
            const sub = findById(root, 'subsection_blocked_domains');
            expect(sub).toBeTruthy();
            // 단일 토글 통합 — banner_enabled ON 일 때만 차단 도메인 본문 노출
            expect(sub?.if).toBe('{{_local.form?.banner_enabled === true}}');
        });

        it('쿠키 배너 노출 OFF 시 안내 박스 — 한 줄 구조 + blue 좌측 색띠 시각 형태', () => {
            const offHint = findById(root, 'blocked_domains_off_hint');
            expect(offHint).toBeTruthy();
            // 단일 토글 통합 — banner_enabled OFF (= !banner_enabled) 일 때 안내 표시
            expect(offHint?.if).toBe('{{!_local.form?.banner_enabled}}');
            const serialized = JSON.stringify(offHint);
            // 안내 텍스트
            expect(serialized).toContain('blocked_domains.auto_blocking_off_hint');
            // ON 박스와 동일한 시각 형태 — blue 좌측 색띠 + circle-info
            expect(serialized).toContain('border-l-4');
            expect(serialized).toContain('border-l-blue-500');
            expect(serialized).toContain('circle-info');
            // 한 줄 + 중간 정렬
            expect(serialized).toContain('flex items-center');
            // 다크모드 가독성: 카드(gray-800)보다 한 단계 어두운 gray-900
            expect(serialized).toContain('dark:bg-gray-900');

            // 옛 단일 회색 박스(blocked_domains_auto_off_warning) + 옛 wrapper 는 모두 제거
            expect(findById(root, 'blocked_domains_auto_off_warning')).toBeNull();
            expect(findById(root, 'blocked_domains_body')).toBeNull();
        });

        it('안내 박스가 3 항목 (외부 폰트 분류 / 정책 버전 bump / 도메인 형식 FQDN 흡수) + 다크모드 가독 가능한 톤 + 좌측 색 띠 + 타이틀 강조 (검토 #8)', () => {
            const box = findById(root, 'blocked_domains_warnings_box');
            expect(box).toBeTruthy();
            const serialized = JSON.stringify(box);
            // 박스 3 항목 — 외부 폰트 분류 주의 / 차단 도메인 변경 후 정책 버전 bump / 도메인 형식 (FQDN 흡수)
            expect(serialized).toContain('blocked_domains.warning_visual_break');
            expect(serialized).toContain('blocked_domains.warning_policy_bump');
            expect(serialized).toContain('blocked_domains.warning_domain_format');
            expect(serialized).toContain('blocked_domains.warnings_title');
            // 메모리 규정 (운영자 안내 박스 3항목 이하 + 차분한 톤) 준수 — 박스에서 제거된 4개 항목
            expect(serialized).not.toContain('blocked_domains.warning_revoke_side_effects');
            expect(serialized).not.toContain('blocked_domains.preblocker_active');
            expect(serialized).not.toContain('blocked_domains.self_hosted_attr');
            expect(serialized).not.toContain('blocked_domains.static_html_limitation');
            // 타이틀 강조 — 별도 badge 없이 제목 폰트를 text-base 로 키워 타이틀 느낌
            expect(serialized).toContain('text-base');
            // 옛 badge 키는 lang 과 박스에서 모두 제거됨
            expect(serialized).not.toContain('blocked_domains.warnings_badge');
            // 기존 옛 항목 키 미사용
            expect(serialized).not.toContain('blocked_domains.hint_warning');
            expect(serialized).not.toContain('blocked_domains.hint_cookie');
            expect(serialized).not.toContain('blocked_domains.hint_policy_bump_before');
            expect(serialized).not.toContain('blocked_domains.hint_policy_bump_after');
            // 톤: amber 제거 (warning 아님)
            expect(serialized).not.toContain('bg-amber-50');
            // 다크모드 가독성: 카드(gray-800)보다 한 단계 어두운 gray-900 + 좌측 색 띠
            expect(serialized).toContain('dark:bg-gray-900');
            expect(serialized).toContain('border-l-4');
            // 아이콘: circle-info (안내 성격)
            expect(serialized).not.toContain('triangle-exclamation');
            expect(serialized).toContain('circle-info');
        });

        it('카탈로그 사용 토글은 단일 개념 통합 후 제거되었다 (banner_enabled 단일 토글로 일원화)', () => {
            // 옛 field_blocked_domains_default_catalog 토글 + 옛 field_auto_blocking_enabled
            // 토글 모두 banner_enabled (쿠키 배너 노출) 와 동일한 의미로 통합되어
            // 더 이상 노출되지 않는다.
            expect(findById(root, 'field_blocked_domains_default_catalog')).toBeNull();
            expect(findById(root, 'field_auto_blocking_enabled')).toBeNull();
            // 카탈로그 미리보기 펼침/접힘 영역도 제거됨
            const card = findById(root, 'card_cookie_banner');
            const serialized = JSON.stringify(card);
            expect(serialized).not.toContain('blockedDomainsCatalogExpanded');
            expect(serialized).not.toContain('use_default_catalog');
        });

        it('blocked_domains_taginputs iteration 이 cookie_categories 에서 necessary 제외하고 렌더', () => {
            const wrapper = findById(root, 'blocked_domains_taginputs');
            expect(wrapper).toBeTruthy();
            expect(wrapper?.iteration?.source).toBe(
                "(_local.form?.cookie_categories ?? []).filter(c => c?.key !== 'necessary')"
            );
            expect(wrapper?.iteration?.item_var).toBe('category');
        });

        it('TagInput 가 카테고리별 도메인 입력으로 사용되며 creatable=true 자유 입력 허용', () => {
            const wrapper = findById(root, 'blocked_domains_taginputs');
            const serialized = JSON.stringify(wrapper);
            // TagInput composite 사용
            expect(serialized).toContain('"name":"TagInput"');
            // creatable: true 로 자유 입력 허용
            expect(serialized).toContain('"creatable":true');
            // value 는 form.blocked_domains.{category.key}
            expect(serialized).toContain('_local.form?.blocked_domains?.[category.key] ?? []');
            // options 는 카탈로그 미리보기에서 동적 추천 (BE 단계 2 보강의 default_blocked_domains_preview)
            expect(serialized).toContain('default_blocked_domains_preview');
        });

        it('TagInput change 이벤트가 $event.target.value 에서 string 배열을 추출하여 form.blocked_domains 정적 키로 setState', () => {
            const wrapper = findById(root, 'blocked_domains_taginputs');
            const serialized = JSON.stringify(wrapper);
            // 정적 setState 키 — form.blocked_domains 만 사용 (CRITICAL 규정 준수)
            expect(serialized).toContain('"form.blocked_domains"');
            // 동적 키는 표현식 내부 spread
            expect(serialized).toContain('[category.key]');
            // change 이벤트 등록
            expect(serialized).toContain('"type":"change"');
            // TagInput onChange 는 createFakeEvent 로 { target: { value: string[] } } 형태
            // 가짜 이벤트를 보내므로 $event.target.value 로 실제 배열 추출 필수.
            // (옛 코드는 $event 객체를 그대로 할당해 칩이 [object Object] 로 표시되는 회귀 발생)
            expect(serialized).toContain('$event?.target?.value');
        });

        it('카탈로그 도메인 일괄 추가 링크 미존재 — 신규 설치 시 카탈로그가 이미 기본값으로 채워져 있고, 카탈로그 갱신 빈도 낮음. 운영자가 의도적으로 제외한 도메인이 재추가되는 위험 회피 (검토 #9)', () => {
            const wrapper = findById(root, 'blocked_domains_taginputs');
            const serialized = JSON.stringify(wrapper);
            // 일괄 추가 링크 라벨/툴팁 키 사용 금지 (lang 키 자체 제거됨)
            expect(serialized).not.toContain('blocked_domains.add_catalog_link');
            // 합집합 로직 (Set 기반 일괄 추가) 미존재
            expect(serialized).not.toContain('new Set([');
            // 단, TagInput 의 자동완성 options 출처로는 default_blocked_domains_preview 가 계속 사용됨
            expect(serialized).toContain('default_blocked_domains_preview');
        });

        it('FQDN 형식 안내가 안내 박스 안 3번째 항목(warning_domain_format)으로 흡수되고 외부 P 노드는 제거되었다 (검토 #8 옵션 B-원안)', () => {
            const sub = findById(root, 'subsection_blocked_domains');
            const serialized = JSON.stringify(sub);
            // FQDN 안내가 박스 안 단일 항목으로 흡수됨
            const box = findById(root, 'blocked_domains_warnings_box');
            const boxSerialized = JSON.stringify(box);
            expect(boxSerialized).toContain('blocked_domains.warning_domain_format');
            // 옛 외부 P 노드 (hint_format) 가 완전히 제거됨 — 안내 영역이 단일 박스로 통합
            expect(serialized).not.toContain('blocked_domains.hint_format');
            // 외부 hint 의 whitespace-pre-line 클래스도 제거 (옛 P 노드 흔적)
            expect(serialized).not.toContain('whitespace-pre-line');
        });

        it('422 검증 에러 시 카테고리 카드 외곽이 빨간 테두리로 강조 + 카드 내부에 첫 잘못된 도메인 에러 메시지 Span 노출 (검토 #10 옵션 B — 카테고리 내 어느 인덱스든 매칭)', () => {
            const wrapper = findById(root, 'blocked_domains_taginputs');
            const serialized = JSON.stringify(wrapper);
            // 카드 외곽 동적 className — 카테고리 내 *어느 인덱스든* 에러 키가 있으면 true (some)
            expect(serialized).toContain("Object.keys(_local.errors ?? {}).some(k => k.startsWith('blocked_domains.' + category.key + '.'))");
            expect(serialized).toContain('border-red-500');
            expect(serialized).toContain('border-gray-200');
            // 카드 내부 에러 Span — Object.entries + find 로 첫 매칭 키의 첫 메시지 추출
            expect(serialized).toContain("Object.entries(_local.errors ?? {}).find(([k]) => k.startsWith('blocked_domains.' + category.key + '.'))");
            // 에러 Span 시각 시맨틱 (form-error) — 텍스트 톤 + 줄바꿈을 한 결로 통일 (#399)
            expect(serialized).toContain('"form-error"');
            // 단, 정적 setState 키 (form.blocked_domains) 는 그대로 유지 (회귀 차단)
            expect(serialized).toContain('"form.blocked_domains"');
        });

        it('header_save_button onError 토스트가 422 응답의 첫 번째 검증 에러 메시지를 노출 + fallback 일반화 메시지 유지 (검토 #10 옵션 A 기반)', () => {
            const saveBtn = findById(root, 'header_save_button');
            const serialized = JSON.stringify(saveBtn);
            // 첫 번째 검증 에러 메시지 추출 표현식
            expect(serialized).toContain('Object.values(error.errors ?? {})[0]');
            // fallback 으로 기존 save_error lang 키 유지
            expect(serialized).toContain('sirsoft-gdpr.settings.save_error');
            // setState 로 errors 저장 (상단 통합 박스 + 카드 내부 표시에 모두 사용)
            expect(serialized).toContain('"errors"');
        });

        it.each([
            ['field_legal_entity_name', 'legal_entity_name'],
            ['field_data_storage_location', 'data_storage_location'],
            ['field_privacy_policy_slug', 'privacy_policy_slug'],
        ])('단일 Input 필드 %s — 422 검증 에러 시 빨간 테두리 + 필드 아래 에러 메시지 (검토 #10 옵션 X — 코어 _tab_general 단순 패턴)', (fieldId, formField) => {
            const wrapper = findById(root, fieldId);
            expect(wrapper).not.toBeNull();
            const serialized = JSON.stringify(wrapper);
            // Input className 동적 변경 — 대괄호 표기법으로 errors 키 접근 (코어 _tab_general 패턴)
            expect(serialized).toContain(`_local.errors?.['${formField}']`);
            expect(serialized).toContain('border-red-500');
            expect(serialized).toContain('focus:ring-red-500');
            // 필드 아래 에러 Span — if 가드 + 첫 메시지 text (코어 단순 패턴)
            expect(serialized).toContain(`_local.errors?.['${formField}']?.[0]`);
            // 에러 Span 시각 시맨틱 (form-error) — 텍스트 톤 + 줄바꿈을 한 결로 통일 (#399)
            expect(serialized).toContain('"form-error"');
        });

        it('field_cookie_policy_version 는 Input 이 아닌 read-only 카드 — gdpr_policy_versions 테이블이 SSoT (옵션 C 정책 버전 자동 발행 + 운영자 수동 발행)', () => {
            const wrapper = findById(root, 'field_cookie_policy_version');
            expect(wrapper).not.toBeNull();
            const serialized = JSON.stringify(wrapper);
            // 옛 Input + name="cookie_policy_version" 패턴 제거됨
            expect(serialized).not.toContain('"name":"cookie_policy_version"');
            // 새 카드: 현재 버전 표시 + 새 버전 발행 + 이력 토글
            expect(serialized).toContain('gdprPolicyVersionCurrent?.data?.data?.version');
            // 새 버전 발행 버튼 — publish modal 진입
            expect(serialized).toContain('sirsoft-gdpr.settings.policy_version.publish_button');
            expect(serialized).toContain('policy_version_publish_modal');
            // 이력 collapsible 토글 라벨 (열림/닫힘 두 키 모두 사용)
            expect(serialized).toContain('sirsoft-gdpr.settings.policy_version.history_toggle_show');
            expect(serialized).toContain('sirsoft-gdpr.settings.policy_version.history_toggle_hide');
            // 토글은 _local.policyHistoryOpen state 사용 + 동시에 refetch
            expect(serialized).toContain('_local.policyHistoryOpen');
            expect(serialized).toContain('refetchDataSource');
            expect(serialized).toContain('gdprPolicyVersionHistory');
            // 옛 history modal 진입 패턴 제거 — 모달이 아닌 inline collapsible 로 전환됨
            expect(serialized).not.toContain('policy_version_history_modal');
            expect(serialized).not.toContain('sirsoft-gdpr.settings.policy_version.history_button');
        });

        it('정책 버전 이력은 inline collapsible Table 로 노출 — _local.policyHistoryOpen 가드로 카드 본문 아래에서 펼침/닫힘 (모달 비사용)', () => {
            const wrapper = findById(root, 'field_cookie_policy_version');
            const serialized = JSON.stringify(wrapper);
            // 표 헤더 5개 (version / created_at / publisher / change_type / memo)
            expect(serialized).toContain('history.col.version');
            expect(serialized).toContain('history.col.created_at');
            expect(serialized).toContain('history.col.publisher');
            expect(serialized).toContain('history.col.change_type');
            expect(serialized).toContain('history.col.memo');
            // 이력 소스 데이터바인딩
            expect(serialized).toContain('gdprPolicyVersionHistory?.data?.data');
            // 빈 상태 메시지 키
            expect(serialized).toContain('sirsoft-gdpr.settings.policy_version.history_empty');
            // collapsible 가드 if
            expect(serialized).toContain('{{_local.policyHistoryOpen}}');

            // 옛 history modal partial 등록 제거 + 옛 modal id 미사용
            const modals = (root as { modals?: Array<{ partial?: string }> }).modals ?? [];
            const partials = modals.map((m) => m.partial ?? '');
            expect(partials.some((p) => p.includes('_policy_version_history_modal'))).toBe(false);
        });

        it('정책 버전 발행 modal (publish modal) partial 이 modals 배열에 등록되어 있다 — 운영자 수동 발행 진입점', () => {
            const modals = (root as { modals?: Array<{ partial?: string }> }).modals ?? [];
            const partials = modals.map((m) => m.partial ?? '');
            expect(partials.some((p) => p.includes('_policy_version_publish_modal'))).toBe(true);
        });

        describe('정책 버전 발행 모달 (_policy_version_publish_modal.json) — 진행 상태 UX', () => {
            const modalText = JSON.stringify(publishModal);

            it('발행 버튼은 isPublishing 동안 spinner + "발행 중..." 라벨 노출 + 양쪽 버튼 disabled', () => {
                // PO 피드백: 이전엔 closeModal 이 apiCall 앞에 있어 클릭 즉시 모달이 닫혀 사용자가
                // 발행 진행 상태를 알 수 없었음. _local.isPublishing 플래그로 발행 중 시각 피드백 +
                // 양쪽 버튼 disabled (취소 버튼도 발행 중엔 막아 도중 닫힘 방지).
                // JSON.stringify 기본 출력은 공백 없는 형식 (콜론 뒤 공백 없음).
                expect(modalText).toContain('"isPublishing":true');
                expect(modalText).toContain('"isPublishing":false');
                expect(modalText).toContain('"name":"spinner"');
                expect(modalText).toContain('"spin":true');
                expect(modalText).toContain('sirsoft-gdpr.settings.policy_version.publishing');
                // 취소 버튼도 isPublishing 동안 disabled (도중 닫힘 방지)
                expect(modalText).toContain('"disabled":"{{_local.isPublishing}}"');
            });

            it('closeModal 이 onSuccess 안으로 이동 — 발행 완료 후에만 모달 닫힘 (발행 실패 시 모달 유지하여 재시도 가능)', () => {
                // 회귀 가드: 이전 패턴 (sequence 의 첫 액션 closeModal → 그 다음 apiCall) 은
                // 발행 실패해도 모달이 닫혀버려 사용자가 처음부터 다시 열어야 했음. closeModal 을
                // onSuccess 마지막에 두어 발행 완료 시점에만 닫히고, onError 면 모달 유지.
                // onSuccess 배열 안에 closeModal 핸들러가 존재
                const onSuccessMatch = modalText.match(/"onSuccess":\[[^\]]*"closeModal"[^\]]*\]/);
                expect(onSuccessMatch).not.toBeNull();
                // onError 배열 안엔 closeModal 없음 (실패 시 모달 유지)
                const onErrorMatch = modalText.match(/"onError":\[([^\]]*)\]/);
                expect(onErrorMatch?.[1] ?? '').not.toContain('closeModal');
            });
        });

        it('정책 버전 가이드는 카드 hint 영역에 2줄 P 로 통합된다 (PO 피드백: 토글/박스 제거)', () => {
            const box = findById(root, 'policy_version_guide_box');
            expect(box).not.toBeNull();
            const serialized = JSON.stringify(box);

            // 2개 다국어 키가 P 텍스트로 노출됨
            // 1줄: cookie_policy_version.hint (발행 시점 안내)
            // 2줄: policy_version.guide_body (발행 대상 구분)
            expect(serialized).toContain('cookie_policy_version.hint');
            expect(serialized).toContain('policy_version.guide_body');

            // amber 색 회귀 가드 — 통째로 제거
            expect(serialized).not.toContain('border-l-amber-500');
            expect(serialized).not.toContain('bg-amber-50');
            // 토글 회귀 가드 — chevron / policyGuideOpen / circle-question 모두 제거
            expect(serialized).not.toContain('policyGuideOpen');
            expect(serialized).not.toContain('chevron-down');
            expect(serialized).not.toContain('chevron-up');
            // guide_title 도 노출 안 함 (토글 헤더로 쓰던 라벨이라 더 이상 필요 없음)
            expect(serialized).not.toContain('policy_version.guide_title');

            // 옛 발행 항목 keys 미사용 (압축 회귀 가드 보존)
            expect(serialized).not.toContain('guide_publish_item_');
            expect(serialized).not.toContain('guide_no_publish_');
        });

        it('init_actions 에 _local.policyGuideOpen 키가 더 이상 존재하지 않는다 (토글 제거 회귀 가드)', () => {
            const initActions = (root as { init_actions?: Array<{ params?: Record<string, unknown> }> }).init_actions ?? [];
            const setStateAction = initActions.find((a) => (a as { handler?: string }).handler === 'setState');
            expect(setStateAction).toBeTruthy();
            const params = setStateAction?.params as Record<string, unknown> | undefined;
            expect(params).toBeTruthy();
            expect(Object.keys(params ?? {})).not.toContain('policyGuideOpen');
        });

        it('상단 저장 sequence 는 단순 save_success 토스트만 노출 — published_version 분기 / 정책 버전 refetch 제거 (수동 발행 모델)', () => {
            const btn = findById(root, 'header_save_button');
            const serialized = JSON.stringify(btn);
            // 옛 자동 발행 흐름의 분기 / refetch 제거 — 저장은 정책 버전을 발행하지 않으므로 분기 불필요
            expect(serialized).not.toContain('response?.data?.published_version');
            expect(serialized).not.toContain('policy_version.publish_success');
            expect(serialized).not.toContain('gdprPolicyVersionCurrent');
            // 단순 save_success 토스트만 사용
            expect(serialized).toContain('sirsoft-gdpr.settings.save_success');
        });

        it('데이터소스 — gdprPolicyVersionCurrent (auto) + gdprPolicyVersionHistory (on demand)', () => {
            const dataSources = (root as { data_sources?: { id: string; auto_fetch?: boolean }[] }).data_sources ?? [];
            const ids = dataSources.map((d) => d.id);
            expect(ids).toContain('gdprPolicyVersionCurrent');
            expect(ids).toContain('gdprPolicyVersionHistory');
            const current = dataSources.find((d) => d.id === 'gdprPolicyVersionCurrent');
            expect(current?.auto_fetch).toBe(true);
            const history = dataSources.find((d) => d.id === 'gdprPolicyVersionHistory');
            expect(history?.auto_fetch).toBe(false);
        });
    });

    describe('마이페이지 동의 관리 카드 제거 (GDPR Art.7(3) 대칭성 의무)', () => {
        it('card_mypage_data 카드는 존재하지 않는다 — 플러그인 활성 시 카드는 항상 노출이므로 운영자 토글 불필요', () => {
            expect(findById(root, 'card_mypage_data')).toBeNull();
            expect(findById(root, 'card_mypage_data_fields')).toBeNull();
            expect(findById(root, 'field_mypage_privacy_tab_visible')).toBeNull();
        });
    });

    describe('necessary 안내 P 노드 제거 (검토 #7)', () => {
        /**
         * 회귀 가드: 카테고리 카드 묶음 아래 별도 P 노드 (category_edit.required_locked_hint) 가
         * 섹션 desc (cookie_categories.description) 와 중복되어 제거됨.
         * "필수 카테고리는 회원이 거부할 수 없습니다" 안내는 섹션 desc 한 곳으로 통합.
         */
        const layoutJson = JSON.stringify(root);

        it('category_edit.required_locked_hint 키가 layout 어디에도 사용되지 않는다', () => {
            expect(layoutJson).not.toContain('category_edit.required_locked_hint');
            expect(layoutJson).not.toContain('required_locked_hint');
        });
    });

    describe('카테고리 라벨/설명 — locale 자동 반영 (#30 옵션 C)', () => {
        /**
         * 회귀 가드: 카테고리 라벨/설명이 `?.ko` / `?.en` 고정 키 참조가 아니라
         * `$localized()` 헬퍼로 현재 locale 을 자동 반영해야 한다.
         * 영문 모드에서 한국어가 메인으로 노출되던 i18n 회귀 차단.
         */
        const layoutJson = JSON.stringify(root);

        it('카테고리 라벨 바인딩이 ?.ko / ?.en 고정 참조를 사용하지 않는다', () => {
            // 고정 locale 참조 패턴이 남아 있으면 안 됨
            expect(layoutJson).not.toMatch(/category\?\.label\?\.ko/);
            expect(layoutJson).not.toMatch(/category\?\.label\?\.en/);
            expect(layoutJson).not.toMatch(/category\?\.description\?\.ko/);
            expect(layoutJson).not.toMatch(/category\?\.description\?\.en/);
        });

        it('카테고리 라벨/설명은 $localized() 헬퍼를 사용한다', () => {
            // category.label / category.description 은 $localized() 로 감싸 현재 locale 반영
            const labelMatches = layoutJson.match(/\$localized\(category\?\.label\)/g) ?? [];
            const descMatches = layoutJson.match(/\$localized\(category\?\.description\)/g) ?? [];

            // 라벨: 최소 2회 사용 (카테고리 카드 + 도메인 차단 섹션 라벨)
            expect(labelMatches.length, '카테고리 라벨에 $localized() 헬퍼가 2회 이상 사용됨').toBeGreaterThanOrEqual(2);
            // 설명: 최소 2회 사용 (description if 조건 + 본문)
            expect(descMatches.length, '카테고리 설명에 $localized() 헬퍼가 2회 이상 사용됨').toBeGreaterThanOrEqual(2);
        });

        it('카테고리 보조 라벨에 카탈로그 key 가 노출된다 (DB/코드 매핑 가독성)', () => {
            // 옵션 C 의 디버그/매핑 보조 정보 — (necessary), (analytics) 같은 key 표시 유지
            expect(layoutJson).toMatch(/\(\{\{category\?\.key \?\? ''\}\}\)/);
        });
    });

    describe('제거된 DPO / 제3자 필드는 노출되지 않는다', () => {
        it.each([
            'field_dpo_name',
            'field_dpo_email',
            'field_dpo_phone',
            'field_dpo_position',
            'field_dpo_address',
            'field_dpo_footer_visible',
            'field_dpo_footer_format',
            'field_data_request_response_days',
            'field_export_retention_days',
            'field_dpo_self_review_allowed',
            'field_order_consent_enabled',
            'third_party_providers_table',
            'third_party_providers_empty',
            'third_party_providers_rows',
        ])('필드 %s 미존재', (fieldId) => {
            expect(findById(root, fieldId)).toBeNull();
        });
    });

    /**
     * 작업 2 (B-3 옵션 X) — 정책 버전 v배지 + 이력 표 행 클릭 → snapshot 모달.
     * GDPR Art.7(1) 입증 책임 — DPO 가 분쟁 시 즉시 그 시점 정책 본문 확인 가능.
     */
    describe('작업 2: 정책 버전 snapshot 조회', () => {
        const layoutJson = JSON.stringify(layout);

        it('gdprPolicyVersionSnapshot data_source 가 등록되어 있다', () => {
            expect(layoutJson).toContain('"id":"gdprPolicyVersionSnapshot"');
            expect(layoutJson).toContain('/api/plugins/sirsoft-gdpr/admin/policy-versions/{{_global.viewingPolicyVersion}}');
        });

        it('snapshot 모달 partial 이 _shared 위치에 등록되어 있다', () => {
            expect(layoutJson).toContain('partials/_shared/_policy_version_snapshot_modal.json');
        });

        it('현재 버전 v배지 옆에 본문 보기 Button 이 노출된다 (PO 피드백 — 평문 + 명시 액션 분리)', () => {
            // v배지는 평문 Span (text 'v...' 으로 항상 표시), 본문 보기 Button 은 if 분기 + sequence
            expect(layoutJson).toContain('"text":"v{{gdprPolicyVersionCurrent?.data?.data?.version ?? \'?\'}}"');
            expect(layoutJson).toContain('"if":"{{gdprPolicyVersionCurrent?.data?.data?.version}}"');
            expect(layoutJson).toContain('"viewingPolicyVersion":"{{gdprPolicyVersionCurrent?.data?.data?.version}}"');
            expect(layoutJson).toContain('"dataSourceId":"gdprPolicyVersionSnapshot"');
            expect(layoutJson).toContain('"target":"policy_version_snapshot_modal"');
            expect(layoutJson).toContain('snapshot_view_short');
        });

        it('이력 표에 본문 컬럼이 추가되고 각 행에 본문 보기 Button 이 노출된다', () => {
            // Thead 에 snapshot_action 컬럼 헤더 추가
            expect(layoutJson).toContain('history.col.snapshot_action');
            // 각 행의 본문 액션 셀 - viewingPolicyVersion=row.version + sequence
            expect(layoutJson).toContain('"viewingPolicyVersion":"{{row?.version}}"');
        });

        it('policy_version_guide_box wrapper 는 라벨 + hint 2줄 구조의 단순 Div (PO 피드백: 토글/박스 제거)', () => {
            const box = findById(root, 'policy_version_guide_box');
            expect(box).not.toBeNull();
            // wrapper 는 className 없음 (라벨 + hint 두 줄을 단순 그룹핑)
            const className = String((box?.props as { className?: string } | undefined)?.className ?? '');
            expect(className).not.toContain('border');
            expect(className).not.toContain('rounded-lg');
            // 자식은 Label + 2개 P (hint + guide_body)
            const children = (box?.children ?? []) as Array<{ name?: string }>;
            const names = children.map((c) => c.name);
            expect(names).toContain('Label');
            expect(names.filter((n) => n === 'P')).toHaveLength(2);
        });
    });
});
