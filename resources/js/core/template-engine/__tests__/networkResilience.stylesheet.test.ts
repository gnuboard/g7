/**
 * loadStylesheetWithRetry — CSS 로드의 재시도 계층
 *
 * CSS 경로만 이 계층이 없어 실패가 통째로 무음이었다. 아이콘만으로 조작하는 버튼이
 * 있는 화면에서는 스타일 소실이 곧 조작 불능이다.
 *
 * @scenario asset_class=vendored, outcome=failed
 * @scenario asset_class=translation, outcome=failed
 * @effects stylesheet_failure_retries_then_surfaces
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadStylesheetWithRetry } from '../networkResilience';

describe('loadStylesheetWithRetry', () => {
    let appendSpy: ReturnType<typeof vi.spyOn>;
    let created: HTMLLinkElement[];

    /**
     * head.appendChild 를 가로채 시도별 결과를 발화시킵니다.
     *
     * @param outcomes 시도 순서대로의 결과
     * @return void
     */
    function stub(outcomes: Array<'load' | 'error'>): void {
        appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
            if (node.tagName !== 'LINK') {
                return node;
            }

            created.push(node);
            const outcome = outcomes[created.length - 1] ?? 'load';

            queueMicrotask(() => {
                if (outcome === 'error') {
                    node.onerror?.(new Event('error'));
                } else {
                    node.onload?.(new Event('load'));
                }
            });

            return node;
        }) as any);
    }

    beforeEach(() => {
        created = [];
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.querySelectorAll('link[id^="test-css"]').forEach(el => el.remove());
    });

    it('첫 시도에 성공하면 재시도하지 않는다', async () => {
        stub(['load']);

        await expect(loadStylesheetWithRetry('/a.css')).resolves.toBeUndefined();
        expect(created).toHaveLength(1);
    });

    it('rel 과 href 를 설정하고 전달한 속성을 붙인다', async () => {
        stub(['load']);

        await loadStylesheetWithRetry('/a.css', { id: 'test-css-1', media: 'screen' });

        expect(created[0].rel).toBe('stylesheet');
        expect(created[0].getAttribute('href')).toBe('/a.css');
        expect(created[0].id).toBe('test-css-1');
        expect(created[0].getAttribute('media')).toBe('screen');
    });

    it('일시 실패 후 성공하면 최종 resolve 한다', async () => {
        stub(['error', 'load']);

        await expect(
            loadStylesheetWithRetry('/a.css', {}, { baseDelayMs: 1, maxDelayMs: 2 })
        ).resolves.toBeUndefined();

        expect(created).toHaveLength(2);
    });

    it('모든 시도가 실패하면 reject 한다 (실패를 resolve 로 은폐하지 않는다)', async () => {
        stub(['error', 'error', 'error']);

        await expect(
            loadStylesheetWithRetry('/a.css', {}, { baseDelayMs: 1, maxDelayMs: 2 })
        ).rejects.toThrow(/Failed to load stylesheet/);

        expect(created).toHaveLength(3);
    });

    it('retries 옵션으로 시도 횟수를 조정할 수 있다', async () => {
        stub(['error']);

        await expect(
            loadStylesheetWithRetry('/a.css', {}, { retries: 0 })
        ).rejects.toThrow();

        expect(created).toHaveLength(1);
    });

    it('재시도 전 실패한 element 를 문서에서 제거한다 (누적 방지)', async () => {
        // 실제 DOM 에 붙여 제거 여부를 관측한다
        appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
            if (node.tagName !== 'LINK') return node;

            created.push(node);
            document.body.appendChild(node);
            const isFirst = created.length === 1;

            queueMicrotask(() => {
                if (isFirst) {
                    node.onerror?.(new Event('error'));
                } else {
                    node.onload?.(new Event('load'));
                }
            });

            return node;
        }) as any);

        await loadStylesheetWithRetry('/a.css', { id: 'test-css-2' }, { baseDelayMs: 1 });

        // 실패분은 제거되고 성공분만 남는다
        expect(document.querySelectorAll('#test-css-2')).toHaveLength(1);
        expect(created[0].isConnected).toBe(false);
    });
});
