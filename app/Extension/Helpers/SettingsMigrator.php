<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장 환경설정 마이그레이션 헬퍼
 *
 * UpgradeStep 내에서 사용하여 설정 파일의 스키마를 안전하게 변경합니다.
 * 모듈은 카테고리별 다중 파일, 플러그인은 단일 파일 구조를 지원합니다.
 *
 * @example
 * SettingsMigrator::forModule('sirsoft-ecommerce')
 *     ->addField('shipping.tracking_enabled', false)
 *     ->renameField('order_settings.auto_cancel_days', 'order_settings.pending_cancel_days')
 *     ->removeField('seo.deprecated_field')
 *     ->addCategory('notifications', ['email_enabled' => true])
 *     ->apply();
 */
class SettingsMigrator
{
    /**
     * 확장 타입 ('module' 또는 'plugin')
     */
    private string $type;

    /**
     * 확장 식별자
     */
    private string $identifier;

    /**
     * 적용할 오퍼레이션 큐
     *
     * @var array<int, array{operation: string, args: array}>
     */
    private array $operations = [];

    /**
     * @param  string  $type  확장 타입 ('module' 또는 'plugin')
     * @param  string  $identifier  확장 식별자
     */
    private function __construct(string $type, string $identifier)
    {
        $this->type = $type;
        $this->identifier = $identifier;
    }

    /**
     * 모듈용 마이그레이터를 생성합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return static
     */
    public static function forModule(string $identifier): static
    {
        return new static('module', $identifier);
    }

    /**
     * 플러그인용 마이그레이터를 생성합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return static
     */
    public static function forPlugin(string $identifier): static
    {
        return new static('plugin', $identifier);
    }

    /**
     * 새 필드를 추가합니다. 이미 존재하면 스킵합니다.
     *
     * @param  string  $path  dot notation 경로 (모듈: 'category.field', 플러그인: 'field')
     * @param  mixed  $default  기본값
     * @return $this
     */
    public function addField(string $path, mixed $default): static
    {
        $this->operations[] = [
            'operation' => 'addField',
            'args' => ['path' => $path, 'default' => $default],
        ];

        return $this;
    }

    /**
     * 필드 이름을 변경합니다. 이전 키가 없으면 스킵합니다.
     *
     * @param  string  $oldPath  이전 경로 (dot notation)
     * @param  string  $newPath  새 경로 (dot notation)
     * @return $this
     */
    public function renameField(string $oldPath, string $newPath): static
    {
        $this->operations[] = [
            'operation' => 'renameField',
            'args' => ['oldPath' => $oldPath, 'newPath' => $newPath],
        ];

        return $this;
    }

    /**
     * 필드를 제거합니다. 키가 없으면 스킵합니다.
     *
     * @param  string  $path  제거할 경로 (dot notation)
     * @return $this
     */
    public function removeField(string $path): static
    {
        $this->operations[] = [
            'operation' => 'removeField',
            'args' => ['path' => $path],
        ];

        return $this;
    }

    /**
     * 필드 값을 변환합니다. 키가 없으면 스킵합니다.
     *
     * @param  string  $path  변환할 경로 (dot notation)
     * @param  callable  $transformer  변환 함수 (현재 값을 받아 새 값 반환)
     * @return $this
     */
    public function transformField(string $path, callable $transformer): static
    {
        $this->operations[] = [
            'operation' => 'transformField',
            'args' => ['path' => $path, 'transformer' => $transformer],
        ];

        return $this;
    }

    /**
     * 새 카테고리를 추가합니다. 모듈 전용입니다.
     *
     * 카테고리 파일을 생성하고 defaults.json의 _meta.categories 배열을 업데이트합니다.
     *
     * @param  string  $category  카테고리 이름
     * @param  array  $defaults  기본값
     * @return $this
     *
     * @throws \LogicException 플러그인에서 호출 시
     */
    public function addCategory(string $category, array $defaults): static
    {
        $this->operations[] = [
            'operation' => 'addCategory',
            'args' => ['category' => $category, 'defaults' => $defaults],
        ];

        return $this;
    }

    /**
     * 모든 오퍼레이션을 실행합니다.
     *
     * @return array{applied: int, skipped: int, errors: array<string>}
     */
    public function apply(): array
    {
        $result = ['applied' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($this->operations as $op) {
            try {
                $method = 'execute'.ucfirst($op['operation']);
                $applied = $this->{$method}($op['args']);

                if ($applied) {
                    $result['applied']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "[{$op['operation']}] {$e->getMessage()}";

                Log::warning('SettingsMigrator 오퍼레이션 실패', [
                    'type' => $this->type,
                    'identifier' => $this->identifier,
                    'operation' => $op['operation'],
                    'args' => array_filter($op['args'], fn ($v) => ! is_callable($v)),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * addField 오퍼레이션을 실행합니다.
     *
     * @param  array  $args  오퍼레이션 인수
     * @return bool 적용 여부
     */
    private function executeAddField(array $args): bool
    {
        $path = $args['path'];
        $default = $args['default'];

        [$filePath, $fieldPath] = $this->resolveFilePath($path);

        if (! File::exists($filePath)) {
            return false;
        }

        $data = $this->readJsonFile($filePath);

        if (Arr::has($data, $fieldPath)) {
            return false;
        }

        Arr::set($data, $fieldPath, $default);
        $this->writeJsonFile($filePath, $data);

        return true;
    }

    /**
     * renameField 오퍼레이션을 실행합니다.
     *
     * @param  array  $args  오퍼레이션 인수
     * @return bool 적용 여부
     */
    private function executeRenameField(array $args): bool
    {
        $oldPath = $args['oldPath'];
        $newPath = $args['newPath'];

        [$oldFilePath, $oldFieldPath] = $this->resolveFilePath($oldPath);
        [$newFilePath, $newFieldPath] = $this->resolveFilePath($newPath);

        if (! File::exists($oldFilePath)) {
            return false;
        }

        $oldData = $this->readJsonFile($oldFilePath);

        if (! Arr::has($oldData, $oldFieldPath)) {
            return false;
        }

        $value = Arr::get($oldData, $oldFieldPath);

        // 같은 파일 내 이동
        if ($oldFilePath === $newFilePath) {
            Arr::forget($oldData, $oldFieldPath);
            Arr::set($oldData, $newFieldPath, $value);
            $this->writeJsonFile($oldFilePath, $oldData);
        } else {
            // 다른 파일 간 이동
            Arr::forget($oldData, $oldFieldPath);
            $this->writeJsonFile($oldFilePath, $oldData);

            if (! File::exists($newFilePath)) {
                return false;
            }

            $newData = $this->readJsonFile($newFilePath);
            Arr::set($newData, $newFieldPath, $value);
            $this->writeJsonFile($newFilePath, $newData);
        }

        return true;
    }

    /**
     * removeField 오퍼레이션을 실행합니다.
     *
     * @param  array  $args  오퍼레이션 인수
     * @return bool 적용 여부
     */
    private function executeRemoveField(array $args): bool
    {
        $path = $args['path'];

        [$filePath, $fieldPath] = $this->resolveFilePath($path);

        if (! File::exists($filePath)) {
            return false;
        }

        $data = $this->readJsonFile($filePath);

        if (! Arr::has($data, $fieldPath)) {
            return false;
        }

        Arr::forget($data, $fieldPath);
        $this->writeJsonFile($filePath, $data);

        return true;
    }

    /**
     * transformField 오퍼레이션을 실행합니다.
     *
     * @param  array  $args  오퍼레이션 인수
     * @return bool 적용 여부
     */
    private function executeTransformField(array $args): bool
    {
        $path = $args['path'];
        $transformer = $args['transformer'];

        [$filePath, $fieldPath] = $this->resolveFilePath($path);

        if (! File::exists($filePath)) {
            return false;
        }

        $data = $this->readJsonFile($filePath);

        if (! Arr::has($data, $fieldPath)) {
            return false;
        }

        $currentValue = Arr::get($data, $fieldPath);
        $newValue = $transformer($currentValue);

        Arr::set($data, $fieldPath, $newValue);
        $this->writeJsonFile($filePath, $data);

        return true;
    }

    /**
     * addCategory 오퍼레이션을 실행합니다.
     *
     * @param  array  $args  오퍼레이션 인수
     * @return bool 적용 여부
     *
     * @throws \LogicException 플러그인에서 호출 시
     */
    private function executeAddCategory(array $args): bool
    {
        if ($this->type === 'plugin') {
            throw new \LogicException('addCategory는 모듈에서만 사용할 수 있습니다.');
        }

        $category = $args['category'];
        $defaults = $args['defaults'];

        $settingsDir = $this->getSettingsDir();
        $categoryFile = $settingsDir.'/'.$category.'.json';

        // 카테고리 파일이 이미 존재하면 스킵
        if (File::exists($categoryFile)) {
            return false;
        }

        // 디렉토리 확인 및 생성
        if (! File::isDirectory($settingsDir)) {
            File::makeDirectory($settingsDir, 0755, true);
        }

        // 카테고리 파일 생성
        $this->writeJsonFile($categoryFile, $defaults);

        // defaults.json의 _meta.categories 업데이트
        $this->updateDefaultsJsonCategories($category);

        return true;
    }

    /**
     * dot notation 경로를 파일 경로와 필드 경로로 분리합니다.
     *
     * @param  string  $path  dot notation 경로
     * @return array{0: string, 1: string} [파일 경로, 필드 경로]
     */
    private function resolveFilePath(string $path): array
    {
        $settingsDir = $this->getSettingsDir();

        if ($this->type === 'module') {
            // 모듈: 'category.field.subfield' → category.json 내 'field.subfield'
            $dotPos = strpos($path, '.');
            if ($dotPos === false) {
                // 카테고리 자체를 대상으로 하는 경우 (addCategory에서 처리)
                return [$settingsDir.'/'.$path.'.json', ''];
            }

            $category = substr($path, 0, $dotPos);
            $fieldPath = substr($path, $dotPos + 1);

            return [$settingsDir.'/'.$category.'.json', $fieldPath];
        }

        // 플러그인: 'field.subfield' → setting.json 내 'field.subfield'
        return [$settingsDir.'/setting.json', $path];
    }

    /**
     * 설정 디렉토리 경로를 반환합니다.
     *
     * @return string 설정 디렉토리 절대 경로
     */
    private function getSettingsDir(): string
    {
        if ($this->type === 'module') {
            return storage_path('app/modules/'.$this->identifier.'/settings');
        }

        return storage_path('app/plugins/'.$this->identifier.'/settings');
    }

    /**
     * JSON 파일을 읽어 배열로 반환합니다.
     *
     * @param  string  $filePath  파일 절대 경로
     * @return array 파싱된 데이터
     *
     * @throws \RuntimeException JSON 파싱 실패 시
     */
    private function readJsonFile(string $filePath): array
    {
        $content = File::get($filePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "JSON 파싱 실패: {$filePath} - ".json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * 배열 데이터를 JSON 파일로 저장합니다.
     *
     * @param  string  $filePath  파일 절대 경로
     * @param  array  $data  저장할 데이터
     * @return void
     */
    private function writeJsonFile(string $filePath, array $data): void
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put($filePath, $content);
    }

    /**
     * defaults.json의 _meta.categories 배열에 새 카테고리를 추가합니다.
     *
     * @param  string  $category  추가할 카테고리 이름
     * @return void
     */
    private function updateDefaultsJsonCategories(string $category): void
    {
        $defaultsPath = base_path("modules/{$this->identifier}/config/settings/defaults.json");

        if (! File::exists($defaultsPath)) {
            return;
        }

        try {
            $data = $this->readJsonFile($defaultsPath);
            $categories = $data['_meta']['categories'] ?? [];

            if (! in_array($category, $categories)) {
                $categories[] = $category;
                $data['_meta']['categories'] = $categories;
                $this->writeJsonFile($defaultsPath, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('defaults.json 카테고리 업데이트 실패', [
                'module' => $this->identifier,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
