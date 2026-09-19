/**
 * @file admin-cell-children-defer-translation.test.ts
 * @description DataGrid 셀 안의 치환 파라미터가 딸린 다국어 문구는 `$t:defer:` 여야 한다 (S17 재실측)
 *
 * 결함: 교환 신청상품 표의 차액 줄이 `$t:` 로 선언돼 있어 라벨('차액')만 번역되고
 *   `{{amount}}` 는 빈 문자열로 지워졌다. 화면에는 금액 없는 '차액' 만 남는다 —
 *   오류도 경고도 없고, 파라미터가 없는 형제 문구는 정상이라 눈에 띄지 않는다.
 *
 * `$t:defer:` 는 DataGrid 의 `cellChildren`/`expandChildren`(renderItemChildren)에서만
 * 해소된다. 그 자리에서 파라미터를 쓰려면 반드시 defer 여야 하고, 반복 밖 노드는 반대로
 * 일반 `$t:` 여야 한다(defer 를 쓰면 원문 키가 그대로 나온다).
 *
 * 모집단은 이 확장의 레이아웃 전수다 — 손으로 적은 목록이 아니라 파일에서 파생하므로
 * 나중에 추가되는 셀도 자동으로 검사 대상이 된다.
 *
 * 소유 시나리오: `admin-claim-detail.yaml` (효과 매핑은 각 테스트에 있다 — 클레임 유형·단계와 무관한 정적 스캔)
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts');

/** 반복 서브트리를 만드는 노드 키 (renderItemChildren 경로) */
const REPEATED_SUBTREE_KEYS = ['cellChildren', 'expandChildren'];

/**
 * 레이아웃 JSON 파일 경로를 모두 모읍니다.
 *
 * @param dir 탐색 시작 디렉토리
 * @returns JSON 파일 경로 목록
 */
function collectLayoutFiles(dir: string): string[] {
    const found: string[] = [];

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            found.push(...collectLayoutFiles(full));
        } else if (entry.isFile() && entry.name.endsWith('.json')) {
            found.push(full);
        }
    }

    return found;
}

/**
 * 반복 서브트리 안에서 파라미터가 딸린 비-defer 다국어 문구를 찾습니다.
 *
 * @param node 검사할 노드
 * @param inRepeated 현재 노드가 반복 서브트리 안인지
 * @param out 위반 수집 배열
 */
function collectViolations(node: unknown, inRepeated: boolean, out: string[]): void {
    if (Array.isArray(node)) {
        node.forEach((child) => collectViolations(child, inRepeated, out));

        return;
    }

    if (node === null || typeof node !== 'object') {
        return;
    }

    const record = node as Record<string, unknown>;
    const text = record.text;

    if (
        inRepeated
        && typeof text === 'string'
        && text.startsWith('$t:')
        && !text.startsWith('$t:defer:')
        && text.includes('|')
    ) {
        out.push(text.slice(0, 140));
    }

    for (const [key, value] of Object.entries(record)) {
        collectViolations(value, inRepeated || REPEATED_SUBTREE_KEYS.includes(key), out);
    }
}

describe('DataGrid 셀 안의 치환 파라미터 다국어 문구', () => {
    const files = collectLayoutFiles(LAYOUTS_ROOT);

    /**
     * @effects cell_children_parameterized_translation_uses_defer
     */
    it('검사 대상 레이아웃이 비어 있지 않다', () => {
        // 모집단이 비면 아래 단언이 조용히 통과한다 — 하한을 먼저 고정한다.
        expect(files.length).toBeGreaterThan(10);
    });

    /**
     * @effects cell_children_parameterized_translation_uses_defer
     */
    it('cellChildren·expandChildren 안에서는 반드시 $t:defer: 를 쓴다', () => {
        const offenders: Array<{ file: string; text: string }> = [];

        for (const file of files) {
            const raw = fs.readFileSync(file, 'utf-8');
            let parsed: unknown;

            try {
                parsed = JSON.parse(raw);
            } catch {
                continue;
            }

            const found: string[] = [];
            collectViolations(parsed, false, found);
            found.forEach((text) => offenders.push({ file: path.relative(LAYOUTS_ROOT, file), text }));
        }

        expect(offenders).toEqual([]);
    });
});
