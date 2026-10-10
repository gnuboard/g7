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

import React, { type ComponentType } from 'react';
import { createLogger } from '../utils/Logger';
import { suffixed, extStaticUrl } from '../support/assetUrl';
import { fetchStaticFirst } from '../support/fetchStaticFirst';

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

  /**
   * Form 자동 바인딩 대상에서 제외 (engine-v1.68.1+)
   *
   * 자동 바인딩의 활성 조건은 "`name` prop 존재 + 부모 FormContext" 뿐이라,
   * `name` 이 **폼 필드명이 아닌** 컴포넌트까지 대상이 된다 — Icon 의 `name` 은
   * 아이콘 글리프 이름이고, Avatar·UserInfo 의 `name` 은 표시용 사람 이름이다.
   * 이런 컴포넌트가 같은 이름의 폼 필드와 겹치면 그 필드 값이 `value` 로 주입되어
   * DOM 에 `[object Object]` 로 새거나(배열/객체 필드) 표시가 깨진다.
   *
   * 매니페스트에서 `false` 로 선언하면 그 컴포넌트는 자동 바인딩에서 빠진다.
   * 미선언(기본)은 종전과 동일하게 자동 바인딩 대상이다 — 선언을 빠뜨려도
   * 기존 폼이 깨지지 않도록 fail-open 으로 둔다.
   *
   * 레이아웃 단위로 끄려면 노드 props 의 `autoBinding: false` 를 쓴다(같은 효과).
   */
  autoBinding?: false;
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

  /**
   * 이 템플릿이 대체하는 확장 컴포넌트 선언 목록
   *
   * 확장이 제공한 컴포넌트를 템플릿이 자기 구현으로 갈아끼울 때 선언한다. 구현체는
   * `components/overrides/{확장식별자}/{이름}.tsx` 에 두며, 디렉토리명이 곧 `target` 이다 —
   * 레이아웃 오버라이드(`layouts/overrides/{확장식별자}/`)와 같은 규약.
   *
   * 대체 권한은 템플릿에만 있다. 확장 매니페스트가 이 키를 선언하면 컴파일러가 거부한다.
   *
   * @since engine-v1.67.0
   */
  overrides?: ComponentOverrideDeclaration[];
}

/**
 * 템플릿이 확장 컴포넌트를 대체한다는 선언 하나
 *
 * @since engine-v1.67.0
 */
export interface ComponentOverrideDeclaration extends ComponentMetadata {
  /** 대체 대상 확장 식별자 (예: `sirsoft-ecommerce`) */
  target: string;

  /**
   * 템플릿 번들에서 이 대체본을 내보낸 이름 (미선언이면 `name`)
   *
   * `name` 은 대상 확장이 붙인 이름이라 템플릿 자신의 컴포넌트와 겹칠 수 있다 — 템플릿에도
   * `ProductCard` 가 있으면 번들 전역에서 어느 쪽인지 가릴 수 없다. 그럴 때만 별도 export
   * 이름을 준다.
   */
  exportName?: string;

  /**
   * 대상 확장의 허용 버전 제약 (Semver)
   *
   * 남의 컴포넌트를 대체하므로 그 남이 갱신되면 대체본이 낡는다. 비호환이면 대체본을
   * 폐기하고 원본으로 폴백한다 — 레이아웃 오버라이드와 같은 규약.
   */
  targetVersion?: string;
}

/**
 * 확장이 선언한 컴포넌트 판본 하나
 *
 * @since engine-v1.67.0
 */
export interface ExtensionComponentDeclaration {
  /** 컴포넌트 구현체 */
  component: ComponentType<any>;

  /** 컴포넌트 메타데이터 */
  metadata: ComponentMetadata;

  /** 이 판본이 전용으로 쓰일 템플릿 id (미지정이면 기본 판본) */
  forTemplate?: string;
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

  /** 확장 캐시 버전 (0 = 무버전 URL — 편집기 경로) */
  private cacheVersion = 0;

  /** 로딩 상태 */
  private loadingState: LoadingState = 'idle';

  /** 에러 정보 */
  private error: Error | null = null;

  /** 템플릿 ID */
  private templateId: string | null = null;

  /** 템플릿 타입 (admin 또는 user) */
  private templateType: string | null = null;

  /**
   * 템플릿이 대체한 확장 컴포넌트 이름 — 이 이름에 대한 확장의 자가등록은 무시된다.
   *
   * 확장 번들이 레지스트리 로딩보다 나중에 실행되므로(TemplateApp :468 → :687) 이 기록이
   * 없으면 확장의 원본이 템플릿의 대체본을 되덮는다. 대체 권한은 템플릿 전용이라는 규약의
   * 런타임 쪽 절반이다.
   *
   * @since engine-v1.67.0
   */
  private overriddenByTemplate: Set<string> = new Set();

  /**
   * 확장이 선언한 컴포넌트 판본 — **인스턴스가 아니라 클래스에 보관**한다.
   *
   * 확장 IIFE 는 페이지당 한 번 실행되고 그때 싱글톤에 등록한다. 그런데 레이아웃 편집기는
   * **그 뒤에** 격리 인스턴스를 만들어 다른 템플릿을 렌더한다(`createIsolatedInstance`) —
   * 선언을 인스턴스에만 담아 두면 그 격리 인스턴스에는 확장 컴포넌트가 영원히 없고, 편집기
   * 미리보기에서 그 노드만 조용히 사라진다.
   *
   * 또 판본 선택은 **인스턴스마다 답이 다르다**. 호스트가 admin 템플릿을 띄운 채
   * user 템플릿을 편집하면, 같은 선언에서 호스트는 기본 판본을, 편집기는 user 전용 판본을
   * 골라야 한다. 그래서 "고른 결과" 가 아니라 **"선언 전체"** 를 보관하고 각 인스턴스가
   * 자기 `templateId` 로 고른다.
   *
   * @since engine-v1.67.0
   */
  private static extensionDeclarations = new Map<string, ExtensionComponentDeclaration[]>();

  /**
   * 이 인스턴스가 확장 컴포넌트별로 실제 채택한 구현체 — 중복 등록(덮어쓰기 warn) 방지용.
   *
   * @since engine-v1.67.0
   */
  private appliedExtensionChoice: Map<string, ComponentType<any>> = new Map();

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
   * 격리 인스턴스 생성 — 싱글톤과 독립된 별도 ComponentRegistry.
   *
   * 레이아웃 편집기 캔버스 처럼 한 페이지에서 호스트 템플릿과
   * 편집 대상 템플릿이 동시에 살아 있어야 하는 케이스용. 싱글톤을 점유 중인
   * 호스트(`getInstance()`)의 등록 상태와 충돌하지 않도록, 격리 인스턴스는
   * 정적 manifestCache 만 공유하고 registry/manifest/loadingState 는 자기
   * 인스턴스 안에만 존재한다.
   *
   * `loadComponents()` 등 인스턴스 메서드는 싱글톤과 동일하게 사용 가능.
   *
   * @since engine-v1.50.0
   */
  public static createIsolatedInstance(): ComponentRegistry {
    return new ComponentRegistry();
  }

  /**
   * 레지스트리 초기화 (테스트용)
   */
  public static resetInstance(): void {
    ComponentRegistry.instance = null;
    // 확장 선언은 클래스에 살아 있어 인스턴스를 새로 만들어도 남는다 — 테스트가 서로
    // 오염되지 않도록 함께 비운다.
    ComponentRegistry.extensionDeclarations.clear();
  }

  /**
   * 템플릿 컴포넌트 로드 및 등록
   *
   * @param templateId 템플릿 식별자
   * @param templateType 템플릿 타입 (admin 또는 user)
   * @param cacheVersion 확장 캐시 버전 — 0(기본)이면 무버전 URL(편집기 경로,
   *   서버는 `?v` 생략 시 현재 버전 폴백 #588). 매니페스트 캐시 키에도 포함되어
   *   버전 간 교차 오염을 막는다 (#122, @since engine-v1.61.0)
   */
  public async loadComponents(templateId: string, templateType: string, cacheVersion: number = 0): Promise<void> {
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
    this.cacheVersion = cacheVersion;
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

      // 캐시 키 생성 (버전 포함 — 확장 라이프사이클 후 stale 매니페스트 교차 오염 방지)
      const cacheKey = `${this.templateId}:${this.templateType}:v${this.cacheVersion || 0}`;

      // 캐시 확인
      if (ComponentRegistry.manifestCache.has(cacheKey)) {
        this.manifest = ComponentRegistry.manifestCache.get(cacheKey)!;
        logger.log('Manifest loaded from cache:', this.manifest.templateId);
        return;
      }

      // 캐시 미스 - API에서 로드
      // 네트워크 일시 실패(응답 없음)에만 재시도. HTTP 에러는 아래 !ok 분기가 종전대로 처리.
      // @since engine-v1.53.0
      const manifestUrl = suffixed(
        `/api/templates/${this.templateId}/components`,
        'json',
        this.cacheVersion > 0 ? this.cacheVersion : null,
      );
      // 정적 게시본(bake) 우선 (#122) — 편집기 경로(v0)는 legacy 직행 유지.
      // miss 는 fetchStaticFirst 가 legacy 로 폴백하고, legacy 측이 fetchWithRetry 를 재사용한다.
      const response = await fetchStaticFirst(
        this.cacheVersion > 0
          ? extStaticUrl(`templates/${this.templateId}/components.json`, this.cacheVersion)
          : null,
        manifestUrl,
        { label: 'components.json' }
      );

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

    this.registerTemplateOverrides(module);
  }

  /**
   * 템플릿이 선언한 확장 컴포넌트 대체본을 등록합니다.
   *
   * 대체본은 확장의 좌표(`{확장식별자}.{이름}`)에 그대로 들어앉는다 — 마크업 태그가 바뀌면
   * 대체가 아니라 새 컴포넌트이기 때문이다. 확장 번들은 이보다 나중에 실행되므로
   * (TemplateApp :468 → :687) 대체한 이름을 기록해 두어야 확장의 원본이 되덮지 않는다.
   *
   * 버전 제약 검사는 **서버(컴파일러)가 이미 수행**했다 — 대상 확장의 실제 버전을 아는 것은
   * 서버이고, 비호환 선언은 컴파일 시점에 걸러져 원본으로 폴백된다. 런타임은 그 판정을
   * 다시 하지 않고 선언을 그대로 이행한다(판정이 두 곳에 있으면 서로 갈린다).
   *
   * @param module 템플릿 번들 전역 객체
   *
   * @since engine-v1.67.0
   */
  private registerTemplateOverrides(module: any): void {
    const overrides = this.manifest?.overrides ?? [];

    for (const declaration of overrides) {
      const exportName = declaration.exportName ?? declaration.name;
      const component = module[exportName];

      if (!component) {
        logger.warn(
          `[ComponentRegistry] 오버라이드 '${declaration.target}.${exportName}' 의 구현이 템플릿 번들에 없습니다 — 원본을 사용합니다`
        );
        continue;
      }

      // 좌표는 언제나 대상 확장이 붙인 이름이다. `exportName` 은 번들 전역의 평면성을 피하려고
      // 템플릿이 붙인 로컬 별칭일 뿐이라 좌표에 섞이면 컴파일러가 방출한 노드 이름
      // (`{확장식별자}.{이름}`) 과 갈라져 대체본이 아무도 안 보는 키에 등록된다.
      const registryKey = `${declaration.target}.${declaration.name}`;

      this.overriddenByTemplate.add(registryKey);
      this.registerComponent(registryKey, component, { ...declaration, name: registryKey });
    }
  }

  /**
   * 개별 컴포넌트 등록
   *
   * 등록 시 React.memo로 자동 래핑하여, props가 변경되지 않은 컴포넌트의
   * 불필요한 리렌더링을 방지합니다.
   *
   * @since engine-v1.25.0
   */
  private registerComponent(
    name: string,
    component: ComponentType<any>,
    metadata: ComponentMetadata
  ): void {
    if (this.registry[name]) {
      logger.warn(`Component '${name}' already registered, overwriting`);
    }

    // 성능 최적화: React.memo 자동 래핑 (engine-v1.25.0)
    // - basic/composite/layout 모든 타입에 적용
    // - forwardRef 컴포넌트도 memo(forwardRef(...)) 패턴으로 정상 작동
    // - 내부 useState/useRef는 memo와 무관 (외부 props 비교만 수행)
    // - worst case: memo 비교 실패 → 기존과 동일하게 리렌더 (회귀 없음)
    const memoizedComponent = React.memo(component);

    this.registry[name] = {
      component: memoizedComponent,
      metadata,
    };

    logger.log(`[ComponentRegistry] Registered component: ${name} (${metadata.type})`);
  }

  /**
   * 확장(모듈/플러그인)이 자기 컴포넌트를 등록합니다 — 확장 IIFE 전용 공개 API.
   *
   * 템플릿 컴포넌트는 매니페스트 → `window[PascalCase(templateId)]` 경로로 등록되지만
   * (`loadComponents`), 확장은 그 전역에 실릴 자리가 없다. 확장은 액션 핸들러와 같은 방식으로
   * 자기 번들에서 스스로 등록한다(`registerHandler` 선례).
   *
   * **호출 시점은 안전하다.** `TemplateApp.init()` 이 확장 번들 로드(:687)를 초기 라우트 처리
   * **전에** await 하므로, 확장 IIFE 의 등록은 첫 렌더보다 반드시 먼저 끝난다. 번들 로드가
   * 실패하면 등록은 영영 오지 않고 그 컴포넌트 노드는 조용히 사라지는데(DynamicRenderer 가
   * 미발견 시 null 반환), 그 침묵은 `getMissingExtensionComponents()` 로 관측한다.
   *
   * **판본 선택은 코어가 한다.** 확장은 `forTemplate` 으로 "이 판본은 그 템플릿용" 이라고
   * **선언만** 하고, 활성 템플릿과 대조해 고르는 판정은 여기서 전담한다. 확장이 활성 템플릿을
   * 읽어 스스로 분기하면 확장마다 판정 규칙과 폴백이 갈리고, 그 순간 선언형이 아니라 명령형이
   * 된다 — 확장 번들 priority 를 선언으로만 받고 코어가 정렬하는 규율과 같은 이유다.
   *
   * 등록 규칙:
   *  - `name` 은 반드시 `{확장식별자}.{컴포넌트명}` — 핸들러의 네임스페이스 관례와 같다.
   *    프리픽스가 없으면 템플릿 컴포넌트를 덮어쓸 수 있으므로 거부한다.
   *  - 템플릿이 그 이름을 오버라이드했으면(매니페스트 `overrides`) 템플릿이 이긴다 — 확장의
   *    등록은 무시된다. 대체 권한은 템플릿 전용이라는 규약의 런타임 쪽 절반이다.
   *  - `forTemplate` 이 활성 템플릿과 맞으면 기본 판본을 이긴다. 맞지 않으면 무시된다.
   *
   * @param name 컴포넌트 이름 (`{확장식별자}.{컴포넌트명}`)
   * @param component 컴포넌트 구현체
   * @param metadata 컴포넌트 메타데이터
   * @param options 등록 옵션 — `forTemplate` 은 이 판본이 전용으로 쓰일 템플릿 id
   * @returns **이 판본이 채택되었으면** true, 규칙에 따라 물러났으면 false (다른 판본이 이겼거나 템플릿이 대체했거나 접두어가 없는 경우)
   *
   * @since engine-v1.67.0
   */
  public registerExtensionComponent(
    name: string,
    component: ComponentType<any>,
    metadata: ComponentMetadata,
    options?: { forTemplate?: string }
  ): boolean {
    if (!name.includes('.')) {
      logger.error(
        `[ComponentRegistry] 확장 컴포넌트 이름에는 확장 식별자 접두어가 필요합니다: '${name}' → '{확장식별자}.${name}'`
      );
      return false;
    }

    const declarations = ComponentRegistry.extensionDeclarations.get(name) ?? [];
    const forTemplate = options?.forTemplate;
    const existing = declarations.findIndex(d => d.forTemplate === forTemplate);

    // 같은 자리를 두 번 선언하면 나중 것으로 갱신한다(핫 리로드 등). 판본이 다르면 나란히 쌓인다.
    if (existing >= 0) {
      declarations[existing] = { component, metadata, forTemplate };
    } else {
      declarations.push({ component, metadata, forTemplate });
    }

    ComponentRegistry.extensionDeclarations.set(name, declarations);

    this.applyExtensionDeclaration(name);

    // 이 인스턴스가 이 이름을 서빙하는지가 아니라 **방금 넘긴 이 판본이 채택됐는지**를
    // 돌려준다 — 호출자가 알고 싶은 것은 자기 판본의 운명이다.
    return this.appliedExtensionChoice.get(name) === component;
  }

  /**
   * 확장이 선언해 둔 컴포넌트를 이 인스턴스에 반영합니다.
   *
   * 확장 IIFE 는 페이지당 한 번만 실행되므로, **그 뒤에 만들어지는 레지스트리**(레이아웃 편집기의
   * 격리 인스턴스)는 이 메서드를 직접 불러야 확장 컴포넌트를 갖는다. 부르지 않으면 편집기
   * 미리보기에서 그 노드만 조용히 사라진다.
   *
   * 템플릿 오버라이드를 먼저 알아야 하므로 `loadComponents()` **뒤에** 호출한다.
   *
   * @returns 이 인스턴스에 반영된 컴포넌트 수
   *
   * @since engine-v1.67.0
   */
  public applyExtensionComponents(): number {
    let applied = 0;

    for (const name of ComponentRegistry.extensionDeclarations.keys()) {
      if (this.applyExtensionDeclaration(name)) applied++;
    }

    if (applied > 0) {
      logger.log(`[ComponentRegistry] 확장 컴포넌트 ${applied}개를 ${this.templateId} 에 반영했습니다`);
    }

    return applied;
  }

  /**
   * 이름 하나에 대해 이 인스턴스가 쓸 판본을 골라 등록합니다.
   *
   * 판정은 **선언 전체를 매번 다시 본다** — 그래서 확장이 기본 판본과 전용 판본을 어느 순서로
   * 등록하든 결과가 같다(순서에 결과가 걸리면 그것 자체가 침묵 승자다).
   *
   * @param name 컴포넌트 이름 (`{확장식별자}.{컴포넌트명}`)
   * @returns 등록되었으면 true, 규칙에 따라 물러났으면 false
   */
  private applyExtensionDeclaration(name: string): boolean {
    // 템플릿의 오버라이드가 이 자리를 차지했으면 확장의 판본은 모두 물러난다.
    if (this.overriddenByTemplate.has(name)) {
      logger.log(`[ComponentRegistry] '${name}' 은 템플릿이 오버라이드했습니다 — 확장 등록을 건너뜁니다`);
      return false;
    }

    const declarations = ComponentRegistry.extensionDeclarations.get(name) ?? [];
    const scoped = declarations.find(d => d.forTemplate === this.templateId);
    const base = declarations.find(d => d.forTemplate === undefined);
    const chosen = scoped ?? base;

    if (!chosen) {
      // 다른 템플릿 전용 판본만 선언된 경우 — 이 인스턴스에는 쓸 것이 없다.
      return false;
    }

    // 같은 구현체를 다시 넣으면 registerComponent 가 덮어쓰기 warn 을 낸다. 결과가 같은
    // 재적용은 조용히 통과시킨다(편집기가 applyExtensionComponents 를 다시 부를 수 있다).
    if (this.appliedExtensionChoice.get(name) === chosen.component) {
      return true;
    }

    this.appliedExtensionChoice.set(name, chosen.component);
    this.registerComponent(name, chosen.component, { ...chosen.metadata, name });

    return true;
  }

  /**
   * 템플릿이 오버라이드한 확장 컴포넌트 이름을 기록합니다.
   *
   * 템플릿 매니페스트의 `overrides` 선언이 이 목록을 채우며, 그 이름에 대한 확장의 자가등록은
   * 이후 무시된다. 확장 번들이 레지스트리 로딩보다 나중에 실행되므로(TemplateApp :468 → :687)
   * 이 기록이 없으면 확장의 원본이 템플릿의 대체본을 되덮는다.
   *
   * @param names 템플릿이 대체한 컴포넌트 이름 목록 (`{확장식별자}.{컴포넌트명}`)
   *
   * @since engine-v1.67.0
   */
  public markOverriddenByTemplate(names: string[]): void {
    names.forEach(name => this.overriddenByTemplate.add(name));
  }

  /**
   * 레이아웃이 쓰지만 아직 등록되지 않은 확장 컴포넌트 이름을 반환합니다.
   *
   * 미발견 컴포넌트는 렌더에서 조용히 사라지므로(DynamicRenderer 가 null 반환), 확장 번들
   * 로드 실패를 화면이 아니라 이 목록으로 관측한다.
   *
   * @param usedNames 레이아웃이 참조하는 컴포넌트 이름 목록
   * @returns 등록되지 않은 확장 컴포넌트 이름 목록
   *
   * @since engine-v1.67.0
   */
  public getMissingExtensionComponents(usedNames: string[]): string[] {
    return usedNames.filter(name => name.includes('.') && !this.registry[name]);
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
    this.overriddenByTemplate.clear();
    this.appliedExtensionChoice.clear();
    logger.log('Registry cleared');
  }
}

// 전역 export
export default ComponentRegistry;
