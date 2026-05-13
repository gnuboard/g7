/**
 * G7 DevTools 유틸리티 함수
 *
 * DevToolsPanel에서 사용되는 공통 유틸리티 함수들을 정의합니다.
 */

import { STORAGE_KEY, StoredPanelConfig } from './constants';

/**
 * JSON 값의 타입에 따른 색상 클래스 반환
 *
 * @param value - 색상을 결정할 값
 * @returns CSS 클래스 이름
 */
export const getValueColor = (value: unknown): string => {
    if (value === null) return 'g7dt-text-gray';
    if (value === undefined) return 'g7dt-text-gray';
    if (typeof value === 'string') return 'g7dt-text-yellow';
    if (typeof value === 'number') return 'g7dt-text-cyan';
    if (typeof value === 'boolean') return 'g7dt-text-pink';
    if (Array.isArray(value)) return 'g7dt-text-orange';
    if (typeof value === 'object') return 'g7dt-text-blue';
    return 'g7dt-text-white';
};

/**
 * JSON 값의 타입 라벨 반환
 *
 * @param value - 타입 라벨을 반환할 값
 * @returns 타입 라벨 문자열
 */
export const getTypeLabel = (value: unknown): string => {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (Array.isArray(value)) return `Array(${value.length})`;
    if (typeof value === 'object') return `Object(${Object.keys(value).length})`;
    return typeof value;
};

/**
 * 값을 표시용 문자열로 변환
 *
 * @param value - 변환할 값
 * @returns 표시용 문자열
 */
export const formatValue = (value: unknown): string => {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (typeof value === 'string') {
        // 긴 문자열은 줄임
        if (value.length > 50) {
            return `"${value.substring(0, 50)}..."`;
        }
        return `"${value}"`;
    }
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    if (typeof value === 'number') return String(value);
    return '';
};

/**
 * 타임스탬프를 포맷팅된 문자열로 변환
 *
 * @param timestamp - 타임스탬프 (밀리초)
 * @returns 포맷팅된 시간 문자열 (HH:MM:SS.mmm)
 */
export const formatTimestamp = (timestamp: number): string => {
    const date = new Date(timestamp);
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    const seconds = date.getSeconds().toString().padStart(2, '0');
    const ms = date.getMilliseconds().toString().padStart(3, '0');
    return `${hours}:${minutes}:${seconds}.${ms}`;
};

/**
 * 로컬스토리지에서 패널 설정 로드
 *
 * @returns 저장된 패널 설정 (부분적)
 */
export const loadPanelConfig = (): Partial<StoredPanelConfig> => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            return JSON.parse(stored);
        }
    } catch (e) {
        console.warn('[G7DevTools] 설정 로드 실패:', e);
    }
    return {};
};

/**
 * 로컬스토리지에 패널 설정 저장
 *
 * @param config - 저장할 설정 (부분적)
 */
export const savePanelConfig = (config: Partial<StoredPanelConfig>): void => {
    try {
        const current = loadPanelConfig();
        const merged = { ...current, ...config };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(merged));
    } catch (e) {
        console.warn('[G7DevTools] 설정 저장 실패:', e);
    }
};

/**
 * 바이트 크기를 읽기 쉬운 형식으로 변환
 *
 * @param bytes - 바이트 크기
 * @returns 포맷팅된 크기 문자열
 */
export const formatBytes = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

/**
 * 경과 시간을 읽기 쉬운 형식으로 변환
 *
 * @param ms - 밀리초
 * @returns 포맷팅된 시간 문자열
 */
export const formatDuration = (ms: number): string => {
    if (ms < 1) return `${(ms * 1000).toFixed(0)}μs`;
    if (ms < 1000) return `${ms.toFixed(1)}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(2)}s`;
    return `${(ms / 60000).toFixed(2)}m`;
};

/**
 * 상태 뱃지 색상 클래스 반환
 *
 * @param status - 상태 문자열
 * @returns CSS 클래스 이름
 */
export const getStatusBadgeClass = (status: string): string => {
    const statusMap: Record<string, string> = {
        success: 'g7dt-badge-success',
        completed: 'g7dt-badge-success',
        done: 'g7dt-badge-success',
        error: 'g7dt-badge-error',
        failed: 'g7dt-badge-error',
        warning: 'g7dt-badge-warning',
        pending: 'g7dt-badge-pending',
        loading: 'g7dt-badge-loading',
        idle: 'g7dt-badge-idle',
    };
    return statusMap[status.toLowerCase()] || 'g7dt-badge-default';
};

/**
 * 객체를 안전하게 JSON 문자열로 변환
 *
 * @param obj - 변환할 객체
 * @param indent - 들여쓰기 (기본: 2)
 * @returns JSON 문자열
 */
export const safeStringify = (obj: unknown, indent: number = 2): string => {
    try {
        const seen = new WeakSet();
        return JSON.stringify(
            obj,
            (key, value) => {
                if (typeof value === 'object' && value !== null) {
                    if (seen.has(value)) {
                        return '[Circular]';
                    }
                    seen.add(value);
                }
                if (typeof value === 'function') {
                    return '[Function]';
                }
                if (value instanceof Error) {
                    return {
                        name: value.name,
                        message: value.message,
                        stack: value.stack,
                    };
                }
                return value;
            },
            indent
        );
    } catch (e) {
        return '[Stringify Error]';
    }
};

/**
 * 깊은 객체에서 경로로 값 가져오기
 *
 * @param obj - 대상 객체
 * @param path - 점으로 구분된 경로 (예: "user.profile.name")
 * @param defaultValue - 기본값
 * @returns 경로에 해당하는 값 또는 기본값
 */
export const getValueByPath = (obj: unknown, path: string, defaultValue: unknown = undefined): unknown => {
    if (!obj || typeof obj !== 'object') return defaultValue;

    const keys = path.split('.');
    let current: unknown = obj;

    for (const key of keys) {
        if (current === null || current === undefined) return defaultValue;
        if (typeof current !== 'object') return defaultValue;
        current = (current as Record<string, unknown>)[key];
    }

    return current ?? defaultValue;
};

/**
 * 문자열을 잘라서 말줄임표 추가
 *
 * @param str - 원본 문자열
 * @param maxLength - 최대 길이
 * @returns 잘린 문자열
 */
export const truncate = (str: string, maxLength: number): string => {
    if (str.length <= maxLength) return str;
    return str.substring(0, maxLength - 3) + '...';
};
