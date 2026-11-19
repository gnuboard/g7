<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 확장 신뢰 키 (운영자가 등록한 제작자 공개키) 모델.
 *
 * 이 키로 서명된 확장·언어팩은 `vendor_scope` 의 vendor 패키지에 한해 「서명 확인됨」이 된다. 코어 패키지는 이 키로
 * 검증하지 않는다(내장 키만). key_id 는 label·공개키로 서버가 계산한다.
 *
 * @property int $id 신뢰 키 ID
 * @property string $key_id 키 식별자 ({label 소문자}:{sha256(raw 공개키) 앞 16 hex})
 * @property string $public_key Ed25519 공개키 (raw 32바이트 표준 base64)
 * @property string $label 서명자 이름
 * @property array<int, string> $vendor_scope 이 키를 믿는 vendor 목록 (하나 이상)
 * @property int|null $created_by 등록한 관리자 ID
 * @property Carbon|null $created_at 등록 일시
 * @property Carbon|null $updated_at 수정 일시
 *
 * @since 7.2.0
 */
class ExtensionTrustKey extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'extension_trust_keys';

    /**
     * 일괄 할당 허용 칸
     *
     * @var list<string>
     */
    protected $fillable = [
        'key_id',
        'public_key',
        'label',
        'vendor_scope',
        'created_by',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 칸 이름 → 캐스트 종류
     */
    protected function casts(): array
    {
        return [
            'vendor_scope' => 'array',
            'created_by' => 'integer',
        ];
    }

    /**
     * 등록한 관리자
     *
     * @return BelongsTo 관리자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 이 키가 주어진 vendor 의 패키지를 믿는지 판정합니다.
     *
     * @param  string  $vendor  패키지 vendor
     * @return bool vendor 범위 안이면 true
     */
    public function coversVendor(string $vendor): bool
    {
        return in_array(strtolower($vendor), array_map('strtolower', (array) $this->vendor_scope), true);
    }
}
