<?php

namespace App\Support;

/**
 * DB 최고권한 계정 판정 (인스톨러·코어 공용 SSoT).
 *
 * 인스톨러(`public/install/`)는 Laravel 오토로드 없이 동작하는 순수 PHP 이므로
 * 이 클래스는 파사드·헬퍼 등 프레임워크 의존성을 일절 참조하지 않는다.
 * 인스톨러는 `require_once` 로 이 파일을 직접 로드해 사용한다.
 */
final class PrivilegedDatabaseAccounts
{
    /**
     * 차단 대상 DB 최고권한 계정명 (소문자 기준).
     *
     * `admin` 은 의도적으로 제외한다 — 일반 권한으로 운영 중인 `admin` 계정
     * 사용자가 업그레이드 후 오차단되는 것을 막기 위함이다.
     */
    public const BLOCKED = [
        'root',      // MySQL / MariaDB
        'postgres',  // PostgreSQL
        'sa',        // SQL Server
        'sys',       // Oracle
        'system',    // Oracle
    ];

    /**
     * 최고권한 계정명인지 판정합니다.
     *
     * 대소문자와 앞뒤 공백을 무시해 `ROOT`, ` root ` 같은 우회를 차단합니다.
     *
     * @param  string|null  $username  검사할 DB 사용자명
     * @return bool 최고권한 계정이면 true
     */
    public static function isBlocked(?string $username): bool
    {
        if ($username === null) {
            return false;
        }

        return in_array(self::normalize($username), self::BLOCKED, true);
    }

    /**
     * G7 이 사용할 수 있는 DB 사용자명인지 판정합니다.
     *
     * 빈 값(설정 누락)과 최고권한 계정을 모두 사용 불가로 봅니다.
     *
     * @param  string|null  $username  검사할 DB 사용자명
     * @return bool 사용 가능하면 true
     */
    public static function isUsable(?string $username): bool
    {
        if ($username === null || self::normalize($username) === '') {
            return false;
        }

        return ! self::isBlocked($username);
    }

    /**
     * DB 커넥션 설정에서 실제 사용되는 사용자명을 해석합니다.
     *
     * read/write 분리 설정인 경우 read 사용자명을 우선합니다. 이 우선순위 규칙이
     * 호출처마다 달라지면 같은 설정을 두고 서로 다른 판정이 나오므로 여기서만 정의합니다.
     *
     * @param  array  $config  DB 커넥션 설정 배열
     * @return string|null 해석된 사용자명 (없으면 null)
     */
    public static function resolveUsername(array $config): ?string
    {
        if (isset($config['read']['username'])) {
            return $config['read']['username'];
        }

        return $config['username'] ?? null;
    }

    /**
     * 비교용으로 사용자명을 정규화합니다.
     *
     * @param  string  $username  원본 사용자명
     * @return string 소문자·트림된 사용자명
     */
    private static function normalize(string $username): string
    {
        return strtolower(trim($username));
    }
}
