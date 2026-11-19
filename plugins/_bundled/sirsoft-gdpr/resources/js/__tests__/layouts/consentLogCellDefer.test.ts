// e2e:allow Vitest 레이아웃 정적 계약 — 셀 안 번역 접두만 본다(지연 번역 동작은 엔진 DataBindingEngine 시험이 덮는다).
/**
 * 동의 기록 표 셀 — 행 값을 번역 인자로 넘기는 문구는 `$t:defer:` 로 쓴다.
 *
 * `$t:` 는 엔진이 셀 문맥(`row`)이 생기기 전에 먼저 번역해 인자가 빈 값이 된다. 「스냅샷 보기」 버튼의
 * 접근 이름(title)이 버전 자리가 빈 채로 나왔다(#798). 규정: `docs/frontend/data-binding-i18n.md` 「지연 번역」.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const layoutPath = path.resolve(__dirname, '../../../layouts/admin/gdpr_consent_log.json');

/** 셀 안에서 행 값을 인자로 받는 즉시 번역(`$t:` — `$t:defer:` 아님). */
const EAGER_ROW_ARG = /\$t:(?!defer:)[A-Za-z0-9_.-]+\|[^"]*\{\{\s*(?:row|item)[.?[]/;

/**
 * `cellChildren` 아래 문자열 값 중 즉시 번역에 행 값을 넘기는 것을 모은다.
 *
 * @param node 레이아웃 노드
 * @param inCell 셀 안인지
 * @returns 위반 값 목록
 */
function eagerRowTranslations(node: unknown, inCell = false): string[] {
    if (Array.isArray(node)) return node.flatMap((item) => eagerRowTranslations(item, inCell));
    if (!node || typeof node !== 'object') return [];

    return Object.entries(node).flatMap(([key, value]) => {
        const cell = inCell || key === 'cellChildren';
        if (typeof value === 'string') return cell && EAGER_ROW_ARG.test(value) ? [value] : [];

        return eagerRowTranslations(value, cell);
    });
}

describe('동의 기록 표 셀 번역', () => {
    // @scenario source=consent_log_cell
    // @effects cell_row_args_deferred
    it('셀 안에서 행 값을 넘기는 번역은 모두 $t:defer: 다', () => {
        const layout = JSON.parse(fs.readFileSync(layoutPath, 'utf8'));

        expect(eagerRowTranslations(layout)).toEqual([]);
    });

    // @scenario source=consent_log_cell
    // @effects snapshot_title_deferred
    it('스냅샷 보기 버튼 title 은 지연 번역으로 버전을 넘긴다', () => {
        const text = fs.readFileSync(layoutPath, 'utf8');

        expect(text).toContain('"title": "$t:defer:sirsoft-gdpr.settings.policy_version.snapshot_modal.view_button|version={{row.policy_version}}"');
    });
});
