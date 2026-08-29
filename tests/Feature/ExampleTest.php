<?php

test('the root path redirects to the panel login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/admin/login');
});
