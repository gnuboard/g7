<?php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryController;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

Route::bind('inquiry', fn ($value) => Inquiry::where('uuid', $value)->firstOrFail());

Route::prefix('inquiries')
    ->middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('inquiries.')
    ->group(function () {
        Route::get('/', [InquiryController::class, 'index'])->name('index');
        Route::post('/', [InquiryController::class, 'store'])->name('store');
        Route::get('/{inquiry}', [InquiryController::class, 'show'])->name('show');
        Route::patch('/{inquiry}', [InquiryController::class, 'update'])->name('update');
        Route::post('/{inquiry}/cancel', [InquiryController::class, 'cancel'])->name('cancel');
        Route::get('/{inquiry}/messages', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryMessageController::class, 'index'])->name('messages.index');
        Route::post('/{inquiry}/messages', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryMessageController::class, 'store'])->name('messages.store');
        Route::post('/{inquiry}/attachments', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryAttachmentController::class, 'uploadInquiryBody'])
            ->name('attachments.inquiry-body');
        Route::post('/{inquiry}/messages/attachments', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryAttachmentController::class, 'uploadMessage'])
            ->name('attachments.message');
    });

Route::middleware(['auth:sanctum', 'throttle:600,1'])
    ->name('inquiry-attachments.')
    ->group(function () {
        Route::get('/attachments/{attachment}', [\Modules\Sirsoft\Inquiry\Http\Controllers\User\InquiryAttachmentController::class, 'download'])
            ->name('download');
    });
