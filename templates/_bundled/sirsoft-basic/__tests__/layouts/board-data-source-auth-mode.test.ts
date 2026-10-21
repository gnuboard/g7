/**
 * 게시판 데이터소스 — 로그인 토큰 전달 계약 (게시판 1.2.1 #142)
 *
 * @description
 * 게시판 공개 API(메뉴·게시판 목록·최근/인기 글·게시글·프로필 작성글 등)는 보는 사람의 열람 권한으로 결과를 거른다.
 * 데이터소스가 토큰을 보내지 않으면 로그인한 회원도 비회원 기준 결과를 받는다 — 게시판 메뉴가 열람 권한으로
 * 걸러지기 시작하자 헤더·모바일 메뉴·마이페이지 게시판 선택칸이 회원에게 비회원 메뉴(7건)를 보였다(실측).
 *
 * 그래서 이 템플릿의 모든 레이아웃에서 `/api/modules/sirsoft-board/` 를 부르는 데이터소스는 토큰을 보내야 한다
 * (`auth_mode` optional/required 또는 `auth_required: true`). 모집단은 레이아웃 파일에서 파생한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import userBase from '../../layouts/_user_base.json';

type Node = Record<string, any>;

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');

/** 레이아웃 디렉토리의 .json 파일을 모두 모은다. */
function walk(dir: string, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full, out);
        else if (entry.name.endsWith('.json')) out.push(full);
    }
    return out;
}

/** 트리에서 data_sources 항목을 모두 모은다. */
function dataSources(node: unknown, out: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => dataSources(child, out));
        return out;
    }
    if (node && typeof node === 'object') {
        const obj = node as Node;
        if (Array.isArray(obj.data_sources)) out.push(...obj.data_sources);
        Object.values(obj).forEach((value) => dataSources(value, out));
    }
    return out;
}

/** 데이터소스가 토큰을 보내는가. */
const sendsToken = (ds: Node) => ds.auth_mode === 'optional' || ds.auth_mode === 'required' || ds.auth_required === true;

describe('게시판 데이터소스 — 로그인 토큰 전달', () => {
    // @scenario case=board_menu_sends_token
    // @effects board_menu_request_carries_login_token
    it('헤더·모바일 메뉴의 게시판 메뉴 데이터소스는 로그인했으면 토큰을 보낸다(auth_mode optional)', () => {
        const boards = dataSources(userBase).find((ds) => ds.id === 'boards');

        expect(boards?.endpoint).toBe('/api/modules/sirsoft-board/boards/board-menu');
        expect(boards?.auth_mode).toBe('optional');
    });

    // @scenario case=all_board_sources_send_token
    // @effects board_public_sources_carry_login_token
    it('게시판 API 를 부르는 모든 데이터소스가 토큰을 보낸다', () => {
        const offenders: string[] = [];
        let count = 0;

        for (const file of walk(LAYOUT_ROOT)) {
            let json: unknown;
            try {
                json = JSON.parse(fs.readFileSync(file, 'utf8'));
            } catch {
                continue;
            }
            for (const ds of dataSources(json)) {
                if (typeof ds.endpoint !== 'string' || !ds.endpoint.startsWith('/api/modules/sirsoft-board/')) continue;
                count++;
                if (!sendsToken(ds)) offenders.push(`${path.relative(LAYOUT_ROOT, file)}#${ds.id}`);
            }
        }

        expect(count).toBeGreaterThan(15);
        expect(offenders).toEqual([]);
    });
});
