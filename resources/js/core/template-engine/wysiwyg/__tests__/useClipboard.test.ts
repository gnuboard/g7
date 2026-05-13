/**
 * useClipboard.test.ts
 *
 * 클립보드 훅 테스트
 */

import { regenerateIds, serializeComponent, deserializeComponent } from '../hooks/useClipboard';
import type { ComponentDefinition } from '../types/editor';

describe('useClipboard utilities', () => {
  // ==========================================================================
  // regenerateIds 테스트
  // ==========================================================================

  describe('regenerateIds', () => {
    it('should generate new id for component', () => {
      const component: ComponentDefinition = {
        id: 'original-id',
        type: 'basic',
        name: 'Button',
        props: { label: 'Click me' },
      };

      const result = regenerateIds(component);

      expect(result.id).not.toBe('original-id');
      // ID는 소문자로 생성됨 (예: button_xxx_xxx)
      expect(result.id.toLowerCase()).toContain('button');
      expect(result.name).toBe('Button');
      expect(result.props).toEqual({ label: 'Click me' });
    });

    it('should regenerate ids for nested children', () => {
      const component: ComponentDefinition = {
        id: 'parent-id',
        type: 'layout',
        name: 'Container',
        children: [
          {
            id: 'child-1',
            type: 'basic',
            name: 'Button',
          },
          {
            id: 'child-2',
            type: 'basic',
            name: 'Input',
          },
        ],
      };

      const result = regenerateIds(component);

      expect(result.id).not.toBe('parent-id');
      expect(result.children).toHaveLength(2);
      expect(result.children![0].id).not.toBe('child-1');
      expect(result.children![1].id).not.toBe('child-2');
      expect(result.children![0].name).toBe('Button');
      expect(result.children![1].name).toBe('Input');
    });

    it('should regenerate ids for deeply nested children', () => {
      const component: ComponentDefinition = {
        id: 'root',
        type: 'layout',
        name: 'Root',
        children: [
          {
            id: 'level-1',
            type: 'layout',
            name: 'Level1',
            children: [
              {
                id: 'level-2',
                type: 'basic',
                name: 'Level2',
              },
            ],
          },
        ],
      };

      const result = regenerateIds(component);

      expect(result.id).not.toBe('root');
      expect(result.children![0].id).not.toBe('level-1');
      expect((result.children![0] as ComponentDefinition).children![0].id).not.toBe('level-2');
    });

    it('should preserve other properties', () => {
      const component: ComponentDefinition = {
        id: 'test',
        type: 'basic',
        name: 'Button',
        props: { variant: 'primary' },
        if: '{{showButton}}',
        actions: [
          { type: 'onClick', handler: 'navigate', target: '/home' },
        ],
      };

      const result = regenerateIds(component);

      expect(result.props).toEqual({ variant: 'primary' });
      expect(result.if).toBe('{{showButton}}');
      expect(result.actions).toHaveLength(1);
    });
  });

  // ==========================================================================
  // serializeComponent / deserializeComponent 테스트
  // ==========================================================================

  describe('serializeComponent', () => {
    it('should serialize component to JSON string', () => {
      const component: ComponentDefinition = {
        id: 'test-id',
        type: 'basic',
        name: 'Button',
        props: { label: 'Test' },
      };

      const serialized = serializeComponent(component);
      const parsed = JSON.parse(serialized);

      expect(parsed.type).toBe('g7-component');
      expect(parsed.data).toEqual(component);
      expect(typeof parsed.timestamp).toBe('number');
    });
  });

  describe('deserializeComponent', () => {
    it('should deserialize g7-component format', () => {
      const component: ComponentDefinition = {
        id: 'test-id',
        type: 'basic',
        name: 'Button',
        props: { label: 'Test' },
      };

      const json = JSON.stringify({
        type: 'g7-component',
        data: component,
        timestamp: Date.now(),
      });

      const result = deserializeComponent(json);

      expect(result).toEqual(component);
    });

    it('should deserialize legacy format', () => {
      const component = {
        id: 'test-id',
        type: 'basic',
        name: 'Button',
        props: { label: 'Test' },
      };

      const json = JSON.stringify(component);
      const result = deserializeComponent(json);

      expect(result).toEqual(component);
    });

    it('should return null for invalid JSON', () => {
      const result = deserializeComponent('not valid json');
      expect(result).toBeNull();
    });

    it('should return null for non-component object', () => {
      const json = JSON.stringify({ foo: 'bar' });
      const result = deserializeComponent(json);
      expect(result).toBeNull();
    });

    it('should return null for empty string', () => {
      const result = deserializeComponent('');
      expect(result).toBeNull();
    });
  });

  // ==========================================================================
  // 직렬화/역직렬화 왕복 테스트
  // ==========================================================================

  describe('serialization roundtrip', () => {
    it('should preserve component data through serialization roundtrip', () => {
      const original: ComponentDefinition = {
        id: 'complex-component',
        type: 'composite',
        name: 'Card',
        props: {
          title: 'Test Card',
          variant: 'elevated',
        },
        children: [
          {
            id: 'card-body',
            type: 'basic',
            name: 'Div',
            text: 'Card content',
          },
        ],
        if: '{{showCard}}',
        actions: [
          {
            type: 'onClick',
            handler: 'navigate',
            params: { path: '/details' },
          },
        ],
      };

      const serialized = serializeComponent(original);
      const deserialized = deserializeComponent(serialized);

      expect(deserialized).toEqual(original);
    });
  });
});
