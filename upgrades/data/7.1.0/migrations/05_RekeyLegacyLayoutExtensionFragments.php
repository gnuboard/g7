<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 조각 식별자 마이그레이션이 대상 이름으로 백필한 옛 확장 조각 행을 소스 파일 키로 옮깁니다 (#712).
 *
 * 7.0.x 의 확장 조각 행은 대상(확장점·레이아웃) 단위로 하나였고, `fragment_key` 컬럼을 더한 마이그레이션은 그 키를
 * 대상 이름으로 채웠다. 7.1.0 의 등록은 조각 파일 이름을 키로 쓰므로, 옮기지 않으면 첫 업데이트·갱신에서
 *  - 템플릿 오버라이드 옛 행은 소스에 없는 키로 보여 정리되고(운영자가 고친 내용이 사라진다),
 *  - 같은 대상에 조각이 둘 이상인 확장의 옛 행은 어느 조각인지 몰라 남거나(고쳤으면 keep 정리가 남긴다 — 새 행과 함께
 *    두 번 주입된다) 지워진다.
 * 대상당 조각이 하나뿐인 모듈·플러그인 옛 행은 등록 경로도 이어 붙이지만(런타임 승계), 이 스텝은 업그레이드 시점에
 * 한 번에 같은 규칙으로 정리해 첫 등록 전에 상태를 맞춘다.
 *
 * 규칙 — 대상 행: `fragment_key = target_name` 인 행(삭제된 행 포함). 출처 디렉토리의 조각 파일을 읽어
 * (파일 기본 이름 → 대상) 표를 만든다. 모듈·플러그인은 `{modules|plugins}/{식별자}/resources/extensions/`, 템플릿
 * 오버라이드는 `templates/{템플릿 식별자}/extensions/{대상 확장}/` (활성 디렉토리).
 *  - 같은 대상을 겨냥한 파일 중 하나의 기본 이름이 대상 이름과 같으면 드리프트가 아니다 — 건너뛴다.
 *  - 후보 파일 정확히 1개: 새 키 행이 없으면 키만 바꾼다. 새 키 행이 이미 있으면 병합 — 옛 행이 운영자 수정본이면
 *    새 키 행을 지우고 옛 행에 새 키를 준다, 아니면 옛 행을 지운다(삭제된 옛 행은 병합하지 않는다). 새 키 행도
 *    살아 있는 운영자 수정본이면 둘 다 그대로 두고 경고와 함께 보류한다.
 *  - 후보 2개 이상: 옛 행의 원본 해시가 어느 후보 파일(짝 `.json`)의 현재 정의 해시와 같으면 그 키로 위 규칙을
 *    적용한다. 아무것도 안 맞고 옛 행이 무수정이면 옛 행을 지운다(다음 등록이 파일별 행을 만든다). 수정됐으면
 *    **손대지 않고** 경고를 남긴다 — 어느 조각이었는지 운영자가 판단할 몫이다.
 *  - 후보 0개(출처 디렉토리 없음·삭제된 확장): 손대지 않는다.
 *  - 이미 지워진 옛 행 옆에 새 키 행이 있으면(새 키 행의 삭제 여부와 무관) 정리가 끝난 것이다: 손대지 않고 「정리됨」으로
 *    센다(보류 아님).
 * 행은 하나씩 트랜잭션으로 바꾸고, 결과 요약(재키잉·병합·삭제·보류·정리됨·건너뜀)을 로그로 남긴다. 건너뜀은 출처
 * 디렉토리·후보 파일이 없거나, 대상 이름과 같은 파일이 있거나, 이미 지워진 무수정 행이라 바꾸지 않은 행이다.
 *
 * 조각 대상 판별(SSoT: `App\Extension\Traits\CollectsFragmentSources` 의 소스 규약과 마크업 컴파일)은 V-1 안전을 위해
 * 여기에 단순화해 동결했다 — 짝 `.json` 이 있으면 그 `extension_point`/`target_layout`, 없으면 `.g7.html` 의 첫
 * `<extension>`/`<override>` 여는 태그 속성(`extension-point`/`target-layout`)을 정규식으로 읽는다. 여는 태그 앞의
 * 주석 안에 같은 속성 문자열이 있으면 오판할 수 있어, 주석을 먼저 걷어 낸다. 키 파생(파일 기본 이름, 확장자
 * `.g7.html`/`.json` 제외)은 `LayoutFragmentSourceHelper::fragmentKey()` 와 같다. 수정 판정 해시는
 * `02_RehashLayoutOriginalContent` 가 맞춘 7.1.0 정규화(배열 디코드 후 재인코드)와 같다.
 *
 * V-1 안전: DB·Schema 파사드와 리터럴·파일 읽기만 쓴다 — 모델·Enum·서비스를 참조하지 않는다.
 *
 * idempotent: 옮긴 행은 키가 대상 이름과 달라 다음 실행의 대상이 아니다. 지운(soft delete) 옛 행은 새 키 행이 있으면
 * 병합하지 않으므로 두 번째 실행에서 바뀌는 것이 없다.
 */
class RekeyLegacyLayoutExtensionFragments implements DataMigration
{
    private const TABLE = 'template_layout_extensions';

    /** json_encode 플래그 — 7.1.0 해시 정규화 */
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 출처 디렉토리별 (키 → [type, target, jsonPath|null]) 표 캐시.
     *
     * @var array<string, array<string, array{type: string|null, target: string|null, json: string|null}>>
     */
    private array $fragmentTables = [];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RekeyLegacyLayoutExtensionFragments';
    }

    /**
     * 대상 이름으로 백필된 옛 조각 행을 파일 키로 옮기거나 정리합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'fragment_key') || ! Schema::hasTable('templates')) {
            $context->logger->info('[core:7.1.0] template_layout_extensions.fragment_key 부재 — 옛 조각 키 재키잉 스킵');

            return;
        }

        $summary = ['rekeyed' => 0, 'merged' => 0, 'deleted' => 0, 'held' => 0, 'settled' => 0, 'skipped' => 0];
        $templates = DB::table('templates')->pluck('identifier', 'id')->all();

        $legacyIds = DB::table(self::TABLE)->whereColumn('fragment_key', 'target_name')->orderBy('id')->pluck('id')->all();

        foreach ($legacyIds as $id) {
            $row = DB::table(self::TABLE)->where('id', $id)->first();

            if ($row === null || (string) $row->fragment_key !== (string) $row->target_name) {
                continue;
            }

            $directory = $this->sourceDirectory($row, $templates);

            if ($directory === null) {
                $summary['skipped']++;

                continue;
            }

            $candidates = $this->candidateKeys($directory, (string) $row->extension_type, (string) $row->target_name);

            // 파일 이름이 곧 대상 이름이면 드리프트가 아니다.
            if ($candidates === [] || in_array((string) $row->target_name, $candidates, true)) {
                $summary['skipped']++;

                continue;
            }

            if (count($candidates) > 1) {
                $matched = $this->matchByOriginalHash($directory, $candidates, $row->original_content_hash);

                if ($matched === null) {
                    if ($this->isOperatorModified($row)) {
                        $summary['held']++;
                        $context->logger->warning(sprintf(
                            '[core:7.1.0] 옛 조각 행 보류 — 같은 대상에 조각이 여럿이고 운영자가 고친 행이라 어느 조각인지 정할 수 없습니다 (id=%d, 대상=%s, 후보=%s)',
                            $row->id,
                            $row->target_name,
                            implode(',', $candidates)
                        ));

                        continue;
                    }

                    if ($row->deleted_at === null) {
                        DB::table(self::TABLE)->where('id', $row->id)->update(['deleted_at' => now()]);
                        $summary['deleted']++;
                    } else {
                        $summary['skipped']++;
                    }

                    continue;
                }

                $candidates = [$matched];
            }

            $result = $this->rekey($row, $candidates[0]);
            $summary[$result]++;

            if ($result === 'held') {
                $context->logger->warning(sprintf(
                    '[core:7.1.0] 옛 조각 행 보류 — 옛 행과 새 키 행이 모두 운영자가 고친 행이라 어느 쪽을 남길지 정할 수 없습니다 (id=%d, 새 키=%s, 대상=%s)',
                    $row->id,
                    $candidates[0],
                    $row->target_name
                ));
            }
        }

        $context->logger->info(sprintf(
            '[core:7.1.0] 옛 조각 키 재키잉 — 재키잉 %d · 병합 %d · 삭제 %d · 보류 %d · 정리됨 %d · 건너뜀 %d',
            $summary['rekeyed'],
            $summary['merged'],
            $summary['deleted'],
            $summary['held'],
            $summary['settled'],
            $summary['skipped']
        ));
    }

    /**
     * 옛 행 하나를 새 키로 옮기거나 같은 신원의 새 키 행과 병합합니다 (행 단위 트랜잭션).
     *
     * @param  object  $row  옛 행
     * @param  string  $newKey  파일 키
     * @return string 결과 종류 (`rekeyed`·`merged`·`deleted`·`settled`·`held`)
     */
    private function rekey(object $row, string $newKey): string
    {
        return DB::transaction(function () use ($row, $newKey): string {
            // 옛 행 자신은 제외한다 — 정렬 규칙이 대소문자를 가리지 않으면(`utf8mb4_unicode_ci`) 대소문자만 다른 파일 키
            // 조회가 옛 행 자신을 돌려주고, 운영자 수정본 병합 분기가 자기 자신을 지운다.
            $existing = DB::table(self::TABLE)
                ->where('id', '!=', $row->id)
                ->where('template_id', $row->template_id)
                ->where('extension_type', $row->extension_type)
                ->where('target_name', $row->target_name)
                ->where('source_type', $row->source_type)
                ->where('source_identifier', $row->source_identifier)
                ->where('fragment_key', $newKey)
                ->when($row->override_target === null, fn ($query) => $query->whereNull('override_target'), fn ($query) => $query->where('override_target', $row->override_target))
                ->first();

            if ($existing === null) {
                DB::table(self::TABLE)->where('id', $row->id)->update(['fragment_key' => $newKey]);

                return 'rekeyed';
            }

            // 지워진 옛 행은 병합 대상이 아니다 — 새 키 행이 이미 그 키를 맡았으므로(새 키 행이 지워졌어도 고유 키를
            // 차지해 옮길 수 없다) 정리가 끝난 행이다.
            // 운영자가 확인할 「보류」와 섞어 세면 7.0 을 거친 사이트의 요약이 수십 건의 보류로 찍힌다(로컬 실측 41건).
            if ($row->deleted_at !== null) {
                return 'settled';
            }

            if ($this->isOperatorModified($row)) {
                // 새 키 행도 살아 있는 운영자 수정본이면 어느 쪽을 지워도 수정본 하나가 사라진다 — 운영자 판단에 맡긴다.
                // 첫 업그레이드에는 새 키 행이 아직 없고, 업데이트 뒤 스텝을 다시 돌린 경우에만 생긴다.
                if ($existing->deleted_at === null && $this->isOperatorModified($existing)) {
                    return 'held';
                }

                // 운영자 수정본이 이긴다 — 새 키 행(파일본)을 지우고 옛 행이 그 키를 맡는다. 고유 키 충돌을 피하려면 먼저 지워야 한다.
                if (Schema::hasTable('template_layout_extension_versions')) {
                    DB::table('template_layout_extension_versions')->where('extension_id', $existing->id)->delete();
                }
                DB::table(self::TABLE)->where('id', $existing->id)->delete();
                DB::table(self::TABLE)->where('id', $row->id)->update(['fragment_key' => $newKey]);

                return 'merged';
            }

            DB::table(self::TABLE)->where('id', $row->id)->update(['deleted_at' => now()]);

            return 'deleted';
        });
    }

    /**
     * 옛 행의 출처 조각 디렉토리(활성 디렉토리)를 정합니다.
     *
     * @param  object  $row  옛 행
     * @param  array<int|string, string>  $templates  템플릿 id → 식별자
     * @return string|null 디렉토리 절대 경로, 정할 수 없으면 null
     */
    private function sourceDirectory(object $row, array $templates): ?string
    {
        $identifier = (string) $row->source_identifier;

        if ($identifier === '' || str_contains($identifier, '/') || str_contains($identifier, '\\') || str_contains($identifier, '..')) {
            return null;
        }

        return match ((string) $row->source_type) {
            'module' => base_path("modules/{$identifier}/resources/extensions"),
            'plugin' => base_path("plugins/{$identifier}/resources/extensions"),
            'template' => $row->override_target !== null && ! str_contains((string) $row->override_target, '..')
                ? base_path('templates/'.($templates[$row->template_id] ?? $identifier).'/extensions/'.$row->override_target)
                : null,
            default => null,
        };
    }

    /**
     * 디렉토리에서 주어진 (종류, 대상)을 겨냥한 조각 파일 키를 고릅니다.
     *
     * @param  string  $directory  조각 디렉토리
     * @param  string  $extensionType  `extension_point` | `overlay`
     * @param  string  $target  대상 이름
     * @return array<int, string> 후보 키
     */
    private function candidateKeys(string $directory, string $extensionType, string $target): array
    {
        $keys = [];

        foreach ($this->fragmentTable($directory) as $key => $info) {
            if ($info['type'] === $extensionType && $info['target'] === $target) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * 디렉토리의 조각 파일 표를 만듭니다 — 키 → 대상 (짝 `.json` 우선, 없으면 `.g7.html` 여는 태그).
     *
     * @param  string  $directory  조각 디렉토리
     * @return array<string, array{type: string|null, target: string|null, json: string|null}> 표
     */
    private function fragmentTable(string $directory): array
    {
        if (isset($this->fragmentTables[$directory])) {
            return $this->fragmentTables[$directory];
        }

        $table = [];

        if (is_dir($directory)) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                $key = basename($file, '.json');
                $table[$key] = $this->targetOf(is_array($data) ? $data['extension_point'] ?? null : null, is_array($data) ? $data['target_layout'] ?? null : null) + ['json' => $file];
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*.g7.html') ?: [] as $file) {
                $key = substr(basename($file), 0, -strlen('.g7.html'));

                if (isset($table[$key])) {
                    continue; // 짝 `.json` 이 대상을 이미 정했다
                }

                $source = preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file)) ?? '';
                $point = preg_match('/<(?:extension|override)\b[^>]*\bextension-point="([^"]+)"/', $source, $m) === 1 ? $m[1] : null;
                $layout = preg_match('/<(?:extension|override)\b[^>]*\btarget-layout="([^"]+)"/', $source, $n) === 1 ? $n[1] : null;
                $table[$key] = $this->targetOf($point, $layout) + ['json' => null];
            }
        }

        return $this->fragmentTables[$directory] = $table;
    }

    /**
     * 조각 선언에서 (종류, 대상)을 정합니다 — 두 방식을 함께 선언했거나 둘 다 없으면 대상이 정해지지 않는다.
     *
     * @param  mixed  $extensionPoint  `extension_point` 값
     * @param  mixed  $targetLayout  `target_layout` 값
     * @return array{type: string|null, target: string|null} 종류와 대상
     */
    private function targetOf(mixed $extensionPoint, mixed $targetLayout): array
    {
        $hasPoint = is_string($extensionPoint) && $extensionPoint !== '';
        $hasLayout = is_string($targetLayout) && $targetLayout !== '';

        if ($hasPoint === $hasLayout) {
            return ['type' => null, 'target' => null];
        }

        return $hasPoint
            ? ['type' => 'extension_point', 'target' => $extensionPoint]
            : ['type' => 'overlay', 'target' => $targetLayout];
    }

    /**
     * 옛 행의 원본 해시와 같은 정의를 가진 후보(짝 `.json`)를 찾습니다.
     *
     * @param  string  $directory  조각 디렉토리
     * @param  array<int, string>  $candidates  후보 키
     * @param  string|null  $originalHash  옛 행의 원본 해시
     * @return string|null 정확히 하나가 맞으면 그 키
     */
    private function matchByOriginalHash(string $directory, array $candidates, ?string $originalHash): ?string
    {
        if ($originalHash === null || $originalHash === '') {
            return null;
        }

        $table = $this->fragmentTable($directory);
        $matched = [];

        foreach ($candidates as $key) {
            $json = $table[$key]['json'] ?? null;

            if ($json === null) {
                continue;
            }

            $data = json_decode((string) file_get_contents($json), true);

            if (is_array($data) && hash('sha256', (string) json_encode($data, self::FLAGS)) === $originalHash) {
                $matched[] = $key;
            }
        }

        return count($matched) === 1 ? $matched[0] : null;
    }

    /**
     * 행이 등록 뒤 운영자에게 고쳐졌는지 판정합니다 (7.1.0 해시 정규화 — 배열 디코드 후 재인코드).
     *
     * 원본 해시가 없는 행은 판정 근거가 없어 고치지 않은 것으로 본다.
     *
     * @param  object  $row  행
     * @return bool 고쳤으면 true
     */
    private function isOperatorModified(object $row): bool
    {
        $stored = $row->original_content_hash ?? null;

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $content = json_decode((string) $row->content, true);

        return ! is_array($content) || hash('sha256', (string) json_encode($content, self::FLAGS)) !== $stored;
    }
}
