<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 값을 바꾸지 않은 저장이 남긴 거짓 "운영자 수정" 표식을 지웁니다 (#727 W8).
 *
 * 7.0.x 까지는 본인인증 정책 편집 창이 보낸 칸 전부를, 알림·본인인증 메시지 템플릿은 표기만 다른 값(앞뒤 공백·빈 문자열과
 * null·`[]`와 null)을 "수정" 으로 기록했습니다. 그 표식이 붙은 칸은 새 버전의 문구·정책 교정을 영영 받지 못합니다.
 * 7.1.0 은 실제로 바뀐 칸만 기록하므로, 이미 쌓인 거짓 표식을 여기서 한 번 정리합니다.
 *
 * 규칙: 표식된 칸마다 지금 값과 **7.1.0 코어 선언값**을 비교해 같으면 표식을 지웁니다. 템플릿은 표식이 비면 「기본」 으로
 * 되돌리고, 정의는 소속 템플릿이 전부 기본이면 「기본」 으로 되돌립니다. 다국어 칸(`body.ko`)은 선언에 있는 로케일만
 * 판정합니다(언어팩이 넣은 로케일은 판정 근거가 없어 그대로 둡니다).
 *
 * 비교 기준은 실행 시점의 `config('core.*')` 가 아니라 이 디렉터리에 동결한 7.1.0 선언 사본입니다(업그레이드 스텝 가이드
 * §13 — 버전별 데이터 스냅샷). 7.0.x → 7.3.0 처럼 여러 버전을 한 번에 건너뛰면 이 스텝은 7.3.0 코드 위에서 도는데,
 * 그때의 live config 와 비교하면 "7.1.0 으로 한 단계씩 올린 설치본" 과 결과가 달라집니다.
 *  - `upgrades/data/7.1.0/identity_policies.snapshot.json` — `config/core.php` 의 identity_policies (키 => 선언)
 *  - `upgrades/data/7.1.0/notification_definitions.snapshot.json` — notification_definitions 의 type => templates
 *  - `upgrades/data/7.1.0/identity_messages.snapshot.json` — identity_messages 의 식별 3칸(provider_id·scope_type·
 *    scope_value) + templates
 * 세 파일은 7.1.0 의 `config/core.php` 에서 기계적으로 뽑은 값이며 이후 버전에서 고치지 않습니다. 파일이 없거나
 * 읽을 수 없으면 해당 대상만 건너뜁니다(표식을 지우지 않는 쪽이 안전).
 *
 * 대상은 **코어 선언** 행뿐입니다 — 모듈·플러그인 선언을 읽으려면 확장 클래스를 불러야 해 V-1(코어 로직 비의존)에
 * 어긋납니다. 그 행들은 업데이트 때 동기화의 "합의 해제 규칙"(값이 같아지면 표식 해제)이 지웁니다.
 *
 * V-1 판단: 동결 JSON 과 DB·Schema 파사드만 읽습니다. 비교 규칙은 모델의 `UserOverrideValueComparator` 를 부르지 않고
 * 여기에 동결합니다.
 *
 * idempotent: 두 번째 실행에서는 지울 표식이 없어 아무것도 바꾸지 않습니다.
 */
class PruneFalseUserOverrides implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'PruneFalseUserOverrides';
    }

    /**
     * 코어 선언 행의 거짓 표식을 정리합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $this->prunePolicies($context);
        $this->pruneTemplates($context, 'notification_templates', 'notification_definitions', $this->notificationDeclarations($context));
        $this->pruneTemplates($context, 'identity_message_templates', 'identity_message_definitions', $this->identityMessageDeclarations($context));
    }

    /**
     * 본인인증 정책(코어 선언)의 거짓 표식을 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function prunePolicies(UpgradeContext $context): void
    {
        $table = 'identity_policies';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_overrides')) {
            $context->logger->info("[core:7.1.0] {$table} 부재 — 스킵");

            return;
        }

        $declared = $this->frozenDeclarations($context, 'identity_policies');
        if ($declared === null) {
            return;
        }
        $removed = 0;

        $rows = DB::table($table)->where('source_type', 'core')->whereNotNull('user_overrides')->get();
        foreach ($rows as $row) {
            $overrides = $this->decode($row->user_overrides);
            $declaration = $declared[$row->key] ?? null;
            if (! is_array($overrides) || $overrides === [] || ! is_array($declaration)) {
                continue;
            }

            $remaining = [];
            foreach ($overrides as $field) {
                if (is_string($field) && array_key_exists($field, $declaration) && $this->same($this->columnValue($row->{$field} ?? null), $declaration[$field])) {
                    $removed++;

                    continue;
                }
                $remaining[] = $field;
            }

            if ($remaining !== $overrides) {
                DB::table($table)->where('id', $row->id)->update(['user_overrides' => $remaining === [] ? null : json_encode($remaining)]);
            }
        }

        $context->logger->info("[core:7.1.0] {$table} 거짓 수정 표식 정리 — 표식 제거 {$removed}칸, 기본 복귀 0행");
    }

    /**
     * 알림·본인인증 메시지 템플릿(코어 선언)의 거짓 표식을 지우고 「기본」 표시를 맞춥니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $table  템플릿 테이블
     * @param  string  $definitionTable  정의 테이블
     * @param  array<int, array{definition_id: int, channel: string, fields: array<string, mixed>}>  $declarations  선언 (정의 ID·채널별 칸 값)
     * @return void 반환값 없음
     */
    private function pruneTemplates(UpgradeContext $context, string $table, string $definitionTable, array $declarations): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_overrides')) {
            $context->logger->info("[core:7.1.0] {$table} 부재 — 스킵");

            return;
        }

        $removed = 0;
        $restored = 0;
        $touchedDefinitions = [];

        foreach ($declarations as $declaration) {
            $row = DB::table($table)
                ->where('definition_id', $declaration['definition_id'])
                ->where('channel', $declaration['channel'])
                ->first();
            if ($row === null) {
                continue;
            }

            $overrides = $this->decode($row->user_overrides) ?? [];
            if (! is_array($overrides) || $overrides === []) {
                continue;
            }

            $remaining = [];
            foreach ($overrides as $entry) {
                if (! is_string($entry)) {
                    $remaining[] = $entry;

                    continue;
                }

                [$field, $locale] = array_pad(explode('.', $entry, 2), 2, null);
                if (! array_key_exists($field, $declaration['fields'])) {
                    $remaining[] = $entry;

                    continue;
                }

                $current = $this->columnValue($row->{$field} ?? null);
                $wanted = $declaration['fields'][$field];

                if ($locale !== null) {
                    // 선언에 없는 로케일(언어팩이 넣은 값)은 판정 근거가 없어 표식을 남긴다
                    if (! is_array($wanted) || ! array_key_exists($locale, $wanted)) {
                        $remaining[] = $entry;

                        continue;
                    }
                    $current = is_array($current) ? ($current[$locale] ?? null) : null;
                    $wanted = $wanted[$locale];
                }

                if ($this->same($current, $wanted)) {
                    $removed++;

                    continue;
                }
                $remaining[] = $entry;
            }

            if ($remaining === $overrides) {
                continue;
            }

            $update = ['user_overrides' => $remaining === [] ? null : json_encode($remaining, JSON_UNESCAPED_UNICODE)];
            if ($remaining === [] && Schema::hasColumn($table, 'is_default')) {
                $update['is_default'] = 1;
                $restored++;
            }
            DB::table($table)->where('id', $row->id)->update($update);
            $touchedDefinitions[$declaration['definition_id']] = true;
        }

        if (Schema::hasColumn($definitionTable, 'is_default')) {
            foreach (array_keys($touchedDefinitions) as $definitionId) {
                $allDefault = ! DB::table($table)->where('definition_id', $definitionId)->where('is_default', 0)->exists();
                if ($allDefault) {
                    DB::table($definitionTable)->where('id', $definitionId)->update(['is_default' => 1]);
                }
            }
        }

        $context->logger->info("[core:7.1.0] {$table} 거짓 수정 표식 정리 — 표식 제거 {$removed}칸, 기본 복귀 {$restored}행");
    }

    /**
     * 동결한 7.1.0 코어 알림 정의 선언을 정의 ID·채널별 칸 값으로 펼칩니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (사본을 못 읽을 때 로그)
     * @return array<int, array{definition_id: int, channel: string, fields: array<string, mixed>}> 선언
     */
    private function notificationDeclarations(UpgradeContext $context): array
    {
        if (! Schema::hasTable('notification_definitions')) {
            return [];
        }

        $out = [];
        foreach ($this->frozenDeclarations($context, 'notification_definitions') ?? [] as $type => $definition) {
            $id = DB::table('notification_definitions')->where('type', $type)->where('extension_type', 'core')->value('id');
            if ($id === null) {
                continue;
            }
            foreach ((array) ($definition['templates'] ?? []) as $template) {
                $out[] = [
                    'definition_id' => (int) $id,
                    'channel' => (string) ($template['channel'] ?? ''),
                    'fields' => array_intersect_key(
                        $template + ['click_url' => null, 'recipients' => null, 'is_active' => true],
                        array_flip(['subject', 'body', 'click_url', 'recipients', 'is_active'])
                    ),
                ];
            }
        }

        return $out;
    }

    /**
     * 동결한 7.1.0 코어 본인인증 메시지 선언을 정의 ID·채널별 칸 값으로 펼칩니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (사본을 못 읽을 때 로그)
     * @return array<int, array{definition_id: int, channel: string, fields: array<string, mixed>}> 선언
     */
    private function identityMessageDeclarations(UpgradeContext $context): array
    {
        if (! Schema::hasTable('identity_message_definitions')) {
            return [];
        }

        $out = [];
        foreach ($this->frozenDeclarations($context, 'identity_messages') ?? [] as $definition) {
            $id = DB::table('identity_message_definitions')
                ->where('provider_id', $definition['provider_id'] ?? '')
                ->where('scope_type', $definition['scope_type'] ?? '')
                ->where('scope_value', (string) ($definition['scope_value'] ?? ''))
                ->where('extension_type', 'core')
                ->value('id');
            if ($id === null) {
                continue;
            }
            foreach ((array) ($definition['templates'] ?? []) as $template) {
                $out[] = [
                    'definition_id' => (int) $id,
                    'channel' => (string) ($template['channel'] ?? ''),
                    'fields' => array_intersect_key(
                        $template + ['is_active' => true],
                        array_flip(['subject', 'body', 'is_active'])
                    ),
                ];
            }
        }

        return $out;
    }

    /**
     * 동결한 7.1.0 코어 선언 사본(`upgrades/data/7.1.0/{kind}.snapshot.json` 의 `declarations`)을 읽습니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (못 읽을 때 로그)
     * @param  string  $kind  선언 종류 (identity_policies · notification_definitions · identity_messages)
     * @return array<string, array<string, mixed>>|null 키 => 선언, 파일이 없거나 깨졌으면 null
     */
    private function frozenDeclarations(UpgradeContext $context, string $kind): ?array
    {
        $path = dirname(__DIR__).'/'.$kind.'.snapshot.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded) || ! is_array($decoded['declarations'] ?? null)) {
            $context->logger->warning("[core:7.1.0] 동결 선언 사본 {$kind}.snapshot.json 을 읽을 수 없음 — 해당 대상 스킵");

            return null;
        }

        return $decoded['declarations'];
    }

    /**
     * 컬럼 원문을 값으로 읽습니다 (JSON 이면 디코드).
     *
     * @param  mixed  $raw  컬럼 원문
     * @return mixed 값
     */
    private function columnValue(mixed $raw): mixed
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || $raw === 'null')) {
                return $decoded;
            }
        }

        return $raw;
    }

    /**
     * JSON 원문을 디코드합니다.
     *
     * @param  mixed  $raw  원문
     * @return mixed 디코드 값
     */
    private function decode(mixed $raw): mixed
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    /**
     * 두 값이 같은 뜻인지 판정합니다 — 7.1.0 기록 판정 규칙을 동결한 사본(V-1).
     *
     * @param  mixed  $a  한쪽 값
     * @param  mixed  $b  다른 쪽 값
     * @return bool 같은 뜻이면 true
     */
    private function same(mixed $a, mixed $b): bool
    {
        return json_encode($this->normalize($a)) === json_encode($this->normalize($b));
    }

    /**
     * 비교용 정규형 — 문자열 trim·빈 문자열=null·숫자 문자열=숫자, 배열 재귀·전부 null 이면 null·연관 배열 키 정렬, bool=0/1.
     *
     * @param  mixed  $value  값
     * @return mixed 정규형
     */
    private function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            return is_numeric($value) ? $value + 0 : $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value + 0;
        }
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalize($item);
        }
        if (array_filter($normalized, fn ($item) => $item !== null) === []) {
            return null;
        }
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
