/**
 * ProductImageViewer 컴포넌트 테스트
 *
 * @description 상품 이미지 뷰어 컴포넌트의 동작을 테스트합니다.
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

// Mock ImageGallery & useImageGallery
const mockOpenGallery = vi.fn();
const mockOnClose = vi.fn();

vi.mock('../ImageGallery', () => ({
  ImageGallery: ({ open }: { open: boolean }) =>
    open ? <div data-testid="lightbox">Lightbox</div> : null,
  useImageGallery: () => ({
    openGallery: mockOpenGallery,
    galleryProps: {
      images: [],
      isOpen: false,
      onClose: mockOnClose,
      startIndex: 0,
    },
  }),
}));

// Mock basic components
vi.mock('../../basic/Div', () => ({
  Div: ({ children, className, ...props }: React.PropsWithChildren<{ className?: string }>) => (
    <div className={className} {...props}>{children}</div>
  ),
}));

vi.mock('../../basic/Img', () => ({
  Img: ({ src, alt, className }: { src: string; alt: string; className?: string }) => (
    <img src={src} alt={alt} className={className} data-testid="image" />
  ),
}));

vi.mock('../../basic/Button', () => ({
  Button: ({ children, onClick, className, ...props }: React.PropsWithChildren<{ onClick?: () => void; className?: string }>) => (
    <button onClick={onClick} className={className} {...props}>{children}</button>
  ),
}));

vi.mock('../../basic/Icon', () => ({
  Icon: ({ name, size, className }: { name?: string; size?: string; className?: string }) => <i data-name={name} data-size={size} className={className} />,
}));

// Mock G7Core
(window as Record<string, unknown>).G7Core = {
  t: (key: string) => key,
};

import { ProductImageViewer } from '../ProductImageViewer';

const mockImages = [
  {
    id: 1,
    url: null,
    download_url: '/api/modules/sirsoft-ecommerce/product-image/abc123',
    alt_text_current: '상품 메인 이미지',
    is_thumbnail: true,
    sort_order: 0,
  },
  {
    id: 2,
    url: 'https://cdn.example.com/img2.jpg',
    download_url: '/api/modules/sirsoft-ecommerce/product-image/def456',
    alt_text_current: '상품 추가 이미지',
    is_thumbnail: false,
    sort_order: 1,
  },
  {
    id: 3,
    url: null,
    download_url: '/api/modules/sirsoft-ecommerce/product-image/ghi789',
    alt_text_current: '상품 추가 이미지 2',
    is_thumbnail: false,
    sort_order: 2,
  },
];

describe('ProductImageViewer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('이미지 없음', () => {
    it('이미지가 빈 배열이면 placeholder를 표시한다', () => {
      render(<ProductImageViewer images={[]} />);
      expect(screen.getByText('shop.no_image')).toBeInTheDocument();
    });
  });

  describe('이미지 표시', () => {
    it('메인 이미지가 표시된다', () => {
      render(<ProductImageViewer images={mockImages} />);
      const images = screen.getAllByTestId('image');
      // 메인 이미지 src: url이 null이면 download_url 사용
      expect(images[0]).toHaveAttribute('src', '/api/modules/sirsoft-ecommerce/product-image/abc123');
    });

    it('url이 존재하면 url을 우선 사용한다', () => {
      render(<ProductImageViewer images={[mockImages[1]]} />);
      const images = screen.getAllByTestId('image');
      expect(images[0]).toHaveAttribute('src', 'https://cdn.example.com/img2.jpg');
    });

    it('이미지가 2개 이상이면 썸네일 리스트를 표시한다', () => {
      render(<ProductImageViewer images={mockImages} />);
      const images = screen.getAllByTestId('image');
      // 메인 1개 + 썸네일 3개 = 4개
      expect(images.length).toBe(4);
    });

    it('이미지가 1개이면 썸네일 리스트를 표시하지 않는다', () => {
      render(<ProductImageViewer images={[mockImages[0]]} />);
      const images = screen.getAllByTestId('image');
      // 메인 1개만
      expect(images.length).toBe(1);
    });
  });

  describe('썸네일 클릭', () => {
    it('썸네일 클릭 시 메인 이미지가 변경된다', () => {
      render(<ProductImageViewer images={mockImages} />);
      const buttons = screen.getAllByRole('button');
      // 두 번째 썸네일 클릭 (buttons[0]은 메인 이미지, [1]~[3]은 썸네일)
      fireEvent.click(buttons[2]);

      const images = screen.getAllByTestId('image');
      // 메인 이미지가 두 번째 이미지(cdn url)로 변경됨
      expect(images[0]).toHaveAttribute('src', 'https://cdn.example.com/img2.jpg');
    });
  });

  describe('라이트박스', () => {
    it('메인 이미지 클릭 시 라이트박스를 연다', () => {
      render(<ProductImageViewer images={mockImages} />);
      const buttons = screen.getAllByRole('button');
      // 첫 번째 버튼이 메인 이미지
      fireEvent.click(buttons[0]);
      expect(mockOpenGallery).toHaveBeenCalledTimes(1);
      expect(mockOpenGallery).toHaveBeenCalledWith(expect.any(Array), 0);
    });
  });
});
