<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulatesMysqlFailures;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use SimulatesMysqlFailures;

    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_the_application_returns_a_successful_response()
    {
        $this->createPublicSchema();
        $this->seedPublicContent();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
