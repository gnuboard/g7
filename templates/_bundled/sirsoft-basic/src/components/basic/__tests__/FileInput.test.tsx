import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { FileInput } from '../FileInput';

describe('FileInput 컴포넌트 (sirsoft-basic)', () => {
  // ========================================
  // 레이아웃 편집기 passthrough
  // ========================================
  // 편집 모드에서 basic 컴포넌트에 주입되는 data-editor-* / id 는 시각적 루트(<div>)에
  // 부착되어야 한다. 숨겨진 <input class="hidden"> (0×0) 에 부착되면 캔버스에서 선택 불가.
  describe('편집기 passthrough (data-editor-* / id → 시각적 루트 <div>)', () => {
    it('data-editor-* 표식이 숨겨진 input 이 아닌 루트 div 에 부착된다', () => {
      const { container } = render(
        <FileInput
          data-editor-name="FileInput"
          data-editor-path="1.children.0"
          id="fi-root-id"
        />
      );

      const root = container.querySelector('[data-editor-name="FileInput"]') as HTMLElement;
      expect(root).toBeTruthy();
      expect(root.tagName.toLowerCase()).toBe('div');
      expect(root.getAttribute('data-editor-path')).toBe('1.children.0');
      expect(root.getAttribute('id')).toBe('fi-root-id');
      expect(root.querySelector('button')).toBeTruthy();
    });

    it('숨겨진 file input 에는 편집 표식이 없다', () => {
      const { container } = render(
        <FileInput data-editor-name="FileInput" data-editor-path="2.children.1" id="fi-id" />
      );
      const hiddenInput = container.querySelector('input[type="file"]') as HTMLElement;
      expect(hiddenInput).toBeTruthy();
      expect(hiddenInput.getAttribute('data-editor-name')).toBeNull();
      expect(hiddenInput.getAttribute('data-editor-path')).toBeNull();
      expect(hiddenInput.getAttribute('id')).toBeNull();
    });

    it('기능 prop(name 등)은 file input 에 그대로 전달된다', () => {
      const { container } = render(
        <FileInput name="upload_field" data-editor-name="FileInput" />
      );
      const hiddenInput = container.querySelector('input[type="file"]') as HTMLInputElement;
      expect(hiddenInput.getAttribute('name')).toBe('upload_field');
    });
  });
});
