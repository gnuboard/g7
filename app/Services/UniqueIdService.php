<?php

namespace App\Services;

use App\Contracts\UniqueIdServiceInterface;
use App\Extension\HookManager;
use Illuminate\Support\Str;

/**
 * 코어 고유 ID 생성 서비스
 *
 * UUID v7 및 NanoID 생성 기능을 제공합니다.
 * 이커머스 SequenceService의 NanoID 알고리즘을 코어 레벨로 추출한 서비스입니다.
 */
class UniqueIdService implements UniqueIdServiceInterface
{
    /**
     * 기본 NanoID 알파벳 (URL-safe)
     */
    private const DEFAULT_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_';

    /**
     * UUID v7을 생성합니다.
     *
     * Str::orderedUuid()를 사용하여 시간 기반 정렬 가능한 UUID를 생성합니다.
     * DB 인덱스 성능이 우수합니다.
     *
     * @return string UUID v7 문자열 (36자)
     */
    public function generateUuid(): string
    {
        HookManager::doAction('core.unique_id.before_generate', 'uuid');

        $uuid = Str::orderedUuid()->toString();

        HookManager::doAction('core.unique_id.after_generate', 'uuid', $uuid);

        return $uuid;
    }

    /**
     * NanoID를 생성합니다.
     *
     * random_bytes() 기반으로 암호학적으로 안전한 랜덤 문자열을 생성합니다.
     * Rejection sampling으로 모듈로 편향을 방지합니다.
     *
     * @param int $length 생성할 문자열 길이
     * @param string|null $alphabet 사용할 문자 집합 (null이면 기본 알파벳)
     * @return string 생성된 NanoID
     */
    public function generateNanoId(int $length = 16, ?string $alphabet = null): string
    {
        $alphabet = $alphabet ?? self::DEFAULT_ALPHABET;
        $alphabetSize = strlen($alphabet);

        HookManager::doAction('core.unique_id.before_generate', 'nanoid');

        // 편향 없는 최대값 (rejection sampling 임계값)
        $maxValid = 256 - (256 % $alphabetSize);

        $id = '';
        while (strlen($id) < $length) {
            $bytes = random_bytes($length - strlen($id));
            for ($i = 0, $len = strlen($bytes); $i < $len && strlen($id) < $length; $i++) {
                $byte = ord($bytes[$i]);
                // 편향 방지: maxValid 이상의 값은 버림
                if ($byte < $maxValid) {
                    $id .= $alphabet[$byte % $alphabetSize];
                }
            }
        }

        HookManager::doAction('core.unique_id.after_generate', 'nanoid', $id);

        return $id;
    }
}
