/**
 * sirsoft-totp 레이아웃 구조 검증 테스트
 *
 * @description
 * 이 화면들의 불변조건은 「평문은 한 번만 보인다」와 「확정 전에는 로그인 수단이 되지
 * 않는다」입니다. 렌더링 조건이 어긋나면 그 두 가지가 조용히 깨지므로, 컴파일된 런타임
 * 계약(JSON)에서 직접 확인합니다.
 *
 * 검증 축:
 * - 등록 화면: 단계별 렌더 조건(안내/등록/확정/백업 코드)이 서로 배타인가
 * - 백업 코드: 1회 노출 경고와 반응형 그리드(5→3→2열)
 * - 회원 상세 카드: 전용 권한 보유자에게만 보이고, 되돌릴 수 없는 조치에 확인이 붙는가
 * - 다국어 키가 이 플러그인 네임스페이스를 쓰는가
 * - 다크모드 클래스 병기
 */

import { describe, it, expect } from 'vitest';

import totpSecurityLayout from '../../../layouts/admin/plugin_settings.json';
import adminUserCardExtension from '../../../extensions/admin_user_totp_card.json';

/**
 * 레이아웃 트리에서 data-ref(또는 id)로 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param ref 찾을 data-ref 또는 id
 * @returns 찾은 노드, 없으면 null
 */
function findByRef(node: any, ref: string): any | null {
    if (!node || typeof node !== 'object') return null;

    if (node.props?.['data-ref'] === ref || node.id === ref) return node;

    // 배열은 그대로 순회한다 — slots.content 처럼 컴포넌트 목록이 값으로 오는 자리가 있다.
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findByRef(child, ref);
            if (found) return found;
        }

        return null;
    }

    // `slots` 는 {이름: [컴포넌트…]} 맵이라 고정 키 목록으로는 닿지 않는다.
    // 구조가 바뀌어도 따라가도록 객체 값 전체를 훑는다.
    for (const value of Object.values(node)) {
        if (value && typeof value === 'object') {
            const found = findByRef(value, ref);
            if (found) return found;
        }
    }

    return null;
}

/**
 * 트리 전체를 문자열로 펼친다 — 키 사용 여부·클래스 병기 검사에 쓴다.
 *
 * @param node 대상 노드
 * @returns 직렬화 문자열
 */
function flatten(node: unknown): string {
    return JSON.stringify(node);
}

describe('plugin_settings 등록 화면', () => {
    it('네 단계 카드가 모두 존재한다', () => {
        for (const ref of [
            'totp_security_intro_card',
            'totp_security_enroll_card',
            'totp_security_enrolled_card',
            'totp_security_backup_card',
        ]) {
            expect(findByRef(totpSecurityLayout, ref), `${ref} 가 없습니다`).not.toBeNull();
        }
    });

    it('등록 전 안내는 등록을 마쳤거나 진행 중이면 숨는다', () => {
        const intro = findByRef(totpSecurityLayout, 'totp_security_intro_card');
        const condition = flatten(intro?.props?.if ?? intro?.if ?? '');

        // 이미 등록한 사람에게 「등록 시작」이 보이면 기존 앱을 무효로 만드는 길로 안내하게 된다.
        expect(condition).toContain('enrolled');
        expect(condition).toContain('_local.secret');
    });

    it('백업 코드 화면은 발급 직후에만 뜬다', () => {
        const backup = findByRef(totpSecurityLayout, 'totp_security_backup_card');
        const condition = flatten(backup?.props?.if ?? backup?.if ?? '');

        // 상태에만 있는 값이라 새로고침하면 사라진다 — 조건이 서버 응답을 보면 안 된다.
        expect(condition).toContain('_local.backupCodes');
    });

    it('백업 코드 화면에 1회 노출 경고가 있다', () => {
        const warning = findByRef(totpSecurityLayout, 'totp_security_backup_warning_text');

        expect(warning).not.toBeNull();
        expect(flatten(warning)).toContain('sirsoft-totp.admin.security.backup.warning');
    });

    it('백업 코드 그리드가 데스크톱 5열에서 모바일 2열로 좁혀진다', () => {
        const grid = findByRef(totpSecurityLayout, 'totp_security_backup_grid');
        const serialized = flatten(grid);

        expect(serialized).toContain('grid-cols-5');
        expect(serialized).toContain('grid-cols-3');
        expect(serialized).toContain('grid-cols-2');
    });

    it('백업 코드를 한 번에 가져갈 복사 버튼이 있다', () => {
        // 이 코드는 이 화면에서만 보인다 — 옮겨 적다 놓치면 휴대폰을 잃었을 때 로그인할
        // 길이 사라지므로, 「보관했습니다」 앞에 가져갈 수단이 있어야 한다.
        const copy = findByRef(totpSecurityLayout, 'totp_security_backup_copy');
        const serialized = flatten(copy);

        expect(copy).not.toBeNull();
        expect(serialized).toContain('sirsoft-totp.copyToClipboard');
        // 열 개를 줄바꿈으로 이어 넘긴다 — 붙여넣은 쪽에서 한 줄에 하나씩 남아야 한다.
        expect(serialized).toContain('_local.backupCodes');
    });

    it('QR 자리와 수동 입력 키가 함께 있다', () => {
        // 카메라를 쓸 수 없는 환경에서도 등록할 수 있어야 한다.
        expect(findByRef(totpSecurityLayout, 'totp_security_qr')).not.toBeNull();
        expect(findByRef(totpSecurityLayout, 'totp_security_manual_key')).not.toBeNull();
    });

    it('QR 자리가 otpauth URI 를 속성으로 받는다', () => {
        const qr = findByRef(totpSecurityLayout, 'totp_security_qr');

        // 시크릿을 외부 QR 서비스로 보내지 않고 브라우저 안에서 그린다.
        expect(flatten(qr)).toContain('data-totp-qr');
        expect(flatten(qr)).toContain('otpauthUri');
    });

    it('해제와 백업 코드 재발급이 코드 입력을 요구한다', () => {
        for (const ref of ['totp_security_disable', 'totp_security_regenerate']) {
            const button = findByRef(totpSecurityLayout, ref);
            // 코드가 비어 있으면 눌리지 않아야 한다 — 열린 세션만으로 2단계 인증을 끌 수 없다.
            expect(flatten(button?.props?.disabled ?? button?.disabled ?? ''), `${ref}`).toContain('_local.code');
        }
    });

    it('모든 다국어 키가 이 플러그인 네임스페이스를 쓴다', () => {
        const serialized = flatten(totpSecurityLayout);
        const keys = serialized.match(/\$t:[a-zA-Z0-9_.\-]+/g) ?? [];

        expect(keys.length).toBeGreaterThan(0);
        for (const key of keys) {
            expect(key.startsWith('$t:sirsoft-totp.'), `${key} 가 플러그인 네임스페이스 밖입니다`).toBe(true);
        }
    });

    it('색상을 쓰는 버튼이 다크모드 변형을 병기한다', () => {
        const serialized = flatten(totpSecurityLayout);

        expect(serialized).toContain('dark:bg-blue-500');
        expect(serialized).toContain('dark:bg-red-500');
    });
});

describe('plugin_settings 운영 설정 카드 (발급자 표기·메일 폴백)', () => {
    // 2026-10-06 실측: 설정 화면에 입력 칸이 하나도 없어 CHANGELOG 가 약속한
    // 「사이트 이름을 설정할 수 있습니다」「플러그인 설정에서 끌 수 있으며」를 화면으로 할 수 없었다.
    // 백엔드(설정 스키마·PUT /api/admin/plugins/{id}/settings)는 이미 있었고 화면만 빠져 있었다.

    it('저장된 플러그인 설정을 읽는 데이터 소스가 있다', () => {
        const sources = (totpSecurityLayout as any).data_sources ?? [];
        const settings = sources.find((s: any) => s.endpoint === '/api/admin/plugins/sirsoft-totp/settings');

        expect(settings, '플러그인 설정 데이터 소스가 없습니다').toBeDefined();
        expect(settings.method ?? 'GET').toBe('GET');
    });

    it('발급자 표기 입력칸과 메일 폴백 토글이 있다', () => {
        const card = findByRef(totpSecurityLayout, 'totp_settings_card');
        const serialized = flatten(card);

        expect(card, '운영 설정 카드가 없습니다').not.toBeNull();
        expect(serialized).toContain('issuer');
        expect(serialized).toContain('allow_mail_fallback');
        // 상한은 서버 스키마(max 64)와 같아야 한다 — 화면이 더 받으면 저장에서야 422 로 드러난다.
        expect(serialized).toContain('64');
    });

    it('저장 버튼이 플러그인 설정 API 를 PUT 으로 부른다', () => {
        const save = findByRef(totpSecurityLayout, 'totp_settings_save');
        const serialized = flatten(save);

        expect(save, '저장 버튼이 없습니다').not.toBeNull();
        expect(serialized).toContain('/api/admin/plugins/sirsoft-totp/settings');
        expect(serialized).toContain('PUT');
    });

    it('폴백 토글은 보이는 라벨 없이 접근 가능한 이름을 갖는다', () => {
        // 라벨 문구가 옆 <span> 에만 있어 스위치(role=switch)의 이름이 비어 있었다(7.1.0 실측 O-B6).
        // 템플릿 Toggle 에 없는 prop(autoBinding)은 넘기지 않는다 — name 이 없어 자동 바인딩 대상도 아니다.
        const toggle = findByRef(totpSecurityLayout, 'totp_settings_fallback_toggle');

        expect(toggle?.name).toBe('Toggle');
        expect(toggle?.props?.ariaLabel).toBe('$t:sirsoft-totp.admin.settings.fallback_label');
        expect(toggle?.props?.label).toBeUndefined();
        expect(toggle?.props).not.toHaveProperty('autoBinding');
    });

    it('저장 오류 줄은 반복 행마다 다른 data-ref 를 쓴다', () => {
        const errors = findByRef(totpSecurityLayout, 'totp_settings_errors');
        const item = (errors?.children ?? [])[0];

        expect(String(item?.iteration?.index_var ?? '')).not.toBe('');
        expect(String(item?.props?.['data-ref'] ?? item?.id ?? '')).toContain('{{');
    });

    it('폴백을 끄면 미등록자 로그인이 막힌다는 경고가 붙는다', () => {
        const warning = findByRef(totpSecurityLayout, 'totp_settings_fallback_off_warning');

        expect(warning).not.toBeNull();
        expect(flatten(warning)).toContain('allow_mail_fallback');
    });
});

describe('회원 상세 인증 앱 카드 (layout_extension)', () => {
    it('회원 상세 레이아웃의 확장 슬롯을 대상으로 한다', () => {
        const serialized = flatten(adminUserCardExtension);

        // 코어 화면을 고치지 않고 슬롯에만 붙는다 — 플러그인을 끄면 함께 사라진다.
        // 대상은 «상세»(admin_user_detail) 다 — 수정 폼이 아니라 목록에서 회원을 눌러
        // 닿는 그 화면이며, 계획서 디자인 M 이 못박은 자리다.
        expect(serialized).toContain('admin_user_detail');
        expect(serialized).toContain('extension_tab_content');
    });

    it('상세 화면의 탭 목록에 항목을 하나 더한다', () => {
        const serialized = flatten(adminUserCardExtension);

        // 주입이 두 군데로 갈린다 — 탭 버튼(inject_props)과 본문(append_child)을 잇는 것은
        // id 하나뿐이라, 한쪽만 있으면 탭은 있고 내용이 빈 화면이 된다.
        expect(serialized).toContain('user_detail_tabs');
        expect(serialized).toContain('inject_props');
        expect(serialized).toContain('ext_totp_security');
        expect(serialized).toContain('$t:sirsoft-totp.admin.user_card.tab_label');
    });

    it('탭과 본문이 같은 조건으로 함께 뜨고 함께 사라진다', () => {
        const serialized = flatten(adminUserCardExtension);

        // 권한은 **화면 조건으로 걸지 않는다.** 관리자 셸(`_admin_base`)이 전역에 올리는 것은
        // installedModules·installedPlugins·adminNotificationCount 뿐이라
        // `_global.currentUser` 는 항상 undefined 였고, 그 조건을 달았더니 탭만 뜨고 본문이
        // 통째로 사라졌다(2026-09-18 브라우저 실측). 형제 확장도 전부 서버 제공 값만 쓴다.
        // 강제 해제의 권한 집행은 라우트 미들웨어가 정본이다.
        // 주석에는 이 경위가 적혀 있으므로 **조건식만** 모아서 본다.
        const conditions = (serialized.match(/"if":"[^"]*"/g) ?? []).join(' ');

        expect(conditions).not.toContain('_global.currentUser');

        // 탭 버튼과 본문은 **같은 조건**이어야 한다 — 어긋나면 「탭은 있는데 빈 화면」이 된다.
        const guards = conditions.match(/route\?\.id/g) ?? [];

        expect(guards.length).toBeGreaterThanOrEqual(2);
    });

    it('되돌릴 수 없는 해제에 확인 대화상자가 붙는다', () => {
        const serialized = flatten(adminUserCardExtension);

        expect(serialized).toContain('confirm');
        expect(serialized).toContain('sirsoft-totp.admin.user_card.confirm');
    });

    it('관리자 해제 API 를 DELETE 로 호출한다', () => {
        const serialized = flatten(adminUserCardExtension);

        expect(serialized).toContain('/api/plugins/sirsoft-totp/admin/credentials/');
        expect(serialized).toContain('DELETE');
    });

    // @scenario viewer=reset_permitted_admin, enrollment=confirmed
    // @effects member_card_shows_enrollment_and_hides_reset_when_not_enrolled
    it('등록 여부를 보이고 등록된 회원에게만 [강제 해제] 를 낸다 (7.1.0 관측 R-26)', () => {
        // 미등록 회원에게도 같은 해제 단추가 보이던 것을 막는다 — 조건은 서버가 해제 권한자에게만 싣는
        // `totp_enrollment.enrolled` 하나다(화면 쪽 권한 판정 없음).
        const reset = findByRef(adminUserCardExtension, 'admin_user_totp_reset');
        expect(reset?.if).toBe('{{user?.data?.totp_enrollment?.enrolled === true}}');

        const hint = findByRef(adminUserCardExtension, 'admin_user_totp_hint');
        expect(hint?.if).toBe('{{user?.data?.totp_enrollment?.enrolled === true}}');

        const enrolled = findByRef(adminUserCardExtension, 'admin_user_totp_status_enrolled');
        const notEnrolled = findByRef(adminUserCardExtension, 'admin_user_totp_status_not_enrolled');
        expect(enrolled?.if).toBe('{{user?.data?.totp_enrollment?.enrolled === true}}');
        expect(notEnrolled?.if).toBe('{{user?.data?.totp_enrollment?.enrolled === false}}');
        expect(flatten(enrolled)).toContain('$t:sirsoft-totp.admin.user_card.enrolled');
        expect(flatten(notEnrolled)).toContain('$t:sirsoft-totp.admin.user_card.not_enrolled');

        const notice = findByRef(adminUserCardExtension, 'admin_user_totp_not_enrolled_notice');
        expect(notice?.if).toBe('{{user?.data?.totp_enrollment?.enrolled === false}}');
    });

    // @scenario viewer=admin_without_reset_permission, enrollment=confirmed
    // @effects member_card_explains_missing_status_without_permission
    it('등록 여부가 실리지 않으면(해제 권한 없음) 그 사실을 알리고 단추를 내지 않는다', () => {
        const status = findByRef(adminUserCardExtension, 'admin_user_totp_status');
        const unavailable = findByRef(adminUserCardExtension, 'admin_user_totp_status_unavailable');

        expect(status?.if).toBe('{{!!user?.data?.totp_enrollment}}');
        expect(unavailable?.if).toBe('{{!user?.data?.totp_enrollment}}');
        expect(flatten(unavailable)).toContain('$t:sirsoft-totp.admin.user_card.status_unavailable');
    });

    // @scenario viewer=reset_permitted_admin, enrollment=confirmed
    // @effects member_card_refreshes_after_reset
    it('해제가 끝나면 회원 상세를 다시 받아 카드가 「미등록」으로 바뀐다', () => {
        const reset = findByRef(adminUserCardExtension, 'admin_user_totp_reset');
        const serialized = flatten(reset);

        expect(serialized).toContain('refetchDataSource');
        expect(serialized).toContain('"dataSourceId":"user"');
    });
});
