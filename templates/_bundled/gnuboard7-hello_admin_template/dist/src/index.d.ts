import { default as templateMetadata } from '../template.json';
/**
 * Gnuboard7 Hello Admin Template
 *
 * 학습용 최소 Admin 템플릿 스켈레톤 — Basic 8개 컴포넌트만 포함
 */
export * from './components/basic';
export { templateMetadata };
/**
 * 템플릿 초기화 함수
 *
 * 코어 엔진의 ComponentRegistry 에 Basic 8개 컴포넌트를 등록합니다.
 */
export declare function initTemplate(): void;
