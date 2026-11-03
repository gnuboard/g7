// e2e:allow 하위 폴더 마운트(`https://host/g7/`) 실측 환경이 로컬에 없다(개발 사이트는 루트 설치, `localhost/g7_2/` 는 사이트 주소로 301). 대체 검증: 이 파일의 실물 Router·ActionDispatcher·AuthManager·LeaveGuard·G7Core 잠금(fail-first 10/10) + 루트 설치 Playwright 실측 매트릭스(이력 20261031_2013)
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { AuthManager } from '../../auth/AuthManager';
import { getApiClient } from '../../api/ApiClient';
import { Router } from '../Router';
import { configureLeaveGuard, resetLeaveGuardForTests, shouldConfirmLeave } from '../LeaveGuard';
import { ActionDispatcher } from '../../template-engine/ActionDispatcher';
import { isUrlOnlyPathAlias } from '../../template-engine/helpers/urlOnlyPathChange';
import { initializeG7CoreGlobals } from '../../template-engine/G7CoreGlobals';
import { Logger } from '../../utils/Logger';

/**
 * 하위 폴더 마운트(`https://host/g7/`)에서 7.2.0 이 새로 만든 엔진 지점의 접두사 계약.
 *
 * 7.2.0 은 「같은 사이트 안 주소」를 다루는 지점을 여럿 새로 만들었고(redirect 핸들러, 개발자 화면 전체 이동,
 * 로그아웃 서버 이동, 로그인 전용 화면 판정, 같은 경로 판정, 이탈 가드, 같은 출처 전체 주소 이동, 언어 전환 재진입),
 * 그 지점들은 하위 폴더 규칙(붙이는 쪽은 `withBasePrefix`, 대조하는 쪽은 `stripBasePrefix`) 이전에 쓰였다.
 * 증상은 전부 루트 설치에서는 보이지 않고 하위 폴더 설치에서만 드러난다 — 사이트 밖 이동, 늘 「다른 화면」으로 읽히는
 * 같은 화면, 걸러지지 않는 로그인 화면.
 *
 * @scenario base_path=subpath, navigation_direction=navigation
 *
 * @effects redirect_handler_stays_inside_the_site,
 *          same_origin_full_url_navigates_in_app,
 *          same_path_target_ignores_base_prefix,
 *          leave_guard_same_path_ignores_base_prefix,
 *          custom_route_full_load_stays_inside_the_site,
 *          custom_route_popstate_matches_under_subpath,
 *          guest_only_return_path_matches_under_subpath,
 *          logout_server_redirect_stays_inside_the_site,
 *          manual_data_sources_refetch_after_locale_switch_under_subpath,
 *          url_only_path_alias_matches_browser_address
 *
 * @since engine-v1.86.0
 */
describe('하위 폴더 마운트 — 7.2.0 신규 엔진 지점', () => {
    const MOUNT = '/g7';
    const originalLocation = window.location;

    const setMount = (value: string | undefined): void => {
        const config = ((globalThis as any).G7Config ??= {});
        if (value === undefined) {
            delete config.basePath;
            return;
        }
        config.basePath = value;
    };

    /** `window.location` 을 원하는 주소로 갈아 끼운다 (jsdom 은 대입을 막는다). */
    const setLocation = (pathname: string, search = ''): Record<string, any> => {
        const location = {
            pathname,
            search,
            hash: '',
            origin: 'https://host',
            href: `https://host${pathname}${search}`,
            assign: vi.fn(),
            replace: vi.fn(),
            reload: vi.fn(),
        };
        Object.defineProperty(window, 'location', { configurable: true, writable: true, value: location });

        return location;
    };

    beforeEach(() => {
        setMount(MOUNT);
        Logger.getInstance().setDebug(false);
    });

    afterEach(() => {
        setMount(undefined);
        delete (globalThis as any).G7Config?.customRoutes;
        delete (window as any).__templateApp;
        delete (window as any).__g7UrlOnlyPaths;
        resetLeaveGuardForTests();
        Object.defineProperty(window, 'location', { configurable: true, writable: true, value: originalLocation });
        vi.restoreAllMocks();
    });

    describe('ActionDispatcher', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects redirect_handler_stays_inside_the_site
        it('redirect 핸들러가 이동 주소에 접두사를 붙인다 (assign·replace)', async () => {
            const location = setLocation('/g7/identity/challenge');
            const dispatcher = new ActionDispatcher({ navigate: vi.fn() });

            await (dispatcher as any).handleRedirect('/mypage', {});
            await (dispatcher as any).handleRedirect('/mypage/orders', { replace: true });

            // 붙이지 않으면 `https://host/mypage` — 사이트 밖이다.
            expect(location.assign).toHaveBeenCalledWith('/g7/mypage');
            expect(location.replace).toHaveBeenCalledWith('/g7/mypage/orders');
        });

        // @scenario base_path=subpath, navigation_direction=navigation

        // @effects same_origin_full_url_navigates_in_app
        it('같은 출처 전체 주소의 navigate 는 접두사를 벗긴 경로로 라우트를 찾는다', async () => {
            setLocation('/g7/notifications');
            const navigate = vi.fn();
            const match = vi.fn(() => ({ route: { path: '/board/:slug/:id' }, params: {} }));
            (window as any).__templateApp = { getRouter: () => ({ match }) };
            const dispatcher = new ActionDispatcher({ navigate });

            await dispatcher.dispatchAction({
                type: 'click',
                handler: 'navigate',
                params: { path: 'https://host/g7/board/free/14' },
            });

            // 벗기지 않으면 `/g7/board/free/14` 로 찾아 미매칭 폴백(전체 페이지 이동)이 된다.
            expect(match).toHaveBeenCalledWith('/board/free/14');
            expect(navigate).toHaveBeenCalledWith('/board/free/14', expect.anything());
        });

        // @scenario base_path=subpath, navigation_direction=navigation

        // @effects same_path_target_ignores_base_prefix
        it('같은 경로 판정이 접두사를 벗겨 대조한다', () => {
            setLocation('/g7/admin/users', '?page=1');
            const dispatcher = new ActionDispatcher({ navigate: vi.fn() });

            expect((dispatcher as any).isSamePathTarget('/admin/users?page=2')).toBe(true);
            expect((dispatcher as any).isSamePathTarget('/admin/orders')).toBe(false);
        });
    });

    describe('이탈 가드', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects leave_guard_same_path_ignores_base_prefix
        it('같은 화면의 쿼리만 바꾸는 이동은 묻지 않는다', () => {
            setLocation('/g7/admin/users', '?page=1');
            configureLeaveGuard({
                getLayoutJson: () => ({ meta: { leave_guard: true } }),
                getDataContext: () => ({}),
                getBindingEngine: () => null,
                translate: (key: string) => key,
            });

            // 목적지는 앱 기준 경로, 떠나는 쪽은 브라우저 주소다 — 접두사를 벗기지 않으면 늘 다른 화면이다.
            expect(shouldConfirmLeave('/admin/users?page=2')).toBe(false);
            expect(shouldConfirmLeave('/admin/orders')).toBe(true);
        });
    });

    describe('Router — 개발자 화면(routes/custom.php)', () => {
        beforeEach(() => {
            (globalThis as any).G7Config.customRoutes = { user: ['/custom/page'], admin: [] };
            vi.spyOn(AuthManager, 'getInstance').mockReturnValue({
                isAuthenticated: () => true,
                getAuthType: () => 'user',
                checkAuth: async () => true,
            } as any);
        });

        // @scenario base_path=subpath, navigation_direction=navigation

        // @effects custom_route_full_load_stays_inside_the_site
        it('엔진 화면에서 개발자 화면으로 가면 접두사를 붙여 전체 이동한다', () => {
            const location = setLocation('/g7/');
            const router = new Router('sirsoft-basic');

            router.navigate('/custom/page');

            expect(location.assign).toHaveBeenCalledWith('/g7/custom/page');
        });

        // @scenario base_path=subpath, navigation_direction=navigation

        // @effects custom_route_popstate_matches_under_subpath
        it('뒤로 가기로 개발자 화면 주소에 돌아오면 서버가 다시 그리게 한다', () => {
            const location = setLocation('/g7/custom/page');
            const router = new Router('sirsoft-basic');
            const navigateToCurrentPath = vi.spyOn(router, 'navigateToCurrentPath').mockResolvedValue();

            (router as any).handlePopState();

            // 벗기지 않으면 패턴(접두사 없음)과 맞지 않아 엔진이 그려 「없는 페이지」가 된다.
            expect(location.reload).toHaveBeenCalledTimes(1);
            expect(navigateToCurrentPath).not.toHaveBeenCalled();
        });
    });

    describe('AuthManager', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects guest_only_return_path_matches_under_subpath
        it('로그인 화면 자신은 복귀 경로로 싣지 않고, 그 화면의 복귀 경로를 이어 싣는다', () => {
            const auth = AuthManager.getInstance();

            const plain = auth.getLoginRedirectUrl('user', '/g7/login');
            const nested = auth.getLoginRedirectUrl(
                'user',
                '/g7/login?redirect=' + encodeURIComponent('/g7/mypage'),
                'session_expired'
            );

            // 벗기지 않으면 `/g7/login` 이 로그인 전용 화면으로 인식되지 않아, 로그인을 마친 뒤 다시 로그인 폼에 떨어진다.
            expect(plain).toBe('/g7/login');
            expect(nested).toContain('redirect=' + encodeURIComponent('/g7/mypage'));
            expect(nested).not.toContain(encodeURIComponent('/g7/login'));
        });

        // @scenario base_path=subpath, navigation_direction=navigation

        // @effects logout_server_redirect_stays_inside_the_site
        it('로그아웃 뒤 서버가 준 이동 경로에 접두사를 붙인다', async () => {
            const location = setLocation('/g7/mypage');
            const client = getApiClient();
            vi.spyOn(client, 'post').mockResolvedValue({ data: { redirect_url: '/sso/bye' } } as any);
            vi.spyOn(client, 'removeToken').mockImplementation(() => {});
            const auth = AuthManager.getInstance();
            (auth as any).state = { ...(auth as any).state, type: 'user', isAuthenticated: true };

            await auth.logout();

            expect(location.href).toBe('/g7/sso/bye');
        });
    });

    describe('TemplateApp', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects manual_data_sources_refetch_after_locale_switch_under_subpath
        it('언어 전환 때 수동 데이터소스를 모으는 경로 대조가 접두사를 벗긴다', async () => {
            const { TemplateApp } = await import('../../TemplateApp');
            setLocation('/g7/admin/users');

            const app = Object.create(TemplateApp.prototype) as any;
            app.currentRoutePath = '/admin/users';
            app.currentDataSources = [
                { id: 'tabList', type: 'api', auto_fetch: false },
                { id: 'auto', type: 'api' },
            ];
            app.currentFetchedData = { tabList: { data: [] }, auto: { data: [] } };

            // 벗기지 않으면 늘 빈 목록이라 언어를 바꿔도 탭 목록이 옛 언어로 남는다.
            expect(app.collectLoadedManualDataSourceIds()).toEqual(['tabList']);
        });
    });

    describe('주소만 바꾼 경로 변경 기록', () => {
        // @scenario base_path=subpath, navigation_direction=navigation
        // @effects url_only_path_alias_matches_browser_address
        it('G7Core.updateQueryParams 가 남긴 별칭이 브라우저 주소끼리 대조된다', async () => {
            setLocation('/g7/admin/users/1');
            const app = { getRouteChangeId: () => 7, updateQueryParams: vi.fn(async () => {}) };
            (window as any).__templateApp = app;
            initializeG7CoreGlobals({
                getState: vi.fn(() => ({})),
                transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
                responsiveManager: {},
                webSocketManager: {
                    subscribe: vi.fn(() => 's'),
                    unsubscribe: vi.fn(),
                    leaveChannel: vi.fn(),
                    disconnect: vi.fn(),
                    isInitialized: vi.fn(() => true),
                    getSubscriptionCount: vi.fn(() => 0),
                },
            } as any);

            // 목록·탭의 navigate replace:true 는 앱 기준 경로를 넘긴다.
            await (window as any).G7Core.updateQueryParams('/admin/users/2?tab=memo');

            // 디바운스 칸의 경로 가드는 `location.pathname`(접두사 포함)끼리 묻는다 — 기록도 같은 기준이어야 참이다.
            expect(app.updateQueryParams).toHaveBeenCalledTimes(1);
            expect(isUrlOnlyPathAlias('/g7/admin/users/1', '/g7/admin/users/2')).toBe(true);
        });
    });
});
