<?php

test('saras login credentials are exposed through configuration', function () {
    $sarasConfig = config('saras');

    expect($sarasConfig)->toHaveKey('username')
        ->and($sarasConfig)->toHaveKey('password');
});
