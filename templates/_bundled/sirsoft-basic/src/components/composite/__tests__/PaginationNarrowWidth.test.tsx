// e2e:allow Vitest 컴포넌트 계약 — 390px 넘침은 7.1.0 실측 K3 후속(KF3-3)에서 Playwright 로 마이페이지 게시판 활동·내 반응·스크랩·게시판 목록 1·2쪽을 ko/en, 390/1440 으로 처음부터 끝까지 재측정했다.
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Pagination } from '../Pagination';

/**
 * 좁은 화면(390px)에서 쪽 이동 줄이 칸보다 넓어 가로로 넘치던 회귀 (7.1.0 실측 K3 관측 O-K3T-4)
 *
 * 마이페이지 게시판 활동의 쪽 이동 줄(«‹1 … 7›»)이 버튼 11개라 343px 칸에 378px 로 그려져
 * 문서 전체가 가로로 19px 밀렸다(scrollWidth 394 > clientWidth 375). 맨 앞·맨 끝 쪽 버튼은
 * 번호 목록이 이미 첫 쪽과 마지막 쪽을 품고 있어(축약 시에도 1 과 마지막 쪽을 남긴다) 좁은 화면에서는
 * 숨겨도 갈 수 있는 쪽이 줄지 않는다. 그래도 넘치면 줄을 바꿔 칸 밖으로 나가지 않게 한다.
 *
 * 조합 요약(마커 아님, 평문): viewport=narrow
 * 효과 요약(마커 아님, 평문): pagination_fits_narrow_width
 */
describe('Pagination — 좁은 화면에서 칸 밖으로 넘치지 않는다', () => {
  // @scenario viewport=narrow
  // @effects pagination_fits_narrow_width
  it('맨 앞·맨 끝 쪽 버튼은 좁은 화면에서 숨고 sm 이상에서 보인다', () => {
    // 폭은 엔진 폭(편집기 미리보기에서는 기기 폭)으로 고른다 — Tailwind 반응형 접두를 쓰지 않는다
    const saved = (window as any).G7Core;
    const classesAt = (width: number) => {
      (window as any).G7Core = { ...(saved ?? {}), useResponsive: () => ({ width }) };
      const { unmount } = render(<Pagination currentPage={1} totalPages={7} showFirstLast onPageChange={vi.fn()} />);
      const result = ['첫 페이지', '마지막 페이지'].map((label) => screen.getByLabelText(label).className.split(/\s+/));
      unmount();
      return result;
    };
    try {
      for (const tokens of classesAt(390)) {
        expect(tokens).toContain('hidden');
        expect(tokens).not.toContain('inline-block');
      }
      for (const tokens of classesAt(640)) {
        expect(tokens).toContain('inline-block');
        expect(tokens).not.toContain('hidden');
      }
    } finally {
      (window as any).G7Core = saved;
    }
  });

  // @scenario viewport=narrow
  // @effects pagination_fits_narrow_width
  it('줄과 번호 묶음은 줄바꿈을 허용하고 칸 폭을 넘지 않는다', () => {
    render(<Pagination currentPage={1} totalPages={7} onPageChange={vi.fn()} />);

    const nav = screen.getByRole('navigation');
    expect(nav.className).toContain('flex-wrap');
    expect(nav.className).toContain('max-w-full');

    const numbers = screen.getByLabelText('페이지 1').parentElement as HTMLElement;
    expect(numbers.className).toContain('flex-wrap');
  });

  // @scenario viewport=wide
  // @effects pagination_fits_narrow_width
  it('쪽이 많아 축약될 때도 번호 목록에 첫 쪽과 마지막 쪽이 남는다 (맨 앞·맨 끝 버튼을 숨겨도 갈 수 있다)', () => {
    render(<Pagination currentPage={10} totalPages={30} onPageChange={vi.fn()} />);

    expect(screen.getByLabelText('페이지 1')).toBeInTheDocument();
    expect(screen.getByLabelText('페이지 30')).toBeInTheDocument();
  });
});
