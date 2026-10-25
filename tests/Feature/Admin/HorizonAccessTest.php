<?php

use App\Models\User;

test('guests and non-admins cannot open horizon', function () {
    $this->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});

test('admins can open horizon', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();
});
