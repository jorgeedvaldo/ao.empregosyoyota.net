<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use CreatesPublicSchema;

    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_the_application_returns_a_successful_response()
    {
        $this->createPublicSchema();
        $this->seedContent();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
