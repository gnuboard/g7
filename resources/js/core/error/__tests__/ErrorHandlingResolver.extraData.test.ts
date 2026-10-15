/**
 * ErrorHandlingResolver — 데이터소스 오류 처리 실행 문맥에 route·query 싣기 (engine-v1.80.0, #753 D4)
 *
 * 데이터소스 오류 처리기(onError·errorHandling·errorCondition)의 실행 문맥이 `{ auth, error }` 뿐이라
 * 작성/수정 겸용 화면이 404 를 주소(`route.claim_number`)로 가를 수 없었다. 그래서 레이아웃은 그 판정을
 * 확장 핸들러로 옮겨 우회했다. 이제 `execute(handler, errorContext, { route, query })` 로 넘긴다.
 *
 * @since engine-v1.80.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ErrorHandlingResolver } from '../ErrorHandlingResolver';
import type { ErrorContext, ErrorHandlerConfig } from '../../types/ErrorHandling';
import { Logger } from '../../utils/Logger';

describe('ErrorHandlingResolver — 추가 실행 문맥 (#753)', () => {
  let resolver: ErrorHandlingResolver;
  let mockExecutor: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    ErrorHandlingResolver.resetInstance();
    Logger.getInstance().setDebug(false);
    resolver = ErrorHandlingResolver.getInstance();
    mockExecutor = vi.fn().mockResolvedValue({ success: true });
    resolver.setActionExecutor(mockExecutor);
  });

  /**
   * @scenario slot=datasource_error, shape=single
   * @effects datasource_error_reads_route_query
   */
  it('execute(handler, ctx, {route, query}) 가 실행기에 {error, route, query} 를 넘긴다', async () => {
    const handler: ErrorHandlerConfig = { handler: 'suppress' };
    const errorContext: ErrorContext = { status: 404, message: 'Not Found' };

    await resolver.execute(handler, errorContext, { route: { claim_number: 'RT1' }, query: { mode: 'quiet' } });

    expect(mockExecutor).toHaveBeenCalledWith(handler, {
      error: errorContext,
      route: { claim_number: 'RT1' },
      query: { mode: 'quiet' },
    });
  });

  /**
   * @scenario slot=datasource_error, shape=single
   * @effects datasource_error_reads_route_query
   */
  it('추가 문맥이 없으면 지금처럼 {error} 만 넘긴다 (회귀)', async () => {
    const errorContext: ErrorContext = { status: 500, message: 'error' };

    await resolver.execute({ handler: 'suppress' }, errorContext);

    expect(mockExecutor).toHaveBeenCalledWith({ handler: 'suppress' }, { error: errorContext });
  });

  /**
   * @scenario slot=datasource_error, shape=single
   * @effects datasource_error_reads_route_query
   */
  it('추가 문맥의 error 키가 오류 문맥을 덮지 못한다', async () => {
    const errorContext: ErrorContext = { status: 404, message: 'Not Found' };

    await resolver.execute({ handler: 'suppress' }, errorContext, { error: { status: 1 } } as any);

    expect(mockExecutor.mock.calls[0][1].error).toBe(errorContext);
  });

  /**
   * @scenario slot=datasource_error, shape=conditions
   * @effects datasource_error_reads_route_query
   */
  it('resolveAndExecute 가 추가 문맥(route·query)을 실행기까지 넘긴다', async () => {
    await resolver.resolveAndExecute(
      404,
      { status: 404, message: 'Not Found' },
      { errorHandling: { 404: { handler: 'conditions', conditions: [] } as any } },
      { route: { id: '7' }, query: {} },
    );

    expect(mockExecutor).toHaveBeenCalledWith(
      expect.objectContaining({ handler: 'conditions' }),
      { error: { status: 404, message: 'Not Found' }, route: { id: '7' }, query: {} },
    );
  });

  /**
   * 단일 onError 객체는 resolve 결과에서 conditions 키를 그대로 유지한다 — 탈락은 실행기 쪽이었다(현행 보증).
   *
   * @scenario slot=fail, shape=conditions
   * @effects fail_runs_full_action
   */
  it('단일 onError 객체가 resolve 결과에서 conditions·if 키를 유지한다', () => {
    const onError = { handler: 'conditions', conditions: [{ if: '{{error.status === 409}}', then: { handler: 'toast' } }], if: '{{true}}' };

    const result = resolver.resolve(409, { onError: onError as any });

    expect(result.handler).toEqual(onError);
  });
});
