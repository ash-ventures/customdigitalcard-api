<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_server_errors_return_a_specific_traceable_message(): void
    {
        Route::get('/api/v1/testing/boom', function () {
            throw new RuntimeException('Database is unavailable');
        });

        $response = $this->getJson('/api/v1/testing/boom');

        $response->assertStatus(500)
            ->assertJsonStructure(['message', 'error_code']);

        $message = (string) $response->json('message');
        $code = (string) $response->json('error_code');

        // Never the old vague text, and always something support can trace.
        $this->assertStringContainsString('Ralat pelayan', $message);
        $this->assertStringContainsString($code, $message);
        $this->assertStringStartsWith('MD-', $code);
    }

    public function test_missing_records_and_validation_failures_explain_themselves(): void
    {
        Route::get('/api/v1/testing/missing', function () {
            abort(404);
        });

        $this->getJson('/api/v1/testing/missing')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'http_404')
            ->assertJsonPath('message', 'Rekod yang diminta tidak ditemui. Ia mungkin telah dipadamkan atau pautannya tidak sah.');

        Route::post('/api/v1/testing/validate', function () {
            request()->validate(['email' => ['required', 'email']]);
        });

        $this->postJson('/api/v1/testing/validate', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Medan emel mesti alamat emel yang sah.')
            ->assertJsonPath('error_code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'Medan emel mesti alamat emel yang sah.');
    }
}
