/**
 * Gnuboard7 Hello User Template
 *
 * 학습용 최소 샘플 사용자 템플릿.
 * Basic 8개 컴포넌트 (Div, Button, H1, H2, H3, A, Span, Img) + 확장 컴포넌트 오버라이드 1종.
 *
 * **템플릿은 컴포넌트를 스스로 등록하지 않는다.** 코어가 `components.json` 을 읽고 이 번들의
 * 전역 export 에서 구현체를 찾아 등록한다 — 이 파일이 할 일은 매니페스트가 선언한 이름을
 * export 하는 것뿐이다. (자가등록은 매니페스트에 실릴 자리가 없는 **확장**의 방식이다.)
 */
export { Div, type DivProps } from './components/basic/Div';
export { Button, type ButtonProps } from './components/basic/Button';
export { H1, type H1Props } from './components/basic/H1';
export { H2, type H2Props } from './components/basic/H2';
export { H3, type H3Props } from './components/basic/H3';
export { A, type AProps } from './components/basic/A';
export { Span, type SpanProps } from './components/basic/Span';
export { Img, type ImgProps } from './components/basic/Img';
export { HelloModuleMemoCard } from './components/overrides/gnuboard7-hello_module/MemoCard';
