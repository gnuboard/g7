<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Types;

use Closure;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Exports\Declarations\ModelDeclaration;
use Modules\Sirsoft\Ecommerce\Exports\Declarations\ModelDeclarationRegistry;
use Modules\Sirsoft\Ecommerce\Exports\ExportField;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;

/**
 * 내보내기 유형의 공통 골격 — 모델 필드 선언(D19)을 필드로 바꾸는 규칙 한곳
 *
 * 모델마다 선언 클래스({@see ModelDeclaration})가 두 목록을 갖는다.
 * - `fields()` : 내보낼 컬럼 → `['type' => …, 'personal' => bool, 'label_key' => …?, 'summable' => bool?, 'enum' => class?]`.
 *   `type`·`personal` 은 필수다(`personal` 을 빠뜨리면 개인정보가 일반 필드로 나갈 수 있으므로 기본값을 두지 않는다).
 * - `excluded()` : 내보내지 않을 컬럼 → 사유
 *
 * 새 컬럼은 스키마 동기화 테스트가 「어느 목록에도 없음」으로 잡는다. 선언만 하고 화면 컬럼 묶음에 넣지
 * 않은 필드는 자동으로 「추가 필드」(extra) 그룹에 들어간다 — 유형 코드를 고치지 않아도 모달·파일에 나온다.
 */
abstract class AbstractExportType implements ExportTypeInterface
{
    /**
     * 행 단위별 필드 캐시
     *
     * @var array<string, array<string, ExportField>>
     */
    private array $fieldCache = [];

    /**
     * 행 단위의 필드를 만듭니다(확장 훅 적용 전).
     *
     * @param  string  $unit  행 단위
     * @return array<int, ExportField> 필드 목록
     */
    abstract protected function buildFields(string $unit): array;

    /**
     * 확장이 필드를 더하는 필터를 발행합니다.
     *
     * 훅 이름은 유형마다 고정 문자열로 적는다(`sirsoft-ecommerce.export.{유형}.fields`) — 이름을 조립하면
     * 구독자가 훅 목록에서 그 이름을 찾을 수 없다. 확장 유형은 자기 이름의 훅을 발행하거나 그대로 돌려준다.
     *
     * @param  array<int, ExportField>  $fields  유형이 만든 필드
     * @param  string  $unit  행 단위
     * @return mixed 확장이 더한 필드 목록 (형식이 맞지 않으면 무시된다)
     */
    abstract protected function extendFields(array $fields, string $unit): mixed;

    /**
     * {@inheritDoc}
     */
    public function fields(string $unit): array
    {
        if (isset($this->fieldCache[$unit])) {
            return $this->fieldCache[$unit];
        }

        $fields = $this->buildFields($unit);

        // 확장이 필드를 더한다 — 선언과 같은 형식(ExportField)만 받는다
        $extended = $this->extendFields($fields, $unit);

        $keyed = [];
        foreach (is_array($extended) ? $extended : $fields as $field) {
            if (! $field instanceof ExportField) {
                Log::warning('[내보내기] 형식이 맞지 않는 확장 필드를 무시했습니다', ['type' => $this->key(), 'unit' => $unit]);

                continue;
            }
            if ($field->availableIn($unit)) {
                $keyed[$field->key] = $field;
            }
        }

        return $this->fieldCache[$unit] = $keyed;
    }

    /**
     * 모델의 필드 선언을 필드 목록으로 바꿉니다.
     *
     * @param  class-string  $modelClass  모델 클래스
     * @param  string  $source  필드 키 접두 (예: order, address)
     * @param  Closure  $sourceOf  행 → 이 모델 인스턴스 (fn (object $row): ?Model)
     * @param  array<string, string>  $groups  컬럼 → 화면 컬럼 키 (없으면 extra)
     * @param  array<int, string>  $units  쓸 수 있는 행 단위 (빈 배열이면 전 단위)
     * @param  bool  $parentLevel  하위 단위 행에서 첫 행에만 쓸 상위 금액인지 (합계 대상 금액에만 적용)
     * @return array<int, ExportField> 필드 목록
     */
    protected function declaredFields(string $modelClass, string $source, Closure $sourceOf, array $groups = [], array $units = [], bool $parentLevel = false): array
    {
        $fields = [];

        foreach (self::declarationsOf($modelClass) as $column => $spec) {
            $type = $spec['type'];
            $summable = (bool) ($spec['summable'] ?? false);

            $fields[] = new ExportField(
                key: "{$source}.{$column}",
                labelKey: $spec['label_key'],
                type: $type,
                personal: $spec['personal'],
                resolver: static fn (object $row) => $sourceOf($row)?->getAttribute($column),
                summable: $summable,
                group: $groups[$column] ?? 'extra',
                units: $units,
                enumClass: $spec['enum'] ?? null,
                parentLevel: $parentLevel && $summable,
            );
        }

        return $fields;
    }

    /**
     * 계산 필드를 만듭니다(관계 너머 값·여러 컬럼 조합).
     *
     * @param  string  $key  필드 키
     * @param  ExportFieldType  $type  값 종류
     * @param  bool  $personal  개인정보 여부
     * @param  Closure  $resolver  행 → 원값
     * @param  string  $group  화면 컬럼 키
     * @param  array<string, mixed>  $options  summable·units·enumClass·parentLevel·labelKey
     * @return ExportField 필드
     */
    protected function computed(string $key, ExportFieldType $type, bool $personal, Closure $resolver, string $group = 'extra', array $options = []): ExportField
    {
        return new ExportField(
            key: $key,
            labelKey: $options['labelKey'] ?? 'sirsoft-ecommerce::export.columns.'.$key,
            type: $type,
            personal: $personal,
            resolver: $resolver,
            summable: (bool) ($options['summable'] ?? false),
            group: $group,
            units: $options['units'] ?? [],
            enumClass: $options['enumClass'] ?? null,
            parentLevel: (bool) ($options['parentLevel'] ?? false),
        );
    }

    /**
     * 모델의 내보내기 선언을 정규화해 돌려줍니다(라벨·Enum 기본값 병합 포함).
     *
     * 라벨 키 우선순위: 선언의 `label_key` → 내보내기 다국어 파일의 `export.columns.{모델표}.{컬럼}` →
     * 활동 로그 선언(`$activityLogFields`)의 라벨. Enum 도 선언 → 활동 로그 선언 순.
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return array<string, array{type: ExportFieldType, personal: bool, label_key: string, summable?: bool, enum?: string|null}> 컬럼 → 정규화된 선언
     *
     * @throws InvalidArgumentException 선언 형식이 틀렸을 때 (type·personal 누락 등)
     */
    public static function declarationsOf(string $modelClass): array
    {
        $declaration = ModelDeclarationRegistry::for($modelClass);
        $declared = $declaration !== null ? $declaration::fields() : [];
        $activity = property_exists($modelClass, 'activityLogFields') ? $modelClass::$activityLogFields : [];
        $table = self::labelNamespaceOf($modelClass);

        $result = [];
        foreach ($declared as $column => $spec) {
            if (! array_key_exists('personal', $spec) || ! is_bool($spec['personal'])) {
                throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.export.declaration_missing_personal', ['model' => $modelClass, 'column' => $column]));
            }

            $type = $spec['type'] ?? null;
            $type = $type instanceof ExportFieldType ? $type : ExportFieldType::tryFrom((string) $type);
            if ($type === null) {
                throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.export.declaration_invalid_type', ['model' => $modelClass, 'column' => $column]));
            }

            $exportLabel = "sirsoft-ecommerce::export.columns.{$table}.{$column}";
            $labelKey = $spec['label_key']
                ?? (Lang::has($exportLabel) ? $exportLabel : ($activity[$column]['label_key'] ?? $exportLabel));

            $result[$column] = [
                'type' => $type,
                'personal' => $spec['personal'],
                'label_key' => $labelKey,
                'summable' => (bool) ($spec['summable'] ?? false),
                'enum' => $spec['enum'] ?? ($activity[$column]['enum'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * 모델의 제외 선언을 돌려줍니다.
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return array<string, string> 컬럼 → 사유
     */
    public static function exclusionsOf(string $modelClass): array
    {
        $declaration = ModelDeclarationRegistry::for($modelClass);

        return $declaration !== null ? $declaration::excluded() : [];
    }

    /**
     * 모델 이름에서 라벨 네임스페이스(스네이크)를 만듭니다. (OrderOption → order_option)
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return string 라벨 네임스페이스
     */
    public static function labelNamespaceOf(string $modelClass): string
    {
        $base = substr($modelClass, (int) strrpos($modelClass, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }
}
