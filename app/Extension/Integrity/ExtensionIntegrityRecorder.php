<?php

namespace App\Extension\Integrity;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Helpers\ExtensionPendingHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 확장 설치 행(`modules`·`plugins`·`templates`)에 무결성 검증 결과를 적습니다.
 *
 * 설치·업데이트가 끝난 뒤에 부른다 — 실패해도 설치를 되돌리지 않는다(경고 로그). 칸이 아직 없는 스키마(7.2.0
 * 마이그레이션 전 코어 업데이트 도중)에서는 아무 일도 하지 않는다.
 *
 * @since 7.2.0
 */
class ExtensionIntegrityRecorder
{
    /**
     * 표별 칸 존재 여부 (한 프로세스 안에서 한 번만 확인)
     *
     * @var array<string, bool>
     */
    private array $columnPresent = [];

    /**
     * 기록기를 만듭니다.
     *
     * @param  ModuleRepositoryInterface  $modules  모듈 저장소
     * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     */
    public function __construct(
        private readonly ModuleRepositoryInterface $modules,
        private readonly PluginRepositoryInterface $plugins,
        private readonly TemplateRepositoryInterface $templates,
    ) {}

    /**
     * 검증 결과를 설치 행에 적습니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template, 복수형도 받음)
     * @param  string  $identifier  확장 식별자
     * @param  PackageVerification  $verification  검증 결과
     * @return void 반환값 없음
     */
    public function record(string $type, string $identifier, PackageVerification $verification): void
    {
        $this->write($type, $identifier, $verification->toInstallColumns());
    }

    /**
     * 설치가 활성 디렉토리로 복사할 원본을 판별합니다 (복사 직전에 부른다).
     *
     * @param  string  $basePath  확장 종류 디렉토리 (modules·plugins·templates 절대경로)
     * @param  string  $identifier  확장 식별자
     * @param  bool  $willCopy  이번 설치가 원본을 복사하는지 (활성 디렉토리가 이미 있고 force 가 아니면 false)
     * @return string|null `pending`·`bundled` (복사하지 않으면 null)
     */
    public static function installSourceOf(string $basePath, string $identifier, bool $willCopy): ?string
    {
        if (! $willCopy) {
            return null;
        }

        if (ExtensionPendingHelper::isPending($basePath, $identifier)) {
            return 'pending';
        }

        return ExtensionPendingHelper::isBundled($basePath, $identifier) ? 'bundled' : null;
    }

    /**
     * 설치 원본에 맞춰 기록합니다 — `_bundled` 는 bundled, `_pending` 은 비움(ZIP·GitHub 설치는 서비스가 곧 결과를 적는다).
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $source  `installSourceOf()` 결과
     * @return void 반환값 없음
     */
    public function recordInstallSource(string $type, string $identifier, ?string $source): void
    {
        if ($source === 'bundled') {
            $this->record($type, $identifier, PackageVerification::bundled());
        } elseif ($source === 'pending') {
            $this->clear($type, $identifier);
        }
    }

    /**
     * 기록을 지웁니다 (무엇으로 들어왔는지 모르는 설치 — `_pending` 에 직접 둔 원본 등).
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    public function clear(string $type, string $identifier): void
    {
        $this->write($type, $identifier, ['integrity_status' => null, 'integrity_key_id' => null]);
    }

    /**
     * 칸을 씁니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  확장 식별자
     * @param  array{integrity_status: string|null, integrity_key_id: string|null}  $columns  칸 값
     * @return void 반환값 없음
     */
    private function write(string $type, string $identifier, array $columns): void
    {
        $type = rtrim($type, 's');
        [$repository, $table] = match ($type) {
            'module' => [$this->modules, 'modules'],
            'plugin' => [$this->plugins, 'plugins'],
            'template' => [$this->templates, 'templates'],
            default => [null, null],
        };

        if ($repository === null || ! $this->hasColumns($table)) {
            return;
        }

        try {
            $repository->updateByIdentifier($identifier, $columns);
        } catch (\Throwable $e) {
            Log::warning('확장 무결성 검증 결과를 설치 행에 적지 못했습니다', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 표에 무결성 칸이 있는지 확인합니다.
     *
     * @param  string  $table  표 이름
     * @return bool 칸이 있으면 true
     */
    private function hasColumns(string $table): bool
    {
        return $this->columnPresent[$table] ??= Schema::hasColumn($table, 'integrity_status');
    }
}
