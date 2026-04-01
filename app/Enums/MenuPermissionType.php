<?php

namespace App\Enums;

/**
 * 메뉴 권한 타입 Enum
 *
 * 메뉴에 대한 접근 권한 유형을 정의합니다.
 */
enum MenuPermissionType: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';

    /**
     * 한국어 라벨 반환
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => '읽기/접근',
            self::Write => '수정',
            self::Delete => '삭제',
        };
    }
}
