<?php

use App\Http\Controllers\FactController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SiteController;
use App\Http\Middleware\OwnerPassword;
use Illuminate\Support\Facades\Route;

// Public, published websites.
Route::get('/s/{slug}', [PublishController::class, 'show'])->name('public.site');
Route::get('/media/{source}', [PublishController::class, 'media'])->name('media');

// The builder.
Route::middleware(OwnerPassword::class)->group(function () {
    Route::get('/', [SiteController::class, 'index'])->name('sites.index');
    Route::get('/sites/new', [SiteController::class, 'create'])->name('sites.create');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::get('/sites/{site}/status', [SiteController::class, 'status'])->name('sites.status');
    Route::get('/sites/{site}/preview', [SiteController::class, 'preview'])->name('sites.preview');
    Route::patch('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
    Route::post('/sites/{site}/retry', [SiteController::class, 'retry'])->name('sites.retry');
    Route::post('/sites/{site}/rebuild', [SiteController::class, 'rebuild'])->name('sites.rebuild');
    Route::post('/sites/{site}/sources', [SiteController::class, 'addSource'])->name('sites.sources.store');
    Route::post('/sites/{site}/publish', [PublishController::class, 'publish'])->name('sites.publish');
    Route::post('/sites/{site}/unpublish', [PublishController::class, 'unpublish'])->name('sites.unpublish');

    Route::post('/questions/{question}/answer', [QuestionController::class, 'answer'])->name('questions.answer');
    Route::post('/questions/{question}/skip', [QuestionController::class, 'skip'])->name('questions.skip');

    Route::post('/sites/{site}/facts', [FactController::class, 'store'])->name('facts.store');
    Route::patch('/facts/{fact}', [FactController::class, 'update'])->name('facts.update');
    Route::delete('/facts/{fact}', [FactController::class, 'destroy'])->name('facts.destroy');

    Route::patch('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::post('/sections/{section}/regenerate', [SectionController::class, 'regenerate'])->name('sections.regenerate');
    Route::post('/sections/{section}/toggle', [SectionController::class, 'toggle'])->name('sections.toggle');
    Route::post('/sections/{section}/move', [SectionController::class, 'move'])->name('sections.move');
    Route::post('/revisions/{revision}/restore', [SectionController::class, 'restore'])->name('revisions.restore');
});
