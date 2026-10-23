/**
 * @file ErrorHandlingResolver.nestedErrorCode.test.ts
 * @description 오류 처리기 묶음 안의 showErrorPage 가 실제 오류 코드를 받는다 (7.1.3)
 *
 * 결함 E8: `ErrorHandlingUtils.injectErrorCode()` 가 최상위 `showErrorPage` 에만 오류 코드를 넣어, `sequence`·`parallel`·
 * `conditions(then)`·`switch(cases)` 안의 `showErrorPage` 는 errorCode 가 없었다 — ActionDispatcher 가 기본값 500 으로 그렸다
 * (403 응답에 500 화면).
 * 결함 E9: `ErrorHandlingResolver.resolve()` 의 `onError` 갈래는 오류 코드를 넣지 않았다.
 *
 * @scenario-manifest tests/scenarios/engine-binding-cache-error-state.yaml
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { ErrorHandlingResolver } from '../ErrorHandlingResolver';
import { ErrorHandlingUtils } from '../../types/ErrorHandling';

const showErrorPage = { handler: 'showErrorPage', params: { target: 'content' } };

describe('묶음 안 showErrorPage 오류 코드 주입 (7.1.3)', () => {
  beforeEach(() => {
    ErrorHandlingResolver.resetInstance();
  });

  /**
   * @scenario path=error_handler_tree
   * @effects nested_show_error_page_gets_code
   */
  it('E8. sequence·parallel·params.actions 안 showErrorPage 에 errorHandling 키 코드를 넣는다', () => {
    const injected = ErrorHandlingUtils.injectErrorCode({
      handler: 'sequence',
      actions: [
        { handler: 'toast', params: { message: 'x' } },
        { handler: 'parallel', params: { actions: [showErrorPage] } },
        showErrorPage,
      ],
    } as any, 403);

    expect((injected as any).actions[2].params.errorCode).toBe(403); // 결함: undefined
    expect((injected as any).actions[1].params.actions[0].params.errorCode).toBe(403);
    expect((injected as any).actions[0].params.errorCode).toBeUndefined();
    // 원본은 바꾸지 않는다
    expect((showErrorPage.params as any).errorCode).toBeUndefined();
  });

  /**
   * @scenario path=error_handler_tree
   * @effects nested_show_error_page_gets_code
   */
  it('E8. conditions(then)·switch(cases) 안 showErrorPage 에도 넣고, 이미 있는 errorCode 는 지킨다', () => {
    const injected = ErrorHandlingUtils.injectErrorCode({
      handler: 'conditions',
      conditions: [
        { if: '{{a}}', then: { handler: 'switch', cases: { x: showErrorPage, default: [{ handler: 'showErrorPage', params: { errorCode: 404 } }] } } },
        { then: [showErrorPage] },
      ],
    } as any, 'default', 503);

    expect((injected as any).conditions[0].then.cases.x.params.errorCode).toBe(503); // 키가 숫자가 아니면 실제 코드
    expect((injected as any).conditions[0].then.cases.default[0].params.errorCode).toBe(404);
    expect((injected as any).conditions[1].then[0].params.errorCode).toBe(503);
  });

  /**
   * @scenario path=error_handler_tree
   * @effects nested_show_error_page_gets_code
   */
  it('E8. 레이아웃 errorHandling 의 sequence 안 showErrorPage 가 resolve 결과에서 실제 코드를 갖는다', () => {
    const resolver = ErrorHandlingResolver.getInstance();
    resolver.setLayoutConfig({ 403: { handler: 'sequence', actions: [showErrorPage] } } as any);

    const result = resolver.resolve(403);
    expect((result.handler as any).actions[0].params.errorCode).toBe(403);
  });

  /**
   * @scenario path=error_handler_tree
   * @effects on_error_show_error_page_gets_code
   */
  it('E9. onError 단독·배열의 showErrorPage 가 실제 오류 코드를 받는다', () => {
    const resolver = ErrorHandlingResolver.getInstance();

    const single = resolver.resolve(403, { onError: showErrorPage as any });
    expect(single.matchedKey).toBe('onError');
    expect((single.handler as any).params.errorCode).toBe(403); // 결함: undefined → 500 화면

    const list = resolver.resolve(404, { onError: [{ handler: 'toast', params: { message: 'x' } }, showErrorPage] as any });
    expect((list.handler as any).handler).toBe('sequence');
    expect((list.handler as any).actions[1].params.errorCode).toBe(404);
  });
});
