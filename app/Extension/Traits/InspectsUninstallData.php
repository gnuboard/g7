<?php

namespace App\Extension\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 모듈/플러그인 삭제 시 삭제될 데이터 정보를 조회하는 Trait
 *
 * 마이그레이션 파일에서 테이블 목록 추출, 테이블 용량 조회(DB 드라이버별 분기),
 * 스토리지 디렉토리 용량 계산 등의 공통 유틸리티를 제공합니다.
 *
 * ModuleManager, PluginManager에서 사용됩니다.
 */
trait InspectsUninstallData
{
    /**
     * 마이그레이션 파일들에서 생성하는 테이블 이름을 추출합니다.
     *
     * @param  array  $migrationPaths  마이그레이션 디렉토리 경로 배열
     * @return array<string> 테이블 이름 배열
     */
    protected function extractTablesFromMigrations(array $migrationPaths): array
    {
        $tables = [];

        foreach ($migrationPaths as $migrationPath) {
            $migrationFiles = glob($migrationPath.'/*.php');
            if (empty($migrationFiles)) {
                continue;
            }

            foreach ($migrationFiles as $filePath) {
                try {
                    $content = File::get($filePath);
                    if (preg_match_all('/Schema::create\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $content, $matches)) {
                        $tables = array_merge($tables, $matches[1]);
                    }
                } catch (\Exception $e) {
                    // 개별 파일 읽기 실패 시 건너뜀
                }
            }
        }

        return array_unique($tables);
    }

    /**
     * 테이블 목록의 용량 정보를 조회합니다.
     *
     * DB 드라이버별 분기 처리:
     * - MySQL/MariaDB: information_schema.TABLES 조회
     * - 기타(SQLite, PostgreSQL 등): 테이블 존재 여부만 확인, 용량은 null 반환
     *
     * @param  array<string>  $tables  테이블 이름 배열
     * @return array<array{name: string, size_bytes: int|null, size_formatted: string}> 테이블 용량 정보
     */
    protected function getTablesSizeInfo(array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $this->getTablesSizeInfoMysql($tables);
        }

        // TODO: PostgreSQL, SQLite 등 다른 DB 드라이버 용량 조회 지원
        return $this->getTablesSizeInfoFallback($tables);
    }

    /**
     * MySQL/MariaDB에서 테이블 용량 정보를 조회합니다.
     *
     * @param  array<string>  $tables  테이블 이름 배열
     * @return array<array{name: string, size_bytes: int, size_formatted: string}> 테이블 용량 정보
     */
    protected function getTablesSizeInfoMysql(array $tables): array
    {
        $prefix = DB::getTablePrefix();
        $dbName = DB::getDatabaseName();
        $result = [];

        foreach ($tables as $table) {
            $prefixedTable = $prefix.$table;

            try {
                $row = DB::selectOne(
                    'SELECT (DATA_LENGTH + INDEX_LENGTH) AS size_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, $prefixedTable]
                );

                $sizeBytes = $row ? (int) $row->size_bytes : 0;
                $result[] = [
                    'name' => $table,
                    'size_bytes' => $sizeBytes,
                    'size_formatted' => $this->formatBytes($sizeBytes),
                ];
            } catch (\Exception $e) {
                $result[] = [
                    'name' => $table,
                    'size_bytes' => 0,
                    'size_formatted' => '0 B',
                ];
            }
        }

        return $result;
    }

    /**
     * 용량 조회를 지원하지 않는 DB에서 테이블 존재 여부만 확인합니다.
     *
     * @param  array<string>  $tables  테이블 이름 배열
     * @return array<array{name: string, size_bytes: null, size_formatted: string}> 테이블 정보 (용량 null)
     */
    protected function getTablesSizeInfoFallback(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            $exists = Schema::hasTable($table);
            $result[] = [
                'name' => $table,
                'size_bytes' => null,
                'size_formatted' => $exists ? '-' : '0 B',
            ];
        }

        return $result;
    }

    /**
     * 스토리지 디렉토리의 1-depth 서브디렉토리 용량 정보를 조회합니다.
     *
     * @param  string  $basePath  기본 경로
     * @return array<array{name: string, size_bytes: int, size_formatted: string}> 디렉토리 용량 정보
     */
    protected function getStorageDirectoriesInfo(string $basePath): array
    {
        if (! File::isDirectory($basePath)) {
            return [];
        }

        $result = [];
        $directories = File::directories($basePath);

        foreach ($directories as $directory) {
            $sizeBytes = $this->getDirectorySize($directory);
            $result[] = [
                'name' => basename($directory),
                'size_bytes' => $sizeBytes,
                'size_formatted' => $this->formatBytes($sizeBytes),
            ];
        }

        return $result;
    }

    /**
     * 디렉토리의 전체 용량을 계산합니다.
     *
     * @param  string  $directory  디렉토리 경로
     * @return int 바이트 단위 용량
     */
    protected function getDirectorySize(string $directory): int
    {
        $size = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // 접근 불가 파일 무시
        }

        return $size;
    }

    /**
     * 확장(모듈/플러그인)의 Composer vendor 디렉토리 정보를 조회합니다.
     *
     * vendor/ 디렉토리와 composer.lock 파일의 존재 여부 및 용량을 반환합니다.
     * 둘 다 존재하지 않으면 null을 반환합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  디렉토리명
     * @return array{items: array, total_size_bytes: int, total_size_formatted: string}|null
     */
    protected function getVendorDirectoryInfo(string $type, string $dirName): ?array
    {
        $basePath = base_path("{$type}/{$dirName}");
        $vendorPath = $basePath.'/vendor';
        $lockPath = $basePath.'/composer.lock';

        $hasVendor = File::isDirectory($vendorPath);
        $hasLock = File::exists($lockPath);

        if (! $hasVendor && ! $hasLock) {
            return null;
        }

        $totalSize = 0;
        $items = [];

        if ($hasVendor) {
            $vendorSize = $this->getDirectorySize($vendorPath);
            $totalSize += $vendorSize;
            $items[] = [
                'name' => 'vendor/',
                'size_bytes' => $vendorSize,
                'size_formatted' => $this->formatBytes($vendorSize),
            ];
        }

        if ($hasLock) {
            $lockSize = File::size($lockPath);
            $totalSize += $lockSize;
            $items[] = [
                'name' => 'composer.lock',
                'size_bytes' => $lockSize,
                'size_formatted' => $this->formatBytes($lockSize),
            ];
        }

        return [
            'items' => $items,
            'total_size_bytes' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * 확장(모듈/플러그인) 설치 디렉토리 정보를 조회합니다.
     *
     * 확장의 루트 디렉토리 경로와 전체 용량을 반환합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  디렉토리명
     * @return array{path: string, size_bytes: int, size_formatted: string}|null
     */
    protected function getExtensionDirectoryInfo(string $type, string $dirName): ?array
    {
        $dirPath = base_path("{$type}/{$dirName}");

        if (! File::isDirectory($dirPath)) {
            return null;
        }

        $size = $this->getDirectorySize($dirPath);

        return [
            'path' => "{$type}/{$dirName}",
            'size_bytes' => $size,
            'size_formatted' => $this->formatBytes($size),
        ];
    }

    /**
     * 확장(모듈/플러그인)의 Composer vendor 디렉토리 및 관련 파일을 삭제합니다.
     *
     * vendor/ 디렉토리와 composer.lock 파일을 삭제합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  디렉토리명
     */
    protected function deleteVendorDirectory(string $type, string $dirName): void
    {
        $basePath = base_path("{$type}/{$dirName}");
        $vendorPath = $basePath.'/vendor';
        $lockPath = $basePath.'/composer.lock';

        if (File::isDirectory($vendorPath)) {
            try {
                File::deleteDirectory($vendorPath);

                Log::info('Composer vendor 디렉토리 삭제 완료', [
                    'type' => $type,
                    'extension' => $dirName,
                    'path' => $vendorPath,
                ]);
            } catch (\Exception $e) {
                Log::error('Composer vendor 디렉토리 삭제 실패', [
                    'type' => $type,
                    'extension' => $dirName,
                    'path' => $vendorPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (File::exists($lockPath)) {
            try {
                File::delete($lockPath);

                Log::info('composer.lock 파일 삭제 완료', [
                    'type' => $type,
                    'extension' => $dirName,
                    'path' => $lockPath,
                ]);
            } catch (\Exception $e) {
                Log::error('composer.lock 파일 삭제 실패', [
                    'type' => $type,
                    'extension' => $dirName,
                    'path' => $lockPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 바이트를 사람이 읽을 수 있는 형식으로 변환합니다.
     *
     * @param  int  $bytes  바이트 수
     * @return string 포맷된 문자열 (예: "1.5 MB")
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }
}
