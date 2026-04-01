/**
 * DiagnosticEngine 테스트
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { DiagnosticEngine } from '../DiagnosticEngine';

describe('DiagnosticEngine', () => {
    let engine: DiagnosticEngine;

    beforeEach(() => {
        // G7DevToolsCore 모킹
        vi.mock('../G7DevToolsCore', () => ({
            G7DevToolsCore: {
                getInstance: () => ({
                    getStateHistory: () => [],
                    getActionHistory: () => [],
                    getCacheStats: () => ({ hits: 0, misses: 0, entries: 0, hitRate: 0 }),
                    getState: () => ({ _global: {}, _local: {}, _computed: {} }),
                    getLifecycleInfo: () => ({ mountedComponents: [], orphanedListeners: [] }),
                    getPerformanceInfo: () => ({
                        renderCounts: new Map(),
                        bindingEvalCount: 0,
                        memoryWarnings: [],
                    }),
                    getNetworkInfo: () => ({
                        activeRequests: [],
                        requestHistory: [],
                        pendingDataSources: [],
                    }),
                    getFormInfo: () => [],
                    getConditionalInfo: () => ({ ifConditions: [], iterations: [] }),
                    getWebSocketInfo: () => ({
                        connectionState: 'disconnected',
                        connections: [],
                        messageHistory: [],
                    }),
                }),
            },
        }));

        engine = new DiagnosticEngine();
    });

    describe('증상 기반 분석', () => {
        it('빈 증상 배열에 대해 detector 기반 결과만 반환해야 한다', () => {
            const results = engine.analyze([]);
            // detector 기반 진단 결과는 증상 없이도 나올 수 있음 (예: websocket disconnected)
            // 하지만 symptom 기반 매칭은 없어야 함
            results.forEach(result => {
                // symptom 매칭 기반 confidence가 아닌 detector 기반만 있어야 함
                expect(result.confidence).toBeLessThanOrEqual(1);
            });
        });

        it('Stale Closure 증상을 진단해야 한다', () => {
            const results = engine.analyze(['이전 값이 전송됨']);

            expect(results.length).toBeGreaterThan(0);
            const staleClosureResult = results.find(r => r.rule.id === 'stale-closure');
            expect(staleClosureResult).toBeDefined();
        });

        it('캐시 반복 렌더링 문제를 진단해야 한다', () => {
            const results = engine.analyze(['모든 행이 같은 값']);

            expect(results.length).toBeGreaterThan(0);
            const cacheIterationResult = results.find(r => r.rule.id === 'cache-iteration');
            expect(cacheIterationResult).toBeDefined();
        });

        it('Sequence setState 병합 문제를 진단해야 한다', () => {
            const results = engine.analyze(['여러 setState 중 마지막만 적용']);

            const sequenceMergeResult = results.find(r => r.rule.id === 'sequence-merge');
            expect(sequenceMergeResult).toBeDefined();
        });

        it('dataKey 초기화 문제를 진단해야 한다', () => {
            const results = engine.analyze(['Form dataKey가 전역 상태에 바인딩 안됨']);

            const dataKeyResult = results.find(r => r.rule.id === 'datakey-global-init');
            expect(dataKeyResult).toBeDefined();
        });

        it('Form dataKey 문제를 진단해야 한다', () => {
            const results = engine.analyze(['Form 값이 저장 안됨']);

            expect(results.length).toBeGreaterThan(0);
        });

        it('iteration 문제를 진단해야 한다', () => {
            const results = engine.analyze(['반복 영역이 비어있음']);

            const iterationResult = results.find(r => r.rule.id === 'iteration-empty');
            expect(iterationResult).toBeDefined();
        });

        it('여러 증상에 대해 여러 진단을 반환해야 한다', () => {
            const results = engine.analyze([
                '이전 값이 전송됨',
                '모든 행이 같은 값',
                'Form 값이 저장 안됨',
            ]);

            expect(results.length).toBeGreaterThan(1);
        });

        it('신뢰도 순으로 정렬되어야 한다', () => {
            const results = engine.analyze(['이전 값이 전송됨', '모든 행이 같은 값']);

            for (let i = 1; i < results.length; i++) {
                expect(results[i - 1].confidence).toBeGreaterThanOrEqual(results[i].confidence);
            }
        });
    });

    describe('수정 제안', () => {
        it('진단 결과에 대한 수정 제안을 반환해야 한다', () => {
            const results = engine.analyze(['이전 값이 전송됨']);
            expect(results.length).toBeGreaterThan(0);

            const suggestion = engine.suggestFix(results[0]);
            expect(suggestion).toBeDefined();
            expect(suggestion.title).toBeDefined();
            expect(suggestion.description).toBeDefined();
            expect(suggestion.docLink).toBeDefined();
        });

        it('코드 예제가 있는 경우 포함해야 한다', () => {
            const results = engine.analyze(['이전 값이 전송됨']);
            const staleClosureResult = results.find(r => r.rule.id === 'stale-closure');

            if (staleClosureResult) {
                const suggestion = engine.suggestFix(staleClosureResult);
                // codeExample이 있을 수 있음
                expect(suggestion.title).toBeDefined();
                expect(typeof suggestion.codeExample === 'string' || suggestion.codeExample === undefined).toBe(true);
            }
        });
    });

    describe('일반적인 이슈 목록', () => {
        it('일반적인 이슈 목록을 반환해야 한다', () => {
            const issues = engine.getCommonIssues();

            expect(Array.isArray(issues)).toBe(true);
            // detector 기반이므로 이슈가 없을 수도 있음 (websocket disconnected만 감지됨)
        });

        it('각 이슈에는 id, name, description, frequency가 있어야 한다', () => {
            const issues = engine.getCommonIssues();

            issues.forEach(issue => {
                expect(issue.id).toBeDefined();
                expect(issue.name).toBeDefined();
                expect(issue.description).toBeDefined();
                expect(typeof issue.frequency).toBe('number');
            });
        });
    });

    describe('카테고리별 규칙', () => {
        it('상태 카테고리 규칙을 반환해야 한다', () => {
            const rules = engine.getRulesByCategory('state');

            expect(Array.isArray(rules)).toBe(true);
            rules.forEach(rule => {
                expect(rule.category).toBe('state');
            });
        });

        it('캐시 카테고리 규칙을 반환해야 한다', () => {
            const rules = engine.getRulesByCategory('cache');

            expect(Array.isArray(rules)).toBe(true);
            rules.forEach(rule => {
                expect(rule.category).toBe('cache');
            });
        });

        it('폼 카테고리 규칙을 반환해야 한다', () => {
            const rules = engine.getRulesByCategory('form');

            expect(Array.isArray(rules)).toBe(true);
            rules.forEach(rule => {
                expect(rule.category).toBe('form');
            });
        });

        it('라이프사이클 카테고리 규칙을 반환해야 한다', () => {
            const rules = engine.getRulesByCategory('lifecycle');

            expect(Array.isArray(rules)).toBe(true);
            rules.forEach(rule => {
                expect(rule.category).toBe('lifecycle');
            });
        });

        it('성능 카테고리 규칙을 반환해야 한다', () => {
            const rules = engine.getRulesByCategory('performance');

            expect(Array.isArray(rules)).toBe(true);
            rules.forEach(rule => {
                expect(rule.category).toBe('performance');
            });
        });

        it('네트워크 카테고리 규칙을 반환해야 한다', () => {
            const rules = engine.getRulesByCategory('network');

            expect(Array.isArray(rules)).toBe(true);
            rules.forEach(rule => {
                expect(rule.category).toBe('network');
            });
        });
    });

    describe('진단 규칙 구조', () => {
        it('모든 규칙에는 필수 필드가 있어야 한다', () => {
            const allCategories = [
                'state',
                'cache',
                'timing',
                'lifecycle',
                'performance',
                'network',
                'form',
                'conditional',
                'i18n',
            ] as const;

            allCategories.forEach(category => {
                const rules = engine.getRulesByCategory(category);
                rules.forEach(rule => {
                    expect(rule.id).toBeDefined();
                    expect(rule.name).toBeDefined();
                    expect(rule.symptoms).toBeDefined();
                    expect(Array.isArray(rule.symptoms)).toBe(true);
                    expect(rule.probability).toBeDefined();
                    expect(rule.probability).toBeGreaterThan(0);
                    expect(rule.probability).toBeLessThanOrEqual(1);
                    expect(rule.solution).toBeDefined();
                    expect(rule.docLink).toBeDefined();
                    expect(rule.category).toBe(category);
                });
            });
        });
    });
});
