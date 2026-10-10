/**
 * 게시판 환경설정 — 설정 스위치 줄 배치 계약 테스트
 *
 * @description
 * 게시판 환경설정의 설정 스위치는 이름·설명이 왼쪽, 스위치가 오른쪽 끝에 놓인다(2026-09-26 결정 —
 * 「게시판 전체를 오른쪽으로」). 「기본 설정」 탭의 삭제 첨부 영구 정리와 개인정보 보유기간 스위치만
 * 설명 아래에 따로 놓여 같은 화면 안에서 줄 모양이 갈렸다. 모집단은 설정 탭 partial 전량에서
 * 폼 값에 묶인(`name`) 스위치를 파생하므로, 새 탭·새 스위치도 같은 양식을 따라야 통과한다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 게시판 환경설정 partial 디렉터리를 찾는다(module.json 기준).
 *
 * @returns partial 디렉터리 절대경로
 */
function partialDir(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));
    for (let depth = 0; depth < 10 && !fs.existsSync(path.join(current, 'module.json')); depth++) {
        current = path.dirname(current);
    }

    return path.join(current, 'resources/layouts/admin/partials/admin_board_settings');
}

/**
 * 폼 값에 묶인 스위치를 부모 경로와 함께 모은다.
 *
 * @param node 탐색 시작 노드
 * @param parents 지금까지의 부모 노드(가까운 것이 끝)
 * @returns [스위치, 부모 목록] 쌍 목록
 */
function collectBoundToggles(node: any, parents: any[] = []): Array<[any, any[]]> {
    if (!node || typeof node !== 'object') return [];
    if (Array.isArray(node)) return node.flatMap((child) => collectBoundToggles(child, parents));

    const found: Array<[any, any[]]> = node.name === 'Toggle' && typeof node.props?.name === 'string' ? [[node, parents]] : [];
    for (const child of node.children ?? []) {
        found.push(...collectBoundToggles(child, [...parents, node]));
    }

    return found;
}

/**
 * 스위치가 줄의 오른쪽 끝에 놓였는지 판정한다.
 * 스위치만 감싼 `flex-shrink-0` 상자가 있으면 그 상자를 스위치 자리로 본다.
 *
 * @param toggle 스위치 노드
 * @param parents 부모 목록
 * @returns 오른쪽 끝이면 true
 */
function isRightAligned(toggle: any, parents: any[]): boolean {
    let item = toggle;
    let row = parents.at(-1);
    if (row && (row.children ?? []).length === 1 && /\bflex-shrink-0\b/.test(String(row.props?.className ?? ''))) {
        item = row;
        row = parents.at(-2);
    }
    if (!row) return false;

    const siblings = row.children ?? [];
    const rowClass = String(row.props?.className ?? '');
    const pushedByRow = /\b(flex-between|justify-between)\b/.test(rowClass);
    const pushedBySibling = /\bflex\b|flex-center|flex-start/.test(rowClass)
        && siblings.slice(0, -1).some((s: any) => /\bflex-1\b/.test(String(s.props?.className ?? '')));

    return siblings.at(-1) === item && siblings.length >= 2 && (pushedByRow || pushedBySibling);
}

const partials = fs.readdirSync(partialDir()).filter((file) => /^_tab_.*\.json$/.test(file));
const toggles = partials.flatMap((file) =>
    collectBoundToggles(JSON.parse(fs.readFileSync(path.join(partialDir(), file), 'utf-8'))).map(
        ([toggle, parents]) => ({ file, toggle, parents }),
    ),
);

describe('게시판 환경설정 — 설정 스위치는 줄 오른쪽 끝에 놓인다', () => {
    /**
     * @scenario tab=general
     *
     * @effects board_settings_toggles_sit_at_row_end
     */
    it('폼 값에 묶인 스위치 전량이 이름·설명 오른쪽 끝에 있다', () => {
        // 모집단이 비면 아래 단언이 공허해진다 — 게시판 설정 10 · 기본 설정 1 · 개인정보 보유기간 1.
        expect(toggles.length).toBeGreaterThanOrEqual(12);

        const misplaced = toggles
            .filter(({ toggle, parents }) => !isRightAligned(toggle, parents))
            .map(({ file, toggle }) => `${file}: ${toggle.props.name}`);
        expect(misplaced).toEqual([]);
    });

    /**
     * @scenario tab=general
     *
     * @effects board_settings_toggles_sit_at_row_end
     */
    it('기본 설정·개인정보 보유기간 탭의 스위치가 모집단에 실제로 들어 있다', () => {
        // 지적된 두 자리 — 파일 이름이 바뀌거나 name 바인딩이 빠지면 위 단언의 모집단에서 조용히 빠진다.
        const names = toggles.map(({ file, toggle }) => `${file}:${toggle.props.name}`);
        expect(names).toContain('_tab_general.json:attachment_settings.purge_enabled');
        expect(names).toContain('_tab_privacy_retention.json:privacy_retention.enabled');
    });
});
