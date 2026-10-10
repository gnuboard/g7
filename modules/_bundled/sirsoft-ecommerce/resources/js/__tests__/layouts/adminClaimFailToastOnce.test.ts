// e2e:allow 레이아웃 계약 테스트(toast+rethrow 핸들러 호출부의 <fail><suppress/> 전수) — 브라우저 흐름은 Playwright MCP 라이브 재실측으로 덮었다: 교환 EX20260922-1136485067 승인 422 에서 25ms 폴링 동시 토스트 최대 1(수정 전 2). (#599 3차 실측 T2-D12, 2026-09-23).
/**
 * 실패 토스트는 **한 번만** 뜬다 — 사유를 스스로 띄우고 다시 던지는 핸들러는 호출 자리가
 * 반드시 `onError` 를 가진다 (T2-D12 · #599 3차 실측 L-5)
 *
 * @description
 * 클레임 관리 핸들러 다수는 실패 시 서버 사유를 토스트로 띄운 뒤 예외를 다시 던진다(호출
 * 쪽 sequence 를 멈추기 위해서다). 그 예외는 액션 디스패처로 올라가는데, 호출 자리에
 * `onError`(`<fail>`)가 없으면 디스패처는 레이아웃·템플릿의 기본 오류 정책을 태우고 그 정책이
 * **같은 사유를 한 번 더** 토스트로 띄운다.
 *
 * 2026-09-22 실측: 관리자 교환 상세에서 재고가 0 인 교환을 [교환승인] 하자 422 와 함께
 * 「교환할 옵션의 재고가 부족합니다.」가 **2개 겹쳐** 떴다. 전수 검사로 같은 구조가 두 곳
 * 더 있었다(환불신청서 수동 접수 · 첨부 전체 삭제).
 *
 * 이 테스트는 **모집단을 저장소에서 파생한다** — 핸들러 소스에서 「catch 안에서 toast 후
 * throw」 하는 export 핸들러를 모으고, 이 모듈의 모든 레이아웃 JSON 에서 그 핸들러를 부르는
 * 자리를 모은다. 새 핸들러·새 호출 자리가 생겨도 이 규칙이 그대로 적용된다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const MODULE_ROOT = path.resolve(__dirname, '..', '..', '..', '..');
const HANDLER_DIR = path.join(MODULE_ROOT, 'resources', 'js', 'handlers');
const LAYOUT_DIR = path.join(MODULE_ROOT, 'resources', 'layouts');

/**
 * 디렉터리 아래 파일을 재귀로 모은다.
 *
 * @param dir 시작 디렉터리
 * @param suffix 파일 접미사
 * @returns 파일 경로 목록
 */
function walk(dir: string, suffix: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return walk(full, suffix);
        return entry.name.endsWith(suffix) ? [full] : [];
    });
}

/**
 * 「catch 안에서 toast 를 띄운 뒤 다시 던지는」 export 핸들러 이름을 모은다.
 *
 * @returns 핸들러 이름 집합 (`Handler` 접미사 제외 — 레이아웃이 부르는 이름)
 */
function toastThenRethrowHandlers(): Set<string> {
    const names = new Set<string>();

    for (const file of walk(HANDLER_DIR, '.ts')) {
        const text = fs.readFileSync(file, 'utf8');
        const starts = [...text.matchAll(/export (?:async )?function (\w+)Handler\s*\(/g)];

        starts.forEach((match, i) => {
            const body = text.slice(match.index, starts[i + 1]?.index ?? text.length);
            const catches = [...body.matchAll(/catch\s*\([^)]*\)\s*\{([\s\S]*?)\n    \}/g)];

            if (catches.some((c) => /toast/.test(c[1]) && /\bthrow\b/.test(c[1]))) {
                names.add(match[1]);
            }
        });
    }

    return names;
}

/**
 * 레이아웃 JSON 에서 액션 노드를 전부 모은다(자식·콜백 포함).
 *
 * @param node 레이아웃 노드
 * @param out 누적 배열
 * @returns 액션 노드 목록
 */
function collectActions(node: unknown, out: any[] = []): any[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collectActions(child, out));
    } else if (node && typeof node === 'object') {
        const obj = node as Record<string, unknown>;
        if (typeof obj.handler === 'string') out.push(obj);
        Object.values(obj).forEach((value) => collectActions(value, out));
    }

    return out;
}

describe('클레임 관리 — 실패 토스트는 한 번만 뜬다 (T2-D12)', () => {
    const rethrowers = toastThenRethrowHandlers();
    const callSites = walk(LAYOUT_DIR, '.json').flatMap((file) => {
        const actions = collectActions(JSON.parse(fs.readFileSync(file, 'utf8')));

        return actions
            .filter((a) => rethrowers.has(String(a.handler).replace(/^sirsoft-ecommerce\./, '')))
            .map((a) => ({ file: path.relative(MODULE_ROOT, file).replace(/\\/g, '/'), action: a }));
    });

    /**
     * 모집단이 비면 이 규칙은 아무것도 재지 않는다 — 비어 있으면 red 다.
     *
     * @scenario surface=admin_claim_detail
     * @effects claim_failure_toast_is_shown_once
     */
    it('모집단(토스트 후 다시 던지는 핸들러와 그 호출 자리)이 비어 있지 않다', () => {
        expect(rethrowers.size).toBeGreaterThan(0);
        expect(callSites.length).toBeGreaterThan(0);
        // 실측으로 드러난 자리가 모집단에 들어 있어야 한다.
        expect([...rethrowers]).toContain('executeClaimStatusTransition');
    });

    /**
     * @scenario surface=admin_claim_detail
     * @effects claim_failure_toast_is_shown_once
     */
    it('그 핸들러를 부르는 자리는 모두 onError 를 가진다', () => {
        const missing = callSites
            .filter(({ action }) => !action.onError)
            .map(({ file, action }) => `${file} → ${action.handler}`);

        expect(missing, `onError 없이 부르는 자리 — 같은 실패가 두 번 뜬다:\n${missing.join('\n')}`).toEqual([]);
    });
});
