/**
 * EventHelpers.ts
 *
 * 템플릿 엔진 컴포넌트를 위한 이벤트 유틸리티
 *
 * ActionDispatcher가 인식할 수 있는 표준 이벤트 객체를 생성하는
 * 헬퍼 함수들을 제공합니다. 컴포넌트에서 가짜 이벤트를 생성할 때
 * 이 헬퍼들을 사용하면 일관된 형식의 이벤트를 생성할 수 있습니다.
 *
 * @packageDocumentation
 *
 * @example
 * ```typescript
 * import { createChangeEvent } from '@/core/template-engine/helpers';
 *
 * // Toggle 컴포넌트에서 사용
 * const handleClick = () => {
 *   const newChecked = !checked;
 *   setChecked(newChecked);
 *   if (onChange) {
 *     onChange(createChangeEvent({ checked: newChecked, name }));
 *   }
 * };
 * ```
 */

import React from 'react';

/**
 * Change 이벤트 생성 옵션
 */
export interface CreateChangeEventOptions {
  /** 체크박스/라디오의 체크 상태 */
  checked?: boolean;
  /** input의 값 */
  value?: string;
  /** input의 name 속성 */
  name?: string;
  /** input의 type 속성 */
  type?: string;
}

/**
 * Click 이벤트 생성 옵션
 */
export interface CreateClickEventOptions {
  /** 클릭된 버튼 (0: 좌클릭, 1: 중클릭, 2: 우클릭) */
  button?: number;
  /** 클릭 X 좌표 */
  clientX?: number;
  /** 클릭 Y 좌표 */
  clientY?: number;
}

/**
 * ActionDispatcher가 인식할 수 있는 change 이벤트를 생성합니다.
 *
 * 컴포넌트에서 실제 input 요소 없이 onChange 콜백을 호출해야 할 때 사용합니다.
 * Toggle, Checkbox, RadioButton 등 커스텀 컴포넌트에서 유용합니다.
 *
 * @param options 이벤트 옵션
 * @returns React ChangeEvent 호환 객체
 *
 * @example
 * ```typescript
 * // 체크박스/토글용
 * const event = createChangeEvent({ checked: true, name: 'myToggle' });
 *
 * // 텍스트 입력용
 * const event = createChangeEvent({ value: 'hello', name: 'myInput' });
 * ```
 */
export function createChangeEvent(
  options: CreateChangeEventOptions = {}
): React.ChangeEvent<HTMLInputElement> {
  const { checked, value, name, type = 'checkbox' } = options;

  const target = {
    checked: checked ?? false,
    value: value ?? String(checked ?? ''),
    name: name ?? '',
    type,
  } as HTMLInputElement;

  return {
    target,
    currentTarget: target,
    preventDefault: () => {},
    stopPropagation: () => {},
    nativeEvent: new Event('change'),
    bubbles: true,
    cancelable: true,
    defaultPrevented: false,
    eventPhase: Event.AT_TARGET,
    isTrusted: false,
    timeStamp: Date.now(),
    type: 'change',
    isDefaultPrevented: () => false,
    isPropagationStopped: () => false,
    persist: () => {},
  } as React.ChangeEvent<HTMLInputElement>;
}

/**
 * ActionDispatcher가 인식할 수 있는 click 이벤트를 생성합니다.
 *
 * @param options 이벤트 옵션
 * @returns React MouseEvent 호환 객체
 *
 * @example
 * ```typescript
 * const event = createClickEvent();
 * onClick(event);
 * ```
 */
export function createClickEvent(
  options: CreateClickEventOptions = {}
): React.MouseEvent<HTMLElement> {
  const { button = 0, clientX = 0, clientY = 0 } = options;

  return {
    button,
    clientX,
    clientY,
    preventDefault: () => {},
    stopPropagation: () => {},
    nativeEvent: new MouseEvent('click'),
    bubbles: true,
    cancelable: true,
    defaultPrevented: false,
    eventPhase: Event.AT_TARGET,
    isTrusted: false,
    timeStamp: Date.now(),
    type: 'click',
    isDefaultPrevented: () => false,
    isPropagationStopped: () => false,
    persist: () => {},
    target: document.createElement('div'),
    currentTarget: document.createElement('div'),
  } as unknown as React.MouseEvent<HTMLElement>;
}

/**
 * ActionDispatcher가 인식할 수 있는 submit 이벤트를 생성합니다.
 *
 * @returns React FormEvent 호환 객체
 *
 * @example
 * ```typescript
 * const event = createSubmitEvent();
 * onSubmit(event);
 * ```
 */
export function createSubmitEvent(): React.FormEvent<HTMLFormElement> {
  return {
    preventDefault: () => {},
    stopPropagation: () => {},
    nativeEvent: new Event('submit'),
    bubbles: true,
    cancelable: true,
    defaultPrevented: false,
    eventPhase: Event.AT_TARGET,
    isTrusted: false,
    timeStamp: Date.now(),
    type: 'submit',
    isDefaultPrevented: () => false,
    isPropagationStopped: () => false,
    persist: () => {},
    target: document.createElement('form'),
    currentTarget: document.createElement('form'),
  } as unknown as React.FormEvent<HTMLFormElement>;
}

/**
 * ActionDispatcher가 인식할 수 있는 키보드 이벤트를 생성합니다.
 *
 * @param key 누른 키 (예: 'Enter', 'Escape', 'a')
 * @param eventType 이벤트 타입 ('keydown' | 'keyup' | 'keypress')
 * @returns React KeyboardEvent 호환 객체
 *
 * @example
 * ```typescript
 * const event = createKeyboardEvent('Enter', 'keydown');
 * onKeyDown(event);
 * ```
 */
export function createKeyboardEvent(
  key: string,
  eventType: 'keydown' | 'keyup' | 'keypress' = 'keydown'
): React.KeyboardEvent<HTMLElement> {
  return {
    key,
    code: key.length === 1 ? `Key${key.toUpperCase()}` : key,
    preventDefault: () => {},
    stopPropagation: () => {},
    nativeEvent: new KeyboardEvent(eventType, { key }),
    bubbles: true,
    cancelable: true,
    defaultPrevented: false,
    eventPhase: Event.AT_TARGET,
    isTrusted: false,
    timeStamp: Date.now(),
    type: eventType,
    isDefaultPrevented: () => false,
    isPropagationStopped: () => false,
    persist: () => {},
    target: document.createElement('div'),
    currentTarget: document.createElement('div'),
    altKey: false,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    repeat: false,
  } as unknown as React.KeyboardEvent<HTMLElement>;
}

/**
 * Drag 이벤트 생성 옵션
 */
export interface CreateDragEventOptions {
  /** 드래그되는 데이터 타입 */
  dataType?: string;
  /** 드래그되는 데이터 */
  data?: string;
  /** 드롭 효과 */
  effectAllowed?: 'none' | 'copy' | 'move' | 'link' | 'copyMove' | 'copyLink' | 'linkMove' | 'all';
  /** 클라이언트 X 좌표 */
  clientX?: number;
  /** 클라이언트 Y 좌표 */
  clientY?: number;
}

/**
 * ActionDispatcher가 인식할 수 있는 drag 이벤트를 생성합니다.
 *
 * 드래그 앤 드롭 이벤트를 테스트하거나 프로그래밍 방식으로 트리거할 때 사용합니다.
 *
 * @param eventType 드래그 이벤트 타입
 * @param options 이벤트 옵션
 * @returns React DragEvent 호환 객체
 *
 * @example
 * ```typescript
 * // dragstart 이벤트 생성
 * const event = createDragEvent('dragstart', { data: 'item-1' });
 * onDragStart(event);
 *
 * // drop 이벤트 생성
 * const event = createDragEvent('drop');
 * onDrop(event);
 * ```
 */
export function createDragEvent(
  eventType: 'dragstart' | 'drag' | 'dragend' | 'dragenter' | 'dragover' | 'dragleave' | 'drop',
  options: CreateDragEventOptions = {}
): React.DragEvent<HTMLElement> {
  const { dataType = 'text/plain', data = '', effectAllowed = 'move', clientX = 0, clientY = 0 } = options;

  // DataTransfer mock 생성
  const dataMap = new Map<string, string>([[dataType, data]]);
  const dataTransfer = {
    data: dataMap,
    effectAllowed,
    dropEffect: 'move' as const,
    setData(type: string, value: string) {
      this.data.set(type, value);
    },
    getData(type: string) {
      return this.data.get(type) || '';
    },
    clearData(type?: string) {
      if (type) {
        this.data.delete(type);
      } else {
        this.data.clear();
      }
    },
    types: [dataType],
    files: [] as unknown as FileList,
    items: [] as unknown as DataTransferItemList,
    setDragImage: () => {},
  };

  return {
    dataTransfer,
    clientX,
    clientY,
    preventDefault: () => {},
    stopPropagation: () => {},
    nativeEvent: new DragEvent(eventType),
    bubbles: true,
    cancelable: true,
    defaultPrevented: false,
    eventPhase: Event.AT_TARGET,
    isTrusted: false,
    timeStamp: Date.now(),
    type: eventType,
    isDefaultPrevented: () => false,
    isPropagationStopped: () => false,
    persist: () => {},
    target: document.createElement('div'),
    currentTarget: document.createElement('div'),
  } as unknown as React.DragEvent<HTMLElement>;
}