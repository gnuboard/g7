/**
 * ComponentRegistry.ts
 *
 * 템플릿 컴포넌트를 동적으로 로드하고 전역 레지스트리에 등록하는 클래스
 *
 * 역할:
 * - /build/template/dist/components.js 동적 로딩
 * - components.json 매니페스트 검증
 * - 컴포넌트 타입별 분류 (basic, composite, layout)
 * - 전역 레지스트리 관리 및 캐싱
 */

import type { ComponentType } from 'react';
import { createLogger } from '../utils/Logger';

const logger = createLogger('ComponentRegistry');

/**
 * 컴포넌트 타입 정의
 */
export type ComponentTypeEnum = 'basic' | 'composite' | 'layout';

/**
 * 컴포넌트 메타데이터 인터페이스
 */
export interface ComponentMetadata {
  /** 컴포넌트 이름 */
  name: string;

  /** 컴포넌트 타입 */
  type: ComponentTypeEnum;

  /** 컴포넌트 설명 */
  description?: string;

  /** 허용된 props 목록 */
  props?: string[];

  /** children 허용 여부 */
  allowsChildren?: boolean;

  /**
   * 바인딩 처리를 건너뛸 props 키 목록
   *
   * 컴포넌트가 내부적으로 row/iteration 컨텍스트 등을 사용하여
   * 자체적으로 바인딩을 처리해야 하는 props 키를 지정합니다.
   *
   * 예시: DataGrid는 ['cellChildren', 'expandChildren', 'expandContext', 'render']
   */
  skipBindingKeys?: string[];

  /**
   * Form 자동 바인딩 시 boolean 값의 바인딩 타입
   *
   * - 'checked': 항상 checked prop으로 바인딩 (Toggle, Checkbox 등)
   * - 'checkable': type이 checkbox/radio일 때만 checked, 그 외 value (Input)
   * - 미지정: 항상 value prop으로 바인딩 (RadioGroup, Select 등 기본값)
   */
  bindingType?: 'checked' | 'checkable';
}

/**
 * components.json 매니페스트 스키마
 */
export interface ComponentManifest {
  /** 매니페스트 버전 */
  version: string;

  /** 템플릿 식별자 */
  templateId: string;

  /** 컴포넌트 목록 */
  components: {
    /** 기본 컴포넌트 목록 */
    basic: ComponentMetadata[];

    /** 집합 컴포넌트 목록 */
    composite: ComponentMetadata[];

    /** 레이아웃 컴포넌트 목록 */
    layout: ComponentMetadata[];
  };
}

/**
 * 레지스트리 맵 인터페이스
 */
export interface RegistryMap {
  [componentName: string]: {
    component: ComponentType<any>;
    metadata: ComponentMetadata;
  };
}

/**
 * 로딩 상태 타입
 */
export type LoadingState = 'idle' | 'loading' | 'loaded' | 'error';

/**
 * ComponentRegistry 에러 클래스
 */
export class ComponentRegistryError extends Error {
  constructor(message: string, public code: string, public details?: any) {
    super(message);
    this.name = 'ComponentRegistryError';
  }
}

/**
 * ComponentRegistry 클래스
 *
 * 싱글톤 패턴으로 전역 컴포넌트 레지스트리 관리
 */
export class ComponentRegistry {
  private static instance: ComponentRegistry | null = null;

  /** 매니페스트 캐시 (템플릿별) */
  private static manifestCache = new Map<string, ComponentManifest>();

  /** 컴포넌트 레지스트리 맵 */
  private registry: RegistryMap = {};

  /** 컴포넌트 매니페스트 */
  private manifest: ComponentManifest | null = null;

  /** 로딩 상태 */
  private loadingState: LoadingState = 'idle';

  /** 에러 정보 */
  private error: Error | null = null;

  /** 템플릿 ID */
  private templateId: string | null = null;

  /** 템플릿 타입 (admin 또는 user) */
  private templateType: string | null = null;

  /**
   * private 생성자 (싱글톤 패턴)
   */
  private constructor() {}

  /**
   * 싱글톤 인스턴스 반환
   */
  public static getInstance(): ComponentRegistry {
    if (!ComponentRegistry.instance) {
      ComponentRegistry.instance = new ComponentRegistry();
    }
    return ComponentRegistry.instance;
  }

  /**
   * 레지스트리 초기화 (테스트용)
   */
  public static resetInstance(): void {
    ComponentRegistry.instance = null;
  }

  /**
   * 템플릿 컴포넌트 로드 및 등록
   *
   * @param templateId 템플릿 식별자
   * @param templateType 템플릿 타입 (admin 또는 user)
   */
  public async loadComponents(templateId: string, templateType: string): Promise<void> {
    if (this.loadingState === 'loading') {
      throw new ComponentRegistryError(
        'Components are already being loaded',
        'LOADING_IN_PROGRESS'
      );
    }

    if (this.loadingState === 'loaded' && this.templateId === templateId && this.templateType === templateType) {
      logger.log('Components already loaded for template:', templateId, templateType);
      return;
    }

    this.loadingState = 'loading';
    this.templateId = templateId;
    this.templateType = templateType;
    this.error = null;

    try {
      // 1. components.json 매니페스트 로드
      await this.loadManifest();

      // 2. 컴포넌트 번들 동적 import
      await this.loadComponentBundle();

      // 3. 로딩 완료
      this.loadingState = 'loaded';
      logger.log('Successfully loaded components:', Object.keys(this.registry).length);
    } catch (error) {
      this.loadingState = 'error';
      this.error = error instanceof Error ? error : new Error(String(error));

      throw new ComponentRegistryError(
        `Failed to load components: ${this.error.message}`,
        'LOAD_FAILED',
        { originalError: this.error }
      );
    }
  }

  /**
   * components.json 매니페스트 로드 및 검증 (캐싱 지원)
   */
  private async loadManifest(): Promise<void> {
    try {
      if (!this.templateId) {
        throw new ComponentRegistryError(
          'Template ID not set',
          'TEMPLATE_ID_NOT_SET'
        );
      }

      // 캐시 키 생성
      const cacheKey = `${this.templateId}:${this.templateType}`;

      // 캐시 확인
      if (ComponentRegistry.manifestCache.has(cacheKey)) {
        this.manifest = ComponentRegistry.manifestCache.get(cacheKey)!;
        logger.log('Manifest loaded from cache:', this.manifest.templateId);
        return;
      }

      // 캐시 미스 - API에서 로드
      const manifestUrl = `/api/templates/${this.templateId}/components.json`;
      const response = await fetch(manifestUrl);

      if (!response.ok) {
        throw new ComponentRegistryError(
          `Failed to fetch manifest: ${response.status} ${response.statusText}`,
          'MANIFEST_FETCH_FAILED',
          { status: response.status, statusText: response.statusText }
        );
      }

      const manifest = await response.json();

      // 매니페스트 검증
      this.validateManifest(manifest);

      this.manifest = manifest;

      // 캐시에 저장
      ComponentRegistry.manifestCache.set(cacheKey, manifest);

      logger.log('Manifest loaded and cached:', manifest.templateId);
    } catch (error) {
      if (error instanceof ComponentRegistryError) {
        throw error;
      }
      throw new ComponentRegistryError(
        'Failed to load component manifest',
        'MANIFEST_LOAD_FAILED',
        { originalError: error }
      );
    }
  }

  /**
   * 매니페스트 검증
   */
  private validateManifest(manifest: any): void {
    // 필수 필드 검증
    if (!manifest.version) {
      throw new ComponentRegistryError(
        'Manifest missing required field: version',
        'MANIFEST_INVALID',
        { field: 'version' }
      );
    }

    if (!manifest.templateId) {
      throw new ComponentRegistryError(
        'Manifest missing required field: templateId',
        'MANIFEST_INVALID',
        { field: 'templateId' }
      );
    }

    // 컴포넌트 객체 검증
    if (!manifest.components || typeof manifest.components !== 'object') {
      throw new ComponentRegistryError(
        'Manifest missing required field: components',
        'MANIFEST_INVALID',
        { field: 'components' }
      );
    }

    // 컴포넌트 배열 검증
    const componentTypes: ComponentTypeEnum[] = ['basic', 'composite', 'layout'];

    for (const type of componentTypes) {
      if (!Array.isArray(manifest.components[type])) {
        throw new ComponentRegistryError(
          `Manifest field 'components.${type}' must be an array`,
          'MANIFEST_INVALID',
          { field: `components.${type}`, value: manifest.components[type] }
        );
      }

      // 각 컴포넌트 메타데이터 검증
      manifest.components[type].forEach((meta: any, index: number) => {
        if (!meta.name || typeof meta.name !== 'string') {
          throw new ComponentRegistryError(
            `Invalid component metadata at ${type}[${index}]: missing or invalid 'name'`,
            'MANIFEST_INVALID',
            { type, index, metadata: meta }
          );
        }

        if (!meta.type || typeof meta.type !== 'string') {
          throw new ComponentRegistryError(
            `Invalid component metadata at ${type}[${index}]: missing or invalid 'type'`,
            'MANIFEST_INVALID',
            { type, index, metadata: meta }
          );
        }
      });
    }

    logger.log('Manifest validation passed');
  }

  /**
   * 컴포넌트 번들에서 컴포넌트 가져오기
   *
   * admin.blade.php에서 이미 IIFE 번들을 로드했으므로,
   * 전역 변수에서 컴포넌트를 가져옵니다 (HTTP 요청 불필요).
   */
  private async loadComponentBundle(): Promise<void> {
    try {
      if (!this.templateId) {
        throw new ComponentRegistryError(
          'Template ID not set',
          'TEMPLATE_ID_NOT_SET'
        );
      }

      // 전역 객체에서 컴포넌트 가져오기
      // admin.blade.php에서 이미 <script> 태그로 로드되어 전역 변수에 노출됨
      const globalVarName = this.getGlobalVariableName();
      const module = (window as any)[globalVarName];

      if (!module || typeof module !== 'object') {
        throw new ComponentRegistryError(
          `Component bundle not loaded. Expected global variable: ${globalVarName}. ` +
          `Ensure admin.blade.php includes the IIFE bundle script.`,
          'BUNDLE_NOT_LOADED',
          { expectedVariable: globalVarName }
        );
      }

      // 매니페스트의 모든 컴포넌트 등록
      await this.registerComponentsFromManifest(module);

      logger.log('Component bundle loaded from global variable:', globalVarName);
    } catch (error) {
      if (error instanceof ComponentRegistryError) {
        throw error;
      }
      throw new ComponentRegistryError(
        'Failed to load component bundle',
        'BUNDLE_LOAD_FAILED',
        { originalError: error }
      );
    }
  }

  /**
   * 템플릿 ID로부터 전역 변수명 생성
   */
  private getGlobalVariableName(): string {
    if (!this.templateId) {
      throw new ComponentRegistryError(
        'Template ID not set',
        'TEMPLATE_ID_NOT_SET'
      );
    }

    // sirsoft-admin_basic -> SirsoftAdminBasic
    return this.templateId
      .split(/[-_]/)
      .map(part => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
      .join('');
  }

  /**
   * 매니페스트 기반 컴포넌트 등록
   */
  private async registerComponentsFromManifest(module: any): Promise<void> {
    if (!this.manifest) {
      throw new ComponentRegistryError(
        'Manifest not loaded',
        'MANIFEST_NOT_LOADED'
      );
    }

    const componentTypes: ComponentTypeEnum[] = ['basic', 'composite', 'layout'];

    for (const type of componentTypes) {
      const components = this.manifest.components[type] || [];

      for (const metadata of components) {
        const componentName = metadata.name;
        const component = module[componentName];

        if (!component) {
          logger.warn(`Component '${componentName}' not found in bundle`);
          continue;
        }

        // 컴포넌트 등록
        this.registerComponent(componentName, component, metadata);
      }
    }
  }

  /**
   * 개별 컴포넌트 등록
   */
  private registerComponent(
    name: string,
    component: ComponentType<any>,
    metadata: ComponentMetadata
  ): void {
    if (this.registry[name]) {
      logger.warn(`Component '${name}' already registered, overwriting`);
    }

    this.registry[name] = {
      component,
      metadata,
    };

    logger.log(`[ComponentRegistry] Registered component: ${name} (${metadata.type})`);
  }

  /**
   * 컴포넌트 조회
   *
   * @param name 컴포넌트 이름
   * @returns 컴포넌트 또는 null
   */
  public getComponent(name: string): ComponentType<any> | null {
    const entry = this.registry[name];
    return entry ? entry.component : null;
  }

  /**
   * 컴포넌트 메타데이터 조회
   *
   * @param name 컴포넌트 이름
   * @returns 메타데이터 또는 null
   */
  public getMetadata(name: string): ComponentMetadata | null {
    const entry = this.registry[name];
    return entry ? entry.metadata : null;
  }

  /**
   * 컴포넌트 존재 여부 확인
   *
   * @param name 컴포넌트 이름
   * @returns 존재 여부
   */
  public hasComponent(name: string): boolean {
    return name in this.registry;
  }

  /**
   * 타입별 컴포넌트 목록 조회
   *
   * @param type 컴포넌트 타입
   * @returns 컴포넌트 이름 배열
   */
  public getComponentsByType(type: ComponentTypeEnum): string[] {
    return Object.entries(this.registry)
      .filter(([_, entry]) => entry.metadata.type === type)
      .map(([name, _]) => name);
  }

  /**
   * 모든 컴포넌트 목록 조회
   *
   * @returns 컴포넌트 이름 배열
   */
  public getAllComponents(): string[] {
    return Object.keys(this.registry);
  }

  /**
   * 전체 컴포넌트 맵 반환
   *
   * 컴포넌트 이름을 키로, React 컴포넌트를 값으로 하는 맵을 반환합니다.
   * CardGrid 등 composite 컴포넌트에서 renderItemChildren 호출 시 사용합니다.
   *
   * @returns 컴포넌트 이름 → React 컴포넌트 맵
   */
  public getComponentMap(): Record<string, ComponentType<any>> {
    const map: Record<string, ComponentType<any>> = {};
    for (const [name, entry] of Object.entries(this.registry)) {
      map[name] = entry.component;
    }
    return map;
  }

  /**
   * 로딩 상태 조회
   */
  public getLoadingState(): LoadingState {
    return this.loadingState;
  }

  /**
   * 에러 정보 조회
   */
  public getError(): Error | null {
    return this.error;
  }

  /**
   * 현재 템플릿 ID 조회
   */
  public getTemplateId(): string | null {
    return this.templateId;
  }

  /**
   * 매니페스트 조회
   */
  public getManifest(): ComponentManifest | null {
    return this.manifest;
  }

  /**
   * 레지스트리 클리어 (테스트용)
   */
  public clear(): void {
    this.registry = {};
    this.manifest = null;
    this.loadingState = 'idle';
    this.error = null;
    this.templateId = null;
    this.templateType = null;
    logger.log('Registry cleared');
  }
}

// 전역 export
export default ComponentRegistry;
