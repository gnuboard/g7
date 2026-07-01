// e2e:allowImageGallery 편집 placeholder 단위 회귀. 라이브 E2E 는
// 1-c-verify 세션의 Playwright spec 이 cover(데이터 단계 면제, 1-a→1-b/1-c 위임과 동형).
/**
 * ImageGallery 편집 모드 placeholder 회귀 테스트
 *
 * ImageGallery 는 서드파티 Lightbox 모달(isOpen=false 시 DOM 미렌더)이라 편집기
 * 캔버스에서 선택·편집이 불가능했다(6 propControl 도달 불가). 편집 모드(editorAttrs
 * 주입) 에서는 인라인 placeholder(썸네일 그리드)를 대신 렌더해 editorAttrs·id 를
 * 부착하도록 보강했다. 본 테스트는 그 동작을 잠근다.
 */

import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect, beforeEach } from 'vitest';
import { ImageGallery } from '../ImageGallery';

// G7Core.t mock (편집 모드 placeholder 라벨 + basic 컴포넌트 의존)
beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
  };
});

const editorAttrs = {
  'data-editor-id': 'auto_ImageGallery_test',
  'data-editor-name': 'ImageGallery',
  'data-editor-type': 'composite',
  'data-editor-path': '3',
} as Record<string, string>;

const sampleImages = [
  { src: 'https://example.com/a.jpg', title: '이미지 A' },
  { src: 'https://example.com/b.jpg', title: '이미지 B', thumbnail: 'https://example.com/b-thumb.jpg' },
];

describe('ImageGallery 편집 모드 placeholder', () => {
  it('editorAttrs 주입 시 인라인 placeholder 루트에 editorAttrs 와 id 가 부착된다', () => {
    const { container } = render(
      <ImageGallery
        images={sampleImages}
        isOpen={false}
        onClose={() => {}}
        id="ig-edit"
        editorAttrs={editorAttrs}
      />
    );
    const node = container.querySelector('[data-editor-name="ImageGallery"]');
    expect(node, '편집 모드에서 인라인 placeholder 가 렌더되어야 함').not.toBeNull();
    expect(node).toHaveAttribute('data-editor-path', '3');
    expect(node).toHaveAttribute('id', 'ig-edit');
  });

  it('editorAttrs 주입 시 이미지 썸네일이 placeholder 에 렌더된다 (썸네일 우선)', () => {
    const { container } = render(
      <ImageGallery
        images={sampleImages}
        isOpen={false}
        onClose={() => {}}
        editorAttrs={editorAttrs}
      />
    );
    const imgs = container.querySelectorAll('img');
    expect(imgs.length).toBe(2);
    // 두 번째 이미지는 thumbnail 우선
    expect(imgs[1]).toHaveAttribute('src', 'https://example.com/b-thumb.jpg');
  });

  it('이미지가 없으면 placeholder 안내 라벨을 렌더한다', () => {
    const { container } = render(
      <ImageGallery images={[]} isOpen={false} onClose={() => {}} editorAttrs={editorAttrs} />
    );
    const node = container.querySelector('[data-editor-name="ImageGallery"]');
    expect(node).not.toBeNull();
    expect(node?.textContent).toContain('editor.component.image_gallery');
  });

  it('editorAttrs 미주입(런타임)이면 인라인 placeholder 를 렌더하지 않는다', () => {
    const { container } = render(
      <ImageGallery images={sampleImages} isOpen={false} onClose={() => {}} />
    );
    // 런타임 경로 — placeholder div(data-editor-name) 가 없어야 함
    const node = container.querySelector('[data-editor-name="ImageGallery"]');
    expect(node).toBeNull();
  });
});
