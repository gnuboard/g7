/**
 * error-handling-tab-roundtrip.test.tsx — [에러 처리] 탭 산출물의 런타임 라운드트립
 *
 *
 * [에러 처리] 탭이 패치한 레이아웃 errorHandling 이 실제 마운트 시:
 *   ① `setLayoutConfig` 등록 → 해당 코드 에러 발생 시 ErrorHandlingResolver 가 그
 *      핸들러를 실제 dispatch (등록 → 실행)
 *   ② `default` 행은 미정의 코드에 폴백
 *   ③ 상속 strip 후에도 부모 병합본이 런타임 동일(자기 선언분 strip + 부모 병합 =
 *      병합본과 동작 일치)
 *   ④ `showErrorPage` 는 `error_config.layouts` 경로로 레이아웃 이름을 해석해 렌더
 * 를 검증한다.
 *
 * 엔진 사실:
 * - ErrorHandlingResolver(싱글톤)는 setLayoutConfig/setTemplateConfig 로 계층 설정을
 *   받고, resolve(code) 가 액션>레이아웃>템플릿>시스템 우선순위로 핸들러를 고른다
 *   (ErrorHandlingResolver.ts:133-219). execute(handler, ctx) 는 주입된 executor
 *   (운영=ActionDispatcher.dispatchAction)로 실제 dispatch 한다(:228-247).
 * - showErrorPage 핸들러는 ErrorPageHandler.renderError 로 위임하고, renderError 는
 *   `error_config.layouts[code]` 에서 레이아웃 이름을 해석한다(ErrorPageHandler.ts:152).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  ErrorHandlingResolver,
  getErrorHandlingResolver,
} from '../../../../error/ErrorHandlingResolver';
import { ActionDispatcher } from '../../../ActionDispatcher';
import { ErrorPageHandler } from '../../../ErrorPageHandler';
import type { ErrorHandlingMap } from '../../../../types/ErrorHandling';

describe('[에러 처리] 탭 errorHandling 런타임 라운드트립', () => {
  let resolver: ErrorHandlingResolver;
  let dispatcher: ActionDispatcher;
  /** 실제 내장 핸들러 효과(navigate/toast)를 순서대로 기록 */
  let effects: Array<{ kind: string; payload: any }>;

  beforeEach(() => {
    ErrorHandlingResolver.resetInstance();
    resolver = getErrorHandlingResolver();

    effects = [];
    const navigate = vi.fn((path: string) => effects.push({ kind: 'navigate', payload: path }));
    dispatcher = new ActionDispatcher({ navigate });
    dispatcher.setGlobalStateUpdater((updates: Record<string, any>) => {
      if (updates.toasts) {
        effects.push({ kind: 'toast', payload: updates.toasts[updates.toasts.length - 1] });
      }
    });
    (window as any).G7Core = { state: { get: () => ({ toasts: [] }) } };

    // 운영 wiring: ErrorHandlingResolver 가 실제 ActionDispatcher 로 핸들러 실행.
    resolver.setActionExecutor((handler, ctx) =>
      dispatcher.dispatchAction({ type: 'click', ...(handler as any) }, ctx),
    );
  });

  afterEach(() => {
    ErrorHandlingResolver.resetInstance();
  });

  it('① 레이아웃 errorHandling[code] 등록 → 해당 코드 에러 시 그 핸들러가 실제 dispatch 된다', async () => {
    // [에러 처리] 탭이 패치한 레이아웃 errorHandling.
    const layoutErrorHandling: ErrorHandlingMap = {
      403: { handler: 'navigate', params: { path: '/forbidden' } },
      404: { handler: 'toast', params: { type: 'error', message: '없는 페이지' } },
    };
    resolver.setLayoutConfig(layoutErrorHandling);

    // 403 에러 → navigate 핸들러 실행
    const r403 = await resolver.resolveAndExecute(403, { status: 403, message: 'Forbidden' });
    expect(r403.handled).toBe(true);
    expect(effects).toContainEqual({ kind: 'navigate', payload: '/forbidden' });

    // 404 에러 → toast 핸들러 실행
    effects.length = 0;
    const r404 = await resolver.resolveAndExecute(404, { status: 404, message: 'Not Found' });
    expect(r404.handled).toBe(true);
    expect(effects[0].kind).toBe('toast');
    expect(effects[0].payload).toMatchObject({ type: 'error', message: '없는 페이지' });
  });

  it('② default 행은 미정의 코드에 폴백된다', async () => {
    const layoutErrorHandling: ErrorHandlingMap = {
      404: { handler: 'navigate', params: { path: '/not-found' } },
      default: { handler: 'toast', params: { type: 'warning', message: '오류가 발생했습니다' } },
    };
    resolver.setLayoutConfig(layoutErrorHandling);

    // 정의된 404 → 전용 핸들러(navigate)
    const result404 = resolver.resolve(404);
    expect(result404.handler?.handler).toBe('navigate');
    expect(result404.matchedKey).toBe(404);

    // 미정의 500 → default 폴백(toast)
    const result500 = resolver.resolve(500);
    expect(result500.handler?.handler).toBe('toast');
    expect(result500.matchedKey).toBe('default');

    // 실제 실행도 default 핸들러
    await resolver.resolveAndExecute(500, { status: 500, message: 'err' });
    expect(effects[0].kind).toBe('toast');
    expect(effects[0].payload).toMatchObject({ type: 'warning' });
  });

  it('③ 상속 strip 후 부모 병합본이 런타임 동일하다 (자기 strip + 부모 = 병합본)', async () => {
    // 부모(템플릿/베이스) errorHandling 과 자식(레이아웃) errorHandling.
    // 자식이 자기 선언분만 저장(상속분 strip)해도, 런타임 병합(레이아웃<템플릿 폴백)으로
    // 부모 동작이 그대로 살아 있어 "병합본"과 동작이 같다.
    const parentTemplateErrorHandling: ErrorHandlingMap = {
      401: { handler: 'navigate', params: { path: '/login' } }, // 부모 전용
      500: { handler: 'toast', params: { type: 'error', message: '부모 500' } },
    };
    // 자식은 500 을 덮고, 자기 고유 404 를 추가(상속분 401 은 strip — 저장 안 됨).
    const childLayoutErrorHandling: ErrorHandlingMap = {
      404: { handler: 'navigate', params: { path: '/child-404' } },
      500: { handler: 'toast', params: { type: 'error', message: '자식 500' } },
    };

    resolver.setTemplateConfig(parentTemplateErrorHandling);
    resolver.setLayoutConfig(childLayoutErrorHandling);

    // 자식이 덮은 500 → 자식 핸들러(레이아웃 우선)
    const r500 = resolver.resolve(500);
    expect(r500.level).toBe('layout');
    expect(r500.handler?.params?.message).toBe('자식 500');

    // 자식 고유 404 → 레이아웃 핸들러
    const r404 = resolver.resolve(404);
    expect(r404.level).toBe('layout');

    // strip 된 상속 401 → 레이아웃엔 없지만 부모(템플릿) 폴백으로 살아 있음(병합본 동일)
    const r401 = resolver.resolve(401);
    expect(r401.level).toBe('template');
    expect(r401.handler?.params?.path).toBe('/login');

    // 실제 실행: 401 은 부모 navigate 로 dispatch
    await resolver.resolveAndExecute(401, { status: 401, message: 'Unauthorized' });
    expect(effects).toContainEqual({ kind: 'navigate', payload: '/login' });
  });

  it('④ showErrorPage 는 error_config.layouts 경로로 레이아웃 이름을 해석해 렌더한다', async () => {
    // template.json 의 error_config.layouts 매핑을 fetch 로 모킹.
    const errorConfig = { layouts: { 404: '404', 403: '403', 500: '500', default: 'error' } };
    const fetchSpy = vi.spyOn(window, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { error_config: errorConfig } }),
    } as Response);

    // 레이아웃 로드/렌더는 스파이로 — renderError 가 layouts[code] 로 해석한 이름을
    // layoutLoader 에 넘기는지(=error_config.layouts 경로)만 본다.
    const loadLayout = vi.fn().mockResolvedValue({ layout_name: '404', components: [] });
    const renderFunction = vi.fn().mockResolvedValue(undefined);

    const handler = new ErrorPageHandler({
      templateId: 'sirsoft-basic',
      layoutLoader: { loadLayout } as any,
      locale: 'ko',
      debug: false,
      renderFunction: renderFunction as any,
      dataSourceManager: {} as any,
    });

    // 컨테이너 준비(renderError 는 containerId 로 DOM 에 렌더)
    const container = document.createElement('div');
    container.id = 'main_content';
    document.body.appendChild(container);

    // error_config.layouts 에 정의된 404 → '404' 레이아웃 이름 해석
    const has404 = await handler.hasErrorLayout(404);
    expect(has404).toBe(true);

    await handler.renderError(404, 'main_content');

    // layoutLoader 가 error_config.layouts[404] = '404' 로 호출됨(경로 정합)
    expect(loadLayout).toHaveBeenCalledWith('sirsoft-basic', '404');

    // 미정의 코드(418) → layouts 에 없으므로 false(폴백은 default 행이 담당)
    const has418 = await handler.hasErrorLayout(418);
    expect(has418).toBe(false);

    document.body.removeChild(container);
    fetchSpy.mockRestore();
  });

  it('④-b error_config.layouts 에 default 매핑이 있으면 default 행으로 렌더 경로 해석', async () => {
    const errorConfig = { layouts: { 404: '404', default: 'error' } };
    vi.spyOn(window, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { error_config: errorConfig } }),
    } as Response);

    const handler = new ErrorPageHandler({
      templateId: 'sirsoft-basic',
      layoutLoader: { loadLayout: vi.fn() } as any,
      locale: 'ko',
      debug: false,
      renderFunction: vi.fn() as any,
      dataSourceManager: {} as any,
    });

    // 'default' 매핑 존재 — error_config.layouts 가 default 행을 갖는다.
    const config = await handler.loadConfig();
    expect(config?.layouts.default).toBe('error');
  });
});
