// e2e:allow 설치 마법사는 미설치 사이트에서만 열려 설치된 사이트를 대상으로 하는 Playwright 스위트 밖이다 — 폴링 설치 완료 흐름은 빈 사본 브라우저 실측(#819 3차 세션 B′)으로 확인했다
/**
 * 설치 진행 폴링 모니터 — 완료 표식 뒤 마무리 작업이 끝나기 전에 완료로 처리하지 않는지 잠근다.
 *
 * 회귀: `complete_flag` 작업이 상태를 completed 로 바꾼 뒤에도 설정 캐시·라우트 캐시·정적 게시 작업이 남는데,
 * 폴링 모니터가 completed 를 보자마자 완료 처리(→ finalize-env 가 상태 파일 삭제)해
 * 워커가 「lock lost」로 끝나고 설정 캐시에 installer_completed=false 가 남았다(SSE 는 마지막 작업 뒤 completed 이벤트라 무관).
 */
import { describe, expect, it, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../../../..');
// 설치 화면은 이 파일을 일반 <script> 로 싣는다 — 브라우저와 같은 방식(전역 window 에 등록)으로 평가한다.
const scope: { G7InstallationMonitor?: any } = {};
new Function('window', fs.readFileSync(path.join(root, 'public/install/assets/js/installation-monitor.js'), 'utf8'))(scope);
const { PollingMonitor } = scope.G7InstallationMonitor;

/**
 * 콜백 호출을 기록하는 모니터를 만든다.
 *
 * @returns 모니터와 완료 콜백 스파이
 */
function makeMonitor() {
    const onComplete = vi.fn();
    const monitor = new PollingMonitor({ onComplete }, { stateUrl: '/state', intervalMs: 1000, stuckThresholdMs: 90000 });

    return { monitor, onComplete };
}

const running = { status: 'running', completed_tasks: ['complete_flag'], last_updated: 't1' };

describe('[case:backend-59] PollingMonitor 완료 판정', () => {
    /**
     * @effects polling_monitor_waits_for_final_tasks_after_complete_flag
     */
    it('completed 라도 마무리 작업이 남아 있으면(tasks_finished=false) 완료 처리하지 않고, 끝나면 한 번 완료한다', () => {
        const { monitor, onComplete } = makeMonitor();

        monitor._diffAndEmit(running);
        monitor._diffAndEmit({ status: 'completed', tasks_finished: false, completed_tasks: ['complete_flag'], last_updated: 't2' });
        monitor._diffAndEmit({ status: 'completed', tasks_finished: false, completed_tasks: ['complete_flag', 'config_cache'], last_updated: 't3' });
        expect(onComplete).not.toHaveBeenCalled();
        expect(monitor.terminated).toBe(false);

        monitor._diffAndEmit({ status: 'completed', tasks_finished: true, completed_tasks: ['complete_flag', 'config_cache', 'route_cache', 'static_publish'], last_updated: 't4' });
        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(monitor.terminated).toBe(true);
    });

    /**
     * @effects polling_monitor_completes_when_final_tasks_stall
     */
    it('마무리 작업 중 상태 갱신이 임계 시간 넘게 멈추면 설치 자체는 끝났으므로 완료 처리한다', () => {
        const { monitor, onComplete } = makeMonitor();
        const waiting = { status: 'completed', tasks_finished: false, completed_tasks: ['complete_flag'], last_updated: 't2' };

        monitor._diffAndEmit(running);
        monitor._diffAndEmit(waiting);
        expect(onComplete).not.toHaveBeenCalled();

        monitor.lastUpdatedClientMs = Date.now() - 90001;
        monitor._diffAndEmit(waiting);
        expect(onComplete).toHaveBeenCalledTimes(1);
    });

    /**
     * @effects polling_monitor_completes_without_tasks_finished_field
     */
    it('tasks_finished 필드가 없는 응답(이전 상태 파일)은 종전처럼 completed 즉시 완료한다', () => {
        const { monitor, onComplete } = makeMonitor();

        monitor._diffAndEmit(running);
        monitor._diffAndEmit({ status: 'completed', completed_tasks: ['complete_flag'], last_updated: 't2' });
        expect(onComplete).toHaveBeenCalledTimes(1);
    });
});
