import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { AuthManager } from '../../auth/AuthManager';
import { Router } from '../Router';
import { RouteResolver } from '../RouteResolver';
import { stripBasePrefix, withBasePrefix } from '../../support/assetUrl';
import { checkLayoutEditorMode } from '../../template-engine/layout-editor/hooks/useEditorMode';

/**
 * 하위 폴더 마운트(`https://host/g7/`)에서의 **화면 이동** 계약.
 *
 * 11차 라운드까지 이 축에서 닫힌 것은 「데이터를 불러오는 주소」(`fetch`·자산 URL)뿐이고,
 * 「화면을 이동하는 주소」는 한 곳도 접두사를 갖지 않았다. 그 상태의 증상은 오류가 아니라
 * **아무 화면도 열리지 않는 것**이다 — 라우트 패턴은 언제나 접두사 없이 등록되는데
 * `location.pathname` 은 접두사를 갖고 오므로 어떤 패턴에도 맞지 않고, 라우터는 그것을
 * 정상적인 `routeNotFound` 로 처리한다. 콘솔 경고 한 줄 말고는 남는 것이 없다.
 *
 * 그래서 이 파일은 **양방향**을 잰다. 붙이는 쪽(`withBasePrefix`)만 재면 벗기는 쪽이
 * 빠져도 초록이고, 그 조합이 정확히 「주소창은 맞는데 화면은 안 열리는」 상태다.
 *
 * @scenario base_path=root, navigation_direction=navigation
 *
 * @effects route_matching_strips_the_site_base_path,
 *          history_entries_carry_the_site_base_path,
 *          login_redirect_stays_inside_the_site,
 *          layout_editor_boots_under_a_subpath_mount
 *
 * @since 12차 G3 검증 라운드
 */
describe('하위 폴더 마운트의 화면 이동', () => {
    const MOUNT = '/g7';

    const setMount = (value: string | undefined): void => {
        const config = ((globalThis as any).G7Config ??= {});
        if (value === undefined) {
            delete config.basePath;
            return;
        }
        config.basePath = value;
    };

    /** `window.location` 을 원하는 주소로 갈아 끼운다 (jsdom 은 대입을 막는다). */
    const setLocation = (pathname: string, search = ''): void => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            // `origin` 도 함께 둔다 — `new URL(path, window.location.origin)` 으로 상대 주소를
            // 푸는 소비처(`TemplateApp.updateQueryParams`)가 있어, 빠지면 그 경로가 던진다.
            value: {
                pathname,
                search,
                origin: 'https://host',
                href: `https://host${pathname}${search}`,
            },
        });
    };

    const originalLocation = window.location;

    beforeEach(() => {
        setMount(MOUNT);
    });

    afterEach(() => {
        setMount(undefined);
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: originalLocation,
        });
        vi.restoreAllMocks();
    });

    describe('접두사 헬퍼 (양방향)', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects base_prefix_helpers_are_inverse_and_idempotent
        it('붙였다 벗기면 원래 경로로 돌아온다', () => {
            for (const path of ['/', '/admin', '/board/free', '/admin/users/7']) {
                expect(stripBasePrefix(withBasePrefix(path))).toBe(path);
            }
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects base_prefix_helpers_are_inverse_and_idempotent
        it('이미 접두사를 가진 값에 다시 붙지 않는다', () => {
            expect(withBasePrefix('/g7/admin')).toBe('/g7/admin');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects base_prefix_helpers_are_inverse_and_idempotent
        it('접두사가 경계 없이 이어지는 경로는 벗기지 않는다', () => {
            // `/g7abc` 는 `/g7` 로 시작하지만 다른 경로다. 벗기면 루트 상대가 아닌
            // `abc` 가 되어 이후 판정이 전부 어긋난다.
            expect(stripBasePrefix('/g7abc/x')).toBe('/g7abc/x');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects base_prefix_helpers_are_inverse_and_idempotent
        it('접두사만 남으면 루트가 된다', () => {
            expect(stripBasePrefix('/g7')).toBe('/');
        });

        // @scenario base_path=root, navigation_direction=navigation
        // @effects base_prefix_helpers_are_inverse_and_idempotent
        it('루트 설치(접두사 없음)에서는 어느 쪽도 값을 바꾸지 않는다', () => {
            setMount(undefined);
            expect(withBasePrefix('/board/free')).toBe('/board/free');
            expect(stripBasePrefix('/board/free')).toBe('/board/free');
        });
    });

    describe('Router', () => {
        beforeEach(() => {
            vi.spyOn(AuthManager, 'getInstance').mockReturnValue({
                isAuthenticated: () => true,
                getAuthType: () => 'user',
                checkAuth: async () => true,
            } as any);
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects route_matching_strips_the_site_base_path
        it('하위 폴더 주소가 접두사 없는 라우트 패턴에 매칭된다', async () => {
            const router = new Router('sirsoft-basic');
            (router as any).routes = [{ path: '/board/:slug', layout: 'board' }];

            setLocation('/g7/board/free');

            const notFound = vi.fn();
            const changed = vi.fn();
            router.on('routeNotFound', notFound);
            router.on('routeChange', changed);

            await router.navigateToCurrentPath();

            // 벗기지 않으면 여기서 routeNotFound 가 나고, 그것이 이 배치에서
            // 전 화면이 비는 유일한 증상이었다.
            expect(notFound).not.toHaveBeenCalled();
            expect(changed).toHaveBeenCalledTimes(1);
            expect(changed.mock.calls[0][0]).toMatchObject({
                path: '/board/free',
                params: { slug: 'free' },
            });
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects history_entries_carry_the_site_base_path
        it('히스토리에 싣는 주소에는 접두사가 붙는다', async () => {
            const router = new Router('sirsoft-basic');
            (router as any).routes = [{ path: '/board/:slug', layout: 'board' }];

            setLocation('/g7/board/free');
            const pushState = vi.spyOn(window.history, 'pushState').mockImplementation(() => {});

            await router.navigate('/board/notice');

            expect(pushState).toHaveBeenCalled();
            expect(pushState.mock.calls[0][2]).toBe('/g7/board/notice');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects login_redirect_stays_inside_the_site
        it('접두사를 벗긴 결과가 `//host` 형태여도 사이트 안 경로로 접혀 사이트 밖으로 나가지 않는다', async () => {
            // `stripBasePrefix('/g7//evil.com')` 은 `//evil.com` 이다 — 벗기는 쪽은 접두사 뒤
            // 나머지를 그대로 돌려주므로 결과가 `/` 하나로 시작한다는 보장이 없다 (15차 G3 라운드 W4-5).
            // 그 값이 사이트 밖으로 나가지 않는 근거는 라우터의 슬래시 정규화다: 대조 전에 연속 슬래시를
            // 하나로 접으므로(`normalizePathname`, 서버 `RequestPath::normalize()` 와 같음 — engine-v1.84.0)
            // `//evil.com` 은 사이트 안 `/evil.com` 이 되고, 주소창도 `/` 하나로 시작하는 정규형으로 되쓴다.
            // 정규형은 교차 출처 주소(`//host`)가 될 수 없다.
            expect(stripBasePrefix('/g7//evil.com')).toBe('//evil.com');

            const router = new Router('sirsoft-basic');
            (router as any).routes = [
                { path: '/', layout: 'home' },
                { path: '/:slug', layout: 'page' },
                { path: '*/:slug', layout: 'page-i18n' },
                { path: '/board/:slug', layout: 'board' },
                { path: '*/board/:slug', layout: 'board-i18n' },
            ];

            expect(router.match('//evil.com')?.route.path).toBe('/:slug');
            expect(router.match('//evil.com')?.params.slug).toBe('evil.com');
            // 언어 접두 패턴(`*/board/:slug`)의 선택 세그먼트로 읽혀도 사이트 안 `/evil.com/board/free` 다.
            expect(router.match('//evil.com/board/free')?.route.path).toBe('*/board/:slug');
            expect(router.match('/board/free')).not.toBeNull();

            setLocation('/g7//evil.com');
            const replaceState = vi.spyOn(window.history, 'replaceState');
            const changed = vi.fn();
            router.on('routeChange', changed);

            await router.navigateToCurrentPath();

            expect(replaceState).toHaveBeenCalled();
            const rewritten = String(replaceState.mock.calls[0][2]);
            expect(rewritten.startsWith('/g7/evil.com')).toBe(true);
            expect(rewritten.startsWith('//')).toBe(false);
            expect(changed).toHaveBeenCalledTimes(1);
            expect(changed.mock.calls[0][0].path).toBe('/evil.com');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects route_matching_strips_the_site_base_path
        it('하위 폴더 주소의 끝·연속 슬래시는 접두사를 품은 채 접히고, 대조는 접두사를 벗긴 정규형으로 한다', async () => {
            // 병합 합성(engine-v1.84.0 슬래시 정규화 × 12차 G3 접두사 벗기기)의 순서를 잠근다 —
            // 접두사 포함 전체 경로를 먼저 접어 주소창에 쓰고, 그 정규형에서 접두사를 벗겨 대조한다.
            const cases: Array<[string, string, string]> = [
                ['/g7/board/free/', '/g7/board/free', '/board/free'],
                ['/g7//board/free', '/g7/board/free', '/board/free'],
                ['/g7/board//free/', '/g7/board/free', '/board/free'],
            ];

            for (const [entry, rewritten, routed] of cases) {
                setMount(MOUNT);
                const router = new Router('sirsoft-basic');
                (router as any).routes = [
                    { path: '/', layout: 'home' },
                    { path: '/board/:slug', layout: 'board' },
                ];

                setLocation(entry);
                (window.location as any).hash = '';
                const replaceState = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
                const changed = vi.fn();
                router.on('routeChange', changed);

                await router.navigateToCurrentPath();

                expect(String(replaceState.mock.calls[0][2])).toBe(rewritten);
                expect(changed).toHaveBeenCalledTimes(1);
                expect(changed.mock.calls[0][0].path).toBe(routed);
                replaceState.mockRestore();
            }
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects route_matching_strips_the_site_base_path
        it('마운트 루트 `/g7/` 는 끝 슬래시 형태를 유지한다 (서버 정본과 같아 새로고침마다 301 이 나지 않는다)', async () => {
            setMount(MOUNT);
            const router = new Router('sirsoft-basic');
            (router as any).routes = [{ path: '/', layout: 'home' }];

            setLocation('/g7/');
            (window.location as any).hash = '';
            const replaceState = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
            const changed = vi.fn();
            router.on('routeChange', changed);

            await router.navigateToCurrentPath();

            expect(replaceState).not.toHaveBeenCalled();
            expect(changed).toHaveBeenCalledTimes(1);
            expect(changed.mock.calls[0][0].path).toBe('/');

            // 끝 슬래시 없이 들어온 마운트 루트도 서버 정본 형태로 맞춘다.
            setLocation('/g7');
            (window.location as any).hash = '';
            await router.navigateToCurrentPath();
            expect(String(replaceState.mock.calls[0][2])).toBe('/g7/');
            replaceState.mockRestore();
        });
    });

    describe('RouteResolver', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects route_matching_strips_the_site_base_path, history_entries_carry_the_site_base_path
        it('현재 주소를 벗겨 대조하고, 이동은 붙여서 싣는다', () => {
            const router = new Router('sirsoft-basic');
            (router as any).routes = [{ path: '/board/:slug', layout: 'board' }];

            const onRouteChange = vi.fn();
            const resolver = new RouteResolver(router);
            (resolver as any).onRouteChange = onRouteChange;

            setLocation('/g7/board/free');
            resolver.resolve();

            expect(onRouteChange).toHaveBeenCalledTimes(1);
            expect(onRouteChange.mock.calls[0][0]).not.toBeNull();

            const pushState = vi.spyOn(window.history, 'pushState').mockImplementation(() => {});
            setLocation('/g7/board/notice');
            resolver.navigate('/board/notice');

            expect(pushState.mock.calls[0][2]).toBe('/g7/board/notice');

            resolver.destroy();
        });
    });

    describe('로그인 복귀', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects login_redirect_stays_inside_the_site
        it('로그인 주소가 사이트 안을 가리킨다', () => {
            const auth = AuthManager.getInstance();
            const url = auth.getLoginRedirectUrl('admin', '/g7/admin/users', 'session_expired');

            // 접두사가 없으면 `https://host/admin/login` — 사이트 밖이고, 세션 만료가
            // 곧 이탈이 된다.
            expect(url.startsWith('/g7/')).toBe(true);
            expect(url).toContain('redirect=' + encodeURIComponent('/g7/admin/users'));
            expect(url).toContain('reason=session_expired');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects login_redirect_stays_inside_the_site, history_entries_carry_the_site_base_path
        it('쿼리가 붙은 하위 폴더 홈으로의 복귀가 이중 접두 없이 히스토리에 실린다', async () => {
            // 실재 경로: `https://host/g7?ref=x` 에서 세션 만료 → 복귀 주소
            // `/g7?ref=x`(접두사를 품은 원본) → 로그인 → `getRedirectUrl()` →
            // `router.navigate()` → `withBasePrefix()`. 접두사 판정이 `/g7` · `/g7/…`
            // 두 형태만 알면 여기서 `/g7/g7?ref=x` 가 되어 로그인 직후 사이트 밖으로
            // 나간다 (13차 G3 라운드 W3-1).
            const auth = AuthManager.getInstance();
            const loginUrl = auth.getLoginRedirectUrl('user', '/g7?ref=x', 'session_expired');

            expect(loginUrl).toContain('redirect=' + encodeURIComponent('/g7?ref=x'));

            const [, search] = loginUrl.split('?');
            setLocation('/g7/login', '?' + search);

            const returnTo = auth.getRedirectUrl('user');
            expect(returnTo).toBe('/g7?ref=x');

            const router = new Router('sirsoft-basic');
            (router as any).routes = [{ path: '/', layout: 'home' }];
            const pushState = vi.spyOn(window.history, 'pushState').mockImplementation(() => {});

            await (router as any).executeNavigation(returnTo);

            expect(pushState.mock.calls[0][2]).toBe('/g7?ref=x');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects login_redirect_stays_inside_the_site
        it('접두사가 이미 실린 loginPath 에 다시 붙지 않는다', () => {
            // 템플릿이 부트스트랩에서 접두사까지 담아 넘기는 경우가 있다.
            // 이중 접두(`/g7/g7/login`)는 조용한 404 다.
            const auth = AuthManager.getInstance();
            auth.updateConfig('user', { loginPath: '/g7/login' });

            expect(auth.getLoginRedirectUrl('user', '/g7/mypage')).toContain('/g7/login?');
            expect(auth.getLoginRedirectUrl('user', '/g7/mypage')).not.toContain('/g7/g7/');

            auth.updateConfig('user', { loginPath: '/login' });
        });
    });

    describe('레이아웃 편집기 진입 판정', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects layout_editor_boots_under_a_subpath_mount
        it('하위 폴더 주소에서도 편집기 모드로 판정된다', () => {
            // 벗기지 않으면 이 배치에서 편집기가 **아예 부팅하지 않는다**.
            expect(checkLayoutEditorMode('/g7/admin/layout-editor/sirsoft-admin_basic')).toEqual({
                templateIdentifier: 'sirsoft-admin_basic',
            });
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects layout_editor_boots_under_a_subpath_mount
        it('편집기 주소가 아닌 값은 여전히 null 이다 (오탐 대조)', () => {
            expect(checkLayoutEditorMode('/g7/admin/users')).toBeNull();
        });
    });

    describe('목록의 쿼리 갱신 (navigate + replace:true)', () => {
        // 목록 화면의 검색·정렬·per_page·페이지 이동은 `navigate` + `replace: true` 로
        // `TemplateApp.updateQueryParams` 를 타고, 그 안의 `history.replaceState` 가
        // 주소창을 갈아 끼운다. 형제 셋(`Router`·`RouteResolver`·`handleReplaceUrl`)은
        // 12차에 접두사를 붙였는데 이 한 곳만 빠져 있었다 — 목록은 정상 갱신되지만
        // 주소창이 마운트 밖으로 바뀌어, 새로고침·북마크·주소 공유·뒤로가기 재진입에서
        // 문서 루트의 다른 사이트(또는 404)가 열렸다. 오류도 콘솔 메시지도 없다.
        // `replaceState` 라 히스토리 항목 자체가 그 잘못된 주소로 대체된다 (14차 G3 라운드).
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects history_entries_carry_the_site_base_path
        it('주소창에 싣는 값에 마운트 접두사가 붙는다', async () => {
            const { TemplateApp } = await import('../../TemplateApp');

            setLocation('/g7/admin/users');
            const replaceState = vi
                .spyOn(window.history, 'replaceState')
                .mockImplementation(() => {});

            // 생성자를 거치지 않고 메서드만 평가한다 — 이 계약은 주소 조립 한 줄이다.
            const app = Object.create(TemplateApp.prototype) as any;
            app.currentQueryParams = new URLSearchParams();
            app.rerenderForQueryChange = () => {};

            // 주소 조립은 메서드 **첫 단계**다(히스토리 교체 → 그 뒤 데이터소스 재평가).
            // 뒷단은 레이아웃·데이터소스 등 앱 상태 전체를 요구하므로 여기서는 던지게 두고,
            // 이 계약이 재는 「히스토리에 실린 주소」만 단언한다.
            await TemplateApp.prototype.updateQueryParams
                .call(app, '/admin/users?page=2')
                .catch(() => {});

            expect(replaceState).toHaveBeenCalled();
            expect(replaceState.mock.calls[0][2]).toBe('/g7/admin/users?page=2');
        });

        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects history_entries_carry_the_site_base_path
        it('이미 접두사가 붙은 값에는 두 번 붙지 않는다 (멱등)', async () => {
            const { TemplateApp } = await import('../../TemplateApp');

            setLocation('/g7/admin/users');
            const replaceState = vi
                .spyOn(window.history, 'replaceState')
                .mockImplementation(() => {});

            const app = Object.create(TemplateApp.prototype) as any;
            app.currentQueryParams = new URLSearchParams();
            app.rerenderForQueryChange = () => {};

            await TemplateApp.prototype.updateQueryParams
                .call(app, '/g7/admin/users?page=3')
                .catch(() => {});

            expect(replaceState.mock.calls[0][2]).toBe('/g7/admin/users?page=3');
        });
    });
});
