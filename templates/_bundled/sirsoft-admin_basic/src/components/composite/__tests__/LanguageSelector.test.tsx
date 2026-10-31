/**
 * LanguageSelector 단위 테스트
 *
 * 언어 저장은 코어 `G7Core.api.patch`, 화면 언어 전환은 공개 API `G7Core.locale.change` 로 일어난다.
 * 템플릿이 엔진 내부(`window.__templateApp`)나 로그인 토큰(`localStorage.auth_token`)에 직접 손대지 않는다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSelector } from '../LanguageSelector';

describe('LanguageSelector', () => {
  const g7 = (window as any).G7Core;
  const originalApi = g7.api;
  const originalLocale = g7.locale;
  let apiMock: { patch: ReturnType<typeof vi.fn> };
  let localeMock: { change: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    apiMock = { patch: vi.fn().mockResolvedValue({ success: true }) };
    localeMock = { change: vi.fn().mockResolvedValue(undefined) };
    g7.api = apiMock;
    g7.locale = localeMock;

    // 직접 fetch 는 쓰지 않는다 — 불리면 단언에서 잡는다
    global.fetch = vi.fn().mockRejectedValue(new Error('raw fetch must not be used'));
  });

  afterEach(() => {
    vi.clearAllMocks();
    g7.api = originalApi;
    g7.locale = originalLocale;
    delete (window as any).__templateApp;
  });

  describe('독립 모드 (standalone)', () => {
    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('언어 선택 버튼이 렌더링되어야 함', () => {
      render(<LanguageSelector availableLocales={['ko', 'en']} />);
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('버튼 클릭 시 드롭다운 메뉴가 표시되어야 함', async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
    });

    // @scenario surface=language_selector_save
    // @effects language_save_via_core_api
    it('언어 선택 시 API가 호출되어야 함', async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      // 드롭다운 열기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // English 선택
      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(apiMock.patch).toHaveBeenCalledWith('/api/admin/users/me/language', { language: 'en' });
      expect(global.fetch).not.toHaveBeenCalled();
    });

    // @scenario surface=language_selector_switch
    // @effects locale_switch_via_public_api
    it('언어 변경 성공 시 G7Core.locale.change 가 호출되어야 함', async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      // 드롭다운 열기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // English 선택
      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(localeMock.change).toHaveBeenCalledWith('en', { persist: false });
    });

    // @scenario surface=language_selector_switch
    // @effects member_language_saved_on_reselect
    it('현재 언어를 다시 고르면 저장 요청을 직접 보내지 않고 코어 언어 전환에 맡겨 회원 언어를 맞춘다 (7.1.0 결정)', async () => {
      // 다른 기기에서 바뀐 회원 언어(users.language)가 이 화면 언어와 갈려 있을 수 있다 — 코어 changeLocale 은
      // 같은 언어면 화면은 그대로 두고 로그인 회원의 언어를 저장한다(engine-v1.79.0)
      localStorage.setItem('g7_locale', 'ko');
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      await user.click(screen.getByRole('button'));
      await user.click(screen.getByText('한국어'));

      expect(localeMock.change).toHaveBeenCalledTimes(1);
      expect(localeMock.change).toHaveBeenCalledWith('ko');
      expect(apiMock.patch).not.toHaveBeenCalled();
      expect(global.fetch).not.toHaveBeenCalled();
      localStorage.removeItem('g7_locale');
    });

    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('showCode 미지정 시 현재 로케일 코드는 표시되지 않는다 (아이콘 전용 기본)', () => {
      localStorage.setItem('g7_locale', 'ko');
      const { container } = render(<LanguageSelector availableLocales={['ko', 'en']} />);
      // 드롭다운을 열지 않은 상태에서 버튼에 로케일 코드 텍스트가 없어야 함(기본 아이콘 전용)
      const codeSpan = container.querySelector('span.uppercase');
      expect(codeSpan).toBeNull();
    });

    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('showCode 지정 시 버튼에 현재 선택 언어 코드를 표시한다 (유저 헤더 패리티)', () => {
      localStorage.setItem('g7_locale', 'ko');
      const { container } = render(<LanguageSelector availableLocales={['ko', 'en']} showCode />);
      // 드롭다운을 열지 않아도 현재 로케일 코드(시각적 대문자, DOM 은 'ko')가 버튼에 노출되어야 함
      const codeSpan = container.querySelector('span.uppercase');
      expect(codeSpan).not.toBeNull();
      expect(codeSpan?.textContent).toBe('ko');
    });

    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('외부 클릭 시 드롭다운이 닫혀야 함', async () => {
      const user = userEvent.setup();
      render(
        <div>
          <LanguageSelector availableLocales={['ko', 'en']} />
          <div data-testid="outside">Outside</div>
        </div>
      );

      // 드롭다운 열기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // 드롭다운이 표시됨
      expect(screen.getByText('한국어')).toBeInTheDocument();

      // 외부 클릭
      const outsideElement = screen.getByTestId('outside');
      await user.click(outsideElement);

      // 드롭다운이 닫힘
      expect(screen.queryByText('한국어')).not.toBeInTheDocument();
    });
  });

  describe('인라인 모드 (inline)', () => {
    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('인라인 모드에서 버튼 클릭 시 언어 목록이 표시되어야 함', async () => {
      const user = userEvent.setup();
      render(
        <LanguageSelector
          availableLocales={['ko', 'en']}
          languageText="언어"
          inline
        />
      );

      // 인라인 모드에서는 접기/펼치기 가능한 뱃지 버튼
      expect(screen.getByText('언어')).toBeInTheDocument();
      // 버튼에 현재 로케일 뱃지가 표시됨 (예: KO)
      expect(screen.getByText('KO')).toBeInTheDocument();
      // 아직 언어 목록은 표시되지 않음
      expect(screen.queryByText('한국어')).not.toBeInTheDocument();

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // 이제 언어 목록이 표시됨
      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
    });

    // @scenario surface=language_selector_save
    // @effects language_save_via_core_api
    it('인라인 모드에서 언어 선택 시 API가 호출되어야 함', async () => {
      const user = userEvent.setup();
      render(
        <LanguageSelector
          availableLocales={['ko', 'en']}
          languageText="언어"
          inline
        />
      );

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // English 선택
      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(apiMock.patch).toHaveBeenCalledWith('/api/admin/users/me/language', { language: 'en' });
      expect(global.fetch).not.toHaveBeenCalled();
    });

    // @scenario surface=language_selector_switch
    // @effects locale_switch_via_public_api
    it('인라인 모드에서 onLanguageChange 콜백이 호출되어야 함', async () => {
      const user = userEvent.setup();
      const onLanguageChange = vi.fn();
      render(
        <LanguageSelector
          availableLocales={['ko', 'en']}
          languageText="언어"
          inline
          onLanguageChange={onLanguageChange}
        />
      );

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // English 선택
      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(onLanguageChange).toHaveBeenCalledWith('en');
    });
  });

  describe('언어 이름 변환', () => {
    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('지원되는 언어 코드가 올바른 이름으로 표시되어야 함', async () => {
      const user = userEvent.setup();
      render(
        <LanguageSelector
          availableLocales={['ko', 'en', 'ja', 'zh']}
          inline
        />
      );

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
      expect(screen.getByText('日本語')).toBeInTheDocument();
      expect(screen.getByText('中文')).toBeInTheDocument();
    });

    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('알 수 없는 언어 코드는 그대로 표시되어야 함', async () => {
      const user = userEvent.setup();
      render(
        <LanguageSelector
          availableLocales={['unknown']}
          inline
        />
      );

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      expect(screen.getByText('unknown')).toBeInTheDocument();
    });
  });

  describe('API 호출 옵션', () => {
    // @scenario surface=language_selector_save
    // @effects language_save_via_core_api
    it('커스텀 API 엔드포인트가 사용되어야 함', async () => {
      const user = userEvent.setup();
      render(
        <LanguageSelector
          availableLocales={['ko', 'en']}
          apiEndpoint="/api/custom/language"
          inline
        />
      );

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(apiMock.patch).toHaveBeenCalledWith('/api/custom/language', { language: 'en' });
    });
  });

  describe('에러 처리', () => {
    // @scenario surface=language_selector_save
    // @effects language_save_failure_keeps_ui
    it('API 실패 시 경고가 콘솔에 로깅되어야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      // 코어 ApiClient 는 4xx·5xx 를 응답이 실린 오류로 던진다
      apiMock.patch.mockRejectedValueOnce({
        response: { status: 500, statusText: 'Internal Server Error' },
      });

      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} inline />);

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      // 컴포넌트는 console.warn으로 경고를 로깅 (UI는 변경됨)
      expect(consoleSpy).toHaveBeenCalledWith(
        '[Comp:LanguageSelector]',
        '언어 설정 저장 실패 (UI는 변경됨):',
        'Internal Server Error'
      );

      consoleSpy.mockRestore();
    });

    // @scenario surface=language_selector_save
    // @effects language_save_failure_keeps_ui
    it('네트워크 에러 시 경고가 콘솔에 로깅되어야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      apiMock.patch.mockRejectedValueOnce(new Error('Network Error'));

      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} inline />);

      // 버튼 클릭하여 언어 목록 펼치기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      // 컴포넌트는 console.warn으로 경고를 로깅 (UI는 변경됨)
      expect(consoleSpy).toHaveBeenCalledWith(
        '[Comp:LanguageSelector]',
        '언어 설정 API 호출 실패 (UI는 변경됨):',
        expect.any(Error)
      );

      consoleSpy.mockRestore();
    });
  });

  describe('스타일 테스트', () => {
    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('커스텀 className이 적용됨', () => {
      const { container } = render(
        <LanguageSelector availableLocales={['ko', 'en']} className="custom-selector" />
      );
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('custom-selector');
    });

    // @scenario surface=language_selector_ui
    // @effects language_selector_ui_contract
    it('인라인 모드에서 커스텀 className이 적용됨', () => {
      const { container } = render(
        <LanguageSelector availableLocales={['ko', 'en']} className="custom-inline" inline />
      );
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('custom-inline');
    });
  });

  describe('공개 API 사용 (엔진 내부·로그인 토큰 비접근)', () => {
    const chooseEnglish = async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);
      await user.click(screen.getByRole('button'));
      await user.click(screen.getByText('English'));
    };

    // @scenario surface=language_selector_switch
    // @effects locale_switch_via_public_api
    it('window.__templateApp 을 읽지 않고 G7Core.locale.change 로 화면 언어를 바꾼다', async () => {
      let touched = false;
      Object.defineProperty(window, '__templateApp', {
        configurable: true,
        get: () => {
          touched = true;
          return { changeLocale: vi.fn() };
        },
      });

      await chooseEnglish();

      expect(localeMock.change).toHaveBeenCalledTimes(1);
      expect(localeMock.change).toHaveBeenCalledWith('en', { persist: false });
      expect(touched).toBe(false);
    });

    // @scenario surface=language_selector_save
    // @effects language_save_via_core_api
    it('로그인 토큰을 직접 읽거나 Authorization 을 붙이지 않고 G7Core.api 에 맡긴다', async () => {
      const getItemSpy = vi.spyOn(Storage.prototype, 'getItem');
      localStorage.setItem('auth_token', 'ADMIN-TOKEN');

      await chooseEnglish();

      expect(getItemSpy.mock.calls.some(([key]) => key === 'auth_token')).toBe(false);
      expect(global.fetch).not.toHaveBeenCalled();
      // 요청 설정(헤더)을 넘기지 않는다 — 토큰·XSRF 첨부는 코어 몫
      expect(apiMock.patch.mock.calls[0]).toEqual(['/api/admin/users/me/language', { language: 'en' }]);

      getItemSpy.mockRestore();
      localStorage.removeItem('auth_token');
    });

    // @scenario surface=language_selector_switch
    // @effects locale_switch_via_public_api, language_save_failure_keeps_ui
    it('저장 요청이 실패해도 화면 언어는 G7Core.locale.change 로 바뀐다', async () => {
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      apiMock.patch.mockRejectedValueOnce({ response: { status: 500, statusText: 'Internal Server Error' } });

      await chooseEnglish();

      expect(localeMock.change).toHaveBeenCalledWith('en', { persist: false });
      warnSpy.mockRestore();
    });
  });
});
