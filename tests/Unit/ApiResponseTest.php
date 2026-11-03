<?php

namespace Tests\Unit;

use App\Exceptions\ApiExceptionResponse;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_success_payload_matches_the_api_contract(): void
    {
        $response = ApiResponse::success(['status' => 'ok'], 'API is healthy.');

        $this->assertSame(200, $response->status());
        $this->assertSame([
            'success' => true,
            'message' => 'API is healthy.',
            'data' => ['status' => 'ok'],
        ], $response->getData(true));
    }

    public function test_error_payload_uses_an_object_when_there_are_no_field_errors(): void
    {
        $response = ApiResponse::error('Not found.', 404);

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('"errors":{}', $response->getContent());
    }

    public function test_validation_errors_keep_field_messages(): void
    {
        $exception = ValidationException::withMessages([
            'price' => ['The price field is required.'],
        ]);

        $response = ApiExceptionResponse::from($exception);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            ['The price field is required.'],
            $response->getData(true)['errors']['price'],
        );
    }

    public function test_unexpected_errors_hide_the_exception_message_when_debug_is_off(): void
    {
        Config::set('app.debug', false);

        $response = ApiExceptionResponse::from(new RuntimeException('SQL leaked'));

        $this->assertSame(500, $response->status());
        $this->assertSame('Server error.', $response->getData(true)['message']);
        $this->assertStringNotContainsString('SQL leaked', $response->getContent());
    }
}
