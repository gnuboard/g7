<?php

namespace App\Models;

use App\Support\RuntimeRole;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * 템플릿 레이아웃 미리보기 모델
 *
 * @property int $id
 * @property string $token
 * @property int $template_id
 * @property string $layout_name
 * @property string $preview_type
 * @property int|null $extension_id
 * @property array $content
 * @property int $admin_id
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class TemplateLayoutPreview extends Model
{
    const UPDATED_AT = null;

    /**
     * @var string 테이블명
     */
    protected $table = 'template_layout_previews';

    /**
     * @var array<int, string> 대량 할당 가능 필드
     */
    protected $fillable = [
        'token',
        'template_id',
        'layout_name',
        'preview_type',
        'extension_id',
        'content',
        'admin_id',
        'expires_at',
        'runtime_role',
    ];

    /**
     * 실행 역할 컬럼이 있는지(마이그레이션 전 판에서 올라오는 첫 업데이트 대비) — 프로세스당 한 번 확인.
     */
    private static ?bool $hasRuntimeRoleColumn = null;

    /**
     * 실행 역할별로 행을 가릅니다 — 각 서버는 자기 역할의 미리보기만 보고 지운다(검수 서버의 미리보기 정리가 운영 서버 미리보기를
     * 지우지 않게). 만료 정리·템플릿 제거처럼 역할과 무관한 정리는 `withoutGlobalScope('runtime_role')` 로 부른다.
     *
     * @return void 반환값 없음
     */
    protected static function booted(): void
    {
        static::addGlobalScope('runtime_role', function (Builder $query): void {
            if (self::hasRuntimeRoleColumn()) {
                $query->where($query->getModel()->getTable().'.runtime_role', RuntimeRole::current());
            }
        });

        static::creating(function (self $preview): void {
            if (self::hasRuntimeRoleColumn() && empty($preview->runtime_role)) {
                $preview->runtime_role = RuntimeRole::current();
            }
        });
    }

    /**
     * 실행 역할 컬럼 존재 여부.
     *
     * @return bool 있으면 true
     */
    private static function hasRuntimeRoleColumn(): bool
    {
        try {
            return self::$hasRuntimeRoleColumn ??= Schema::hasColumn('template_layout_previews', 'runtime_role');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * 템플릿 관계
     *
     * @return BelongsTo 템플릿
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * 생성 관리자 관계
     *
     * @return BelongsTo 관리자
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * 만료되지 않은 미리보기만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 만료 전 행만 거른 쿼리
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
