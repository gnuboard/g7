/**
 * SocialLoginButtons 컴포넌트
 *
 * 소셜 로그인(OAuth) 버튼 그룹입니다.
 * Google, Naver, Kakao 등의 소셜 로그인을 지원합니다.
 */

import React from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';

/**
 * 소셜 로그인 아이콘 SVG 컴포넌트
 */
const SocialIcon: React.FC<{ provider: string; className?: string }> = ({ provider, className = 'w-5 h-5' }) => {
  switch (provider) {
    case 'google':
      return (
        <svg className={className} viewBox="0 0 24 24">
          <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
          <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
          <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
          <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
      );
    case 'naver':
      return (
        <svg className={className} viewBox="0 0 24 24">
          <path fill="currentColor" d="M16.273 12.845L7.376 0H0v24h7.727V11.155L16.624 24H24V0h-7.727z"/>
        </svg>
      );
    case 'kakao':
      return (
        <svg className={className} viewBox="0 0 24 24">
          <path fill="currentColor" d="M12 3c5.799 0 10.5 3.664 10.5 8.185 0 4.52-4.701 8.184-10.5 8.184a13.5 13.5 0 0 1-1.727-.11l-4.408 2.883c-.501.265-.678.236-.472-.413l.892-3.678c-2.88-1.46-4.785-3.99-4.785-6.866C1.5 6.665 6.201 3 12 3zm5.519 8.625h-.634v2.916h-.97v-2.916h-.633v-.848h2.237v.848zm-3.294 2.916h-.97V10.77h.97v3.771zm-1.97 0h-.97v-2.77l-.658 1.53h-.629l-.665-1.53v2.77h-.97V10.77h1.13l.822 1.912.814-1.912h1.126v3.771zm-4.49 0h-.97V10.77h.97v3.771z"/>
        </svg>
      );
    case 'facebook':
      return (
        <svg className={className} viewBox="0 0 24 24">
          <path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
        </svg>
      );
    case 'apple':
      return (
        <svg className={className} viewBox="0 0 24 24">
          <path fill="currentColor" d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/>
        </svg>
      );
    default:
      return null;
  }
};

/**
 * G7Core 번역 함수
 */
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

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

interface ProviderConfig {
  id: SocialProvider;
  name: string;
  color: string;
  textColor: string;
}

/**
 * 소셜 로그인 제공자 설정
 */
const PROVIDER_CONFIGS: ProviderConfig[] = [
  {
    id: 'google',
    name: 'Google',
    color: 'bg-white dark:bg-gray-800',
    textColor: 'text-gray-700 dark:text-gray-300',
  },
  {
    id: 'naver',
    name: '네이버',
    color: 'bg-[#03C75A]',
    textColor: 'text-white',
  },
  {
    id: 'kakao',
    name: '카카오',
    color: 'bg-[#FEE500]',
    textColor: 'text-[#000000]/85',
  },
  {
    id: 'facebook',
    name: 'Facebook',
    color: 'bg-[#1877F2]',
    textColor: 'text-white',
  },
  {
    id: 'apple',
    name: 'Apple',
    color: 'bg-black dark:bg-white',
    textColor: 'text-white dark:text-black',
  },
];

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
const SocialLoginButtons: React.FC<SocialLoginButtonsProps> = ({
  providers = ['google', 'naver', 'kakao'],
  mode = 'login',
  variant = 'full',
  className = '',
}) => {
  const handleSocialLogin = (provider: SocialProvider) => {
    // OAuth 인증 URL로 리다이렉트
    const callbackUrl = encodeURIComponent(window.location.href);
    window.location.href = `/api/auth/${provider}?redirect=${callbackUrl}`;
  };

  const getButtonText = (providerName: string): string => {
    if (mode === 'register') {
      return t('auth.social.register_with', { provider: providerName });
    }
    return t('auth.social.login_with', { provider: providerName });
  };

  const filteredProviders = PROVIDER_CONFIGS.filter((config) =>
    providers.includes(config.id)
  );

  if (variant === 'icon') {
    return (
      <Div className={`flex justify-center gap-4 ${className}`}>
        {filteredProviders.map((provider) => (
          <Button
            key={provider.id}
            onClick={() => handleSocialLogin(provider.id)}
            className={`w-12 h-12 flex items-center justify-center rounded-full ${provider.color} ${provider.textColor} border border-gray-200 dark:border-gray-700 hover:opacity-90 transition-opacity`}
            aria-label={getButtonText(provider.name)}
          >
            <SocialIcon provider={provider.id} className="w-5 h-5" />
          </Button>
        ))}
      </Div>
    );
  }

  return (
    <Div className={`space-y-3 ${className}`}>
      {filteredProviders.map((provider) => (
        <Button
          key={provider.id}
          onClick={() => handleSocialLogin(provider.id)}
          className={`w-full flex items-center justify-center gap-3 px-4 py-3 rounded-lg font-medium border border-gray-200 dark:border-gray-700 ${provider.color} ${provider.textColor} hover:opacity-90 transition-opacity`}
        >
          <SocialIcon provider={provider.id} className="w-5 h-5" />
          <Span>{getButtonText(provider.name)}</Span>
        </Button>
      ))}
    </Div>
  );
};

export default SocialLoginButtons;
