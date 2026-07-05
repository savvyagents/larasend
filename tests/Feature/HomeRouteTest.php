<?php

use App\Models\User;

test('home redirects to registration when the instance has no owner yet', function () {
    $this->get(route('home'))->assertRedirect(route('register'));
});

test('home redirects to login once the instance has an owner', function () {
    User::factory()->create();

    $this->get(route('home'))->assertRedirect(route('login'));
});

test('home renders the marketing landing page when explicitly enabled', function () {
    config(['larasend.show_landing_page' => true]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome'));
});

test('home still renders the landing page for signed in users when enabled', function () {
    config(['larasend.show_landing_page' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome'));
});
