<?php

test('returns a successful response', function () {
    config(['larasend.show_landing_page' => true]);

    $response = $this->get(route('home'));

    $response->assertOk();
});
