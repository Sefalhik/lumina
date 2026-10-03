<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Laravel's error handler drops every deprecation while the application runs in tests, so
        // PHPUnit reported none however many there were. This turns each one into an exception.
        // A deprecation a test cannot avoid — raised by a package, not by this code — is let
        // through with withDeprecationHandling() in that test, and the reason in a comment.
        $this->withoutDeprecationHandling();
    }
}
