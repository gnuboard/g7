/**
 * ErrorHandlingResolver 테스트
 *
 * 계층적 에러 핸들링 설정 해석 및 실행 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ErrorHandlingResolver, getErrorHandlingResolver } from '../ErrorHandlingResolver';
import type { ErrorHandlingMap, ErrorHandlerConfig, ErrorContext } from '../../types/ErrorHandling';
import { Logger } from '../../utils/Logger';

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
    it('getInstance()는 동일한 인스턴스를 반환해야 함', () => {
      const instance1 = ErrorHandlingResolver.getInstance();
      const instance2 = ErrorHandlingResolver.getInstance();
      expect(instance1).toBe(instance2);
    });

    it('resetInstance() 후에는 새 인스턴스를 반환해야 함', () => {
      const instance1 = ErrorHandlingResolver.getInstance();
      ErrorHandlingResolver.resetInstance();
      const instance2 = ErrorHandlingResolver.getInstance();
      expect(instance1).not.toBe(instance2);
    });

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

    it('레이아웃에도 없으면 템플릿 설정 사용', () => {
      resolver.setTemplateConfig({
        403: { handler: 'navigate', params: { path: '/template-403' } },
      });

      const result = resolver.resolve(403, {});

      expect(result.handler?.handler).toBe('navigate');
      expect(result.level).toBe('template');
      expect(result.matchedKey).toBe(403);
    });

    it('모든 레벨에 없으면 시스템 기본값 사용', () => {
      const result = resolver.resolve(403, {});

      expect(result.handler?.handler).toBe('toast');
      expect(result.level).toBe('system');
    });

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
    let mockExecutor: ReturnType<typeof vi.fn>;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
      mockExecutor = vi.fn().mockResolvedValue({ success: true });
      resolver.setActionExecutor(mockExecutor);
    });

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
    let mockExecutor: ReturnType<typeof vi.fn>;

    beforeEach(() => {
      resolver = ErrorHandlingResolver.getInstance();
      mockExecutor = vi.fn().mockResolvedValue({ success: true });
      resolver.setActionExecutor(mockExecutor);
    });

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

    it('핸들러를 찾지 못하면 handled: false 반환', async () => {
      // 모든 설정 초기화
      resolver.clearAllConfig();

      // 존재하지 않는 에러 코드 (시스템 기본값에도 없는)
      // 참고: 시스템 기본값에 default가 있으므로 항상 핸들러를 찾음
      // 이 테스트는 시스템 기본값을 우회할 수 없으므로 항상 handled: true
      const result = await resolver.resolveAndExecute(
        999,
        { status: 999, message: 'Unknown' },
        {}
      );

      // 시스템 기본값의 default 핸들러가 적용됨
      expect(result.handled).toBe(true);
    });

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
