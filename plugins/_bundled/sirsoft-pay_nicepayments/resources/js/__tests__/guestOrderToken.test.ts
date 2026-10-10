/**
 * 비회원 주문 조회 토큰 헤더 (나이스페이먼츠)
 *
 * 서버는 `X-Guest-Order-Token` 으로 비회원 주문 소유자를 확인한다. 토큰은 이커머스가 약속한 공개 상태 계약
 * `_global.guestOrderToken` 에서만 읽는다 — 종전에는 코어 저장소 키(sessionStorage `g7_guest_order_token`)와
 * 로그인 토큰(localStorage `auth_token`)을 직접 읽어 Bearer 를 손수 붙였다. 엔진이 저장 방식을 바꾸면 조용히
 * 깨지고, 코어의 401 처리도 우회한다(#639).
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/public-core-api-only.yaml
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { buildGuestOrderTokenHeaders, getGuestOrderToken } from '../guestOrderToken';

const JS = resolve(__dirname, '..');

/**
 * 공개 상태(_global)를 돌려주는 G7Core 를 심습니다.
 *
 * @param global 전역 상태
 */
function seedGlobal(global: Record<string, unknown>): void {
    (window as any).G7Core = { state: { get: () => global } };
}

/**
 * 플러그인 브라우저 소스(.ts, 테스트 제외)를 모두 읽습니다.
 *
 * @param dir 시작 디렉터리
 * @return 파일 경로 → 내용
 */
function sources(dir: string = JS): Record<string, string> {
    const out: Record<string, string> = {};
    for (const name of readdirSync(dir)) {
        const full = join(dir, name);
        if (statSync(full).isDirectory()) {
            if (name !== '__tests__') Object.assign(out, sources(full));
        } else if (name.endsWith('.ts')) {
            out[full] = readFileSync(full, 'utf8');
        }
    }

    return out;
}

beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
});

afterEach(() => {
    delete (window as any).G7Core;
});

describe('비회원 조회 토큰 헤더', () => {
    // @scenario concern=guest_token
    // @effects guest_token_read_from_public_state_contract
    it('공개 상태 계약(_global.guestOrderToken)의 토큰을 X-Guest-Order-Token 으로 싣는다', () => {
        seedGlobal({ guestOrderToken: '1999999999|guest-token' });

        expect(getGuestOrderToken()).toBe('1999999999|guest-token');
        expect(buildGuestOrderTokenHeaders()).toEqual({ 'X-Guest-Order-Token': '1999999999|guest-token' });
    });

    // @scenario concern=guest_token
    // @effects guest_token_read_from_public_state_contract
    it('코어 저장소 키·로그인 토큰은 읽지 않는다 — 공개 상태에 토큰이 없으면 빈 헤더다', () => {
        seedGlobal({});
        sessionStorage.setItem('g7_guest_order_token', 'storage-token');
        localStorage.setItem('auth_token', 'member-token');

        expect(getGuestOrderToken()).toBeNull();
        expect(buildGuestOrderTokenHeaders()).toEqual({});
    });

    // @scenario concern=guest_token
    // @effects guest_token_read_from_public_state_contract
    it('G7Core 가 없거나 토큰이 문자열이 아니면 빈 헤더다', () => {
        expect(buildGuestOrderTokenHeaders()).toEqual({});

        seedGlobal({ guestOrderToken: 42 });
        expect(buildGuestOrderTokenHeaders()).toEqual({});
    });
});

describe('브라우저 소스가 코어 비공개 내부에 손대지 않는다', () => {
    // @scenario concern=private_internals
    // @effects no_private_core_internals_in_source
    it('로그인 토큰 직접 읽기·엔진 내부 전역·fetch/history 덮어쓰기가 없다', () => {
        for (const [file, text] of Object.entries(sources())) {
            const code = text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
            expect(code, file).not.toMatch(/getItem\(\s*['"`]auth_token['"`]/);
            expect(code, file).not.toMatch(/\b__templateApp\b/);
            expect(code, file).not.toMatch(/\b(?:window|globalThis)\s*\.\s*fetch\s*=(?!=)/);
            expect(code, file).not.toMatch(/\bhistory\s*\.\s*(?:pushState|replaceState)\s*=(?!=)/);
            expect(code, file).not.toMatch(/\bfetch\s*\(/);
        }
    });
});
