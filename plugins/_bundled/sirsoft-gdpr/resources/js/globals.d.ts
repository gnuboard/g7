/**
 * 전역 타입 선언 — sirsoft-gdpr 플러그인
 *
 * Vite 의 CSS side-effect import 를 TypeScript 가 인식할 수 있도록 모듈 선언 추가.
 */

declare module '*.css' {
    const content: string;
    export default content;
}