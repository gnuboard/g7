/**
 * 라우트 requires — 모듈 화면은 그 모듈이 켜져 있을 때만 공개 라우트에 실린다 (#822)
 *
 * @description
 * routes.json 의 `requires: {"modules": [...]}` 는 코어 7.1.4+ 가 읽어, 나열한 모듈이 **모두** 활성일 때만 그 라우트를
 * 공개 라우트 목록·사이트맵·검색봇 렌더·정적 사본에 싣는다(옛 코어는 키를 무시하고 지금처럼 전부 싣는다).
 * 게시판·쇼핑몰·페이지 화면과 그 모듈 데이터를 쓰는 마이페이지 화면이 대상이고, 회원 프로필(`/users/:userId`)처럼
 * 코어 화면 안에 게시판 블록이 일부 있는 화면은 대상이 아니다(블록만 레이아웃 가드로 가린다).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Route = { path: string; layout?: string; requires?: { modules?: string[]; plugins?: string[] } };

const routes: Route[] = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../routes.json'), 'utf8')).routes;

const SHOP_PREFIX = "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/";

const BOARD_PATHS = [
    '/boards',
    '/boards/popular',
    '/board/:slug',
    '/board/:slug/write',
    '/board/:slug/:id',
    '/board/:slug/:id/edit',
    '/mypage/board',
    '/mypage/scrap',
    '/users/:userId/posts',
];
const SHOP_MYPAGE_PATHS = [
    '/mypage/orders',
    '/mypage/orders/:order_number',
    '/mypage/orders/:order_number/claims/apply/:type',
    '/mypage/claims',
    '/mypage/claims/:claim_number',
    '/mypage/claims/:claim_number/edit',
    '/mypage/mileage',
    '/mypage/wishlist',
    '/mypage/addresses',
];

/** 경로로 라우트를 찾는다. */
function route(p: string): Route {
    const found = routes.filter((r) => r.path === p);
    expect(found, p).toHaveLength(1);
    return found[0];
}

describe('routes.json requires (#822)', () => {
    // @scenario surface=routes
    // @effects routes_require_their_module
    it.each(BOARD_PATHS)('게시판 화면 %s 는 sirsoft-board 를 요구한다', (p) => {
        expect(route(p).requires).toEqual({ modules: ['sirsoft-board'] });
    });

    // @scenario surface=routes
    // @effects routes_require_their_module
    it('쇼핑 경로 12개와 쇼핑 마이페이지 9개는 sirsoft-ecommerce 를 요구한다', () => {
        const shop = routes.filter((r) => r.path.startsWith(SHOP_PREFIX));
        expect(shop).toHaveLength(12);
        [...shop, ...SHOP_MYPAGE_PATHS.map(route)].forEach((r) =>
            expect(r.requires, r.path).toEqual({ modules: ['sirsoft-ecommerce'] }),
        );
    });

    // @scenario surface=routes
    // @effects routes_require_their_module
    it('상품 문의 내역(/mypage/inquiries)은 쇼핑몰과 게시판을 모두 요구한다(문의는 게시판 글로 저장)', () => {
        expect(route('/mypage/inquiries').requires).toEqual({ modules: ['sirsoft-ecommerce', 'sirsoft-board'] });
    });

    // @scenario surface=routes
    // @effects routes_require_their_module
    it('페이지 문서(/page/:slug)는 sirsoft-page 를 요구한다', () => {
        expect(route('/page/:slug').requires).toEqual({ modules: ['sirsoft-page'] });
    });

    // @scenario surface=routes
    // @effects core_routes_have_no_requires
    it('나머지(홈·인증·회원 프로필·코어 마이페이지·검색·오류)는 requires 가 없다 — 모듈이 모두 꺼져도 사이트가 선다', () => {
        const guarded = new Set([
            ...BOARD_PATHS,
            ...SHOP_MYPAGE_PATHS,
            '/mypage/inquiries',
            '/page/:slug',
        ]);
        const rest = routes.filter((r) => !guarded.has(r.path) && !r.path.startsWith(SHOP_PREFIX));
        expect(rest.map((r) => r.path)).toEqual(expect.arrayContaining(['/', '/login', '/register', '/users/:userId', '/search', '/mypage/profile', '/mypage/notifications']));
        rest.forEach((r) => expect(r.requires, r.path).toBeUndefined());
        // 표의 행 수 합계 = 9 + 12 + 9 + 1 + 1 = 32
        expect(routes.filter((r) => r.requires).length).toBe(32);
    });

    // @scenario surface=routes
    // @effects routes_require_their_module
    it('requires 는 modules 키 하나의 식별자 배열이고, 식별자는 template.json integrations 에 선언된 모듈이다', () => {
        const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../template.json'), 'utf8'));
        const declared = Object.keys(manifest.integrations?.modules ?? {});
        routes.filter((r) => r.requires).forEach((r) => {
            expect(Object.keys(r.requires!), r.path).toEqual(['modules']);
            r.requires!.modules!.forEach((id) => expect(declared, `${r.path} → ${id}`).toContain(id));
        });
    });
});
