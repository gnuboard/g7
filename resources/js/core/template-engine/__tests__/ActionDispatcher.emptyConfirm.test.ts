// e2e:allow 단위·레이아웃 시험 파일 — 실화면 측정은 같은 엔진 변경의 브라우저 매트릭스
/**
 * 회귀: 동작의 `confirm` 식이 빈 값으로 풀려도 빈 확인창이 떴다 (engine-v1.92.0)
 *
 * `confirm="{{has_token ? '$t:…reissue_confirm' : ''}}"` 처럼 조건에 따라 확인을 묻는 식은, 디스패처가 **식 원문**의
 * 유무(`if (action.confirm)`)로 판정해 결과가 `''` 여도 `confirm('')` 을 불렀다(SSO 첫 SCIM 토큰 발급의 빈 확인창).
 *
 * 고침: 이벤트 핸들러 경로(`createHandler`)·직접 실행 경로(`dispatchAction`) 둘 다 confirm 을 풀고 번역한 뒤 비었으면
 * (빈 문자열·공백 — 문자열 보간이 null·undefined 를 빈 문자열로 푼다) 확인창 없이 동작을 실행한다. 문구가 있으면 종전대로
 * 묻고, 취소면 실행하지 않는다. `false` 는 보간이 문자열 "false" 로 풀므로 묻지 않으려면 `''` 를 쓴다(이 시험 범위 밖).
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({ login: vi.fn(), logout: vi.fn() })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('[case:components-form-conditional-confirm-empty] ActionDispatcher - 빈 confirm 은 묻지 않는다', () => {
  let dispatcher: ActionDispatcher;
  let probe: ReturnType<typeof vi.fn>;
  let confirmSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    dispatcher = new ActionDispatcher();
    probe = vi.fn();
    dispatcher.registerHandler('probe', probe as any);
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  afterEach(() => {
    confirmSpy.mockRestore();
  });

  const action = (confirm: string): ActionDefinition => ({ type: 'click', handler: 'probe', confirm } as ActionDefinition);
  const clickEvent = () => new Event('click', { bubbles: true, cancelable: true });

  const viaHandler = (def: ActionDefinition, data: Record<string, any>) =>
    dispatcher.createHandler(def, data, { state: {}, setState: vi.fn() })(clickEvent() as any);
  const viaDispatch = (def: ActionDefinition, data: Record<string, any>) =>
    dispatcher.dispatchAction(def, { data } as any);

  const paths = [
    { label: 'createHandler', run: viaHandler },
    { label: 'dispatchAction', run: viaDispatch },
  ];

  // 마커 파서는 주석 블록 하나에서 조합 하나만 읽으므로 조합마다 블록을 둔다
  /**
   * @scenario path=createHandler, confirm_result=empty_string
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=createHandler, confirm_result=whitespace
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=createHandler, confirm_result=null
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=createHandler, confirm_result=undefined
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=dispatchAction, confirm_result=empty_string
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=dispatchAction, confirm_result=whitespace
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=dispatchAction, confirm_result=null
   * @effects empty_confirm_runs_without_dialog
   */
  /**
   * @scenario path=dispatchAction, confirm_result=undefined
   * @effects empty_confirm_runs_without_dialog
   */
  it.each(
    paths.flatMap((p) => [
      { ...p, expr: "{{hasToken ? '다시 발급할까요?' : ''}}", kind: '빈 문자열' },
      { ...p, expr: "{{hasToken ? '다시 발급할까요?' : '  '}}", kind: '공백' },
      { ...p, expr: "{{hasToken ? '다시 발급할까요?' : null}}", kind: 'null' },
      { ...p, expr: '{{hasToken ? "다시 발급할까요?" : undefined}}', kind: 'undefined' },
    ])
  )('$label: confirm 식이 $kind 로 풀리면 확인창 없이 실행한다', async ({ run, expr }) => {
    await run(action(expr), { hasToken: false });

    expect(confirmSpy).not.toHaveBeenCalled();
    expect(probe).toHaveBeenCalledTimes(1);
  });

  /**

   * @scenario path=createHandler, confirm_result=message_accept

   * @effects non_empty_confirm_still_asks

   */
  /**
   * @scenario path=dispatchAction, confirm_result=message_accept
   * @effects non_empty_confirm_still_asks
   */
  it.each(paths)('대조군 $label: 문구로 풀리면 종전대로 묻고, 확인이면 실행한다', async ({ run }) => {
    await run(action("{{hasToken ? '다시 발급할까요?' : ''}}"), { hasToken: true });

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(confirmSpy.mock.calls[0][0]).toBe('다시 발급할까요?');
    expect(probe).toHaveBeenCalledTimes(1);
  });

  /**

   * @scenario path=createHandler, confirm_result=message_cancel

   * @effects non_empty_confirm_still_asks

   */
  /**
   * @scenario path=dispatchAction, confirm_result=message_cancel
   * @effects non_empty_confirm_still_asks
   */
  it.each(paths)('대조군 $label: 문구가 있고 취소하면 실행하지 않는다', async ({ run }) => {
    confirmSpy.mockReturnValue(false);
    await run(action('정말 지울까요?'), {});

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(probe).not.toHaveBeenCalled();
  });
});
