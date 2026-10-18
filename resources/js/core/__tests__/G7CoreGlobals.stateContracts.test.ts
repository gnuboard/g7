/**
 * @file G7CoreGlobals.stateContracts.test.ts
 * @description `G7Core.stateContracts` 읽기 전용 조회 API (#639, 결정 P8)
 *
 * 서버가 인라인한 `G7Config.stateContracts`(코어 예약 + 활성 확장 선언)를 확장 코드가 읽는다. 읽기 전용이어야
 * 한다 — 확장이 계약 목록을 고치면 다른 확장이 보는 소유자·모양이 바뀐다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../template-engine/G7CoreGlobals';

vi.mock('../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ get: vi.fn() })),
}));

/** G7Core 전역을 세우는 최소 의존성 */
function deps(): G7CoreDependencies {
    return {
        getState: vi.fn(() => ({
            translationEngine: { translate: vi.fn((key: string) => key) } as any,
            translationContext: { templateId: 't', locale: 'ko' },
            bindingEngine: {} as any,
            actionDispatcher: {} as any,
            templateMetadata: { locales: ['ko'] },
        })),
        transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
        responsiveManager: {},
        webSocketManager: {} as any,
    };
}

describe('G7Core.stateContracts', () => {
    beforeEach(() => {
        delete (window as any).G7Core;
        (window as any).G7Config = {
            stateContracts: {
                consent: { owner: 'core', description: { ko: '동의' }, schema: { type: 'object' }, writers: [] },
                preferredCurrency: { owner: 'module:sirsoft-ecommerce', description: {}, schema: { type: 'string' }, writers: ['@template'] },
            },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).G7Config;
    });

    // @scenario case=frontend_read
    // @effects state_contracts_are_exposed_read_only
    it('get·list·ownerOf 로 읽고, 결과는 동결돼 있다', () => {
        initializeG7CoreGlobals(deps());
        const api = (window as any).G7Core.stateContracts;

        expect(api.ownerOf('preferredCurrency')).toBe('module:sirsoft-ecommerce');
        expect(api.ownerOf('nobody')).toBeNull();
        expect(api.get('consent')).toMatchObject({ owner: 'core', schema: { type: 'object' } });
        expect(Object.keys(api.list())).toEqual(['consent', 'preferredCurrency']);

        expect(Object.isFrozen(api)).toBe(true);
        expect(Object.isFrozen(api.list())).toBe(true);
        expect(Object.isFrozen(api.get('preferredCurrency').writers)).toBe(true);
        expect(() => {
            'use strict';
            (api.list() as any).hacked = {};
        }).toThrow();
    });

    // @scenario case=frontend_read
    // @effects state_contracts_are_exposed_read_only
    it('Object 원형의 속성 이름(constructor·toString)은 계약으로 보지 않는다', () => {
        initializeG7CoreGlobals(deps());
        const api = (window as any).G7Core.stateContracts;

        expect(api.get('constructor')).toBeNull();
        expect(api.get('toString')).toBeNull();
        expect(api.ownerOf('hasOwnProperty')).toBeNull();
    });

    // @scenario case=frontend_missing
    // @effects state_contracts_are_exposed_read_only
    it('인라인이 없으면 빈 목록이다', () => {
        delete (window as any).G7Config;
        initializeG7CoreGlobals(deps());

        expect((window as any).G7Core.stateContracts.list()).toEqual({});
        expect((window as any).G7Core.stateContracts.get('consent')).toBeNull();
    });
});
