import { default as React } from 'react';
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
