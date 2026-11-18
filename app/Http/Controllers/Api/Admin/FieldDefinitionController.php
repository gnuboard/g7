<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\FieldDefinitionConflictException;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldSet;
use App\Fields\FieldSetRegistry;
use App\Fields\FieldTypeRegistry;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Fields\ReorderFieldDefinitionsRequest;
use App\Http\Requests\Fields\ShowFieldDefinitionRequest;
use App\Http\Requests\Fields\StoreFieldDefinitionRequest;
use App\Http\Requests\Fields\UpdateFieldDefinitionRequest;
use App\Http\Resources\Fields\FieldDefinitionResource;
use App\Models\FieldDefinition;
use App\Services\FieldDefinitionService;
use App\Services\MemberFieldSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 관리자 — 항목 집합의 항목 CRUD·순서 컨트롤러.
 *
 * 환경설정 > 회원 > 가입 항목 표, 게시판 설정의 비회원 작성자 항목 표, 쇼핑몰 설정의 주문서 항목 표가 쓴다.
 * 권한은 라우트의 `field_set.permission` 미들웨어가 집합이 정한 조회·수정 권한으로 확인한다(회원 집합은 환경설정 권한).
 *
 * @since 7.2.0
 */
class FieldDefinitionController extends AdminBaseController
{
    /**
     * @param  FieldDefinitionService  $service  항목 관리 Service
     * @param  FieldSetRegistry  $sets  집합 등록소
     * @param  FieldTypeRegistry  $types  유형 등록소
     * @param  MemberFieldSettingsService  $memberSettings  회원 탭 안내 Service (가입 화면 수정본 경고)
     */
    public function __construct(
        protected FieldDefinitionService $service,
        protected FieldSetRegistry $sets,
        protected FieldTypeRegistry $types,
        protected MemberFieldSettingsService $memberSettings,
    ) {
        parent::__construct();
    }

    /**
     * 집합의 항목 목록을 조회합니다.
     *
     * @param  ShowFieldDefinitionRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @return JsonResponse 항목 목록, 정의 버전, 고를 수 있는 유형
     */
    public function index(ShowFieldDefinitionRequest $request, string $set): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        if ($fieldSet === null) {
            return $this->notFound();
        }

        return $this->success('common.success', $this->payload($request, $fieldSet));
    }

    /**
     * 항목 하나를 조회합니다 (수정 창 — 값 수와 선택지별 사용 수 포함).
     *
     * @param  ShowFieldDefinitionRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @param  int  $field  항목 ID
     * @return JsonResponse 항목 상세
     */
    public function show(ShowFieldDefinitionRequest $request, string $set, int $field): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        $definition = $fieldSet === null ? null : $this->service->find($set, $field);
        if ($fieldSet === null || $definition === null) {
            return $this->notFound();
        }

        return $this->success('common.success', $this->detail($request, $definition));
    }

    /**
     * 운영자 항목을 추가합니다.
     *
     * @param  StoreFieldDefinitionRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @return JsonResponse 추가한 항목과 새 정의 버전
     */
    public function store(StoreFieldDefinitionRequest $request, string $set): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        if ($fieldSet === null) {
            return $this->notFound();
        }
        if (! $fieldSet->allowsCustom) {
            return $this->forbidden('fields.errors.custom_not_allowed');
        }

        try {
            $this->service->assertVersion($set, $request->validated('fields_version'));
        } catch (FieldDefinitionConflictException $e) {
            return $this->conflict($e);
        }

        $definition = $this->service->create($fieldSet, $request->validated());

        return $this->success('common.created', [
            'field' => (new FieldDefinitionResource($this->service->presented($definition)))->toArray($request),
            'fields_version' => $this->service->version($set),
        ], 201);
    }

    /**
     * 항목을 수정합니다 (표의 스위치 한 칸 저장 포함).
     *
     * @param  UpdateFieldDefinitionRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @param  int  $field  항목 ID
     * @return JsonResponse 수정한 항목과 새 정의 버전
     */
    public function update(UpdateFieldDefinitionRequest $request, string $set, int $field): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        $definition = $request->targetDefinition();
        if ($fieldSet === null || $definition === null || $definition->id !== $field) {
            return $this->notFound();
        }

        try {
            $this->service->assertVersion($set, $request->validated('fields_version'));
        } catch (FieldDefinitionConflictException $e) {
            return $this->conflict($e);
        }

        $definition = $this->service->update($definition, $request->validated());

        return $this->success('common.updated', [
            'field' => (new FieldDefinitionResource($this->service->presented($definition)))->toArray($request),
            'fields_version' => $this->service->version($set),
        ]);
    }

    /**
     * 운영자 항목을 삭제합니다 (입력된 값도 함께 지운다).
     *
     * @param  ShowFieldDefinitionRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @param  int  $field  항목 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(ShowFieldDefinitionRequest $request, string $set, int $field): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        $definition = $fieldSet === null ? null : $this->service->find($set, $field);
        if ($fieldSet === null || $definition === null) {
            return $this->notFound();
        }
        if (! $definition->isCustom()) {
            // 선언 항목은 확장을 끄거나 지워야 사라진다 — 운영자는 「사용」을 끌 수 있을 뿐이다.
            return $this->forbidden('fields.errors.cannot_delete_declared');
        }

        try {
            $this->service->assertVersion($set, $request->validated('fields_version'));
            $removedValues = $this->service->delete($definition);
        } catch (FieldDefinitionConflictException $e) {
            return $this->conflict($e);
        }

        return $this->success('common.deleted', [
            'removed_values' => $removedValues,
            'fields_version' => $this->service->version($set),
        ]);
    }

    /**
     * 항목 순서를 바꿉니다.
     *
     * @param  ReorderFieldDefinitionsRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @return JsonResponse 새 순서의 항목 목록
     */
    public function reorder(ReorderFieldDefinitionsRequest $request, string $set): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        if ($fieldSet === null) {
            return $this->notFound();
        }

        try {
            $this->service->assertVersion($set, $request->validated('fields_version'));
        } catch (FieldDefinitionConflictException $e) {
            return $this->conflict($e);
        }

        $this->service->reorder($set, array_map('intval', $request->validated('order')));

        return $this->success('common.updated', $this->payload($request, $fieldSet));
    }

    /**
     * 항목이 비어 있는 기존 회원 수를 조회합니다 (선택을 필수로 바꾸기 전 안내).
     *
     * @param  ShowFieldDefinitionRequest  $request  검증된 요청
     * @param  string  $set  집합 식별자
     * @param  int  $field  항목 ID
     * @return JsonResponse 미입력 회원 수
     */
    public function missingCount(ShowFieldDefinitionRequest $request, string $set, int $field): JsonResponse
    {
        $fieldSet = $this->sets->get($set);
        $definition = $fieldSet === null ? null : $this->service->find($set, $field);
        if ($fieldSet === null || $definition === null) {
            return $this->notFound();
        }

        return $this->success('common.success', ['missing_count' => $this->service->missingCount($definition)]);
    }

    /**
     * 표 화면이 쓰는 묶음(항목 목록, 정의 버전, 유형 목록, 집합 정보)을 만듭니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  FieldSet  $fieldSet  집합
     * @return array<string, mixed> 응답 데이터
     */
    protected function payload(Request $request, FieldSet $fieldSet): array
    {
        $fields = $this->service->list($fieldSet->key)
            ->map(fn (FieldDefinition $definition) => (new FieldDefinitionResource($this->service->presented($definition)))->toArray($request))
            ->all();

        return [
            'set' => [
                'key' => $fieldSet->key,
                'label' => $fieldSet->localizedLabel(),
                'owner_type' => $fieldSet->ownerType,
                'owner' => $fieldSet->ownerIdentifier,
                'allows_custom' => $fieldSet->allowsCustom,
                'surfaces' => $fieldSet->surfaces,
            ],
            'fields' => $fields,
            'fields_version' => $this->service->version($fieldSet->key),
            'types' => $this->typeOptions($fieldSet),
            'abilities' => [
                'can_update' => PermissionHelper::check($fieldSet->managePermission, $request->user()),
            ],
            // 표 위에 띄울 경고 — 가입 화면이 운영자 수정본으로 유지돼 항목 설정이 보이지 않을 수 있는 경우
            'notices' => [
                'signup_layout_modified' => $fieldSet->key === FieldSet::CORE_USER && $this->memberSettings->signupLayoutModified(),
            ],
        ];
    }

    /**
     * 수정 창이 쓰는 항목 상세를 만듭니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  FieldDefinition  $definition  항목
     * @return array<string, mixed> 응답 데이터
     */
    protected function detail(Request $request, FieldDefinition $definition): array
    {
        $usage = $this->service->optionUsage($definition);
        $data = (new FieldDefinitionResource($this->service->presented($definition)))->toArray($request);
        $data['values_count'] = $this->service->valuesCount($definition);
        $data['options'] = array_map(
            fn ($option) => is_array($option)
                ? [...$option, 'usage_count' => (int) ($usage[(string) ($option['value'] ?? '')] ?? 0)]
                : $option,
            $definition->options ?? [],
        );
        $data['other_usage_count'] = (int) ($usage['__other__'] ?? 0);

        return ['field' => $data, 'fields_version' => $this->service->version($definition->field_set)];
    }

    /**
     * 집합이 받는 유형 목록을 만듭니다 (항목 추가 화면의 타일).
     *
     * @param  FieldSet  $fieldSet  집합
     * @return array<int, array<string, mixed>> 유형 목록
     */
    protected function typeOptions(FieldSet $fieldSet): array
    {
        $options = [];
        foreach ($this->types->all() as $key => $type) {
            if (! $fieldSet->allowsType($key)) {
                continue;
            }
            [$ownerType, $owner] = $this->types->ownerOf($key);
            $options[] = [
                'key' => $key,
                'label' => $type->label(),
                'description' => $type->description(),
                'category' => $type->category(),
                'renderer' => $type->renderer(),
                'owner_type' => $ownerType,
                'owner' => $owner,
                // 확장이 준 유형의 타일 배지 — 설치 기록의 다국어 이름에서 요청 언어로 고른다(코어 유형은 null)
                'owner_label' => in_array($ownerType, ['module', 'plugin'], true)
                    ? $this->service->extensionLabel($ownerType, (string) $owner)
                    : null,
                'available' => true,
                // 추가 창이 고른 유형에서 켤 수 있는 옵션을 가린다 (항목의 `supports` 와 같은 키)
                'supports' => [
                    'signup' => $type->supports(FieldTypeInterface::FEATURE_SIGNUP),
                    'unique' => $type->supports(FieldTypeInterface::FEATURE_UNIQUE),
                    'search' => $type->supports(FieldTypeInterface::FEATURE_SEARCH),
                    'list_column' => $type->supports(FieldTypeInterface::FEATURE_LIST_COLUMN),
                    'display_name' => $type->supports(FieldTypeInterface::FEATURE_DISPLAY_NAME),
                    'options' => $type->supports(FieldTypeInterface::FEATURE_OPTIONS),
                ],
            ];
        }

        return $options;
    }

    /**
     * 충돌 예외를 409 응답으로 바꿉니다.
     *
     * @param  FieldDefinitionConflictException  $e  충돌 예외
     * @return JsonResponse 409 응답 (`error_code` 포함)
     */
    protected function conflict(FieldDefinitionConflictException $e): JsonResponse
    {
        $response = $e->errorCode === FieldDefinitionConflictException::FIELD_IN_USE
            // 사용처 목록은 화면이 오류 본문(errors)에서 읽는다 — 같은 값을 data 에도 둔다(기존 계약).
            ? ResponseHelper::error('fields.errors.field_in_use', 409, ['usages' => $e->data['usages'] ?? []], [], 'core', $e->data)
            : ResponseHelper::error('fields.errors.fields_changed', 409, null, [], 'core', $e->data);
        $response->setData([...$response->getData(true), 'error_code' => $e->errorCode]);

        return $response;
    }
}
