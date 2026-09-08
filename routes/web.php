<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('organizations/create', 'pages::organizations.create')->name('organizations.create');

    // Not under 'tenant': accepting an invitation is how a user joins a new
    // org, so there's no current tenant to require yet.
    Route::livewire('invitations/{token}', 'pages::invitations.accept')->name('invitations.accept');
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('members', 'pages::members.index')->name('members.index');

    Route::livewire('broadcasts', 'pages::broadcasts.index')->name('broadcasts.index');
    Route::livewire('broadcasts/{broadcast}', 'pages::broadcasts.show')->name('broadcasts.show');

    Route::livewire('team', 'pages::team.index')->name('team.index');
});

require __DIR__.'/settings.php';
