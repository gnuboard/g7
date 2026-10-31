<?php

namespace App\Extension\Helpers;

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

            $message = match (true) {
                $item['installed_version'] === null => __('extensions.warnings.dependency_not_installed', $params),
                ! $item['is_active'] => __('extensions.warnings.dependency_inactive', $params),
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
     * 갱신 완료 문구 뒤에 의존 경고를 이어 붙입니다 — 관리자 업데이트 창은 서버가 보낸 완료 문구를 그대로 보여 준다.
     *
     * @param  string  $message  완료 문구
     * @param  array<int, array{message: string}>  $warnings  {@see forDependencies()} 결과
     * @return string 경고를 덧붙인 문구 (경고가 없으면 그대로)
     */
    public static function appendToMessage(string $message, array $warnings): string
    {
        if ($warnings === []) {
            return $message;
        }

        return $message.' '.__('extensions.warnings.dependency_summary', [
            'list' => implode(' / ', array_column($warnings, 'message')),
        ]);
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
}
