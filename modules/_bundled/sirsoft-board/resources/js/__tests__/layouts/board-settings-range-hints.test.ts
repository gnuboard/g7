/**
 * 게시판 환경설정 — 숫자 필드 범위 힌트 동적 바인딩 회귀 테스트 (이슈 #413)
 *
 * 배경: 환경설정 "게시판 설정" 탭의 숫자 입력 필드는 description 에 허용 범위를
 *   `({{min}}~{{max}})` 형태로 표시한다. 범위 값은 `config('sirsoft-board.limits')` 를
 *   SSoT 로 하여 BoardSettingsController.index() 가 `data._meta.limits` 로 노출하고,
 *   데이터소스 `settings`(initLocal "form") 를 통해 `_local.form._meta.limits` 로 바인딩된다.
 *
 * 회귀 차단 포인트:
 *   1. description text 가 `$t:...|min={{_local?.form?._meta?.limits?.{f}_min ?? N}}|max={{...}}`
 *      파라미터를 포함해야 한다. (파라미터 누락 시 lang 의 `{{min}}`/`{{max}}` 가 raw 노출)
 *   2. 경로는 코어 전역 `_global.settings` 와 ID 충돌하는 `settings.data._meta` 가 아닌
 *      `_local.form._meta` 여야 한다. (충돌 시 fallback 으로만 표시되거나 raw 노출)
 *   3. Input 의 min/max 도 동일 동적 표현식이어야 한다. (하드코딩 시 config 변경과 어긋남)
 *
 * 실제 사용 파일(부모 admin_board_settings.json 이 partial 로 include):
 *   _tab_board_settings_list / _post / _reply / _comment / _attachment
 *   (구 _tab_basic_defaults.json 은 더 이상 사용하지 않음 — 삭제됨)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, it, expect } from 'vitest';

import tabReportPolicy from '../../../layouts/admin/partials/admin_board_settings/_tab_report_policy.json';
import tabSpamSecurity from '../../../layouts/admin/partials/admin_board_settings/_tab_spam_security.json';
import tabList from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_list.json';
import tabPost from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_post.json';
import tabReply from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_reply.json';
import tabComment from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_comment.json';
import tabAttachment from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_attachment.json';

/**
 * [필드명, partial, limits 키] 매핑.
 * limits 키는 description/Input 이 참조하는 `_meta.limits.{키}_min/max` 의 키 prefix.
 * per_page_mobile 은 per_page 와 동일한 limits 범위(per_page_min/max)를 공유한다.
 */
const fields: Array<[string, unknown, string]> = [
    ['per_page', tabList, 'per_page'],
    ['per_page_mobile', tabList, 'per_page'],
    ['min_title_length', tabPost, 'min_title_length'],
    ['max_title_length', tabPost, 'max_title_length'],
    ['min_content_length', tabPost, 'min_content_length'],
    ['max_content_length', tabPost, 'max_content_length'],
    ['max_reply_depth', tabReply, 'max_reply_depth'],
    ['min_comment_length', tabComment, 'min_comment_length'],
    ['max_comment_length', tabComment, 'max_comment_length'],
    ['max_comment_depth', tabComment, 'max_comment_depth'],
    ['max_file_size', tabAttachment, 'max_file_size'],
    ['max_file_count', tabAttachment, 'max_file_count'],
];

describe('게시판 환경설정 — 범위 힌트 동적 바인딩 가드 (#413)', () => {
    it.each(fields)(
        '%s — description text 가 _local.form._meta.limits 파라미터를 포함함',
        (field, layout, limitsKey) => {
            const str = JSON.stringify(layout);
            // description 키에 |min=.../|max=... 파라미터가 _local.form._meta.limits 경로로 존재
            expect(str).toMatch(
                new RegExp(
                    `descriptions\\.${field}\\|min=\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_min`,
                ),
            );
            expect(str).toMatch(
                new RegExp(`max=\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_max`),
            );
        },
    );

    it.each(fields)(
        '%s — Input min/max 가 하드코딩 정수가 아닌 _local.form._meta.limits 동적 표현식임',
        (_field, layout, limitsKey) => {
            const str = JSON.stringify(layout);
            // Input 의 min/max 가 동적 표현식 (하드코딩 정수 금지)
            expect(str).toMatch(
                new RegExp(`"min":\\s*"\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_min`),
            );
            expect(str).toMatch(
                new RegExp(`"max":\\s*"\\{\\{_local\\?\\.form\\?\\._meta\\?\\.limits\\?\\.${limitsKey}_max`),
            );
        },
    );

    it.each(fields)(
        '%s — 코어 전역과 충돌하는 settings.data._meta 경로를 사용하지 않음',
        (_field, layout, limitsKey) => {
            const str = JSON.stringify(layout);
            // settings?.data?._meta 경로는 _global.settings(코어 사이트 설정)와 ID 충돌 → 금지
            expect(str).not.toMatch(
                new RegExp(`settings\\?\\.data\\?\\._meta\\?\\.limits\\?\\.${limitsKey}`),
            );
        },
    );
});

/**
 * `config/board.php` 의 `limits` 블록을 키 → 값으로 읽는다.
 *
 * @returns 한계값 키 맵
 */
function configuredLimits(): Record<string, number> {
    const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
    const source = fs.readFileSync(path.join(moduleRoot, 'config/board.php'), 'utf-8');
    const block = /'limits'\s*=>\s*\[([\s\S]*?)\n\s*\],/.exec(source);

    expect(block, "config/board.php 에서 'limits' 블록을 찾지 못했습니다.").not.toBeNull();

    const limits: Record<string, number> = {};

    for (const line of block![1].split('\n')) {
        const matched = /'([a-z0-9_]+)'\s*=>\s*(-?[\d.]+)\s*,/.exec(line);
        if (matched) {
            limits[matched[1]] = Number(matched[2]);
        }
    }

    return limits;
}

/**
 * 레이아웃에서 해당 필드의 min/max prop 표현식을 찾는다.
 *
 * @param layout 레이아웃 트리
 * @param field 필드 name (예: report_policy.auto_hide_threshold)
 * @returns min/max 표현식
 */
function boundExpressions(layout: unknown, field: string): { min?: string; max?: string } {
    let found: { min?: string; max?: string } = {};

    const visit = (node: any) => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) {
            node.forEach(visit);

            return;
        }
        if (node.props?.name === field) {
            found = { min: node.props.min, max: node.props.max };
        }
        Object.values(node).forEach(visit);
    };

    visit(layout);

    return found;
}

/**
 * 신고 정책 · 스팸 보안 탭의 숫자 필드 — [필드 name, partial, limits 키 prefix].
 *
 * 이 탭들은 위 목록과 달리 description 범위 힌트를 쓰지 않으므로 Input 경계만 검사한다.
 */
const policyFields: Array<[string, unknown, string]> = [
    ['report_policy.auto_hide_threshold', tabReportPolicy, 'auto_hide_threshold'],
    ['report_policy.daily_report_limit', tabReportPolicy, 'daily_report_limit'],
    ['report_policy.rejection_limit_count', tabReportPolicy, 'rejection_limit_count'],
    ['report_policy.rejection_limit_days', tabReportPolicy, 'rejection_limit_days'],
    ['spam_security.report_cooldown_seconds', tabReportPolicy, 'report_cooldown_seconds'],
    ['spam_security.post_cooldown_seconds', tabSpamSecurity, 'post_cooldown_seconds'],
    ['spam_security.comment_cooldown_seconds', tabSpamSecurity, 'comment_cooldown_seconds'],
    ['spam_security.view_count_cache_ttl', tabSpamSecurity, 'view_count_cache_ttl'],
];

describe('게시판 환경설정 — 신고 정책 · 스팸 보안 탭 경계값 (#493 B4)', () => {
    const limits = configuredLimits();

    it.each(policyFields)('%s — min/max 가 한계값 설정을 읽는다', (field, layout, limitsKey) => {
        const bounds = boundExpressions(layout, field);

        for (const bound of ['min', 'max'] as const) {
            expect(bounds[bound], `${field} 에 ${bound} 이 없습니다.`).toBeDefined();
            expect(String(bounds[bound])).toContain(`limits?.${limitsKey}_${bound}`);
        }
    });

    it.each(policyFields)('%s — 폴백이 설정 파일의 한계값과 같다', (field, layout, limitsKey) => {
        const bounds = boundExpressions(layout, field);

        for (const bound of ['min', 'max'] as const) {
            const matched = /\?\?\s*(-?[\d.]+)\s*\}\}/.exec(String(bounds[bound]));

            expect(matched, `${field} 의 ${bound} 폴백을 찾지 못했습니다.`).not.toBeNull();
            expect(Number(matched![1]), `${field} 의 ${bound} 폴백이 설정 파일 값과 다릅니다.`).toBe(
                limits[`${limitsKey}_${bound}`],
            );
        }
    });

    it('자동 숨김 기준은 0(비활성)을 입력할 수 있다', () => {
        // 화면이 min=1 이면 "0=비활성" 안내와 어긋나 비활성으로 되돌릴 방법이 없어진다.
        expect(limits.auto_hide_threshold_min).toBe(0);
        expect(boundExpressions(tabReportPolicy, 'report_policy.auto_hide_threshold').min).toContain('?? 0');
    });
});

/**
 * 서버 저장 규칙(`StoreBoardSettingsRequest`) 원문.
 */
function settingsRequestSource(): string {
    const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');

    return fs.readFileSync(
        path.join(moduleRoot, 'src/Http/Requests/Admin/StoreBoardSettingsRequest.php'),
        'utf-8',
    );
}

describe('게시판 환경설정 — 서버 저장 규칙 정합 (#493 B4)', () => {
    const limits = configuredLimits();
    const requestSource = settingsRequestSource();

    /**
     * 이 화면에는 네이티브 `<form>` 이 없다. 저장은 `apiCall` 이 `_local.form` 을 직접 실어
     * 보내므로 Input 의 `min`/`max` 속성은 브라우저 제출을 막지 못한다 — 안내일 뿐이다.
     * 그래서 실제 관문은 서버 규칙 하나뿐이고, 화면과 규칙이 어긋나면 화면이 거부하는
     * 것처럼 보이는 값이 200 으로 저장된다(B4 의 최초 증상).
     *
     * 세 지점(설정 파일 · 서버 규칙 · 화면 바인딩)이 같은 키·같은 폴백을 가리키는지 대조한다.
     */
    it.each(policyFields)('%s — 서버 규칙이 화면과 같은 한계값 키를 읽는다', (field, layout, limitsKey) => {
        const rule = new RegExp(`'${field.replace('.', '\\.')}'\\s*=>\\s*\\[([^\\]]*)\\]`).exec(requestSource);

        expect(rule, `${field} 의 저장 규칙을 찾지 못했습니다.`).not.toBeNull();

        for (const bound of ['min', 'max'] as const) {
            // 화면이 읽는 키와 규칙이 읽는 키가 같아야 한다
            expect(String(boundExpressions(layout, field)[bound])).toContain(`limits?.${limitsKey}_${bound}`);
            expect(rule![1], `${field} 의 ${bound} 규칙이 한계값 설정을 읽지 않습니다.`)
                .toContain(`'${limitsKey}_${bound}'`);

            // 규칙의 폴백도 설정 파일 값과 같아야 한다 (설정이 비었을 때 양쪽이 갈라지지 않도록)
            const fallback = new RegExp(`'${limitsKey}_${bound}',\\s*(-?[\\d.]+)\\)`).exec(rule![1]);

            expect(fallback, `${field} 의 ${bound} 규칙 폴백을 찾지 못했습니다.`).not.toBeNull();
            expect(Number(fallback![1]), `${field} 의 ${bound} 규칙 폴백이 설정 파일 값과 다릅니다.`)
                .toBe(limits[`${limitsKey}_${bound}`]);
        }
    });
});
