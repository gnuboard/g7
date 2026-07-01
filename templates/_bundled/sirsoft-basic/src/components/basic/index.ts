// e2e:allow 편집기 ComponentRegistry 등록 정합(basic Header/Footer export 제거 — composite 와 이름 충돌 해소, SlotContainer 번들 포함). 편집기 캔버스 렌더는 Chrome MCP 라이브 실측 + Vitest(headerEditorSpecAndCurrencySlot/HeaderPropRender)로 검증. 사용자 사이트 런타임 무변경.
/**
 * 기본 HTML 매핑 컴포넌트 Export
 *
 * DOM 요소에 직접 매핑되는 기본 컴포넌트들
 */

export { Button, type ButtonProps } from './Button';
export { FileInput, type FileInputProps } from './FileInput';
export { Input, type InputProps } from './Input';
export { PasswordInput, type PasswordInputProps, type PasswordRule, defaultPasswordRules, availablePasswordRules } from './PasswordInput';
export { Textarea, type TextareaProps } from './Textarea';
export { Label, type LabelProps } from './Label';
export { Div, type DivProps } from './Div';
export { Span, type SpanProps } from './Span';
export { P, type PProps } from './P';
export { Img, type ImgProps } from './Img';
export { H1, type H1Props } from './H1';
export { H2, type H2Props } from './H2';
export { H3, type H3Props } from './H3';
export { H4, type H4Props } from './H4';
export { Ul, type UlProps } from './Ul';
export { Ol, type OlProps } from './Ol';
export { Li, type LiProps } from './Li';
export { A, type AProps } from './A';
export { Form, type FormProps } from './Form';
export { Select, type SelectProps } from './Select';
export { Option, type OptionProps } from './Option';
export { Optgroup, type OptgroupProps } from './Optgroup';
export { Checkbox, type CheckboxProps } from './Checkbox';
export { Table, type TableProps } from './Table';
export { Thead, type TheadProps } from './Thead';
export { Tbody, type TbodyProps } from './Tbody';
export { Tr, type TrProps } from './Tr';
export { Th, type ThProps } from './Th';
export { Td, type TdProps } from './Td';
export { Nav, type NavProps } from './Nav';
export { Section, type SectionProps } from './Section';
export { Svg, type SvgProps } from './Svg';
export { Icon, type IconProps } from './Icon';
export { Code, type CodeProps } from './Code';
// basic Header/Footer(HTML <header>/<footer> 래퍼)는 레이아웃 사용처 0이며 composite
// Header/Footer 와 이름이 겹쳐 편집기 ComponentRegistry 에서 중복 등록 경고를 유발한다.
// 사이트 헤더/푸터는 composite 컴포넌트가 담당하므로 basic 래퍼 export 를 제거한다
// (필요 시 Div + role/시맨틱 태그로 대체). 파일 자체는 보존(직접 import 경로는 유지).
export { Hr, type HrProps } from './Hr';

// Icon 타입 정의 (enum 및 타입)
export { IconName, type IconStyle, type IconSize } from './IconTypes';
