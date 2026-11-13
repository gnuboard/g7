<?php

namespace Tests\Unit\Fields\Types;

use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldTypeRegistry;
use App\Models\FieldDefinition;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 항목 유형 단위 테스트 공통 기반.
 *
 * DB 를 쓰지 않는다 — 항목 정의는 저장하지 않은 모델로 만든다.
 */
abstract class FieldTypeTestCase extends TestCase
{
    /**
     * 유형을 등록소에서 꺼냅니다.
     *
     * @param  string  $key  유형 키
     * @return FieldTypeInterface 유형
     */
    protected function type(string $key): FieldTypeInterface
    {
        $type = app(FieldTypeRegistry::class)->get($key);
        $this->assertNotNull($type, "유형 {$key} 이 등록소에 없다");

        return $type;
    }

    /**
     * 저장하지 않은 항목 정의를 만듭니다.
     *
     * @param  string  $type  유형 키
     * @param  array<string, mixed>  $constraints  제한 값
     * @param  array<int, array<string, mixed>>  $options  선택지
     * @return FieldDefinition 항목 정의
     */
    protected function definition(string $type, array $constraints = [], array $options = []): FieldDefinition
    {
        return new FieldDefinition([
            'field_set' => 'core.user',
            'key' => 'sample',
            'type' => $type,
            'storage' => FieldDefinition::STORAGE_VALUE,
            'name' => ['ko' => '표본', 'en' => 'Sample'],
            'constraints' => $constraints,
            'options' => $options,
        ]);
    }

    /**
     * 유형 규칙으로 값을 검증했을 때 통과하는지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  입력값
     * @return bool 통과하면 true
     */
    protected function passes(FieldDefinition $definition, mixed $value): bool
    {
        $rules = $this->type($definition->type)->rules($definition, 'field');

        return Validator::make(['field' => $value], $rules)->passes();
    }

    /**
     * 값을 정규화, 직렬화한 뒤 되살린 결과를 반환합니다.
     *
     * 직렬화가 채우지 않은 칸은 null 로 둔다(저장소의 upsert 와 같은 동작).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $input  입력값
     * @return mixed 되살린 값
     */
    protected function roundTrip(FieldDefinition $definition, mixed $input): mixed
    {
        $type = $this->type($definition->type);
        $normalized = $type->normalize($definition, $input);
        $columns = array_merge(
            array_fill_keys(['value_text', 'value_index', 'value_number', 'value_date', 'value_date_end', 'value_json'], null),
            $type->serialize($definition, $normalized),
        );

        return $type->deserialize($definition, $columns);
    }
}
