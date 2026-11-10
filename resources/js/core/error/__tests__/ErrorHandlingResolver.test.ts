/**
 * ErrorHandlingResolver 테스트
 *
 * 계층적 에러 핸들링 설정 해석 및 실행 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';
import { ErrorHandlingResolver, getErrorHandlingResolver } from '../ErrorHandlingResolver';
import type { ErrorHandlingMap, ErrorHandlerConfig, ErrorContext } from '../../types/ErrorHandling';
import { Logger } from '../../utils/Logger';

/** setActionExecutor 가 받는 실행기 시그니처 */
type ActionExecutor = Parameters<ErrorHandlingResolver['setActionExecutor']>[0];

describe('ErrorHandlingResolver', () => {
  beforeEach(() => {
    // 각 테스트 전에 싱글톤 인스턴스 초기화
    ErrorHandlingResolver.resetInstance();
    // Logger 디버그 모드 활성화
    Logger.getInstance().setDebug(true);
  });

  afterEach(() => {
    // Logger 디버그 모드 비활성화
    Logger.getInstance().setDebug(false);
  });

  describe('싱글톤 패턴', () => {
    // @scenario area=singleton
    // @effects error_resolver_singleton
    it('getInstance()는 동일한 인스턴스를 반환해야 함', () => {
      const instance1 = ErrorHandlingResolver.getInstance();
      const instance2 = ErrorHandlingResolver.getInstance();
      expect(instance1).toBe(instance2);
    });

    // @scenario area=singleton
    // @effects error_resolver_singleton
    it('resetInstance() 후에는 새 인스턴스를 반환해야 함', () => {
      const instance1 = ErrorHandlingResolver.getInstance();
      ErrorHandlingResolver.resetInstance();
      const instance2 = ErrorHandlingResolver.getInstance();
      expect(instance1).not.toBe(instance2);
    });

    // @scenario area=singleton
    // @effects error_resolver_singleton
    it('getErrorHandlingResolver()는 getInstance()와 동일한 결과를 반환해야 함', () => {
      const instance1 = getErrorHandlingResolver();
      const instance2 = ErrorHandlingResolver.getInstance();
      expect(instance1).toBe(instance2);
    });
  });

  describe('resolve() - 우선순위 테스트', () => {
    let resolver: ErrorHandlingResolver;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('액션 레벨 errorHandling[코드]가 최우선 순위', () => {
      // 템플릿 설정
      resolver.setTemplateConfig({
        403: { handler: 'navigate', params: { path: '/template-403' } },
      });

      // 레이아웃 설정
      resolver.setLayoutConfig({
        403: { handler: 'toast', params: { message: 'layout 403' } },
      });

      // 액션 레벨 설정
      const result = resolver.resolve(403, {
        errorHandling: {
          403: { handler: 'openModal', target: 'action_modal' },
        },
      });

      expect(result.handler?.handler).toBe('openModal');
      expect(result.handler?.target).toBe('action_modal'); // target도 검증
      expect(result.level).toBe('action');
      expect(result.matchedKey).toBe(403);
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('문자열 키로 정의된 errorHandling에서 target이 보존됨 (JSON 파싱 시나리오)', () => {
      // JSON 파싱 결과를 시뮬레이션 (키가 문자열)
      const jsonParsedErrorHandling: ErrorHandlingMap = {
        '404': { handler: 'openModal', target: 'tempOrderNotFoundModal' },
      };

      const result = resolver.resolve(404, {
        errorHandling: jsonParsedErrorHandling,
      });

      expect(result.handler?.handler).toBe('openModal');
      expect(result.handler?.target).toBe('tempOrderNotFoundModal');
      expect(result.level).toBe('action');
      // 문자열 키 "404"로 매칭되므로 matchedKey는 404 (숫자) 또는 "404" (문자열)
      expect([404, '404']).toContain(result.matchedKey);
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('액션 errorHandling에 코드가 없으면 default 사용', () => {
      const result = resolver.resolve(500, {
        errorHandling: {
          403: { handler: 'openModal', target: 'modal' },
          default: { handler: 'toast', params: { message: 'default error' } },
        },
      });

      expect(result.handler?.handler).toBe('toast');
      expect(result.level).toBe('action');
      expect(result.matchedKey).toBe('default');
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('액션 errorHandling에 없으면 onError 사용', () => {
      const result = resolver.resolve(500, {
        errorHandling: {
          403: { handler: 'openModal', target: 'modal' },
        },
        onError: { handler: 'toast', params: { message: 'onError fallback' } },
      });

      expect(result.handler?.handler).toBe('toast');
      expect(result.level).toBe('action');
      expect(result.matchedKey).toBe('onError');
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('onError가 배열이면 sequence로 래핑', () => {
      const result = resolver.resolve(500, {
        onError: [
          { handler: 'setState', params: { key: 'error', value: true } },
          { handler: 'toast', params: { message: 'error' } },
        ],
      });

      expect(result.handler?.handler).toBe('sequence');
      expect(result.handler?.actions).toHaveLength(2);
      expect(result.level).toBe('action');
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('액션 레벨에 없으면 레이아웃 설정 사용', () => {
      resolver.setLayoutConfig({
        403: { handler: 'toast', params: { message: 'layout 403' } },
      });

      const result = resolver.resolve(403, {
        errorHandling: {
          500: { handler: 'navigate', params: { path: '/500' } },
        },
      });

      expect(result.handler?.handler).toBe('toast');
      expect(result.level).toBe('layout');
      expect(result.matchedKey).toBe(403);
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('레이아웃에도 없으면 템플릿 설정 사용', () => {
      resolver.setTemplateConfig({
        403: { handler: 'navigate', params: { path: '/template-403' } },
      });

      const result = resolver.resolve(403, {});

      expect(result.handler?.handler).toBe('navigate');
      expect(result.level).toBe('template');
      expect(result.matchedKey).toBe(403);
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('모든 레벨에 없으면 시스템 기본값 사용', () => {
      const result = resolver.resolve(403, {});

      expect(result.handler?.handler).toBe('toast');
      expect(result.level).toBe('system');
    });

    // @scenario area=priority
    // @effects error_level_priority
    it('문자열 키와 숫자 키 모두 지원', () => {
      resolver.setTemplateConfig({
        '404': { handler: 'navigate', params: { path: '/404' } },
      });

      const result = resolver.resolve(404, {});

      expect(result.handler?.handler).toBe('navigate');
      // getMatchedKey는 숫자 키를 먼저 확인하므로 404 숫자 반환
      // (errorHandling[404] 먼저 확인 → errorHandling['404'] 확인)
      expect(result.matchedKey).toBe(404);
    });
  });

  describe('showErrorPage 핸들러 errorCode 자동 주입', () => {
    let resolver: ErrorHandlingResolver;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
    });

    // @scenario area=show_error_page
    // @effects error_page_code_injected
    it('showErrorPage에 errorCode가 없으면 매칭된 키값으로 자동 주입', () => {
      resolver.setTemplateConfig({
        403: {
          handler: 'showErrorPage',
          params: { target: 'content' },
        },
      });

      const result = resolver.resolve(403, {});

      expect(result.handler?.handler).toBe('showErrorPage');
      expect(result.handler?.params?.errorCode).toBe(403);
    });

    // @scenario area=show_error_page
    // @effects error_page_code_injected
    it('showErrorPage에 errorCode가 이미 있으면 그대로 유지', () => {
      resolver.setTemplateConfig({
        403: {
          handler: 'showErrorPage',
          params: { errorCode: 401, target: 'full' },
        },
      });

      const result = resolver.resolve(403, {});

      expect(result.handler?.handler).toBe('showErrorPage');
      expect(result.handler?.params?.errorCode).toBe(401);
    });

    // @scenario area=show_error_page
    // @effects error_page_code_injected
    it('default 키로 매칭될 때는 실제 에러 코드(actualErrorCode)로 주입', () => {
      resolver.setTemplateConfig({
        default: {
          handler: 'showErrorPage',
          params: { target: 'content' },
        },
      });

      const result = resolver.resolve(500, {});

      // default 키로 매칭되었지만, actualErrorCode fallback으로 실제 에러 코드 주입
      expect(result.handler?.handler).toBe('showErrorPage');
      expect(result.matchedKey).toBe('default');
      expect(result.handler?.params?.errorCode).toBe(500);
    });

    // @scenario area=show_error_page
    // @effects error_page_code_injected
    it('default 키로 매칭 + HTTP 403 에러 시 실제 에러 코드 403 주입', () => {
      const result = resolver.resolve(403, {
        errorHandling: {
          default: {
            handler: 'showErrorPage',
            params: { target: 'content' },
          },
        },
      });

      expect(result.handler?.handler).toBe('showErrorPage');
      expect(result.handler?.params?.errorCode).toBe(403);
      expect(result.matchedKey).toBe('default');
    });
  });

  describe('execute() - 핸들러 실행', () => {
    let resolver: ErrorHandlingResolver;
    let mockExecutor: Mock<ActionExecutor>;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
      mockExecutor = vi.fn<ActionExecutor>().mockResolvedValue({ success: true });
      resolver.setActionExecutor(mockExecutor);
    });

    // @scenario area=execute
    // @effects error_handler_executed
    it('executeAction이 설정되지 않으면 에러 로그 출력', async () => {
      ErrorHandlingResolver.resetInstance();
      const newResolver = ErrorHandlingResolver.getInstance();
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      const handler: ErrorHandlerConfig = {
        handler: 'toast',
        params: { message: 'test' },
      };

      await newResolver.execute(handler, { status: 500, message: 'error' });

      expect(consoleSpy).toHaveBeenCalledWith(
        '[ErrorHandlingResolver]',
        'Action executor is not set'
      );
      consoleSpy.mockRestore();
    });

    // @scenario area=execute
    // @effects error_handler_executed
    it('핸들러 실행 시 executeAction 호출', async () => {
      const handler: ErrorHandlerConfig = {
        handler: 'toast',
        params: { message: 'test error' },
      };
      const errorContext: ErrorContext = {
        status: 500,
        message: 'Internal Server Error',
      };

      await resolver.execute(handler, errorContext);

      expect(mockExecutor).toHaveBeenCalledWith(handler, { error: errorContext });
    });

    // @scenario area=execute
    // @effects error_handler_executed
    it('openModal 핸들러 실행 시 target이 executor에 전달됨', async () => {
      const handler: ErrorHandlerConfig = {
        handler: 'openModal',
        target: 'tempOrderNotFoundModal',
      };
      const errorContext: ErrorContext = {
        status: 404,
        message: 'Not Found',
      };

      await resolver.execute(handler, errorContext);

      // executor가 target을 포함한 handler 객체를 받는지 확인
      expect(mockExecutor).toHaveBeenCalledWith(
        expect.objectContaining({
          handler: 'openModal',
          target: 'tempOrderNotFoundModal',
        }),
        { error: errorContext }
      );
    });

    // @scenario area=execute
    // @effects error_handler_executed
    it('executeAction 실패 시 에러 throw', async () => {
      mockExecutor.mockRejectedValue(new Error('Execution failed'));

      const handler: ErrorHandlerConfig = {
        handler: 'toast',
        params: { message: 'test' },
      };

      await expect(
        resolver.execute(handler, { status: 500, message: 'error' })
      ).rejects.toThrow('Execution failed');
    });
  });

  describe('resolveAndExecute() - 통합 테스트', () => {
    let resolver: ErrorHandlingResolver;
    let mockExecutor: Mock<ActionExecutor>;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
      mockExecutor = vi.fn<ActionExecutor>().mockResolvedValue({ success: true });
      resolver.setActionExecutor(mockExecutor);
    });

    // @scenario area=resolve_and_execute
    // @effects error_resolve_execute_result
    it('핸들러 찾아서 실행 후 handled: true 반환', async () => {
      resolver.setTemplateConfig({
        403: { handler: 'toast', params: { message: 'forbidden' } },
      });

      const result = await resolver.resolveAndExecute(
        403,
        { status: 403, message: 'Forbidden' },
        {}
      );

      expect(result.handled).toBe(true);
      expect(mockExecutor).toHaveBeenCalled();
    });

    // @scenario area=resolve_and_execute
    // @effects error_resolve_execute_result
    // 종전 이름은 「핸들러를 찾지 못하면 handled: false 반환」이었으나 단언은 true 였다. 시스템 기본값에
    // default 가 있어 설정을 모두 비워도 핸들러를 찾으므로, 실제로 검증하는 내용에 맞게 이름을 바로잡았다(#869).
    it('설정을 모두 비워도 시스템 기본값 default 가 받아 handled: true 를 반환', async () => {
      resolver.clearAllConfig();

      const result = await resolver.resolveAndExecute(
        999,
        { status: 999, message: 'Unknown' },
        {}
      );

      expect(result.handled).toBe(true);
      expect(mockExecutor).toHaveBeenCalledOnce();
    });

    // @scenario area=resolve_and_execute
    // @effects error_resolve_execute_result
    // 이름 그대로의 갈래(핸들러 없음 → handled: false)는 resolve 가 핸들러를 못 찾은 결과를 돌려줄 때만 닿는다.
    it('핸들러를 찾지 못하면 실행하지 않고 handled: false 반환', async () => {
      vi.spyOn(resolver, 'resolve').mockReturnValue({ handler: null, level: null, matchedKey: null });

      const result = await resolver.resolveAndExecute(
        999,
        { status: 999, message: 'Unknown' },
        {}
      );

      expect(result.handled).toBe(false);
      expect(mockExecutor).not.toHaveBeenCalled();
    });

    // @scenario area=resolve_and_execute
    // @effects error_resolve_execute_result
    it('실행 실패 시 handled: false 반환', async () => {
      mockExecutor.mockRejectedValue(new Error('Execution failed'));

      resolver.setTemplateConfig({
        500: { handler: 'toast', params: { message: 'error' } },
      });

      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      const result = await resolver.resolveAndExecute(
        500,
        { status: 500, message: 'Server Error' },
        {}
      );

      expect(result.handled).toBe(false);
      consoleSpy.mockRestore();
    });
  });

  describe('suppress 핸들러 - 에러 전파 방지 (engine-v1.21.0)', () => {
    let resolver: ErrorHandlingResolver;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
    });

    // @scenario area=suppress
    // @effects error_suppress_stops_propagation
    it('suppress 핸들러가 action 레벨로 resolve되어 상위 전파를 중단한다', () => {
      // 레이아웃에 401 → showErrorPage 설정
      resolver.setLayoutConfig({
        401: { handler: 'showErrorPage', params: { errorCode: 401, target: 'content' } },
      });

      // 데이터소스 레벨에서 suppress로 전파 방지
      const result = resolver.resolve(401, {
        errorHandling: {
          401: { handler: 'suppress' },
        },
      });

      // suppress가 action 레벨로 resolve → 레이아웃 showErrorPage 미도달
      expect(result.handler?.handler).toBe('suppress');
      expect(result.level).toBe('action');
      expect(result.matchedKey).toBe(401);
    });

    // @scenario area=suppress
    // @effects error_suppress_stops_propagation
    it('suppress가 없으면 레이아웃 레벨 errorHandling이 실행된다', () => {
      // 레이아웃에 401 → showErrorPage 설정
      resolver.setLayoutConfig({
        401: { handler: 'showErrorPage', params: { errorCode: 401, target: 'content' } },
      });

      // 데이터소스 레벨에 errorHandling 없음 → 레이아웃으로 전파
      const result = resolver.resolve(401, {});

      expect(result.handler?.handler).toBe('showErrorPage');
      expect(result.level).toBe('layout');
    });

    // @scenario area=suppress
    // @effects error_suppress_stops_propagation
    it('suppress를 default 키로 사용하면 모든 에러 코드의 전파를 방지한다', () => {
      resolver.setLayoutConfig({
        401: { handler: 'showErrorPage', params: { target: 'content' } },
        403: { handler: 'showErrorPage', params: { target: 'content' } },
      });

      const result = resolver.resolve(403, {
        errorHandling: {
          default: { handler: 'suppress' },
        },
      });

      expect(result.handler?.handler).toBe('suppress');
      expect(result.level).toBe('action');
      expect(result.matchedKey).toBe('default');
    });

    // @scenario area=suppress
    // @effects error_suppress_stops_propagation
    it('suppress와 다른 핸들러를 혼합 사용할 수 있다', () => {
      resolver.setLayoutConfig({
        401: { handler: 'showErrorPage', params: { target: 'content' } },
      });

      // 401은 suppress, 403은 showErrorPage
      const errorHandling: ErrorHandlingMap = {
        401: { handler: 'suppress' },
        403: { handler: 'showErrorPage', params: { target: 'content' } },
      };

      const result401 = resolver.resolve(401, { errorHandling });
      expect(result401.handler?.handler).toBe('suppress');
      expect(result401.level).toBe('action');

      const result403 = resolver.resolve(403, { errorHandling });
      expect(result403.handler?.handler).toBe('showErrorPage');
      expect(result403.level).toBe('action');
    });
  });

  describe('설정 관리', () => {
    let resolver: ErrorHandlingResolver;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
    });

    // @scenario area=config
    // @effects error_config_managed
    it('clearLayoutConfig()은 레이아웃 설정만 초기화', () => {
      resolver.setTemplateConfig({
        403: { handler: 'toast', params: {} },
      });
      resolver.setLayoutConfig({
        404: { handler: 'toast', params: {} },
      });

      resolver.clearLayoutConfig();

      const status = resolver.getConfigStatus();
      expect(status.hasTemplate).toBe(true);
      expect(status.hasLayout).toBe(false);
    });

    // @scenario area=config
    // @effects error_config_managed
    it('clearAllConfig()은 모든 설정 초기화', () => {
      resolver.setTemplateConfig({
        403: { handler: 'toast', params: {} },
      });
      resolver.setLayoutConfig({
        404: { handler: 'toast', params: {} },
      });

      resolver.clearAllConfig();

      const status = resolver.getConfigStatus();
      expect(status.hasTemplate).toBe(false);
      expect(status.hasLayout).toBe(false);
    });

    // @scenario area=config
    // @effects error_config_managed
    it('getConfigStatus()는 현재 설정 상태 반환', () => {
      const mockExecutor = vi.fn();
      resolver.setActionExecutor(mockExecutor);
      resolver.setTemplateConfig({ 403: { handler: 'toast', params: {} } });

      const status = resolver.getConfigStatus();

      expect(status.hasTemplate).toBe(true);
      expect(status.hasLayout).toBe(false);
      expect(status.hasExecutor).toBe(true);
    });
  });
});
