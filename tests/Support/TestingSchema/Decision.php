<?php

namespace Tests\Support\TestingSchema;

/**
 * 테스트 스키마 재사용 판정(순수 함수)
 *
 * 재사용(RESET — 잔여 행만 정리)은 아래가 모두 참일 때만 고른다. 하나라도 어긋나면 전체 재생(FRESH)이다.
 *  1. 기록이 있다
 *  2. 강제 재생 요청이 없다(`G7_TEST_FRESH_SCHEMA`)
 *  3. 마이그레이션 지문이 같다 — 파일·참조 클래스·composer.lock·로직 판
 *  4. 환경 키가 같다 — DB 서버 판·문자셋·연결 설정
 *  5. DB `migrations` 테이블의 이름 집합이 정규 파일 이름 집합과 같다 — 반쪽 스키마·누락 실행 차단
 *  6. DB 실물 구조 지문이 기록과 같다 — 스키마를 바꾼 채 끝난 테스트·손 수정 차단
 * 5·6 은 앞 조건이 모두 통과했을 때만 계산한다(지연 평가 — 어차피 재생할 거면 조회 비용을 쓰지 않는다).
 */
final class Decision
{
    /** 전체 재생(migrate:fresh) */
    public const FRESH = 'FRESH';

    /** 스키마 재사용 + 잔여 행 정리 */
    public const RESET = 'RESET';

    /**
     * 판정 결과를 만듭니다(decide() 로만 만든다).
     *
     * @param  string  $action  판정 종류(FRESH 또는 RESET)
     * @param  string  $reason  판정 사유(로그용)
     */
    private function __construct(
        public readonly string $action,
        public readonly string $reason,
    ) {}

    /**
     * 재사용 여부를 판정합니다.
     *
     * 강제 재생 값 `1` 은 프로세스마다 처음 한 번만 재생한다. 그 밖의 값은 실행 ID 로 보고, 기록에 남은 ID 와
     * 다를 때만 재생한다 — 같은 ID 로 phpunit 을 여러 번 불러도 첫 프로세스만 재생하게 하려는 것이다.
     *
     * @param  array<string, mixed>|null  $record  기록(RecordStore::read() 결과)
     * @param  string  $fingerprint  현재 마이그레이션 지문
     * @param  string  $envKey  현재 환경 키
     * @param  string|null  $forceValue  `G7_TEST_FRESH_SCHEMA` 값(없으면 null 또는 빈 문자열)
     * @param  bool  $forcedInProcess  이 프로세스에서 `1` 로 이미 재생했는지
     * @param  callable(): array<int, string>  $migrationNames  DB `migrations` 테이블의 이름 목록
     * @param  callable(): string  $structureChecksum  DB 실물 구조 지문
     * @param  array<int, string>  $fileNames  정규 마이그레이션 파일 이름 목록
     * @return self 판정
     */
    public static function decide(
        ?array $record,
        string $fingerprint,
        string $envKey,
        ?string $forceValue,
        bool $forcedInProcess,
        callable $migrationNames,
        callable $structureChecksum,
        array $fileNames,
    ): self {
        if ($record === null) {
            return new self(self::FRESH, 'no-record');
        }

        if ($forceValue !== null && $forceValue !== '') {
            if ($forceValue === '1' && ! $forcedInProcess) {
                return new self(self::FRESH, 'forced');
            }
            if ($forceValue !== '1' && ($record['freshRunId'] ?? null) !== $forceValue) {
                return new self(self::FRESH, 'forced-run-id');
            }
        }

        if (($record['fingerprint'] ?? null) !== $fingerprint) {
            return new self(self::FRESH, 'fingerprint-changed');
        }

        if (($record['envKey'] ?? null) !== $envKey) {
            return new self(self::FRESH, 'env-changed');
        }

        $applied = array_values(array_unique($migrationNames()));
        $expected = array_values(array_unique($fileNames));
        sort($applied, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($applied !== $expected) {
            return new self(self::FRESH, 'migration-set-mismatch');
        }

        if (($record['checksum'] ?? null) !== $structureChecksum()) {
            return new self(self::FRESH, 'structure-changed');
        }

        return new self(self::RESET, 'reuse');
    }
}
