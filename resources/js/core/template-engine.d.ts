/**
 * 그누보드7 템플릿 엔진 타입 선언 파일
 *
 * 전역 타입 정의 및 window 객체 확장
 */

declare module '*/template-engine' {
  import type { ComponentType } from 'react';
  import type ReactDOM from 'react-dom/client';
  import type { ComponentRegistry } from './template-engine/ComponentRegistry';
  import type { DataBindingEngine } from './template-engine/DataBindingEngine';
  import type { TranslationEngine } from './template-engine/TranslationEngine';
  import type { ActionDispatcher } from './template-engine/ActionDispatcher';

  /**
   * 템플릿 엔진 상태 인터페이스
   */
  export interface TemplateEngineState {
    templateId: string | null;
    locale: string;
    isInitialized: boolean;
    reactRoot: ReactDOM.Root | null;
    containerId: string | null;
    currentLayoutJson: any | null;
    currentDataContext: Record<string, any>;
    translationContext: Record<string, any>;
    registry: ComponentRegistry | null;
    bindingEngine: DataBindingEngine | null;
    translationEngine: TranslationEngine | null;
    actionDispatcher: ActionDispatcher | null;
  }

  /**
   * 템플릿 엔진 초기화 옵션
   */
  export interface InitOptions {
    templateId: string;
    locale?: string;
    debug?: boolean;
  }

  /**
   * 렌더링 옵션
   */
  export interface RenderOptions {
    containerId: string;
    layoutJson: any;
    dataContext?: Record<string, any>;
    translationContext?: Record<string, any>;
  }

  /**
   * 템플릿 엔진 공개 API
   */
  export interface TemplateEngineAPI {
    /**
     * 템플릿 엔진 초기화
     *
     * @param options - 초기화 옵션
     * @throws {Error} 이미 초기화된 경우 또는 초기화 실패 시
     */
    initTemplateEngine(options: InitOptions): Promise<void>;

    /**
     * 템플릿 렌더링
     *
     * @param options - 렌더링 옵션
     * @throws {Error} 초기화되지 않은 경우 또는 렌더링 실패 시
     */
    renderTemplate(options: RenderOptions): Promise<void>;

    /**
     * 템플릿 데이터 업데이트
     *
     * @param data - 업데이트할 데이터
     * @throws {Error} 초기화되지 않은 경우 또는 렌더링되지 않은 경우
     */
    updateTemplateData(data: Record<string, any>): void;

    /**
     * 템플릿 정리
     *
     * React Root를 언마운트하고 모든 상태를 초기화합니다.
     */
    destroyTemplate(): void;

    /**
     * 현재 상태 조회
     *
     * @returns 현재 템플릿 엔진 상태 (읽기 전용)
     */
    getState(): Readonly<TemplateEngineState>;
  }

  const TemplateEngine: TemplateEngineAPI;

  export default TemplateEngine;

  export function initTemplateEngine(options: InitOptions): Promise<void>;
  export function renderTemplate(options: RenderOptions): Promise<void>;
  export function updateTemplateData(data: Record<string, any>): void;
  export function destroyTemplate(): void;
  export function getState(): Readonly<TemplateEngineState>;
}

/**
 * 전역 Window 인터페이스 확장
 */
declare global {
  interface Window {
    G7Core?: {
      TemplateEngine?: import('*/template-engine').TemplateEngineAPI;
    };
  }
}

export {};
