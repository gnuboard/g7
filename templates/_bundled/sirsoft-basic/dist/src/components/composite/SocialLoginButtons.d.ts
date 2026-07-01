import { default as React } from 'react';
import { EditorAttrs } from '../../types';
type SocialProvider = 'google' | 'naver' | 'kakao' | 'facebook' | 'apple';
interface SocialLoginButtonsProps {
    /** 표시할 소셜 로그인 제공자 목록 */
    providers?: SocialProvider[];
    /** 로그인/회원가입 모드 */
    mode?: 'login' | 'register';
    /** 버튼 스타일 */
    variant?: 'full' | 'icon';
    /** 추가 CSS 클래스 */
    className?: string;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * 소셜 로그인 버튼 그룹
 *
 * @example
 * ```tsx
 * <SocialLoginButtons
 *   providers={['google', 'naver', 'kakao']}
 *   mode="login"
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "SocialLoginButtons",
 *   "props": {
 *     "providers": ["google", "naver", "kakao"],
 *     "mode": "register"
 *   }
 * }
 * ```
 */
declare const SocialLoginButtons: React.FC<SocialLoginButtonsProps>;
export default SocialLoginButtons;
