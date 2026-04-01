/**
 * 상품 라벨 핸들러
 *
 * 상품 폼에서 라벨 할당 관리를 위한 핸들러들입니다.
 * - toggleLabelAssignment: ChipCheckbox 클릭 시 라벨 할당/해제 토글
 * - saveLabelSettings: 라벨 설정 모달에서 name/color API 저장 + 기간 로컬 업데이트
 */

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 * ActionDispatcher는 (action, context) 형태로 핸들러를 호출합니다.
 */
interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 액션 컨텍스트 인터페이스
 */
interface ActionContext {
    data?: any;
    state?: any;
    setState?: (updates: any) => void;
}

/**
 * 라벨 할당 데이터 인터페이스
 */
interface LabelAssignment {
    id?: number;
    label_id: number;
    started_at?: string | null;
    ended_at?: string | null;
}

/**
 * 라벨 할당 토글 핸들러
 *
 * ChipCheckbox 클릭 시 라벨을 할당하거나 해제합니다.
 * - 이미 할당됨 → 제거
 * - 미할당 → 추가 (started_at: null, ended_at: null)
 * - lastClickedLabelId를 설정하여 미리보기 영역에 반영
 *
 * @param action 액션 객체 (params.labelId 필수)
 * @param _context 액션 컨텍스트 (미사용)
 */
export const toggleLabelAssignmentHandler = (
    action: ActionWithParams,
    _context: ActionContext
): void => {
    const G7Core = (window as any).G7Core;

    // v1.17.6: getLocal()이 템플릿 엔진 레벨에서 globalLocal + pendingState를 병합하므로
    // 핸들러에서는 단순히 getLocal()만 호출하면 됨
    const localState = G7Core?.state?.getLocal?.() || {};
    const formLabelAssignments = localState.form?.label_assignments ?? [];

    const labelId = Number(action.params?.labelId);
    if (!labelId) {
        return;
    }

    const currentAssignments = [...formLabelAssignments] as LabelAssignment[];
    const existingAssignment = currentAssignments.find(a => a.label_id === labelId);

    // 이미 할당되어 있고, 기간이 설정된 경우 → 확인 모달 표시
    // v1.16.0: 모달에서 $parent 바인딩으로 부모 _local에 직접 접근 가능
    // - labelAssignmentsSnapshot 복사 제거 → confirmUncheckLabelHandler에서 getParent() 사용
    // - 날짜도 $parent._local.form.label_assignments에서 직접 조회 → _global 복사 제거
    if (existingAssignment && (existingAssignment.started_at || existingAssignment.ended_at)) {
        G7Core?.state?.setGlobal?.({
            labelToUncheckId: labelId,
        });
        G7Core?.modal?.open?.('modal_label_uncheck_confirm');
        return;
    }

    let updatedAssignments: LabelAssignment[];
    if (existingAssignment) {
        // 이미 할당됨 (기간 미설정) → 바로 제거
        updatedAssignments = currentAssignments.filter(a => a.label_id !== labelId);
    } else {
        // 미할당 → 추가
        updatedAssignments = [...currentAssignments, {
            label_id: labelId,
            started_at: null,
            ended_at: null,
        }];
    }

    G7Core?.state?.setLocal?.({
        'form.label_assignments': updatedAssignments,
        'ui.lastClickedLabelId': labelId,
        hasChanges: true,
    });
};

/**
 * 인라인 기간 업데이트 핸들러
 *
 * 미리보기 영역의 날짜 입력 필드 변경 시 호출됩니다.
 * _local.form.label_assignments의 해당 라벨 기간을 직접 업데이트합니다.
 *
 * @param action 액션 객체 (params.labelId, params.field, params.value 필수)
 * @param _context 액션 컨텍스트 (미사용)
 */
export const updateLabelPeriodInlineHandler = (
    action: ActionWithParams,
    _context: ActionContext
): void => {
    const G7Core = (window as any).G7Core;

    // v1.17.6: getLocal()이 템플릿 엔진 레벨에서 병합 처리
    const localState = G7Core?.state?.getLocal?.() || {};
    const formLabelAssignments = localState.form?.label_assignments ?? [];

    const labelId = Number(action.params?.labelId);
    const field = action.params?.field as 'started_at' | 'ended_at';
    const value = action.params?.value as string | null;

    if (!labelId || !field) {
        return;
    }

    const currentAssignments = [...formLabelAssignments] as LabelAssignment[];
    const idx = currentAssignments.findIndex(a => a.label_id === labelId);

    if (idx < 0) {
        return;
    }

    currentAssignments[idx] = {
        ...currentAssignments[idx],
        [field]: value || null,
    };

    G7Core?.state?.setLocal?.({
        'form.label_assignments': currentAssignments,
        hasChanges: true,
    });
};

/**
 * 인라인 기간 프리셋 핸들러
 *
 * 미리보기 영역의 프리셋 버튼 클릭 시 호출됩니다.
 * 현재 선택된 라벨(lastClickedLabelId)에 프리셋 기간을 적용합니다.
 *
 * @param action 액션 객체 (params.preset: '7d' | '14d' | '30d' | 'permanent')
 * @param _context 액션 컨텍스트 (미사용)
 */
export const setLabelDatePresetInlineHandler = (
    action: ActionWithParams,
    _context: ActionContext
): void => {
    const G7Core = (window as any).G7Core;

    // v1.17.6: getLocal()이 템플릿 엔진 레벨에서 병합 처리
    const localState = G7Core?.state?.getLocal?.() || {};
    const formLabelAssignments = localState.form?.label_assignments ?? [];
    const labelId = localState.ui?.lastClickedLabelId as number;
    const preset = action.params?.preset as string;

    if (!labelId || !preset) {
        return;
    }

    const currentAssignments = [...formLabelAssignments] as LabelAssignment[];
    const idx = currentAssignments.findIndex(a => a.label_id === labelId);

    if (idx < 0) {
        return;
    }

    const today = new Date();
    const formatDate = (date: Date): string => {
        return date.toISOString().split('T')[0];
    };

    const startDate = formatDate(today);
    let endDate: string | null = null;

    switch (preset) {
        case '7d':
            endDate = formatDate(new Date(today.getTime() + 7 * 24 * 60 * 60 * 1000));
            break;
        case '14d':
            endDate = formatDate(new Date(today.getTime() + 14 * 24 * 60 * 60 * 1000));
            break;
        case '30d':
            endDate = formatDate(new Date(today.getTime() + 30 * 24 * 60 * 60 * 1000));
            break;
        case 'permanent':
            endDate = null;
            break;
    }

    currentAssignments[idx] = {
        ...currentAssignments[idx],
        started_at: startDate,
        ended_at: endDate,
    };

    G7Core?.state?.setLocal?.({
        'form.label_assignments': currentAssignments,
        hasChanges: true,
    });
};

/**
 * 라벨 체크해제 확인 핸들러
 *
 * 기간이 설정된 라벨의 체크해제 확인 모달에서 "해제" 클릭 시 호출됩니다.
 * _global.labelToUncheckId 라벨을 부모 레이아웃의 label_assignments에서 제거합니다.
 *
 * v1.16.0부터 $parent 바인딩 컨텍스트를 사용하여 부모의 _local을 직접 수정합니다.
 * - 이전: _global.labelAssignmentsSnapshot 사용 (복사 후 필터링)
 * - 현재: G7Core.state.getParent()._local 직접 접근 + setParentLocal() 수정
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트 (미사용)
 */
export const confirmUncheckLabelHandler = (
    action: ActionWithParams,
    _context: ActionContext
): void => {
    const G7Core = (window as any).G7Core;
    const currentState = G7Core?.state?.get() || {};

    const labelId = currentState.labelToUncheckId as number;
    if (!labelId) {
        return;
    }

    // $parent 바인딩 컨텍스트를 통해 부모의 _local에서 직접 읽기
    const parentContext = G7Core?.state?.getParent?.();
    const parentLocal = parentContext?._local || {};
    const currentAssignments = [...(parentLocal.form?.label_assignments ?? [])] as LabelAssignment[];

    // 해당 라벨 필터링
    const updatedAssignments = currentAssignments.filter(a => a.label_id !== labelId);

    // 부모의 _local 직접 수정
    G7Core?.state?.setParentLocal?.({
        'form.label_assignments': updatedAssignments,
        hasChanges: true,
    });

    // _global 정리 (labelToUncheckId만 사용, 날짜는 $parent에서 직접 조회)
    G7Core?.state?.setGlobal?.({
        labelToUncheckId: null,
    });

    // 모달 닫기
    G7Core?.modal?.close?.();
};

/**
 * 라벨 설정 저장 핸들러
 *
 * 모달에서 편집한 라벨 정보를 저장합니다.
 * - name/color → PUT API로 product_labels 원본 즉시 수정 (모든 상품에 반영)
 * - started_at/ended_at → 로컬 상태의 form.label_assignments 업데이트 (상품 저장 시 반영)
 * - 성공 시 product_labels 데이터소스 리프레시 + 토스트 + 모달 닫기
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트 (미사용)
 */
/**
 * 라벨 삭제 핸들러
 *
 * 라벨을 DB에서 완전히 삭제합니다.
 * - DELETE API 호출로 product_labels 테이블에서 삭제
 * - 성공 시: product_labels 데이터소스 리프레시, 로컬 label_assignments에서 제거, 모달 닫기
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트 (미사용)
 */
export const deleteLabelHandler = async (
    action: ActionWithParams,
    _context: ActionContext
): Promise<void> => {
    const G7Core = (window as any).G7Core;
    const currentState = G7Core?.state?.get() || {};

    // v1.17.6: getLocal()이 템플릿 엔진 레벨에서 병합 처리
    const localState = G7Core?.state?.getLocal?.() || {};
    const formLabelAssignments = localState.form?.label_assignments ?? [];

    const labelToDeleteId = currentState.labelToDeleteId as number | null;
    if (!labelToDeleteId) {
        return;
    }

    G7Core?.state?.setGlobal?.({ isDeletingLabel: true });

    try {
        // 1. API 호출로 DB에서 삭제
        const response = await G7Core.api.delete(
            `/api/modules/sirsoft-ecommerce/admin/product-labels/${labelToDeleteId}`
        );

        if (!response?.success) {
            throw new Error(response?.message || 'Failed to delete label');
        }

        // 2. 로컬 상태에서 해당 라벨 제거
        const currentAssignments = [...formLabelAssignments] as LabelAssignment[];
        const updatedAssignments = currentAssignments.filter(a => a.label_id !== labelToDeleteId);

        G7Core?.state?.setLocal?.({
            'form.label_assignments': updatedAssignments,
            'ui.lastClickedLabelId': null,
        });

        // 3. 데이터소스 리프레시
        await G7Core?.dataSource?.refetch?.('product_labels');

        G7Core?.toast?.success?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.labels.delete_success') ?? 'Label deleted.'
        );
        G7Core?.modal?.close?.();
    } catch (error: any) {
        G7Core?.toast?.error?.(
            error?.response?.data?.message
            || error?.message
            || G7Core.t?.('sirsoft-ecommerce.admin.product.labels.delete_error')
            || 'Failed to delete label.'
        );
    } finally {
        G7Core?.state?.setGlobal?.({ isDeletingLabel: false, labelToDeleteId: null });
    }
};

export const saveLabelSettingsHandler = async (
    action: ActionWithParams,
    _context: ActionContext
): Promise<void> => {
    const G7Core = (window as any).G7Core;
    const currentState = G7Core?.state?.get() || {};

    // v1.17.6: getLocal()이 템플릿 엔진 레벨에서 병합 처리
    const localState = G7Core?.state?.getLocal?.() || {};
    const formLabelAssignments = localState.form?.label_assignments ?? [];

    const editingLabelId = currentState.editingLabelId as number | null;
    const labelFormData = currentState.labelFormData as {
        name: { ko: string; en: string };
        color: string | null;
        started_at: string | null;
        ended_at: string | null;
    };

    if (!editingLabelId || !labelFormData?.name?.ko) {
        return;
    }

    G7Core?.state?.setGlobal?.({ isSavingLabel: true });

    try {
        // 1. 라벨 원본 업데이트 (name, color) - API 즉시 저장
        const response = await G7Core.api.put(
            `/api/modules/sirsoft-ecommerce/admin/product-labels/${editingLabelId}`,
            {
                name: labelFormData.name,
                color: labelFormData.color,
            }
        );

        if (!response?.success) {
            throw new Error(response?.message || 'Failed to update label');
        }

        // 2. 로컬 상태에서 기간 업데이트 (상품 저장 시 반영)
        const currentAssignments = [...formLabelAssignments] as LabelAssignment[];
        const updatedAssignments = currentAssignments.map(a => {
            if (a.label_id === editingLabelId) {
                return {
                    ...a,
                    started_at: labelFormData.started_at || null,
                    ended_at: labelFormData.ended_at || null,
                };
            }
            return a;
        });

        G7Core?.state?.setLocal?.({
            'form.label_assignments': updatedAssignments,
            hasChanges: true,
        });

        // 3. 데이터소스 리프레시 (칩 표시에 변경된 name/color 반영)
        await G7Core?.dataSource?.refetch?.('product_labels');

        G7Core?.toast?.success?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.labels.save_success') ?? 'Label saved.'
        );
        G7Core?.modal?.close?.();
    } catch (error: any) {
        G7Core?.toast?.error?.(
            error?.response?.data?.message
            || error?.message
            || G7Core.t?.('sirsoft-ecommerce.admin.product.labels.save_error')
            || 'Failed to save label.'
        );
    } finally {
        G7Core?.state?.setGlobal?.({ isSavingLabel: false });
    }
};
