/**
 * 쿠키 동의 배너 Layout Extension (W-1)
 *
 * @description
 * - extension_point: user_global_overlay (sirsoft-basic _user_base 가 사전에 정의)
 * - banner_enabled === true && !gdprBannerDismissed 조건 표시
 * - 모두 동의 / 필수만 사용 / 환경설정 / 정책 링크 제공
 * - 환경설정은 모달이 아니라 배너 내부 인라인 펼침 패널 (gdpr_preferences_panel)
 * - banner_position 4종 분기 (bottom_bar / bottom_left_popup / bottom_right_popup / centered_modal)
 *   - bottom_bar 는 화면 하단 풀폭 팝업 카드 (bottom-4 left-4 right-4 rounded-lg)
 */

import { describe, it, expect } from 'vitest';
import extension from '../../../extensions/cookie_banner.json';
import { findById, collectHandlers, collectGdprI18nKeys, type AnyNode , serializeForSearch } from './helpers';

describe('extensions/cookie_banner.json — 쿠키 동의 배너', () => {
    const root = extension as unknown as AnyNode & {
        extension_point?: string;
        mode?: string;
        components?: AnyNode[];
        modals?: AnyNode[];
        priority?: number;
    };

    describe('Extension 메타데이터', () => {
        it('extension_point 가 user_global_overlay 이다 (sirsoft-basic _user_base 가 사전 정의)', () => {
            expect(root.extension_point).toBe('user_global_overlay');
        });

        it('mode 는 append (default 컴포넌트 뒤에 추가)', () => {
            expect(root.mode).toBe('append');
        });

        it('priority 는 50 (높은 우선순위)', () => {
            expect(root.priority).toBe(50);
        });

        it('gdprPublicSettings 데이터소스가 포함된다', () => {
            const ds = (root as { data_sources?: AnyNode[] }).data_sources ?? [];
            const settings = ds.find((d) => d.id === 'gdprPublicSettings');
            expect(settings).toBeTruthy();
            expect((settings as { auth_required?: boolean }).auth_required).toBe(false);
        });

        it('gdprMyConsent 데이터소스가 포함된다 (현재 방문자 동의 완료 여부, auth_mode:optional)', () => {
            // 회귀 가드: 새로고침 후에도 배너 재출력 방지를 위한 서버 조회 데이터소스.
            // /api/plugins/sirsoft-gdpr/consent/cookie/status — 회원/게스트 통합 응답.
            // auth_mode:optional 은 회원 토큰이 있으면 함께 전송하여 서버가 회원 동의 상태를 반환하게 한다.
            // 토큰을 보내지 않으면 (auth_required:false) 서버 라우트 optional.sanctum 가 항상 게스트로 인식
            // → 회원 컨텍스트 누락으로 has_consented:false 가 잘못 반환되어 배너가 다시 뜸.
            const ds = (root as { data_sources?: AnyNode[] }).data_sources ?? [];
            const myConsent = ds.find((d) => d.id === 'gdprMyConsent');
            expect(myConsent).toBeTruthy();
            expect((myConsent as { endpoint?: string }).endpoint).toBe(
                '/api/plugins/sirsoft-gdpr/consent/cookie/status'
            );
            expect((myConsent as { auth_mode?: string }).auth_mode).toBe('optional');
        });

        it('modals 섹션이 없다 (환경설정은 인라인 펼침으로 처리)', () => {
            // 회귀 가드: 이전엔 gdpr_preferences_modal 모달을 분리 정의했으나
            // 배너 내부 인라인 펼침 패널(gdpr_preferences_panel)로 통합됨.
            expect(root.modals ?? []).toHaveLength(0);
        });
    });

    describe('주입 컴포넌트', () => {
        it('components 배열의 첫 컴포넌트가 gdpr_cookie_banner', () => {
            const first = root.components?.[0];
            expect((first as { id?: string })?.id).toBe('gdpr_cookie_banner');
        });
    });

    describe('배너 표시 조건', () => {
        const banner = findById(root, 'gdpr_cookie_banner');

        it('배너 컴포넌트가 존재한다', () => {
            expect(banner).toBeTruthy();
        });

        it('banner_enabled + has_consented + dismissed 3중 조건 — 데이터소스 경로는 한 단계 (?.data?.<key>)', () => {
            // 회귀 가드:
            // 1. ResponseHelper::success($msg, $data) 응답은 데이터소스에서 그대로 보존되므로 단일 객체 응답은 `<id>.data.<key>` 한 단계 경로.
            //    과거 통합본에서 `?.data?.data?` 두 단계로 작성되어 항상 undefined → if false → 배너 미출력 사고.
            // 2. _global.gdprBannerDismissed 만으로는 새로고침 시 휘발 — 서버 조회 (gdprMyConsent.has_consented) 가 우선이며 클릭 직후 즉시 사라지는 효과만 글로벌 상태가 보장.
            // 3. needs_renewal=true 일 때도 배너만 노출 (모달 폐기 — PO 결정 모달/배너 중복 제거). 배너 본문에 사유 안내 + 회원 한정 "현 상태 유지" 액션 통합.
            expect(banner?.if).toBe(
                '{{gdprPublicSettings?.data?.banner_enabled === true && gdprMyConsent?.data?.has_consented !== true && _global.gdprBannerDismissed !== true}}'
            );
        });

        it('needs_renewal 강제 모달 (gdpr_needs_renewal_modal) 이 더 이상 존재하지 않는다 (PO 결정 — 배너 통합)', () => {
            // 모달 폐기 회귀 가드: 모달과 배너의 시각적 중복 + UX 혼란 + dark pattern 회피 위해
            // 모달 통째 제거하고 배너에 사유 안내 + 회원 한정 "현 상태 유지" 액션 통합.
            const modal = findById(root, 'gdpr_needs_renewal_modal');
            expect(modal).toBeNull();
        });

        it('회원 + needs_renewal=true 한정 「현재 동의 갱신」 버튼이 배너 액션 영역에 노출 (POST /consent/renew-all)', () => {
            const keepBtn = findById(root, 'gdpr_keep_consent_button');
            expect(keepBtn).not.toBeNull();
            // 회원/게스트 식별은 status 응답의 is_member 필드 — G7 layout 컨텍스트엔 auth 변수가
            // 존재하지 않아 옛 auth?.user?.id 패턴은 항상 undefined 로 평가되던 회귀 회피.
            expect(keepBtn?.if).toContain('gdprMyConsent?.data?.is_member === true');
            expect(keepBtn?.if).toContain('needs_renewal === true');
            const json = JSON.stringify(keepBtn);
            expect(json).toContain('/api/plugins/sirsoft-gdpr/consent/renew-all');
            // 라벨은 마이페이지 amber 박스와 의미 일치하는 신 키 사용 (banner.btn_renew_consent)
            expect(json).toContain('sirsoft-gdpr.banner.btn_renew_consent');
            // onSuccess 에 toast 핸들러 — 마이페이지 amber 박스와 동일 lang 키 (renew_all_success)
            // 사용자 피드백 일관성 + 토큰 {renewed} 보간으로 갱신 항목 수 안내
            expect(json).toContain('sirsoft-gdpr.mypage.privacy.renew_all_success');
            expect(json).toContain('|renewed=');
        });

        it('게스트 전용 갱신 버튼 (gdpr_keep_consent_button_guest) 은 더 이상 존재하지 않는다 (PO 결정 — 게스트는 status 미사용 + history 기반이라 이전 의사 자동 복원 인프라 부재 → "모두 동의" 그대로 사용)', () => {
            const guestBtn = findById(root, 'gdpr_keep_consent_button_guest');
            expect(guestBtn).toBeNull();
        });

        it('옛 banner.keep_label 키는 더 이상 참조되지 않는다 (회귀 가드 — 신 키 banner.btn_renew_consent 로 통일)', () => {
            const text = serializeForSearch(root);
            expect(text).not.toContain('banner.keep_label');
        });

        it('master_switch 표현식 미사용 (v1.1.0)', () => {
            expect(banner?.if ?? '').not.toContain('master_switch');
        });
    });

    describe('banner_position 분기', () => {
        const banner = findById(root, 'gdpr_cookie_banner');
        const className = (banner?.props as { className?: string })?.className ?? '';

        it.each([
            'bottom_left_popup',
            'bottom_right_popup',
            'centered_modal',
        ])('%s 위치를 className에서 분기 처리', (position) => {
            expect(className).toContain(`'${position}'`);
        });

        it('기본(bottom_bar) 위치는 풀폭 팝업 카드 (bottom-4 left-4 right-4 rounded-lg)', () => {
            // 회귀 가드: 이전엔 화면 하단에 딱 붙는 띠(bottom-0)였지만,
            // PO 결정으로 화면에서 살짝 떠 있는 풀폭 카드 형태로 변경됨.
            expect(className).toContain('bottom-4 left-4 right-4 rounded-lg');
        });

        it('top_bar 분기는 제거되어 있다', () => {
            // 회귀 가드: PO 결정으로 banner_position 옵션에서 top_bar 제거됨.
            expect(className).not.toContain("'top_bar'");
        });

        it('centered_modal 분기는 다른 팝업과 동일한 박스 형태 — 중앙 정렬만 다름', () => {
            // 누적 검토 #12: 이전 구현은 외곽 Div 에 inset-0 + bg-black/40 (전체 화면 dim) +
            // 자식 Div 에 별도 bg-white 박스. 외곽 className 이 'fixed ... bg-white' 와 'bg-black/40'
            // 두 배경을 동시에 가져 Tailwind 빌드 후 뒤에 오는 bg-white 가 이김 → 화면 전체가 흰 박스.
            // 수정 후 다른 3종 팝업과 동일한 max-w-md 박스 + top-1/2 -translate-y-1/2 + inset-x-4 mx-auto
            // (모바일 1rem gutter, 데스크탑 max-width 까지 가로 중앙) 패턴.
            expect(className).toContain('top-1/2');
            expect(className).toContain('-translate-y-1/2');
            expect(className).toContain('inset-x-4');
            expect(className).toContain('mx-auto');
            // 4종 위치 모두 max-w-md 박스 cap (bottom_bar 는 left-4 right-4 로 풀폭이므로 제외)
            // 정규식: 위치 키 ↔ 따옴표 사이 분기 분리자 (`) ? ' ... '` 또는 `? ' ... '`) 허용
            const popupPositions = ['bottom_left_popup', 'bottom_right_popup', 'centered_modal'];
            for (const pos of popupPositions) {
                expect(className).toMatch(new RegExp(`'${pos}'[^']*\\?\\s*'[^']*max-w-md`));
            }
        });

        it('centered_modal 에서 dim 오버레이를 사용하지 않는다 (PO 의도: 다른 팝업과 동일 외형)', () => {
            // 회귀 가드: 이전 구현은 외곽에 bg-black/40 dim 을 깔았으나 자식 박스가 dim 위에 떠야 하는
            // 구조 — 외곽이 bg-white 와 함께 정의되어 dim 의도가 깨졌었다. PO 가 dim 강제 모달이 아닌
            // 단순 중앙 배치만 원하므로 dim 클래스 자체를 제거.
            expect(className).not.toContain('bg-black/40');
            expect(className).not.toContain('bg-black/50');
            expect(className).not.toContain('bg-black/60');
            expect(className).not.toContain('bg-black/70');
            // inset-0 (화면 전체 덮기) 사용도 금지 — centered_modal 분기 한정으로 확인
            expect(className).not.toMatch(/'centered_modal'\s*\?\s*'[^']*inset-0/);
        });

        it('Tailwind 임의값(arbitrary value) 미사용 — safelist 규정 준수', () => {
            // 회귀 가드: w-[calc(100%-2rem)] 같은 임의값은 safelist 미등록 시 빌드에서 제외되어
            // 클래스 미적용. memory/feedback_layout_tailwind_arbitrary_values.md 규정.
            expect(className).not.toMatch(/\[\S+\]/);
        });

        it('자식 Div 에 centered_modal 분기 className 이 남아 있지 않다 (이중 박스 회귀 가드)', () => {
            // 회귀 가드: 이전 구현은 자식 Div 에도
            // 'bg-white dark:bg-gray-800 rounded-lg max-w-lg w-full mx-4 shadow-xl' 를 centered_modal
            // 일 때 추가로 입혔다 — 외곽이 박스 시각 속성을 갖게 되면서 자식의 분기는 이중 적용 회귀 원인.
            // 자식 Div 는 패딩만 담당해야 한다.
            const child = banner?.children?.[0] as { props?: { className?: string } } | undefined;
            const childClassName = child?.props?.className ?? '';
            expect(childClassName).not.toContain('centered_modal');
            expect(childClassName).not.toContain('shadow-xl');
            expect(childClassName).not.toContain('max-w-lg');
        });
    });

    describe('동의 액션 3종', () => {
        const banner = findById(root, 'gdpr_cookie_banner');
        const text = serializeForSearch(banner);

        it('"모두 동의" 시 모든 카테고리 true로 POST (cookie_ prefix)', () => {
            // 백엔드 화이트리스트는 cookie_necessary / cookie_functional / cookie_analytics / cookie_marketing
            // (CookieCategoryService::getAllConsentKeys 가 'cookie_' . c.key 반환).
            // Phase 1: functional 카테고리 추가 — iteration 기반이라 카테고리 데이터에 functional 가 있으면 자동 노출
            expect(text).toContain("['cookie_' + c.key]: true");
            expect(text).toContain('/api/plugins/sirsoft-gdpr/consent/cookie');
        });

        it('Phase 1: iteration 이 gdprPublicSettings.data.cookie_categories 기반이라 functional 등 4종 카테고리가 자동 토글 노출', () => {
            // 카테고리 토글은 cookie_categories 데이터를 iteration 으로 렌더 → 서버 응답에 functional 가 있으면 자동 노출.
            // 별도 분기 추가 불필요. 회귀 가드: iteration source 가 cookie_categories 전체 사용.
            expect(text).toContain("gdprPublicSettings?.data?.cookie_categories");
            // 필수 표시는 c.required === true 기반 — necessary 만 lock
            expect(text).toContain('c.required === true');
        });

        it('"모두 동의" 버튼은 회원 + needs_renewal=true 일 때만 미노출 (GDPR Art.7(3) 자유 변경권 보호 — 이전 철회 의사 덮어쓰기 방지)', () => {
            // 회원: 옆 keep_consent 버튼이 활성 동의만 신정책으로 갱신 → "모두 동의" 숨김.
            // 게스트: status 미사용 + history 기반이라 이전 의사 자동 복원 인프라 부재 → "모두 동의" 그대로 사용.
            // 회원 식별은 status 응답의 is_member 필드 (G7 layout 컨텍스트엔 auth 변수 없음 — 옛 패턴 회귀).
            expect(text).toContain('!(gdprMyConsent?.data?.is_member === true && gdprMyConsent?.data?.needs_renewal === true)');
        });

        it('"필수만 사용" 시 required 카테고리만 true (cookie_ prefix)', () => {
            expect(text).toContain("['cookie_' + c.key]: c.required === true");
        });

        it('POST body 키는 백엔드 검증 규칙(StoreCookieConsentRequest)과 일치 — consents + source 사용', () => {
            // 회귀 가드: 백엔드는 consents (객체, cookie_ prefix 키) + source ("banner") 를 요구한다.
            // 과거 categories / policy_version 으로 잘못 보내서 422 "유효하지 않은 동의 항목입니다." 반환됨.
            expect(text).toContain('consents:');
            expect(text).toContain("source: 'banner'");
            expect(text).not.toContain('categories:');
        });

        it('동의 저장 직후 onSuccess 에서 gdprMyConsent 를 refetch (3개 버튼 모두)', () => {
            // 회귀 가드: 동의 후 새로고침 시 배너 재출력을 막으려면 서버 동의 상태를 다시 조회해야 함.
            // setState gdprBannerDismissed=true 만으로는 글로벌 상태가 새로고침 시 휘발됨.
            const refetchOccurrences = (text.match(/"handler":\s*"refetchDataSource"/g) ?? []).length;
            expect(refetchOccurrences).toBeGreaterThanOrEqual(3);
            expect(text).toContain('"dataSourceId": "gdprMyConsent"');
        });

        it('동의 저장 apiCall 3건 모두 auth_mode:optional (회원 토큰 전달)', () => {
            // 회귀 가드: 회원이 로그인 상태로 배너에서 동의해도 토큰을 보내지 않으면
            // 서버 라우트 optional.sanctum 가 항상 게스트로 인식 → g7_gdpr_user_consents 에 저장 안 되고
            // history 에만 user_id=NULL 로 기록됨. 클라이언트도 토큰을 같이 보내야 회원 동의로 저장됨.
            // 이전 구현(auth_required:false)은 토큰 미전송 → 회원 동의가 누락되는 회귀의 직접 원인.
            const optionalOccurrences = (text.match(/"auth_mode":\s*"optional"/g) ?? []).length;
            expect(optionalOccurrences).toBeGreaterThanOrEqual(3);
            expect(text).not.toContain('"auth_required": false,\n                                                            "target": "/api/plugins/sirsoft-gdpr/consent/cookie"');
        });

        it('동의 저장 직후 onSuccess 에서 sirsoft-gdpr.syncConsent 핸들러를 호출 (3개 버튼 모두)', () => {
            // v1.5.0 회귀 가드: 자동 차단 엔진이 클라이언트 쿠키 SSoT 를 버리고 서버 응답 SSoT 로
            // 전환됨. 동의 저장 직후 차단 엔진의 메모리 캐시를 갱신하지 않으면 G-4 시나리오 (분석/
            // 마케팅 스크립트 동의 직후 즉시 로드) 가 작동 안 함. refetchDataSource 만으로는
            // blocker.ts 가 갱신을 알 수 없으므로 별도 핸들러 호출이 필요.
            const syncOccurrences = (text.match(/"handler":\s*"sirsoft-gdpr\.syncConsent"/g) ?? []).length;
            expect(syncOccurrences).toBeGreaterThanOrEqual(3);
        });

        it('"환경설정" 클릭 시 인라인 펼침 토글 (모달 미사용)', () => {
            // 회귀 가드: 이전엔 openModal handler 로 별도 모달을 열었으나,
            // 배너 내부 인라인 펼침으로 변경되어 setState 로 _global.gdprPreferencesOpen 토글.
            const toggleBtn = findById(banner ?? null, 'gdpr_preferences_toggle_button');
            expect(toggleBtn).toBeTruthy();

            const toggleText = serializeForSearch(toggleBtn);
            expect(toggleText).toContain('"handler": "setState"');
            expect(toggleText).toContain('"gdprPreferencesOpen"');
            expect(toggleText).not.toContain('openModal');
        });

        it('배너 4버튼은 gdprBannerSubmittingAction 식별자 패턴으로 클릭한 버튼만 spinner + 다른 버튼은 원래 라벨 유지', () => {
            // 회귀 가드: PO 피드백 — 단일 boolean 플래그면 모든 버튼이 "동의 중..." 으로 동시 변경되어
            // 어느 버튼을 클릭했는지 시각적으로 알 수 없음. 식별자 string 으로 변경하여 클릭한 버튼만
            // 스피너 + 라벨 변경, 다른 버튼은 disabled + 원래 라벨 유지.
            // 4 action 식별자: accept_all / reject_all / save_selection / renew_consent
            expect(text).toContain('"gdprBannerSubmittingAction": "accept_all"');
            expect(text).toContain('"gdprBannerSubmittingAction": "reject_all"');
            expect(text).toContain('"gdprBannerSubmittingAction": "save_selection"');
            expect(text).toContain('"gdprBannerSubmittingAction": "renew_consent"');

            // disabled 는 4버튼 모두 같은 truthy 가드 (다른 버튼 진행 중에도 disabled)
            const disabledMatches = text.match(/!!_global\.gdprBannerSubmittingAction/g) ?? [];
            expect(disabledMatches.length).toBeGreaterThanOrEqual(4);

            // 스피너 if 가드는 자기 식별자와 정확히 일치할 때만 노출
            expect(text).toContain("_global.gdprBannerSubmittingAction === 'accept_all'");
            expect(text).toContain("_global.gdprBannerSubmittingAction === 'reject_all'");
            expect(text).toContain("_global.gdprBannerSubmittingAction === 'save_selection'");
            expect(text).toContain("_global.gdprBannerSubmittingAction === 'renew_consent'");

            // 동작별 라벨 (granting / renewing) 모두 사용
            expect(text).toContain('"name": "spinner"');
            expect(text).toContain('"spin": true');
            expect(text).toContain('sirsoft-gdpr.consent.granting');
            expect(text).toContain('sirsoft-gdpr.consent.renewing');

            // 옛 단일 boolean 플래그 패턴 회귀 차단
            expect(text).not.toContain('"gdprBannerSubmitting": true');
        });
    });

    describe('환경설정 인라인 펼침 패널', () => {
        const banner = findById(root, 'gdpr_cookie_banner');
        const panel = findById(banner ?? null, 'gdpr_preferences_panel');

        it('gdpr_preferences_panel 이 배너 내부에 존재한다', () => {
            expect(panel).toBeTruthy();
        });

        it('_global.gdprPreferencesOpen === true 일 때만 표시된다', () => {
            expect(panel?.if).toBe('{{_global.gdprPreferencesOpen === true}}');
        });

        it('필수 카테고리 토글은 disabled', () => {
            const text = serializeForSearch(panel);
            expect(text).toContain('"disabled": "{{category?.required === true}}"');
        });

        it('저장 버튼 클릭 시 POST /consent/cookie + 배너 dismiss + 성공 토스트', () => {
            const text = serializeForSearch(panel);
            expect(text).toContain('/api/plugins/sirsoft-gdpr/consent/cookie');
            expect(text).toContain('"gdprBannerDismissed": true');
            expect(text).toContain('"gdprPreferencesOpen": false');
            expect(text).toContain('sirsoft-gdpr.consent.granted');
        });
    });

    describe('정책 링크', () => {
        it('privacy_policy_available일 때만 링크 표시', () => {
            const text = serializeForSearch(extension);
            expect(text).toContain('privacy_policy_available === true');
        });
    });

    describe('환경설정 펼침 — 카테고리 정보 영역 (#14 사용자 친화 표현)', () => {
        const banner = findById(root, 'gdpr_cookie_banner');
        const panel = findById(banner ?? null, 'gdpr_preferences_panel');
        const text = serializeForSearch(panel);
        const fullText = serializeForSearch(extension);

        it('운영자용 info.{key}.{scope|tools|legal_basis} 키를 직접 참조하지 않는다', () => {
            // 누적 검토 #14: 사용자(게스트) 측은 운영자 측과 동일한 info.* 키를 그대로 노출하던 회귀.
            // 운영자 측 info 는 분류 결정에 필요한 기술/법규 상세 (자동 차단 대상, GDPR Art.6(1)(a) 등)
            // 가 포함되어 사용자 입장에서는 과도/난해.
            expect(text).not.toContain('cookie_categories.info.necessary.scope');
            expect(text).not.toContain('cookie_categories.info.necessary.tools');
            expect(text).not.toContain('cookie_categories.info.necessary.legal_basis');
            expect(text).not.toContain('cookie_categories.info.analytics.scope');
            expect(text).not.toContain('cookie_categories.info.analytics.tools');
            expect(text).not.toContain('cookie_categories.info.analytics.legal_basis');
            expect(text).not.toContain('cookie_categories.info.marketing.scope');
            expect(text).not.toContain('cookie_categories.info.marketing.tools');
            expect(text).not.toContain('cookie_categories.info.marketing.legal_basis');
        });

        it('"자세히 보기" 토글 + 펼침 영역이 통째로 제거되었다 (카테고리 description 한 줄로 일원화)', () => {
            // PO 결정: 펼침 영역의 user_info description 이 카테고리 카드 자체의 description 과
            // 의미상 중복 → "자세히 보기" 토글 자체를 없애고, 카테고리 description 을 사용자 친화
            // 표현으로 갈아끼움 (시더 plugin.php / CookieCategoryService 의 기본값 갱신).
            expect(text).not.toContain('banner.category_details_expand');
            expect(text).not.toContain('banner.category_details_collapse');
            expect(text).not.toContain('gdprBannerExpandedCategoryIdx');
            expect(text).not.toContain('"chevron-down"');
            expect(text).not.toContain('"chevron-up"');
        });

        it('사용자 측에서 user_info.* 키 참조가 모두 제거되었다 (description 시더로 이전됨)', () => {
            // 펼침 영역과 함께 user_info.* 키 참조도 통째로 제거. 사용자 친화 description 은
            // 카테고리 데이터의 description 필드에 직접 들어감 (시더 디폴트).
            expect(fullText).not.toContain('cookie_categories.user_info');
        });

        it('legal_basis / scope / tools / description 라벨 키 참조 모두 제거', () => {
            // 회귀 가드: 일반 사용자에게 "GDPR Art.6(1)(a)" 등 법조항 / 운영자용 분류 라벨 노출 금지.
            expect(fullText).not.toContain('banner.category_legal_basis_label');
            expect(fullText).not.toContain('banner.category_scope_label');
            expect(fullText).not.toContain('banner.category_tools_label');
            expect(fullText).not.toContain('banner.category_description_label');
            expect(fullText).not.toContain('banner.category_examples_label');
            // 펼침 영역에 사용되던 아이콘들도 제거
            expect(fullText).not.toContain('"scale-balanced"');
            expect(fullText).not.toContain('"shield-halved"');
            expect(fullText).not.toContain('"screwdriver-wrench"');
            expect(fullText).not.toContain('"list-ul"');
            expect(fullText).not.toContain('"circle-info"');
        });
    });

    describe('카테고리 카드 — 임의값 회귀 가드 (#14 글씨 키우기)', () => {
        // PO 결정: 카테고리 카드 자체의 보조 텍스트 (필수 배지, 카테고리 설명) 도 너무 작아
        // 가독성 저하. 임의값 text-[10px] 11건을 모두 표준 text-xs 로 통일.
        it('cookie_banner.json 전체에서 text-[10px] 임의값이 사용되지 않는다', () => {
            const fullText = serializeForSearch(extension);
            expect(fullText).not.toMatch(/text-\[10px\]/);
        });
    });

    describe('운영 주체 + 저장 위치 푸터 라인 (EDPB §64 + GDPR Art.13(1)(f))', () => {
        const banner = findById(root, 'gdpr_cookie_banner');
        const footer = findById(banner ?? null, 'gdpr_banner_operator_footer');

        it('gdpr_banner_operator_footer 가 배너 내부에 존재한다', () => {
            // EDPB Guidelines 05/2020 §64: controller identity 가 동의 1차 화면에서 즉시 식별 가능해야
            // informed consent 의 유효성을 입증할 수 있다. 처리방침 링크 뒤로 숨기는 구조는 불충분.
            expect(footer).toBeTruthy();
        });

        it('둘 다 비면 푸터 라인 전체 숨김 — 어느 한 값만 있어도 노출', () => {
            expect(footer?.if).toBe(
                '{{!!gdprPublicSettings?.data?.legal_entity_name || !!gdprPublicSettings?.data?.data_storage_location}}'
            );
        });

        it('3-case 분기: 둘 다 / 운영자만 / 저장위치만 — 동시 노출 회피를 위해 각 케이스 if 가 상호 배타적', () => {
            // 한국어 조사 분기 + 자연어 한 줄 표현을 위해 ko/en lang 에 3종 키를 두고 if 가드로 분기.
            const children = (footer?.children ?? []) as AnyNode[];
            const both = children.find((c) =>
                String(c.text ?? '').includes('operator_and_storage_line')
            );
            const operatorOnly = children.find((c) =>
                String(c.text ?? '').includes('operator_only_line')
            );
            const storageOnly = children.find((c) =>
                String(c.text ?? '').includes('storage_only_line')
            );

            // 케이스 1: 둘 다 (entity && location)
            expect(both?.if).toBe(
                '{{!!gdprPublicSettings?.data?.legal_entity_name && !!gdprPublicSettings?.data?.data_storage_location}}'
            );
            // 케이스 2: 운영자만 (entity && !location)
            expect(operatorOnly?.if).toBe(
                '{{!!gdprPublicSettings?.data?.legal_entity_name && !gdprPublicSettings?.data?.data_storage_location}}'
            );
            // 케이스 3: 저장위치만 (!entity && location)
            expect(storageOnly?.if).toBe(
                '{{!gdprPublicSettings?.data?.legal_entity_name && !!gdprPublicSettings?.data?.data_storage_location}}'
            );
        });

        it('각 케이스의 다국어 키 + 토큰 보간 사용 (entity/location 자연어 분기)', () => {
            const text = serializeForSearch(footer);
            expect(text).toContain('sirsoft-gdpr.banner.operator_and_storage_line|entity=');
            expect(text).toContain('|location=');
            expect(text).toContain('sirsoft-gdpr.banner.operator_only_line|entity=');
            expect(text).toContain('sirsoft-gdpr.banner.storage_only_line|location=');
        });

        it('푸터 라인은 시각적 무게 최소화 — 작은 보조 텍스트 시맨틱 + 상단 구분선', () => {
            // 동의 행위 자체에 시선이 가도록 운영 주체는 부드러운 보조 정보로 처리.
            // 작은 회색 텍스트 톤은 표준 시맨틱(.text-tertiary)으로 통일 (#399).
            const className = (footer?.props as { className?: string })?.className ?? '';
            expect(className).toContain('text-tertiary');
            expect(className).toContain('border-t');
        });
    });

    describe('규정 준수', () => {
        it('핸들러는 G7 내장 핸들러 또는 sirsoft-gdpr.* 플러그인 등록 핸들러만 사용', () => {
            // G7 빌트인 핸들러 + 플러그인이 ActionDispatcher 에 등록한 sirsoft-gdpr.* 네임스페이스 핸들러.
            // module-assets.md 의 핸들러 네이밍 규칙: {plugin-identifier}.{handler-name}
            const allowedBuiltins = [
                'apiCall', 'setState', 'sequence', 'toast', 'refetchDataSource',
            ];
            for (const h of collectHandlers(extension)) {
                if (h.startsWith('sirsoft-gdpr.')) continue;
                expect(allowedBuiltins).toContain(h);
            }
        });

        it('i18n 키 prefix 검증', () => {
            for (const k of collectGdprI18nKeys(extension)) {
                expect(k).toMatch(/^\$t:sirsoft-gdpr\./);
            }
        });
    });
});
