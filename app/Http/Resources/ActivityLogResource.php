<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * 활동 로그 API 리소스
 */
class ActivityLogResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_type' => $this->log_type->value,
            'log_type_label' => $this->log_type_label,
            'loggable_type' => $this->loggable_type,
            'loggable_type_display' => $this->loggable_type_display,
            'loggable_id' => $this->loggable_id,
            'action' => $this->action,
            'action_label' => $this->action_label,
            'localized_description' => $this->resolveUnreplacedCount(
                $this->localized_description,
                $this->changes
            ),
            'description_key' => $this->description_key,
            'properties' => $this->properties,
            ...$this->formatChangesOutput($this->changes),
            'has_changes' => ! empty($this->changes),
            'actor_name' => $this->actor_name,
            'user' => $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : [
                'name' => __('common.system'),
            ],
            'ip_address' => $this->ip_address,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * changes 데이터를 단일/일괄 구분하여 반환합니다.
     *
     * 단일 수정: changes = [{field, old, new}, ...]
     * 일괄 수정: bulk_changes = [{model_id, changes: [{field, old, new}, ...]}, ...]
     *
     * @param array|null $changes 원본 changes 데이터
     * @return array changes + bulk_changes 키를 포함하는 배열
     */
    private function formatChangesOutput(?array $changes): array
    {
        if ($changes === null) {
            return ['changes' => null, 'bulk_changes' => null];
        }

        // 일괄 수정 여부 감지: 첫 번째 값이 배열의 배열이면 일괄 (key=모델ID)
        $firstValue = reset($changes);
        $isBulk = is_array($firstValue) && isset($firstValue[0]) && is_array($firstValue[0]);

        if ($isBulk) {
            $bulkChanges = [];
            foreach ($changes as $modelId => $modelChanges) {
                $bulkChanges[] = [
                    'model_id' => $modelId,
                    'changes' => $this->translateChangeGroup($modelChanges),
                ];
            }

            return ['changes' => null, 'bulk_changes' => $bulkChanges];
        }

        return ['changes' => $this->translateChangeGroup($changes), 'bulk_changes' => null];
    }

    /**
     * 단일 changes 그룹의 label_key 및 enum/boolean label을 번역합니다.
     *
     * 하위 호환: 기존 DB 레코드에서 type이 'text'로 저장되어 있지만
     * 모델의 현재 $activityLogFields에서 'enum'으로 정의된 필드는
     * 동적으로 enum 라벨을 해석합니다.
     *
     * @param array $changes 변경 항목 배열
     * @return array 번역된 변경 항목 배열
     */
    private function translateChangeGroup(array $changes): array
    {
        $modelFields = $this->resolveModelActivityLogFields();

        return array_map(function (array $change) use ($modelFields) {
            $change['label'] = isset($change['label_key']) ? __($change['label_key']) : ($change['field'] ?? '');

            $storedType = $change['type'] ?? 'text';
            $field = $change['field'] ?? null;

            // 하위 호환: 저장된 type이 'text'지만 모델에서 'enum'으로 정의된 경우 동적 해석
            if ($storedType === 'text' && $field && isset($modelFields[$field])) {
                $currentMeta = $modelFields[$field];
                if (($currentMeta['type'] ?? 'text') === 'enum' && isset($currentMeta['enum'])) {
                    $storedType = 'enum';
                    $change['type'] = 'enum';
                    $enumClass = $currentMeta['enum'];
                    $change['old_label_key'] = isset($change['old']) ? $enumClass::tryFrom($change['old'])?->labelKey() : null;
                    $change['new_label_key'] = isset($change['new']) ? $enumClass::tryFrom($change['new'])?->labelKey() : null;
                }
            }

            if ($storedType === 'enum') {
                $change['old_label'] = isset($change['old_label_key']) ? __($change['old_label_key']) : null;
                $change['new_label'] = isset($change['new_label_key']) ? __($change['new_label_key']) : null;
            }

            if ($storedType === 'boolean') {
                $change['old_label'] = $change['old'] ? __('common.yes') : __('common.no');
                $change['new_label'] = $change['new'] ? __('common.yes') : __('common.no');
            }

            if ($storedType === 'json') {
                $change['old'] = $this->extractLocaleValue($change['old']);
                $change['new'] = $this->extractLocaleValue($change['new']);
            }

            return $change;
        }, $changes);
    }

    /**
     * 다국어 JSON 값에서 현재 로케일 텍스트를 추출합니다.
     *
     * @param mixed $value 원본 값 (배열이면 로케일 추출, 아니면 그대로 반환)
     * @return mixed 로케일 텍스트 또는 원본 값
     */
    private function extractLocaleValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $locale = App::getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $value[$locale] ?? $value[$fallback] ?? reset($value) ?: $value;
    }

    /**
     * loggable_type으로부터 모델의 $activityLogFields 메타데이터를 해석합니다.
     *
     * 하위 호환을 위해 기존 DB 레코드의 type: 'text' 필드를
     * 현재 모델 정의와 비교하여 enum 타입을 동적으로 판별합니다.
     *
     * @return array<string, array> 필드 메타데이터 (field => meta 매핑)
     */
    private function resolveModelActivityLogFields(): array
    {
        $loggableType = $this->loggable_type;
        if (! $loggableType || ! class_exists($loggableType)) {
            return [];
        }

        if (! property_exists($loggableType, 'activityLogFields')) {
            return [];
        }

        return $loggableType::$activityLogFields;
    }

    /**
     * 일괄 변경 로그에서 description_params에 count가 누락된 경우 보정합니다.
     *
     * 하위 호환: 기존 DB 레코드에서 count 파라미터가 누락되어
     * `:count` 플레이스홀더가 미치환되는 문제를 방지합니다.
     *
     * @param string $description 번역된 설명 문자열
     * @param array|null $changes 변경 사항 데이터
     * @return string 보정된 설명 문자열
     */
    private function resolveUnreplacedCount(string $description, ?array $changes): string
    {
        if (! str_contains($description, ':count') || $changes === null) {
            return $description;
        }

        // bulk 형식 감지: 첫 번째 값이 배열의 배열이면 일괄 수정
        $firstValue = reset($changes);
        $isBulk = is_array($firstValue) && isset($firstValue[0]) && is_array($firstValue[0]);

        if ($isBulk) {
            $count = count($changes);

            return str_replace(':count', (string) $count, $description);
        }

        return $description;
    }

    /**
     * 권한 체크 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_read' => 'core.activities.read',
            'can_delete' => 'core.activities.delete',
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null
     */
    protected function ownerField(): ?string
    {
        return 'user_id';
    }
}
