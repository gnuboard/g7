/** CSS 를 번들 진입점에서 불러오기 위한 선언 — 빌드가 dist/css/plugin.css 로 뽑는다. */
declare module '*.css' {
  const content: string;
  export default content;
}
