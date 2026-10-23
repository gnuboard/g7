/**
 * @file initTemplate.test.ts
 * @description 학습용 템플릿의 최소 부트스트랩 `initTemplate()` (7.1.3 D19)
 *
 * 모든 템플릿은 `initTemplate()` 에서 템플릿 전용 핸들러를 엔진에 등록하고, 본인인증 모달 launcher 를 등록하거나 코어
 * 기본을 쓴다고 정해야 한다(docs/frontend/template-development.md 「템플릿 부트스트랩 의무」). 학습용 템플릿 2종에는
 * 이 함수가 없어 샘플을 본뜬 새 템플릿이 의무를 놓쳤다. 최소 구현은 빈 핸들러 맵을 등록하고 코어 기본 launcher 를 쓴다.
 *
 * @scenario-manifest tests/scenarios/hello-template-bootstrap.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { handlerMap, initTemplate } from '../../src/bootstrap';

afterEach(() => {
    delete (window as any).G7Core;
    for (const key of Object.keys(handlerMap)) delete handlerMap[key];
    vi.useRealTimers();
});

describe('initTemplate', () => {
    /**
     * @scenario dispatcher=ready
     * @effects template_handlers_registered, core_default_identity_launcher_kept
     */
    it('엔진이 준비돼 있으면 핸들러 맵을 등록하고 본인인증 launcher 는 코어 기본을 둔다', () => {
        const registerHandler = vi.fn();
        const setLauncher = vi.fn();
        (window as any).G7Core = { getActionDispatcher: () => ({ registerHandler }), identity: { setLauncher } };
        const sample = vi.fn();
        handlerMap.sampleHandler = sample;

        initTemplate();

        expect(registerHandler).toHaveBeenCalledWith('sampleHandler', sample);
        expect(setLauncher).not.toHaveBeenCalled();
    });

    /**
     * @scenario dispatcher=late
     * @effects template_handlers_registered
     */
    it('엔진이 늦게 준비되면 100ms 간격으로 다시 시도해 등록한다', () => {
        vi.useFakeTimers();
        const registerHandler = vi.fn();
        let ready = false;
        (window as any).G7Core = { getActionDispatcher: () => (ready ? { registerHandler } : undefined) };
        handlerMap.lateHandler = vi.fn();

        initTemplate();
        expect(registerHandler).not.toHaveBeenCalled();

        ready = true;
        vi.advanceTimersByTime(100);
        expect(registerHandler).toHaveBeenCalledWith('lateHandler', handlerMap.lateHandler);
    });
});
