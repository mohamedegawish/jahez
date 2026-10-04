<?php

/*
 * The project is API-only (OQ-34): no web pages, so nothing outside /api starts a
 * session. Only the framework's liveness probe answers outside the API.
 */
test('serves no web page at the root URL, only the liveness probe outside the API', function () {
    $this->get('/')->assertNotFound();
    $this->get('/up')->assertOk();
});
