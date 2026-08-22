import { default as templateMetadata } from '../template.json';
export * from './components/basic';
export * from './components/composite';
export * from './components/layout';
export * from './config/monaco.config';
/**
 * 템플릿 메타데이터 export
 *
 * template.json 파일의 내용을 번들에 포함시켜 API 호출 없이
 * 코어 엔진에서 직접 접근 가능하도록 합니다.
 */
export { templateMetadata };
/**
 * 템플릿 초기화 함수
 *
 * 코어 엔진에 커스텀 핸들러를 등록합니다.
 */
export declare function initTemplate(): void;
