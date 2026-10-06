<?php

it('returns 404 with not_found for the root path', function (): void {
    $this->get('/')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});
