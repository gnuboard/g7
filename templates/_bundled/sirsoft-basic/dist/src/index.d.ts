import { default as templateMetadata } from '../template.json';
export { Button, type ButtonProps, FileInput, type FileInputProps, Input, type InputProps, PasswordInput, type PasswordInputProps, type PasswordRule, defaultPasswordRules, availablePasswordRules, Textarea, type TextareaProps, Label, type LabelProps, Div, type DivProps, Span, type SpanProps, P, type PProps, Img, type ImgProps, H1, type H1Props, H2, type H2Props, H3, type H3Props, H4, type H4Props, Ul, type UlProps, Ol, type OlProps, Li, type LiProps, A, type AProps, Form, type FormProps, Select, type SelectProps, Option, type OptionProps, Optgroup, type OptgroupProps, Checkbox, type CheckboxProps, Table, type TableProps, Thead, type TheadProps, Tbody, type TbodyProps, Tr, type TrProps, Th, type ThProps, Td, type TdProps, Nav, type NavProps, Section, type SectionProps, Svg, type SvgProps, Icon, type IconProps, Code, type CodeProps, Footer as BasicFooter, type FooterProps as BasicFooterProps, Header as BasicHeader, type HeaderProps as BasicHeaderProps, Hr, type HrProps, IconName, type IconStyle, type IconSize, } from './components/basic';
export * from './components/composite';
export * from './components/layout';
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
