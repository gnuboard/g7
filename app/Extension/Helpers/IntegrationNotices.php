<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\Support\IntegrationReverseIndex;
use App\Support\ExtensionIntegrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 선택 연동(`integrations`) 생명주기 안내 — 막지 않고 알리기만 한다 (7.1.4).
 *
 * `dependencies` 는 없으면 설치·활성화를 막지만 `integrations` 는 「있으면 연동, 없어도 동작」이다. 그래도 운영자는
 * 무엇이 빠져 연동이 꺼져 있는지, 무엇을 끄면 어느 확장의 연동 화면이 사라지는지 알아야 한다. 이 헬퍼는 두 방향을 만든다.
 *
 * - 정방향 `integration_notices`: 설치·활성화·업데이트한 확장의 연동 대상 중 미설치·비활성·버전 미달인 것
 * - 역방향 `integrated_by`: 비활성화·제거하는 확장을 연동으로 선언한 활성 확장
 *
 * 관리자 API 응답·Artisan 출력이 같은 결과를 쓴다. 종료 코드·HTTP 상태는 바꾸지 않는다.
 */
final class IntegrationNotices
{
    /** 연동 대상이 설치되어 있지 않음 */
    public const STATE_NOT_INSTALLED = 'not_installed';

    /** 연동 대상이 설치됐지만 꺼져 있음 */
    public const STATE_INACTIVE = 'inactive';

    /** 연동 대상의 설치 버전이 요구 버전에 못 미침 */
    public const STATE_VERSION_MISMATCH = 'version_mismatch';

    /** 확장 유형 */
    private const TYPES = ['module', 'plugin', 'template'];

    /**
     * 연동 선언(목록형·버전 제약 객체형·정규화 결과)에서 충족되지 않은 대상만 안내 항목으로 만듭니다.
     *
     * 판정 순서는 의존 경고({@see DependencyVersionWarnings})와 같다 — 미설치 → 비활성 → 버전 미달.
     *
     * @param  mixed  $integrations  manifest 의 integrations 값
     * @return list<array{type: string, identifier: string, name: string, state: string, required_version: string, installed_version: string|null, message: string}> 안내 항목
     */
    public static function fromIntegrations(mixed $integrations): array
    {
        $notices = [];

        foreach (DependencyEnricher::enrichIntegrations($integrations) as $item) {
            $state = match (true) {
                ! $item['is_installed'] => self::STATE_NOT_INSTALLED,
                ! $item['is_active'] => self::STATE_INACTIVE,
                ! $item['is_met'] => self::STATE_VERSION_MISMATCH,
                default => null,
            };

            if ($state === null) {
                continue;
            }

            $notices[] = [
                'type' => $item['type'],
                'identifier' => $item['identifier'],
                'name' => $item['name'],
                'state' => $state,
                'required_version' => $item['required_version'],
                'installed_version' => $item['installed_version'],
                'message' => self::noticeMessage($item, $state),
            ];
        }

        return $notices;
    }

    /**
     * 설치·로드된 확장의 연동 선언에서 충족되지 않은 대상을 안내 항목으로 만듭니다.
     *
     * 안내 수집 실패가 생명주기 결과를 깨뜨리지 않도록 예외는 로그만 남기고 빈 목록을 돌려준다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return list<array{type: string, identifier: string, name: string, state: string, required_version: string, installed_version: string|null, message: string}> 안내 항목
     */
    public static function forExtension(string $type, string $identifier): array
    {
        try {
            return self::fromIntegrations(self::constraintsOf($type, $identifier));
        } catch (\Throwable $e) {
            Log::warning('연동 안내 수집 실패 — 안내 없이 진행합니다', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * manifest 파일(module.json·plugin.json·template.json)의 연동 선언을 검사합니다 — 업데이트 뒤 새 판 기준 (D10).
     *
     * @param  string  $manifestPath  manifest 파일 절대 경로
     * @return list<array{type: string, identifier: string, name: string, state: string, required_version: string, installed_version: string|null, message: string}> 안내 항목
     */
    public static function forManifestFile(string $manifestPath): array
    {
        try {
            if (! File::isFile($manifestPath)) {
                return [];
            }

            $manifest = json_decode(File::get($manifestPath), true);

            return is_array($manifest) ? self::fromIntegrations($manifest['integrations'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('연동 안내 수집 실패 — 안내 없이 진행합니다', ['manifest' => $manifestPath, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 지정한 확장을 연동으로 선언한 활성 확장 목록을 반환합니다 (자기 자신 제외).
     *
     * @param  string  $type  대상 확장 유형 (module|plugin|template)
     * @param  string  $identifier  대상 확장 식별자
     * @return list<array{type: string, identifier: string, name: string}> 연동 중인 활성 확장
     */
    public static function integratedBy(string $type, string $identifier): array
    {
        if (! in_array($type, self::TYPES, true)) {
            return [];
        }

        // 목록 응답은 행마다 이 함수를 부른다 — 켜진 확장 전체 조회·manifest 스캔은 요청당 한 번만 한다(역맵, 요청 스코프)
        try {
            return app(IntegrationReverseIndex::class)->integratedBy($type, $identifier);
        } catch (\Throwable $e) {
            Log::warning('연동 역조회 실패 — 안내 없이 진행합니다', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 완료 문구 뒤에 연동 안내 한 줄을 이어 붙입니다 (안내가 없으면 그대로).
     *
     * @param  string  $message  완료 문구
     * @param  array<int, array{message: string}>  $notices  {@see fromIntegrations()} 결과
     * @return string 안내를 덧붙인 문구
     */
    public static function appendToMessage(string $message, array $notices): string
    {
        if ($notices === []) {
            return $message;
        }

        return $message.' '.self::summary($notices);
    }

    /**
     * 연동 안내 한 줄 요약을 만듭니다.
     *
     * @param  array<int, array{message: string}>  $notices  {@see fromIntegrations()} 결과
     * @return string 요약 문구
     */
    public static function summary(array $notices): string
    {
        return __('extensions.integrations.notice_summary', [
            'list' => implode(' / ', array_column($notices, 'message')),
        ]);
    }

    /**
     * 확장의 연동 선언을 「식별자 => 버전 제약」 맵으로 읽습니다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return array{modules: array<string, string>, plugins: array<string, string>, templates: array<string, string>} 유형별 제약 맵
     */
    private static function constraintsOf(string $type, string $identifier): array
    {
        $empty = ExtensionIntegrations::constraints([]);

        return match ($type) {
            'module' => app(ModuleManagerInterface::class)->getModule($identifier)?->getIntegrationConstraints() ?? $empty,
            'plugin' => app(PluginManagerInterface::class)->getPlugin($identifier)?->getIntegrationConstraints() ?? $empty,
            'template' => app(TemplateManagerInterface::class)->getIntegrationConstraints($identifier),
            default => $empty,
        };
    }

    /**
     * 안내 항목 하나의 문구를 만듭니다.
     *
     * @param  array{identifier: string, name: string, required_version: string, installed_version: string|null}  $item  보강된 연동 항목
     * @param  string  $state  안내 상태
     * @return string 문구
     */
    private static function noticeMessage(array $item, string $state): string
    {
        return __('extensions.integrations.notice_'.$state, [
            'name' => $item['name'],
            'identifier' => $item['identifier'],
            'required' => $item['required_version'],
            'installed' => (string) ($item['installed_version'] ?? ''),
        ]);
    }
}
