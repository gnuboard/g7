<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Support\LifecycleWarnings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장 갱신 뒤 의존 버전 검사 — 부족하면 경고로 알리고 갱신은 막지 않는다.
 *
 * 의존성 검사는 설치 때만 돌아, 의존 확장이 아직 옛 판인 채로(예: 일괄 업데이트를 건너뛰거나 이커머스 갱신이 실패한 뒤
 * 결제 플러그인만 갱신) 새 판을 올려도 아무 말이 없었다. 갱신 경로(모듈·플러그인·템플릿 update)는 성공한 뒤 새 판
 * manifest 의 `dependencies` 를 지금 설치 상태와 대조해, 없거나 꺼져 있거나 버전이 모자란 의존을 결과
 * (`dependency_warnings`)·완료 문구·로그로 알린다. 갱신을 거부하지 않는 이유: 코어 업데이트 뒤 번들 일괄 업데이트처럼
 * 의존 확장이 같은 흐름에서 곧 따라 올라오는 경우가 정상 경로이고, 거부하면 그 흐름 전체가 멈춘다.
 *
 * 역방향({@see forDependents()}): 의존 **대상**의 판이 내려가거나 꺼지는 경로(강제 업데이트·`install --force`·멈춘
 * 업데이트 정리·강제 비활성화)는 그 확장을 요구하던 활성 템플릿·모듈·플러그인을 다시 보지 않아, 의존자가 조용히 깨졌다
 * (템플릿이면 화면 전체 503). 작업은 막지 않고 깨지는 의존자를 안내(notice)로 알린다(#152).
 */
final class DependencyVersionWarnings
{
    /**
     * manifest 파일(module.json·plugin.json·template.json)의 의존 선언을 검사합니다.
     *
     * 파일이 없거나 읽을 수 없으면 빈 목록 — 경고 수집이 갱신 결과를 깨뜨리지 않는다.
     *
     * @param  string  $manifestPath  manifest 파일 절대 경로
     * @return list<array{identifier: string, name: string, type: string, required_version: string, installed_version: string|null, is_active: bool, message: string}> 충족되지 않은 의존 목록
     */
    public static function forManifestFile(string $manifestPath): array
    {
        try {
            if (! File::isFile($manifestPath)) {
                return [];
            }

            $manifest = json_decode(File::get($manifestPath), true);

            if (! is_array($manifest) || ! is_array($manifest['dependencies'] ?? null)) {
                return [];
            }

            return self::forDependencies($manifest['dependencies']);
        } catch (\Throwable $e) {
            Log::warning('의존 버전 검사 실패 — 경고 없이 진행합니다', ['manifest' => $manifestPath, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 의존 선언(`['modules' => [...], 'plugins' => [...]]`)에서 충족되지 않은 것만 골라 경고 문구를 붙입니다.
     *
     * @param  array<string, mixed>  $dependencies  manifest 의 dependencies
     * @return list<array{identifier: string, name: string, type: string, required_version: string, installed_version: string|null, is_active: bool, message: string}> 충족되지 않은 의존 목록
     */
    public static function forDependencies(array $dependencies): array
    {
        $warnings = [];

        foreach (DependencyEnricher::enrich($dependencies) as $item) {
            if ($item['is_met']) {
                continue;
            }

            $params = [
                'identifier' => $item['identifier'],
                'required' => (string) $item['required_version'],
                'installed' => (string) ($item['installed_version'] ?? ''),
            ];

            $message = match ($item['status']) {
                DependencyEnricher::STATUS_NOT_INSTALLED => __('extensions.warnings.dependency_not_installed', $params),
                DependencyEnricher::STATUS_INACTIVE => __('extensions.warnings.dependency_inactive', $params),
                default => __('extensions.warnings.dependency_version_unmet', $params),
            };

            $warnings[] = [
                'identifier' => (string) $item['identifier'],
                'name' => (string) $item['name'],
                'type' => (string) $item['type'],
                'required_version' => (string) $item['required_version'],
                'installed_version' => $item['installed_version'],
                'is_active' => (bool) $item['is_active'],
                'message' => $message,
            ];
        }

        return $warnings;
    }

    /**
     * 이 확장을 의존으로 선언한 활성 템플릿·모듈·플러그인 중, 지금 상태로는 그 의존을 충족하지 못하는 것을 찾습니다.
     *
     * 의존 대상의 판이 내려가거나 꺼진 뒤 부른다. 판정은 활성화와 같은 원천(DependencyEnricher)이다. 수집 중 예외는 삼키고
     * 로그만 남긴다 — 안내 수집이 본 작업(업데이트·설치·정리·비활성화)을 깨지 않게 한다.
     *
     * @param  string  $type  상태가 바뀐 확장 종류 (`module`·`plugin`)
     * @param  string  $identifier  상태가 바뀐 확장 식별자
     * @param  bool  $versionOnly  판만 본다(꺼짐은 따지지 않음) — 곧 다시 켜지는 흐름(멈춘 업데이트 정리)에서 일시적인 「꺼짐」을 알리지 않는다
     * @return list<array{direction: string, dependent_type: string, dependent_identifier: string, dependent_name: string, identifier: string, type: string, required_version: string, installed_version: string|null, status: string, message: string}> 깨지는 의존자 목록
     */
    public static function forDependents(string $type, string $identifier, bool $versionOnly = false): array
    {
        if (! in_array($type, ['module', 'plugin'], true)) {
            return [];
        }

        try {
            $finder = $type === 'module' ? 'findActiveByModuleDependency' : 'findActiveByPluginDependency';
            $targetRecord = app($type === 'module' ? ModuleRepositoryInterface::class : PluginRepositoryInterface::class)
                ->findByIdentifier($identifier);

            // 의존자 종류별 (저장소, manifest 의존 선언 읽기)
            $sources = [
                'template' => [TemplateRepositoryInterface::class, fn (object $record) => app(TemplateManagerInterface::class)->getTemplate($record->identifier)['dependencies'] ?? []],
                'module' => [ModuleRepositoryInterface::class, fn (object $record) => app(ModuleManagerInterface::class)->getModule($record->identifier)?->getDependencies() ?? []],
                'plugin' => [PluginRepositoryInterface::class, fn (object $record) => app(PluginManagerInterface::class)->getPlugin($record->identifier)?->getDependencies() ?? []],
            ];

            $result = [];
            foreach ($sources as $dependentType => [$repository, $readDependencies]) {
                foreach (app($repository)->{$finder}($identifier) as $dependent) {
                    $dependencies = $readDependencies($dependent);

                    foreach (DependencyEnricher::iterate(is_array($dependencies) ? $dependencies : []) as $dependency) {
                        if ($dependency['type'] !== $type || $dependency['identifier'] !== $identifier) {
                            continue;
                        }

                        $status = $versionOnly && $targetRecord !== null
                            ? (DependencyEnricher::checkVersionConstraint($targetRecord->version, $dependency['constraint']) ? DependencyEnricher::STATUS_MET : DependencyEnricher::STATUS_VERSION_MISMATCH)
                            : DependencyEnricher::statusOf($targetRecord, $dependency['constraint']);
                        if ($status === DependencyEnricher::STATUS_MET) {
                            continue;
                        }

                        $item = [
                            'direction' => 'dependent',
                            'dependent_type' => $dependentType,
                            'dependent_identifier' => (string) $dependent->identifier,
                            'dependent_name' => DependencyEnricher::displayName($dependent, (string) $dependent->identifier),
                            'identifier' => $identifier,
                            'type' => $type,
                            'required_version' => $dependency['constraint'],
                            'installed_version' => $targetRecord?->version,
                            'status' => $status,
                        ];

                        $message = __('extensions.warnings.dependent_unmet', [
                            'dependent_type' => __('extensions.types.'.$dependentType),
                            'dependent' => $item['dependent_name'],
                            'identifier' => $identifier,
                            'required' => $dependency['constraint'],
                            'state' => DependencyEnricher::describeReason($item),
                        ]);
                        if ($dependentType === 'template') {
                            $message .= ' '.__('extensions.warnings.dependent_template_503');
                        }

                        $result[] = [...$item, 'message' => $message];
                    }
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('의존자 검사 실패 — 안내 없이 진행합니다', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 깨지는 의존자를 생명주기 안내(notice)로 남깁니다 — 관리자 결과 창과 CLI 가 보여 주고 종료 코드는 바꾸지 않습니다.
     *
     * `install --force`·멈춘 업데이트 정리·강제 비활성화 뒤에 부른다.
     *
     * @param  string  $type  상태가 바뀐 확장 종류 (`module`·`plugin`)
     * @param  string  $identifier  상태가 바뀐 확장 식별자
     * @param  bool  $versionOnly  판만 본다 ({@see forDependents()})
     * @return list<array<string, mixed>> 남긴 의존자 목록 ({@see forDependents()})
     */
    public static function noticeDependents(string $type, string $identifier, bool $versionOnly = false): array
    {
        $dependents = self::forDependents($type, $identifier, $versionOnly);

        foreach ($dependents as $dependent) {
            LifecycleWarnings::current()->notice('dependents', $dependent['dependent_identifier'], $dependent['message']);
        }

        if ($dependents !== []) {
            Log::warning('의존 대상 상태 변경 — 의존하는 확장이 요구 조건을 더는 충족하지 못합니다', [
                'type' => $type,
                'identifier' => $identifier,
                'dependents' => array_column($dependents, 'dependent_identifier'),
            ]);
        }

        return $dependents;
    }

    /**
     * 갱신 완료 문구 뒤에 의존 경고를 이어 붙입니다 — 관리자 업데이트 창은 서버가 보낸 완료 문구를 그대로 보여 준다.
     *
     * @param  string  $message  완료 문구
     * @param  array<int, array{message: string, direction?: string}>  $warnings  {@see forDependencies()}·{@see forDependents()} 결과
     * @return string 경고를 덧붙인 문구 (경고가 없으면 그대로)
     */
    public static function appendToMessage(string $message, array $warnings): string
    {
        // 자기 의존(이 확장이 요구하는 것)과 역방향(이 확장을 요구하는 의존자, direction=dependent)은 문장이 다르다
        $own = array_values(array_filter($warnings, fn (array $w) => ($w['direction'] ?? null) !== 'dependent'));
        $dependents = array_values(array_filter($warnings, fn (array $w) => ($w['direction'] ?? null) === 'dependent'));

        if ($own !== []) {
            $message .= ' '.__('extensions.warnings.dependency_summary', [
                'list' => implode(' / ', array_column($own, 'message')),
            ]);
        }

        if ($dependents !== []) {
            $message .= ' '.implode(' ', array_column($dependents, 'message'));
        }

        return $message;
    }

    /**
     * 갱신이 끝난 확장의 경고를 모아 로그에 남기고 돌려줍니다 — 세 매니저의 update 성공 경로가 부른다.
     *
     * @param  string  $type  갱신한 확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  갱신한 확장 식별자
     * @param  string  $manifestPath  갱신된 manifest 파일 경로
     * @return list<array{identifier: string, name: string, type: string, required_version: string, installed_version: string|null, is_active: bool, message: string}> 충족되지 않은 의존 목록
     */
    public static function afterUpdate(string $type, string $identifier, string $manifestPath): array
    {
        $warnings = self::forManifestFile($manifestPath);

        if ($warnings !== []) {
            Log::warning('확장 업데이트 완료 — 의존 확장이 요구 버전에 못 미칩니다', [
                'type' => $type,
                'identifier' => $identifier,
                'unmet' => array_map(fn (array $w) => $w['identifier'].' '.$w['required_version'].' (installed: '.($w['installed_version'] ?? '-').')', $warnings),
            ]);
        }

        return $warnings;
    }

    /**
     * 갱신이 끝난 확장의 선택 연동(`integrations`) 안내를 모아 로그에 남기고 돌려줍니다 — 의존 경고의 연동 변형 (7.1.4 D10).
     *
     * 의존과 달리 연동은 없어도 동작하므로 경고가 아니라 안내다. 새 판 manifest 의 연동 대상 중 미설치·비활성·버전 미달인 것을
     * 결과(`integration_notices`)·완료 문구로 알린다.
     *
     * @param  string  $type  갱신한 확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  갱신한 확장 식별자
     * @param  string  $manifestPath  갱신된 manifest 파일 경로
     * @return list<array{type: string, identifier: string, name: string, state: string, required_version: string, installed_version: string|null, message: string}> 연동 안내 목록
     */
    public static function integrationsAfterUpdate(string $type, string $identifier, string $manifestPath): array
    {
        $notices = IntegrationNotices::forManifestFile($manifestPath);

        if ($notices !== []) {
            Log::info('확장 업데이트 완료 — 연동 확장 일부가 없거나 꺼져 있거나 버전이 모자랍니다', [
                'type' => $type,
                'identifier' => $identifier,
                'integrations' => array_map(fn (array $n) => $n['identifier'].' '.$n['state'].' '.$n['required_version'].' (installed: '.($n['installed_version'] ?? '-').')', $notices),
            ]);
        }

        return $notices;
    }
}
