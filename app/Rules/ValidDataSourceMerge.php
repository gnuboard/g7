<?php

namespace App\Rules;

use App\Models\TemplateLayout;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDataSourceMerge implements ValidationRule, DataAwareRule
{
    /**
     * 검증할 전체 데이터
     */
    protected array $data = [];

    /**
     * 전체 데이터 설정
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * 데이터 소스 병합 검증
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // data_sources 필드가 없으면 검증 통과
        if (empty($value)) {
            return;
        }

        // data_sources는 배열이어야 함
        if (!is_array($value)) {
            $fail(__('validation.layout_inheritance.data_sources_must_be_array'));

            return;
        }

        // 현재 레이아웃의 data_sources ID 수집
        $currentIds = [];
        foreach ($value as $index => $dataSource) {
            if (!isset($dataSource['id'])) {
                $fail(__('validation.layout_inheritance.data_source_must_have_id', ['index' => $index]));

                return;
            }

            if (!is_string($dataSource['id'])) {
                $fail(__('validation.layout_inheritance.data_source_id_must_be_string', ['index' => $index]));

                return;
            }

            $currentIds[] = $dataSource['id'];
        }

        // ID 중복 검증 (현재 레이아웃 내에서)
        $duplicates = $this->findDuplicates($currentIds);
        if (!empty($duplicates)) {
            $fail(__('validation.layout_inheritance.data_source_id_duplicate', ['id' => $duplicates[0]]));

            return;
        }

        // extends가 있으면 부모 레이아웃과의 ID 중복 검증
        $extends = $this->data['content']['extends'] ?? null;
        if (!$extends) {
            return; // extends가 없으면 추가 검증 불필요
        }

        // template_id가 필요함
        $templateId = $this->data['template_id'] ?? null;
        if (!$templateId) {
            return; // template_id가 없으면 ValidParentLayout에서 처리
        }

        // 부모 레이아웃의 모든 data_sources ID 수집 (상속 체인 전체)
        $parentIds = $this->collectParentDataSourceIds($templateId, $extends);

        // 부모와 중복되는 ID 확인
        $duplicatesWithParent = array_intersect($currentIds, $parentIds);
        if (!empty($duplicatesWithParent)) {
            $fail(__('validation.layout_inheritance.data_source_id_duplicate', ['id' => reset($duplicatesWithParent)]));

            return;
        }
    }

    /**
     * 배열에서 중복 값 찾기
     */
    private function findDuplicates(array $array): array
    {
        return array_values(array_unique(array_diff_assoc($array, array_unique($array))));
    }

    /**
     * 부모 레이아웃의 모든 data_sources ID 수집 (상속 체인 전체)
     */
    private function collectParentDataSourceIds(int $templateId, string $layoutName): array
    {
        $allIds = [];
        $visited = [];
        $currentName = $layoutName;

        while ($currentName) {
            // 무한 루프 방지
            if (in_array($currentName, $visited)) {
                break;
            }

            $visited[] = $currentName;

            // 레이아웃 조회
            $layout = TemplateLayout::where('template_id', $templateId)
                ->where('name', $currentName)
                ->first();

            if (!$layout) {
                break;
            }

            // content 파싱
            $content = is_string($layout->content)
                ? json_decode($layout->content, true)
                : $layout->content;

            // data_sources ID 수집
            if (isset($content['data_sources']) && is_array($content['data_sources'])) {
                foreach ($content['data_sources'] as $dataSource) {
                    if (isset($dataSource['id']) && is_string($dataSource['id'])) {
                        $allIds[] = $dataSource['id'];
                    }
                }
            }

            // 다음 부모로 이동
            $currentName = $content['extends'] ?? null;
        }

        return $allIds;
    }
}
