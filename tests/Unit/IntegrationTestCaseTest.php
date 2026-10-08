<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Integrations\Tests\Fixtures\TestDataResponse;
use Integrations\Tests\TestCase;

class IntegrationTestCaseTest extends TestCase
{
    public function test_data_from_validates_outside_requests(): void
    {
        $this->expectException(ValidationException::class);

        TestDataResponse::from(['data' => '']);
    }
}
