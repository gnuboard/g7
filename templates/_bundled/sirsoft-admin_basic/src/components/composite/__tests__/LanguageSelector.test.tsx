import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSelector } from '../LanguageSelector';

describe('LanguageSelector', () => {
  let templateAppMock: { changeLocale: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    // __templateApp mock
    templateAppMock = {
      changeLocale: vi.fn().mockResolvedValue(undefined),
    };
    (window as any).__templateApp = templateAppMock;

    // fetch mock
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true }),
    });
  });

  afterEach(() => {
    vi.clearAllMocks();
    delete (window as any).__templateApp;
  });

  describe('독립 모드 (standalone)', () => {
    it('언어 선택 버튼이 렌더링되어야 함', () => {
      render(<LanguageSelector availableLocales={['ko', 'en']} />);
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    it('버튼 클릭 시 드롭다운 메뉴가 표시되어야 함', async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      expect(screen.getByText('한국어')).toBeInTheDocument();
      expect(screen.getByText('English')).toBeInTheDocument();
    });

    it('언어 선택 시 API가 호출되어야 함', async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      // 드롭다운 열기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // English 선택
      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/admin/users/me/language',
        expect.objectContaining({
          method: 'PATCH',
          body: JSON.stringify({ language: 'en' }),
        })
      );
    });

    it('언어 변경 성공 시 TemplateApp.changeLocale이 호출되어야 함', async () => {
      const user = userEvent.setup();
      render(<LanguageSelector availableLocales={['ko', 'en']} />);

      // 드롭다운 열기
      const toggleButton = screen.getByRole('button');
      await user.click(toggleButton);

      // English 선택
      const englishButton = screen.getByText('English');
      await user.click(englishButton);

      expect(templateAppMock.changeLocale).toHaveBeenCalledWith('en');
    });

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

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/admin/users/me/language',
        expect.objectContaining({
          method: 'PATCH',
          body: JSON.stringify({ language: 'en' }),
        })
      );
    });

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

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/custom/language',
        expect.anything()
      );
    });
  });

  describe('에러 처리', () => {
    it('API 실패 시 경고가 콘솔에 로깅되어야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        statusText: 'Internal Server Error',
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

    it('네트워크 에러 시 경고가 콘솔에 로깅되어야 함', async () => {
      const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      (global.fetch as any).mockRejectedValueOnce(new Error('Network Error'));

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
    it('커스텀 className이 적용됨', () => {
      const { container } = render(
        <LanguageSelector availableLocales={['ko', 'en']} className="custom-selector" />
      );
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('custom-selector');
    });

    it('인라인 모드에서 커스텀 className이 적용됨', () => {
      const { container } = render(
        <LanguageSelector availableLocales={['ko', 'en']} className="custom-inline" inline />
      );
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('custom-inline');
    });
  });
});
