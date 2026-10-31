<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 모델 클래스 → 내보내기 선언 연결표
 *
 * 기본 선언은 내보내기가 이커머스 모델 8종에 대해 갖고, 확장은 필터로 더하거나 바꾼다.
 */
class ModelDeclarationRegistry
{
    /**
     * 기본 선언 클래스
     *
     * @var array<int, class-string<ModelDeclaration>>
     */
    private const DEFAULTS = [
        OrderDeclaration::class,
        OrderAddressDeclaration::class,
        OrderOptionDeclaration::class,
        OrderPaymentDeclaration::class,
        OrderShippingDeclaration::class,
        ProductDeclaration::class,
        ProductOptionDeclaration::class,
        MileageTransactionDeclaration::class,
    ];

    /**
     * 요청 단위 캐시 (모델 클래스 → 선언 클래스)
     *
     * @var array<string, class-string<ModelDeclaration>>|null
     */
    private static ?array $map = null;

    /**
     * 모델의 선언 클래스를 반환합니다.
     *
     * @param  class-string  $modelClass  모델 클래스
     * @return class-string<ModelDeclaration>|null 선언 클래스 (없으면 null)
     */
    public static function for(string $modelClass): ?string
    {
        return self::all()[$modelClass] ?? null;
    }

    /**
     * 등록된 선언 전부를 반환합니다.
     *
     * @return array<string, class-string<ModelDeclaration>> 모델 클래스 → 선언 클래스
     */
    public static function all(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $classes = HookManager::applyFilters('sirsoft-ecommerce.export.declarations', self::DEFAULTS);

        $map = [];
        foreach (is_array($classes) ? $classes : self::DEFAULTS as $class) {
            if (! is_string($class) || ! is_subclass_of($class, ModelDeclaration::class)) {
                Log::warning('[내보내기] 계약을 구현하지 않은 선언을 무시했습니다', ['class' => is_string($class) ? $class : gettype($class)]);

                continue;
            }
            $map[$class::model()] = $class;
        }

        return self::$map = $map;
    }

    /**
     * 캐시를 비웁니다(테스트·훅 재등록용).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$map = null;
    }
}
