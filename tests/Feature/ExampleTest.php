<?php

it('redirects the root to the environment check page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/environment-check');
});
