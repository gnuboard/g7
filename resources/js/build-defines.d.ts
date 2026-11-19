/**
 * 빌드 치환 상수의 타입 — 브라우저 코드 전용 (tsconfig.app.json).
 *
 * `vite.config.core.js` 와 템플릿 `vite.config.ts` 의 `define` 이 `process.env.NODE_ENV` 를 빌드 때
 * 문자열(`"production"`)로 바꾼다. 브라우저에는 `process` 가 없으므로 Node 타입(@types/node) 대신 이
 * 치환 상수 하나만 선언한다. Node 타입을 브라우저 설정에 주면 `require`, `fs`, `NodeJS.Timeout` 같은
 * Node 전용 의존이 타입 검사를 그대로 통과한다.
 *
 * 시험 설정(tsconfig.test.json)은 이 파일을 빼고 @types/node 의 `process` 를 쓴다. 둘을 함께 두면
 * `process` 가 두 번 선언된다.
 */
declare const process: { readonly env: { readonly NODE_ENV?: string } };
