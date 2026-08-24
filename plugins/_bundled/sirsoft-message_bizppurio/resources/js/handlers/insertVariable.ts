/**
 * insertVariable 핸들러 (#597 §4.2)
 *
 * 알림톡 템플릿 작성 모달·SMS 본문 모달에서 "사용 가능 변수" 칩을 클릭하면, 대상 본문의
 * 현재 커서 위치에 `#{변수}` 를 삽입한다. 값 자체는 G7 상태를 SSoT 로 삼아 갱신한다
 * (DOM 값을 직접 쓰지 않으므로 다음 렌더에서 상태값으로 덮여 사라지는 충돌이 없다).
 *
 * 커서 위치만 DOM 에서 읽고(selectionStart), 없으면 문자열 끝에 붙인다.
 *
 * 레이아웃 JSON 사용 예:
 * {
 *   "handler": "sirsoft-message_bizppurio.insertVariable",
 *   "params": {
 *     "variable": "name",
 *     "target": "bz_template_content",
 *     "stateTarget": "global",
 *     "statePath": "bz_tpl_modal.content.templateContent"
 *   }
 * }
 */

import type { ActionContext, ActionWithParams } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('MessageBizppurio:InsertVariable')) ?? {
    log: (...args: unknown[]) => console.log('[MessageBizppurio:InsertVariable]', ...args),
    warn: (...args: unknown[]) => console.warn('[MessageBizppurio:InsertVariable]', ...args),
    error: (...args: unknown[]) => console.error('[MessageBizppurio:InsertVariable]', ...args),
};

/** 삽입 대상 본문 필드(name) 기본값 */
const DEFAULT_TARGET = 'bz_template_content';

/** 상태 경로 기본값 */
const DEFAULT_STATE_PATH = 'bz_tpl_modal.content.templateContent';

/**
 * 대상 본문의 커서 위치에 `#{변수}` 를 삽입하고 G7 상태를 갱신합니다.
 *
 * @param action  액션 객체(params.variable/target/stateTarget/statePath)
 * @param context  액션 컨텍스트(state 스냅샷)
 */
export function insertVariableHandler(
    action: ActionWithParams,
    context: ActionContext,
): void {
    const G7Core = (window as any).G7Core;
    const params = action.params ?? {};
    const variable = String(params.variable ?? '').trim();
    const target = String(params.target ?? DEFAULT_TARGET);
    const stateTarget = String(params.stateTarget ?? 'global');
    const statePath = String(params.statePath ?? DEFAULT_STATE_PATH);

    if (variable === '') {
        logger.warn('삽입할 변수명이 지정되지 않았습니다.');
        return;
    }

    const setState = stateTarget === 'local'
        ? G7Core?.state?.setLocal
        : (G7Core?.state?.setGlobal ?? G7Core?.state?.set);
    const getState = stateTarget === 'local'
        ? G7Core?.state?.getLocal
        : (G7Core?.state?.getGlobal ?? G7Core?.state?.get);

    if (!setState || !getState) {
        logger.error('G7Core.state API 를 사용할 수 없습니다.');
        return;
    }

    // 현재 값: 핸들러 컨텍스트 스냅샷 우선(React 커밋 직후 stale globalState 회피), 없으면 getState.
    const stateSnapshot = stateTarget === 'local'
        ? ((context as any)?.state ?? getState() ?? {})
        : (getState() ?? {});
    const current = String(readPath(stateSnapshot, statePath) ?? '');

    const token = `#{${variable}}`;

    // 커서 위치: DOM 에서 selectionStart 를 읽되, 값 자체는 상태 기준으로 조립.
    const field = document.querySelector<HTMLTextAreaElement | HTMLInputElement>(
        `textarea[name="${target}"], input[name="${target}"]`,
    );
    const domValue = field?.value ?? '';
    // DOM 값과 상태 값이 동일할 때만 커서 위치를 신뢰(동기화된 경우). 다르면 끝에 붙인다.
    const inSync = domValue === current;
    const start = inSync ? (field?.selectionStart ?? current.length) : current.length;
    const end = inSync ? (field?.selectionEnd ?? current.length) : current.length;

    const next = current.slice(0, start) + token + current.slice(end);

    setState({ [statePath]: next });

    // 커서를 삽입한 토큰 뒤로 이동(다음 렌더 후 DOM 반영되므로 requestAnimationFrame 안에서).
    if (field) {
        const caret = start + token.length;
        requestAnimationFrame(() => {
            try {
                field.focus();
                field.setSelectionRange(caret, caret);
            } catch {
                // setSelectionRange 미지원 input — 무시
            }
        });
    }

    logger.log(`변수 삽입: ${token} → ${stateTarget}:${statePath}`);
}

/**
 * dot notation 경로로 객체에서 값을 읽습니다. (예: "bz_tpl_modal.content.templateContent")
 *
 * @param obj  대상 객체
 * @param path  dot 경로
 * @return 경로 값(없으면 undefined)
 */
function readPath(obj: Record<string, any>, path: string): unknown {
    return path.split('.').reduce<any>((acc, key) => (acc == null ? undefined : acc[key]), obj);
}
