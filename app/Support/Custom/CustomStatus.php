<?php

namespace App\Support\Custom;

use App\Extension\Custom\CustomPackageConflictChecker;
use App\Extension\Helpers\UpdateReservedPaths;

/**
 * custom 예약 자리 현황 — `php artisan custom:check` 와 관리자 견본 화면이 같은 값을 쓴다.
 *
 * @since 7.2.0
 */
class CustomStatus
{
    /**
     * 현황을 모읍니다.
     *
     * @return array{
     *     reserved: array<int, array{path: string, exists: bool}>,
     *     routes: array{exists: bool, registered: array<int, array<string, mixed>>, rejected: array<int, array<string, string>>},
     *     patterns: array{user: array<int, string>, admin: array<int, string>},
     *     packages: array<string, string>,
     *     conflicts: array<int, array<string, string>>,
     *     developer_provider: bool
     * }
     */
    public static function collect(): array
    {
        $reserved = [];
        foreach (UpdateReservedPaths::all() as $path) {
            $reserved[] = ['path' => $path, 'exists' => file_exists(base_path($path))];
        }

        return [
            'reserved' => $reserved,
            'routes' => CustomRoutes::inspect(),
            'patterns' => CustomRoutes::patterns(),
            'packages' => CustomPackageConflictChecker::customPackages(),
            'conflicts' => app(CustomPackageConflictChecker::class)->checkFiles(base_path('composer.lock')),
            'developer_provider' => class_exists('App\\Custom\\CustomServiceProvider'),
        ];
    }
}
