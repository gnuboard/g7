<?php

namespace App\Support\MenuIcon;

use App\Enums\ExtensionOwnerType;
use App\Extension\Support\LifecycleWarnings;
use App\Support\ExtensionIcon\ExtensionIconValidator;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * 코어·확장이 선언한 메뉴 아이콘(`config/core.php` menus · `getAdminMenus()`)을 저장 정규형으로 바꾼다.
 *
 * 허용 선언:
 * - 문자열 — FA 클래스(기존 호환)
 * - `['type' => 'class', 'value' => 'fas fa-x']`
 * - `['type' => 'image', 'path' => 'assets/menu.png']` — 자기 패키지 기준 상대경로(코어는 public/ 기준), 실파일 확인
 * - `['type' => 'svg', 'path' => 'assets/menu.svg']` — 파일을 읽어 검사한 뒤 **인라인 svg 로 저장**
 * - `['type' => 'svg', 'value' => '<svg …>']`
 * - 이미 정규형인 JSON 문자열(멱등)
 *
 * 그 밖·위반은 null 을 돌려주고 `Log::warning` + 라이프사이클 경고(`menu_icon`)를 남긴다 — 설치·업데이트는 막지 않는다.
 * 확장 선언의 `/` 절대경로·URL 은 거부한다(이미지는 자기 패키지 안 파일만).
 *
 * @since 7.2.1
 */
final class MenuIconDeclaration
{
    /** @var Closure(ExtensionOwnerType, string): string|null 테스트용 패키지 루트 해석기 */
    private static ?Closure $rootResolver = null;

    /**
     * 선언 아이콘을 정규 저장값으로 바꿉니다.
     *
     * @param  mixed  $declared  선언값
     * @param  ExtensionOwnerType  $type  선언 주체 유형
     * @param  string  $identifier  선언 주체 식별자(코어는 'core')
     * @param  string  $slug  메뉴 슬러그(경고 표시용)
     * @return string|null 정규 저장값, 비었거나 위반이면 null
     */
    public static function normalize(mixed $declared, ExtensionOwnerType $type, string $identifier, string $slug): ?string
    {
        if ($declared === null || $declared === '' || $declared === []) {
            return null;
        }

        if (is_string($declared)) {
            $trimmed = trim($declared);

            if ($trimmed === '') {
                return null;
            }

            // 이미 정규형인 JSON(재동기화·DB 값 재주입) — 형식만 다시 검사해 통과
            if ($trimmed[0] === '{') {
                $decoded = json_decode($trimmed, true);

                if (is_array($decoded)) {
                    return self::normalizeArray($decoded, $type, $identifier, $slug);
                }
            }

            $violation = MenuIconSpec::classViolation($trimmed);

            if ($violation !== null) {
                return self::reject($identifier, $slug, $violation, $trimmed);
            }

            return MenuIconSpec::classIcon($trimmed)->toStored();
        }

        if (is_array($declared)) {
            return self::normalizeArray($declared, $type, $identifier, $slug);
        }

        return self::reject($identifier, $slug, 'invalid_type', get_debug_type($declared));
    }

    /**
     * 선언 주체의 패키지 루트(이미지·SVG 상대경로의 기준)를 반환합니다.
     *
     * @param  ExtensionOwnerType  $type  선언 주체 유형
     * @param  string  $identifier  식별자
     * @return string 절대 경로 (코어는 public/, 모듈·플러그인은 설치본 디렉터리)
     */
    public static function packageRoot(ExtensionOwnerType $type, string $identifier): string
    {
        if (self::$rootResolver !== null) {
            return (self::$rootResolver)($type, $identifier);
        }

        return match ($type) {
            ExtensionOwnerType::Core => public_path(),
            ExtensionOwnerType::Module => base_path('modules/'.$identifier),
            ExtensionOwnerType::Plugin => base_path('plugins/'.$identifier),
        };
    }

    /**
     * 테스트용 — 패키지 루트 해석기를 바꿉니다 (null 이면 기본으로 되돌림).
     *
     * @param  Closure|null  $resolver  (ExtensionOwnerType, string) => string
     * @return void
     */
    public static function resolveRootUsing(?Closure $resolver): void
    {
        self::$rootResolver = $resolver;
    }

    /**
     * 배열 선언을 정규 저장값으로 바꿉니다.
     *
     * @param  array<string, mixed>  $declared  선언 배열
     * @param  ExtensionOwnerType  $type  선언 주체 유형
     * @param  string  $identifier  식별자
     * @param  string  $slug  메뉴 슬러그
     * @return string|null 정규 저장값, 위반이면 null
     */
    private static function normalizeArray(array $declared, ExtensionOwnerType $type, string $identifier, string $slug): ?string
    {
        $kind = $declared['type'] ?? null;

        if ($kind === MenuIconSpec::TYPE_CLASS) {
            $value = is_string($declared['value'] ?? null) ? $declared['value'] : '';
            $violation = MenuIconSpec::classViolation($value);

            return $violation === null
                ? MenuIconSpec::classIcon($value)->toStored()
                : self::reject($identifier, $slug, $violation, $value);
        }

        if ($kind === MenuIconSpec::TYPE_SVG) {
            return self::normalizeSvg($declared, $type, $identifier, $slug);
        }

        if ($kind === MenuIconSpec::TYPE_IMAGE) {
            return self::normalizeImage($declared, $type, $identifier, $slug);
        }

        return self::reject($identifier, $slug, 'invalid_type', is_scalar($kind) ? (string) $kind : get_debug_type($kind));
    }

    /**
     * 이미지 선언을 정규화합니다 — 자기 패키지 기준 상대경로 + 실파일 확인.
     *
     * @param  array<string, mixed>  $declared  선언 배열
     * @param  ExtensionOwnerType  $type  선언 주체 유형
     * @param  string  $identifier  식별자
     * @param  string  $slug  메뉴 슬러그
     * @return string|null 정규 저장값, 위반이면 null
     */
    private static function normalizeImage(array $declared, ExtensionOwnerType $type, string $identifier, string $slug): ?string
    {
        // 이미 정규형인 package 이미지(재동기화) — source 가 package 면 path 그대로 검사한다.
        // site·attachment 출처는 운영자 값이므로 선언으로 받지 않는다.
        $source = $declared['source'] ?? MenuIconSpec::SOURCE_PACKAGE;
        $path = $declared['path'] ?? null;

        if ($source !== MenuIconSpec::SOURCE_PACKAGE || ! is_string($path)) {
            return self::reject($identifier, $slug, 'image_source_invalid', is_string($path) ? $path : '');
        }

        $violation = MenuIconSpec::packagePathViolation($path);

        if ($violation !== null) {
            return self::reject($identifier, $slug, $violation, $path);
        }

        if (ExtensionIconValidator::resolveInsideRoot(self::packageRoot($type, $identifier), trim($path)) === null) {
            return self::reject($identifier, $slug, 'file_missing', $path);
        }

        return MenuIconSpec::packageImage($path)->toStored();
    }

    /**
     * SVG 선언을 정규화합니다 — path 면 파일을 읽어 인라인 코드로 바꾼다.
     *
     * @param  array<string, mixed>  $declared  선언 배열
     * @param  ExtensionOwnerType  $type  선언 주체 유형
     * @param  string  $identifier  식별자
     * @param  string  $slug  메뉴 슬러그
     * @return string|null 정규 저장값, 위반이면 null
     */
    private static function normalizeSvg(array $declared, ExtensionOwnerType $type, string $identifier, string $slug): ?string
    {
        $svg = $declared['value'] ?? null;

        if (! is_string($svg) || trim($svg) === '') {
            $path = $declared['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                return self::reject($identifier, $slug, 'svg_required', '');
            }

            $violation = ExtensionIconValidator::manifestPathViolation($path);

            if ($violation === null && strtolower((string) pathinfo(trim($path), PATHINFO_EXTENSION)) !== 'svg') {
                $violation = 'extension';
            }

            if ($violation !== null) {
                return self::reject($identifier, $slug, $violation, $path);
            }

            $file = ExtensionIconValidator::resolveInsideRoot(self::packageRoot($type, $identifier), trim($path));

            if ($file === null) {
                return self::reject($identifier, $slug, 'file_missing', $path);
            }

            $svg = (string) file_get_contents($file);
        }

        $violations = MenuIconSvgValidator::violations($svg);

        if ($violations !== []) {
            return self::reject($identifier, $slug, implode(',', $violations), 'svg');
        }

        return MenuIconSpec::svg($svg)->toStored();
    }

    /**
     * 선언을 버리고 경고를 남깁니다.
     *
     * @param  string  $identifier  선언 주체 식별자
     * @param  string  $slug  메뉴 슬러그
     * @param  string  $reason  위반 코드
     * @param  string  $value  위반 값(로그용, 200자 절단)
     * @return null 항상 null
     */
    private static function reject(string $identifier, string $slug, string $reason, string $value): ?string
    {
        Log::warning('메뉴 아이콘 선언 무시', [
            'extension' => $identifier,
            'slug' => $slug,
            'reason' => $reason,
            'value' => mb_substr($value, 0, 200),
        ]);

        LifecycleWarnings::current()->declaration(
            'menu_icon',
            $identifier,
            LifecycleWarnings::reason(
                'menu.icon_declaration_invalid',
                ['slug' => $slug, 'reason' => $reason],
                "menu icon ignored: {$slug} ({$reason})"
            ),
        );

        return null;
    }
}
