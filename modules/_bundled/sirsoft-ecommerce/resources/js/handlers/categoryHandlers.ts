/**
 * 카테고리 관련 핸들러
 *
 * 상품 등록/수정 화면에서 카테고리 선택 기능을 처리합니다.
 * isolatedState를 활용하여 카테고리 선택 영역의 상태를 격리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Category')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Category]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Category]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Category]', ...args),
};

interface Category {
    id: number;
    name: Record<string, string>;
    children?: Category[];
    parent_id?: number | null;
    path?: string;
    depth?: number;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

interface IsolatedContextValue {
    state: Record<string, any>;
    setState: (path: string, value: any) => void;
    getState: (path: string) => any;
    mergeState: (updates: Record<string, any>) => void;
}

/**
 * 카테고리 트리에서 특정 카테고리 ID의 브레드크럼을 생성합니다.
 *
 * @param categories 카테고리 트리 배열
 * @param targetId 찾을 카테고리 ID
 * @param locale 언어 코드
 * @param path 현재까지의 경로 (재귀용)
 * @returns 브레드크럼 문자열 또는 null
 */
function buildBreadcrumbFromTree(
    categories: Category[],
    targetId: number,
    locale: string,
    path: string[] = []
): string | null {
    for (const cat of categories) {
        const catName = cat.name?.[locale] || cat.name?.ko || '';

        if (cat.id === targetId) {
            return [...path, catName].join(' > ');
        }

        if (cat.children && cat.children.length > 0) {
            const found = buildBreadcrumbFromTree(cat.children, targetId, locale, [...path, catName]);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

/**
 * form.categories 배열에서 selectedCategoryInfos 형식으로 변환합니다.
 * API에서 제공하는 breadcrumb 필드를 우선 사용합니다.
 *
 * @param formCategories API에서 가져온 상품의 카테고리 배열
 * @param categoriesTree 전체 카테고리 트리
 * @param locale 언어 코드
 * @returns selectedCategoryInfos 형식의 배열
 */
function initCategoryInfosFromFormCategories(
    formCategories: Array<{ id: number; name?: Record<string, string>; name_localized?: string; breadcrumb?: string }>,
    categoriesTree: Category[],
    locale: string
): Array<{ id: number; breadcrumb: string }> {
    return formCategories.map(cat => {
        // 1. API에서 제공하는 breadcrumb 우선 사용
        if (cat.breadcrumb) {
            return { id: cat.id, breadcrumb: cat.breadcrumb };
        }

        // 2. 카테고리 트리에서 브레드크럼 찾기
        const breadcrumb = buildBreadcrumbFromTree(categoriesTree, cat.id, locale);
        if (breadcrumb) {
            return { id: cat.id, breadcrumb };
        }

        // 3. 트리에서도 못 찾으면 이름만 사용
        const catName = cat.name?.[locale] || cat.name?.ko || cat.name_localized || '';
        return { id: cat.id, breadcrumb: catName };
    });
}

/**
 * 데스크톱에서 카테고리를 선택합니다.
 *
 * - depth에 해당하는 카테고리를 선택하고 하위 depth는 초기화
 * - selectedCategories 배열을 업데이트
 * - isolatedState를 사용하여 카테고리 선택 영역만 리렌더링
 *
 * @param action 액션 객체 (params.depth, params.category 필요)
 * @param context 액션 컨텍스트 (isolatedContext 포함)
 */
export function selectCategoryHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const depth = params.depth as number;
    const category = params.category as Category;

    if (depth === undefined || !category) {
        logger.warn('[selectCategory] Missing depth or category param');
        return;
    }

    // isolatedContext 우선 사용 (성능 최적화)
    const isolatedContext = (context as any).isolatedContext as IsolatedContextValue | null;

    if (isolatedContext) {
        const currentSelected = isolatedContext.state.selectedCategories || [null, null, null, null];

        // 동일한 카테고리 클릭 시 상태 업데이트 스킵 (성능 최적화)
        if (currentSelected[depth]?.id === category.id) {
            logger.log(`[selectCategory] Same category already selected at depth ${depth}, skipping`);
            return;
        }

        // 새로운 배열 생성 - depth까지는 유지, depth 위치에 새 카테고리, 이후는 null
        const newSelected = currentSelected.map((cat: Category | null, idx: number) => {
            if (idx < depth) return cat;
            if (idx === depth) return category;
            return null;
        });

        isolatedContext.mergeState({ selectedCategories: newSelected });
        logger.log(`[selectCategory] Selected category at depth ${depth} (isolated):`, category.id);
        return;
    }

    // 폴백: G7Core.state.setLocal() 사용
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[selectCategory] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const currentSelected = state.ui?.selectedCategories || [null, null, null, null];

    // 동일한 카테고리 클릭 시 상태 업데이트 스킵 (성능 최적화)
    if (currentSelected[depth]?.id === category.id) {
        logger.log(`[selectCategory] Same category already selected at depth ${depth}, skipping`);
        return;
    }

    // 새로운 배열 생성 - depth까지는 유지, depth 위치에 새 카테고리, 이후는 null
    const newSelected = currentSelected.map((cat: Category | null, idx: number) => {
        if (idx < depth) return cat;
        if (idx === depth) return category;
        return null;
    });

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            selectedCategories: newSelected,
        },
    });

    logger.log(`[selectCategory] Selected category at depth ${depth}:`, category.id);
}

/**
 * 모바일에서 카테고리를 선택합니다.
 *
 * - 카테고리 선택 후 자식이 있으면 다음 단계로 자동 이동
 * - isolatedState를 사용하여 카테고리 선택 영역만 리렌더링
 *
 * @param action 액션 객체 (params.depth, params.category 필요)
 * @param context 액션 컨텍스트 (isolatedContext 포함)
 */
export function selectCategoryMobileHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const depth = params.depth as number;
    const category = params.category as Category;

    if (depth === undefined || !category) {
        logger.warn('[selectCategoryMobile] Missing depth or category param');
        return;
    }

    // isolatedContext 우선 사용 (성능 최적화)
    const isolatedContext = (context as any).isolatedContext as IsolatedContextValue | null;

    if (isolatedContext) {
        const currentSelected = isolatedContext.state.selectedCategories || [null, null, null, null];
        const currentStep = isolatedContext.state.categoryStep || 1;

        // 새로운 배열 생성
        const newSelected = currentSelected.map((cat: Category | null, idx: number) => {
            if (idx < depth) return cat;
            if (idx === depth) return category;
            return null;
        });

        // 자식이 있으면 다음 단계로 이동
        const hasChildren = category.children && category.children.length > 0;
        const nextStep = hasChildren ? depth + 2 : currentStep;

        isolatedContext.mergeState({
            selectedCategories: newSelected,
            categoryStep: nextStep,
        });

        logger.log(`[selectCategoryMobile] Selected category at depth ${depth} (isolated):`, category.id, 'nextStep:', nextStep);
        return;
    }

    // 폴백: G7Core.state.setLocal() 사용
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[selectCategoryMobile] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const currentSelected = state.ui?.selectedCategories || [null, null, null, null];

    // 새로운 배열 생성
    const newSelected = currentSelected.map((cat: Category | null, idx: number) => {
        if (idx < depth) return cat;
        if (idx === depth) return category;
        return null;
    });

    // 자식이 있으면 다음 단계로 이동
    const hasChildren = category.children && category.children.length > 0;
    const nextStep = hasChildren ? depth + 2 : state.ui?.categoryStep;

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            selectedCategories: newSelected,
            categoryStep: nextStep,
        },
    });

    logger.log(`[selectCategoryMobile] Selected category at depth ${depth}:`, category.id, 'nextStep:', nextStep);
}

/**
 * 선택된 카테고리를 form.category_ids에 추가합니다.
 *
 * - 최대 5개까지 선택 가능
 * - 중복 선택 불가
 * - 첫 번째 선택된 카테고리가 primary_category_id로 설정됨
 * - selectedCategoryInfos에 브레드크럼 정보도 함께 저장
 * - isolatedContext에서 selectedCategories를 읽고, _local.form에 저장
 *
 * @param _action 액션 객체
 * @param context 액션 컨텍스트 (isolatedContext 포함)
 */
export function addCategoryToSelectionHandler(
    _action: ActionWithParams,
    context: ActionContext
): void {
    logger.log('[addCategoryToSelection] START');

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[addCategoryToSelection] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const locale = G7Core.locale?.current?.() || 'ko';

    // isolatedContext에서 selectedCategories 읽기 (우선), 없으면 _local.ui에서 읽기
    const isolatedContext = (context as any).isolatedContext as IsolatedContextValue | null;
    const selectedCats = isolatedContext
        ? (isolatedContext.state.selectedCategories || [])
        : (state.ui?.selectedCategories || []);

    logger.log('[addCategoryToSelection] Current state:', {
        selectedCats: selectedCats.map((c: Category | null) => c?.id ?? null),
        currentCategoryIds: state.form?.category_ids,
        currentSelectedCategoryInfos: state.selectedCategoryInfos,
        usingIsolatedContext: !!isolatedContext,
    });

    // 마지막으로 선택된 카테고리 가져오기
    const lastSelected = selectedCats.filter(Boolean).pop();

    if (!lastSelected) {
        logger.warn('[addCategoryToSelection] No category selected');
        return;
    }

    logger.log('[addCategoryToSelection] Last selected category:', lastSelected.id, lastSelected.name);

    const currentIds: number[] = state.form?.category_ids || [];

    // 최대 5개 제한
    if (currentIds.length >= 5) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.category.messages.max_5')
            ?? 'You can select up to 5 categories.'
        );
        return;
    }

    // 중복 체크
    if (currentIds.includes(lastSelected.id)) {
        logger.log('[addCategoryToSelection] Duplicate detected:', lastSelected.id, 'in', currentIds);
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.category.messages.already_selected')
            ?? 'This category is already selected.'
        );
        return;
    }

    // 브레드크럼 생성: 선택된 카테고리 경로를 ' > '로 연결
    const breadcrumb = selectedCats
        .filter(Boolean)
        .map((cat: Category) => cat.name?.[locale] || cat.name?.ko || '')
        .join(' > ');

    const newCategoryIds = [...currentIds, lastSelected.id];
    const primaryCategoryId = state.form?.primary_category_id || lastSelected.id;

    // 카테고리 트리 가져오기 (브레드크럼 생성용)
    const dataSources = G7Core.state.getDataSources?.() || {};
    const categoriesTree = dataSources.categories?.data?.data || [];

    // 기존 selectedCategoryInfos에 새 정보 추가
    // selectedCategoryInfos가 비어있지만 form.categories에 데이터가 있으면 먼저 초기화
    let currentInfos: Array<{ id: number; breadcrumb: string }> = state.selectedCategoryInfos || [];
    const formCategories = state.form?.categories || [];

    if (currentInfos.length === 0 && formCategories.length > 0) {
        logger.log('[addCategoryToSelection] Initializing from form.categories:', formCategories.length, 'items');
        currentInfos = initCategoryInfosFromFormCategories(formCategories, categoriesTree, locale);
    }

    const newInfos = [...currentInfos, { id: lastSelected.id, breadcrumb }];

    logger.log('[addCategoryToSelection] Before setLocal:', {
        newCategoryIds,
        primaryCategoryId,
        newInfos,
    });

    try {
        G7Core.state.setLocal({
            form: {
                ...state.form,
                category_ids: newCategoryIds,
                primary_category_id: primaryCategoryId,
            },
            selectedCategoryInfos: newInfos,
            hasChanges: true,
        });
        logger.log('[addCategoryToSelection] setLocal SUCCESS');
    } catch (error) {
        logger.error('[addCategoryToSelection] setLocal FAILED:', error);
    }

    // setLocal 후 상태 확인
    const afterState = G7Core.state.getLocal() || {};
    logger.log('[addCategoryToSelection] After setLocal:', {
        category_ids: afterState.form?.category_ids,
        selectedCategoryInfos: afterState.selectedCategoryInfos,
    });

    logger.log(`[addCategoryToSelection] Added category ${lastSelected.id} with breadcrumb: ${breadcrumb}. Total: ${newCategoryIds.length}`);
}

/**
 * 선택된 카테고리를 form.category_ids에서 제거합니다.
 *
 * - primary_category_id가 제거되면 첫 번째 남은 카테고리로 변경
 * - selectedCategoryInfos에서도 해당 카테고리 정보 제거
 *
 * @param action 액션 객체 (params.categoryId 필요)
 * @param _context 액션 컨텍스트
 */
export function removeCategoryFromSelectionHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const categoryId = params.categoryId as number;

    if (!categoryId) {
        logger.warn('[removeCategoryFromSelection] Missing categoryId param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeCategoryFromSelection] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const currentIds: number[] = state.form?.category_ids || [];

    const newIds = currentIds.filter((id: number) => id !== categoryId);

    // primary_category_id 업데이트
    let newPrimaryCategoryId = state.form?.primary_category_id;
    if (newPrimaryCategoryId === categoryId) {
        newPrimaryCategoryId = newIds[0] || null;
    }

    // selectedCategoryInfos에서도 해당 카테고리 제거
    const currentInfos: Array<{ id: number; breadcrumb: string }> = state.selectedCategoryInfos || [];
    const newInfos = currentInfos.filter((info) => info.id !== categoryId);

    G7Core.state.setLocal({
        form: {
            ...state.form,
            category_ids: newIds,
            primary_category_id: newPrimaryCategoryId,
        },
        selectedCategoryInfos: newInfos,
        hasChanges: true,
    });

    logger.log(`[removeCategoryFromSelection] Removed category ${categoryId}. Remaining: ${newIds.length}`);
}

/**
 * 카테고리 ID로 브레드크럼 문자열을 반환합니다.
 *
 * @param action 액션 객체 (params.categoryId, params.categories 필요)
 * @param _context 액션 컨텍스트
 * @returns 브레드크럼 문자열 (예: "의류 > 남성 > 상의")
 */
export function getCategoryBreadcrumbHandler(
    action: ActionWithParams,
    _context: ActionContext
): string {
    const params = action.params || {};
    const categoryId = params.categoryId as number;
    const categories = params.categories as Category[];

    if (!categoryId || !categories || !Array.isArray(categories)) {
        return '';
    }

    const G7Core = (window as any).G7Core;
    const locale = G7Core?.locale?.current?.() || 'ko';

    /**
     * 재귀적으로 카테고리 경로를 찾습니다.
     */
    const findPath = (
        cats: Category[],
        targetId: number,
        path: string[] = []
    ): string[] | null => {
        for (const cat of cats) {
            const catName = cat.name?.[locale] || cat.name?.ko || '';

            if (cat.id === targetId) {
                return [...path, catName];
            }

            if (cat.children && cat.children.length > 0) {
                const found = findPath(cat.children, targetId, [...path, catName]);
                if (found) {
                    return found;
                }
            }
        }
        return null;
    };

    const pathNames = findPath(categories, categoryId);
    return pathNames ? pathNames.join(' > ') : '';
}

/**
 * 상품 데이터 로드 시 categories 배열로부터 selectedCategoryInfos를 초기화합니다.
 *
 * - API 응답의 categories 배열에서 브레드크럼 정보 생성
 * - _local.selectedCategoryInfos를 설정하여 선택된 카테고리 UI 표시
 *
 * @param action 액션 객체 (params.categories: 상품의 카테고리 배열)
 * @param _context 액션 컨텍스트
 */
export function initCategoryInfosFromProductHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    logger.log('[initCategoryInfosFromProduct] START');

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[initCategoryInfosFromProduct] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const locale = G7Core.locale?.current?.() || 'ko';

    // categories 데이터소스에서 전체 카테고리 트리 가져오기
    const dataSources = G7Core.state.getDataSources?.() || {};
    const categoriesTree = dataSources.categories?.data?.data || [];

    // 상품의 카테고리 정보 (API 응답에서 가져온 것)
    const productCategories = state.form?.categories || [];
    const categoryIds = state.form?.category_ids || [];

    logger.log('[initCategoryInfosFromProduct] Product categories:', productCategories);
    logger.log('[initCategoryInfosFromProduct] Category IDs:', categoryIds);
    logger.log('[initCategoryInfosFromProduct] Categories tree available:', categoriesTree.length > 0);

    if (categoryIds.length === 0) {
        logger.log('[initCategoryInfosFromProduct] No categories to initialize');
        return;
    }

    // 카테고리 ID로 브레드크럼을 찾는 헬퍼 함수
    const findBreadcrumb = (
        cats: Category[],
        targetId: number,
        path: string[] = []
    ): string[] | null => {
        for (const cat of cats) {
            const catName = cat.name?.[locale] || cat.name?.ko || '';

            if (cat.id === targetId) {
                return [...path, catName];
            }

            if (cat.children && cat.children.length > 0) {
                const found = findBreadcrumb(cat.children, targetId, [...path, catName]);
                if (found) {
                    return found;
                }
            }
        }
        return null;
    };

    // selectedCategoryInfos 생성
    const selectedCategoryInfos: Array<{ id: number; breadcrumb: string }> = [];

    for (const categoryId of categoryIds) {
        // 먼저 카테고리 트리에서 브레드크럼 찾기
        const pathNames = findBreadcrumb(categoriesTree, categoryId);

        if (pathNames) {
            selectedCategoryInfos.push({
                id: categoryId,
                breadcrumb: pathNames.join(' > '),
            });
        } else {
            // 트리에서 못 찾으면 productCategories에서 직접 이름 사용
            const productCat = productCategories.find((c: any) => c.id === categoryId);
            if (productCat) {
                const catName = productCat.name?.[locale] || productCat.name?.ko || productCat.name_localized || '';
                selectedCategoryInfos.push({
                    id: categoryId,
                    breadcrumb: catName,
                });
            }
        }
    }

    logger.log('[initCategoryInfosFromProduct] Generated selectedCategoryInfos:', selectedCategoryInfos);

    // 상태 업데이트
    G7Core.state.setLocal({
        selectedCategoryInfos,
    });

    logger.log('[initCategoryInfosFromProduct] DONE');
}

/**
 * URL 파라미터로 받은 카테고리 경로를 검증하고 유효한 것만 반환합니다.
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 * @returns 유효한 카테고리 ID 객체 { category1, category2, category3, category4 }
 */
export function validateCategoryPathHandler(
    action: ActionWithParams,
    _context: ActionContext
): { category1: string; category2: string; category3: string; category4: string } {
    const result = {
        category1: '',
        category2: '',
        category3: '',
        category4: '',
    };

    const params = action.params || {};
    const categoryId1 = params.categoryId1 ? String(params.categoryId1) : '';
    const categoryId2 = params.categoryId2 ? String(params.categoryId2) : '';
    const categoryId3 = params.categoryId3 ? String(params.categoryId3) : '';
    const categoryId4 = params.categoryId4 ? String(params.categoryId4) : '';

    // 카테고리 데이터가 없으면 빈 결과 반환
    const categories = params.categories as Category[] | undefined;
    if (!categories || !Array.isArray(categories) || categories.length === 0) {
        logger.warn('[validateCategoryPath] No categories data provided');
        return result;
    }

    // category1 검증 (depth 0인 최상위 카테고리여야 함)
    if (categoryId1) {
        const cat1 = categories.find((cat) => String(cat.id) === categoryId1);
        if (cat1 && cat1.depth === 0) {
            result.category1 = categoryId1;

            // category2 검증 (category1의 자식이어야 함)
            if (categoryId2 && cat1.children) {
                const cat2 = cat1.children.find((cat) => String(cat.id) === categoryId2);
                if (cat2 && cat2.depth === 1) {
                    result.category2 = categoryId2;

                    // category3 검증 (category2의 자식이어야 함)
                    if (categoryId3 && cat2.children) {
                        const cat3 = cat2.children.find((cat) => String(cat.id) === categoryId3);
                        if (cat3 && cat3.depth === 2) {
                            result.category3 = categoryId3;

                            // category4 검증 (category3의 자식이어야 함)
                            if (categoryId4 && cat3.children) {
                                const cat4 = cat3.children.find((cat) => String(cat.id) === categoryId4);
                                if (cat4 && cat4.depth === 3) {
                                    result.category4 = categoryId4;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    logger.log('[validateCategoryPath] Result:', result);
    return result;
}
