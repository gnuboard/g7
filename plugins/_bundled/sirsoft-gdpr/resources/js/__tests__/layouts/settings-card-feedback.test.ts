// e2e:allow 레이아웃 조건식·문구 수정의 단위 검증 — 같은 화면은 Playwright 브라우저로 ko/en 재실측해 이력(20261007_1905_710_KLM_M1)에 남김
/**
 * @file settings-card-feedback.test.ts
 * @description GDPR 설정 화면이 「없음」과 「볼 수 없음」, 저장 오류의 자리를 구분해 알리는지
 *
 * 회귀 배경(7.1.0 실측 M1):
 * - 알림 템플릿 카드: 알림 정의 조회가 403 이면 fallback 이 빈 목록을 줘 「등록된 알림 정의가 없습니다.」가
 *   나왔다 — 권한이 없는 관리자는 기능이 없다고 오해한다.
 * - 통지문 서문: 길이 초과 422 가 상단 알림·토스트에만 나오고 칸 아래에는 아무 표시가 없었다(서문 오류 키는
 *   언어별 `annual_notice.introduction.{언어}`).
 * - 정책 스냅샷 창 안내가 「발행 시점 settings 의 …」처럼 내부 용어를 그대로 보였다.
 *
 * 조건식은 테스트에 옮겨 적지 않고 소스(`.g7.html`)에서 구운 짝 JSON 에서 꺼내 평가한다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUT_DIR = path.resolve(__dirname, '../../../layouts/admin');
const settings: any = JSON.parse(fs.readFileSync(path.join(LAYOUT_DIR, 'plugin_settings.json'), 'utf8'));
const LANG_DIR = path.resolve(__dirname, '../../../lang');
const JA = path.resolve(__dirname, '../../../../../../../lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/frontend/ja.json');

/**
 * 트리에서 조건에 맞는 첫 노드를 찾는다.
 *
 * @param pred 판정 함수
 * @returns 노드 또는 undefined
 */
function find(pred: (node: any) => boolean): any {
    const stack: unknown[] = [settings];
    while (stack.length) {
        const node: any = stack.pop();
        if (Array.isArray(node)) {
            stack.push(...node);
            continue;
        }
        if (!node || typeof node !== 'object') continue;
        if (pred(node)) return node;
        stack.push(...Object.values(node));
    }
    return undefined;
}

/**
 * `{{ }}` 조건식을 주어진 문맥으로 평가한다.
 *
 * @param expr 조건식 원문
 * @param ctx 문맥 변수
 * @returns 평가 결과
 */
function evaluate(expr: string, ctx: Record<string, unknown>): unknown {
    const body = expr.replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    const fn = new Function(...Object.keys(ctx), 'return (' + body + ');');
    return fn(...Object.values(ctx));
}

describe('알림 템플릿 카드 — 권한 없음·불러오기 실패를 「정의 없음」과 구분', () => {
    const source = (settings.data_sources ?? []).find((ds: any) => ds.id === 'gdprNotificationDefinitions');
    const empty = find((n) => n.id === 'gdpr_notification_empty' || n.data_ref === 'gdpr_notification_empty' || n['data-ref'] === 'gdpr_notification_empty');
    const forbidden = find((n) => n.text === '$t:sirsoft-gdpr.notification_definitions.forbidden_message');
    const failed = find((n) => n.text === '$t:sirsoft-gdpr.notification_definitions.load_failed_message');
    const ctxFor = (error: string | undefined, rows: unknown[]) => ({
        gdprNotificationDefinitions: { data: { data: rows } },
        _local: { gdprNotificationDefinitionsError: error },
    });

    /**
     * @scenario case=definitions_forbidden
     * @effects notification_card_distinguishes_forbidden_from_empty
     */
    it('403 이면 실패 종류를 남기고 권한 없음 안내만 보인다', () => {
        const onForbidden = source?.errorHandling?.['403'];
        expect(JSON.stringify(onForbidden)).toContain('gdprNotificationDefinitionsError');
        expect(JSON.stringify(onForbidden)).toContain('forbidden');
        expect(forbidden).toBeTruthy();
        const ctx = ctxFor('forbidden', []);
        expect(evaluate(forbidden.if, ctx)).toBe(true);
        expect(evaluate(empty.if, ctx)).toBe(false);
        expect(evaluate(failed.if, ctx)).toBe(false);
    });

    /**
     * @scenario case=definitions_failed
     * @effects notification_card_distinguishes_forbidden_from_empty
     */
    it('그 밖의 실패(500·네트워크)는 불러오지 못했다고 알린다', () => {
        expect(JSON.stringify(source?.errorHandling?.default)).toContain('failed');
        const ctx = ctxFor('failed', []);
        expect(evaluate(failed.if, ctx)).toBe(true);
        expect(evaluate(empty.if, ctx)).toBe(false);
        expect(evaluate(forbidden.if, ctx)).toBe(false);
    });

    /**
     * @scenario case=definitions_empty
     * @effects notification_card_distinguishes_forbidden_from_empty
     */
    it('정상 응답이 비었을 때만 「등록된 알림 정의가 없습니다」를 보인다', () => {
        const ctx = ctxFor(undefined, []);
        expect(evaluate(empty.if, ctx)).toBe(true);
        expect(evaluate(forbidden.if, ctx)).toBe(false);
        expect(evaluate(failed.if, ctx)).toBe(false);
        expect(evaluate(empty.if, ctxFor(undefined, [{ id: 1 }]))).toBe(false);
    });
});

describe('통지문 서문 — 언어별 오류를 칸 아래에 보인다', () => {
    const error = find((n) => typeof n.text === 'string' && n.text.includes("'annual_notice.introduction'") && n.props?.className === 'form-error');

    /**
     * @scenario case=introduction_error
     * @effects introduction_error_shown_under_field
     */
    it('`annual_notice.introduction.ko` 오류가 있으면 그 문구를 칸 아래에 그린다', () => {
        expect(error).toBeTruthy();
        const ctx = { _local: { errors: { 'annual_notice.introduction.ko': ['통지문 서문 (한국어) 필드는 2000자를 초과하면 안 됩니다.'] } } };
        expect(evaluate(error.if, ctx)).toBeTruthy();
        expect(evaluate(error.text, ctx)).toBe('통지문 서문 (한국어) 필드는 2000자를 초과하면 안 됩니다.');
        expect(evaluate(error.if, { _local: { errors: { 'annual_notice.batch_size': ['x'] } } })).toBeFalsy();
        expect(evaluate(error.if, { _local: { errors: null } })).toBeFalsy();
    });
});

describe('정책 스냅샷 창 안내 — 내부 용어 없이', () => {
    /**
     * @scenario case=snapshot_description
     * @effects snapshot_description_uses_user_terms
     */
    it.each([
        ['ko', path.join(LANG_DIR, 'ko.json')],
        ['en', path.join(LANG_DIR, 'en.json')],
        ['ja', JA],
    ])('%s 안내 문구에 설정 키 이름(settings)이 그대로 나오지 않는다', (_locale, file) => {
        const text: string = JSON.parse(fs.readFileSync(file, 'utf8')).settings.policy_version.snapshot_modal.description;
        expect(text).not.toMatch(/\bsettings\b/);
        expect(text.length).toBeGreaterThan(10);
    });
});

describe('알림 템플릿 편집 창 — 「이벤트 유발자 제외」 스위치 이름', () => {
    const modal: any = JSON.parse(
        fs.readFileSync(path.join(LAYOUT_DIR, 'partials/plugin_settings/_modal_notification_template_edit.json'), 'utf8'),
    );

    /**
     * @scenario case=exclude_trigger_switch
     * @effects exclude_trigger_switch_has_accessible_name
     */
    it('스위치가 label 로 이름을 받는다(옆 글자만 따로 두면 이름 없는 스위치로 읽힌다)', () => {
        const stack: unknown[] = [modal];
        let toggle: any;
        while (stack.length && !toggle) {
            const node: any = stack.pop();
            if (Array.isArray(node)) {
                stack.push(...node);
                continue;
            }
            if (!node || typeof node !== 'object') continue;
            if (node.name === 'Toggle' && String(node.props?.checked ?? '').includes('exclude_trigger_user')) toggle = node;
            stack.push(...Object.values(node));
        }
        expect(toggle).toBeTruthy();
        expect(toggle.props.label).toBe('$t:admin.settings.notification_definitions.recipients.exclude_trigger_user');
    });
});
