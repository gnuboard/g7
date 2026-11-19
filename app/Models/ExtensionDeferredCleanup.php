<?php

namespace App\Models;

use App\Enums\DeferredCleanupKind;
use Illuminate\Database\Eloquent\Model;

/**
 * 검수 서버가 미뤄 둔 선언 정리 한 건 — 「이 확장(또는 코어)의 선언 정리가 필요하다」.
 *
 * 지울 대상 목록은 적지 않는다. 운영 서버가 실행하는 순간의 그 서버 파일 선언과 공유 DB 로 다시 정한다.
 *
 * @property int $id
 * @property string $scope 소유 범위 (module|plugin|core)
 * @property string $identifier 확장 식별자 (코어는 빈 문자열)
 * @property DeferredCleanupKind $kind 정리 종류
 * @property string $target seeder 종류의 시더 클래스 (그 밖은 빈 문자열)
 * @property string|null $recorded_version 코어 정리를 적을 때의 코어 판
 * @property string|null $code_fingerprint 적을 때 검수 서버 코드 내용 지문 (확장은 활성 폴더, 코어는 config/core.php)
 *
 * @since 7.2.0
 */
class ExtensionDeferredCleanup extends Model
{
    /**
     * @var string 테이블 이름
     */
    protected $table = 'extension_deferred_cleanups';

    /**
     * @var array<int, string> 대량 할당 허용 컬럼
     */
    protected $fillable = ['scope', 'identifier', 'kind', 'target', 'recorded_version', 'code_fingerprint'];

    /**
     * 컬럼 형변환.
     *
     * @return array<string, string> 형변환 규칙
     */
    protected function casts(): array
    {
        return ['kind' => DeferredCleanupKind::class];
    }

    /**
     * 저장된 종류 문자열을 그대로 돌려줍니다 — 알 수 없는 값이어도 예외를 내지 않는다(DB 에서 읽는 값).
     *
     * @return string 종류 값
     */
    public function kindValue(): string
    {
        return (string) $this->getRawOriginal('kind', $this->attributes['kind'] ?? '');
    }

    /**
     * 종류를 열거형으로 돌려줍니다 — 알 수 없는 값이면 null.
     *
     * @return DeferredCleanupKind|null 종류
     */
    public function kindOrNull(): ?DeferredCleanupKind
    {
        return DeferredCleanupKind::tryFrom($this->kindValue());
    }
}
