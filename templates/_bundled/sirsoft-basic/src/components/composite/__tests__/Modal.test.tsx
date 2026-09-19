/**
 * 사용자 템플릿 Modal — `size` 표면 회귀 잠금.
 *
 * 이관 12차 검증에서 검출: 소스 코퍼스가 `<modal size="sm">` 을 216건/199파일 쓰고 있었는데
 * 두 템플릿 `Modal.tsx` 어느 쪽도 `size` 를 구조분해하지 않고 rest spread 도 없어 값이 그대로
 * 소멸했다. 지정 크기와 무관하게 전량 기본 500px 로 렌더되던 상태다.
 *
 * `md`/`medium` 이 종전 기본값과 같은 500px 인 것은 의도된 것이다 — 표면을 뒤늦게 살릴 때
 * 그 단계를 쓰던 화면이 움직이지 않게 맞췄다. 좁은 화면 넘침은 컨테이너의
 * `max-w-[calc(100vw-2rem)]` 이 막으므로 매핑은 단순 px 로 둔다.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Modal } from '../Modal';

describe('Modal (sirsoft-basic)', () => {
  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '테스트 모달',
    children: <div>모달 내용</div>,
  };

  describe('size prop', () => {
    it.each([
      ['sm', '400px'],
      ['small', '400px'],
      ['md', '500px'],
      ['medium', '500px'],
      ['lg', '720px'],
      ['large', '720px'],
      ['xl', '900px'],
      ['2xl', '1120px'],
    ])('size="%s" 는 width %s 로 해석됨', (size, expected) => {
      render(<Modal {...mockProps} size={size as any} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: expected });
    });

    it('width 를 명시하면 size 보다 우선한다 (기존 width 사용처 무영향)', () => {
      render(<Modal {...mockProps} size="sm" width="920px" />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '920px' });
    });

    it('size 가 없으면 종전 기본값 500px 을 유지한다 (미지정 모달 회귀 방어)', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '500px' });
    });

    it('알 수 없는 size 값은 기본값으로 떨어진다 (작성 오타가 화면을 깨뜨리지 않음)', () => {
      render(<Modal {...mockProps} size={'huge' as any} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '500px' });
    });
  });

  it('좁은 화면 넘침 방어는 스타일 계층이 맡는다 — 컨테이너에 max-w 클래스가 붙어 있다', () => {
    render(<Modal {...mockProps} size="2xl" />);

    expect(screen.getByRole('dialog').className).toContain('max-w-[calc(100vw-2rem)]');
  });
});
