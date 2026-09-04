<?php

it('renders the environment check inertia page', function () {
    $response = $this->get('/environment-check');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('EnvironmentCheck'));
});
