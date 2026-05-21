<?php

namespace Modules\Sirsoft\Inquiry\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryAttachment extends Model
{
    protected $table = 'inquiry_attachments';

    protected $fillable = [
        'inquiry_id',
        'message_id',
        'uploader_user_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InquiryMessage::class, 'message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }
}
