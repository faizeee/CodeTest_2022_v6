<?php

namespace Tests\Helpers;

use Carbon\Carbon;
use DTApi\Helpers\TeHelper;
use PHPUnit\Framework\TestCase;

class TeHelperTest extends TestCase
{
  public function testWillExpireAtWithin90Minutes()
  {
    // Arrange
    $dueTime = Carbon::now()->addMinutes(60); // Due time 1 hour from now
    $createdAt = Carbon::now(); // Current time

    // Act
    $result = TeHelper::willExpireAt($dueTime, $createdAt);

    // Assert
    $this->assertNotNull($result);
    $this->assertInstanceOf(Carbon::class, Carbon::parse($result));
    $this->assertEquals($dueTime->format('Y-m-d H:i:s'), $result);
  }

  public function testWillExpireAtWithin24Hours()
  {
    // Arrange
    $dueTime = Carbon::now()->addHours(10); // Due time 10 hours from now
    $createdAt = Carbon::now(); // Current time

    // Act
    $result = TeHelper::willExpireAt($dueTime, $createdAt);

    // Assert
    $this->assertNotNull($result);
    $this->assertInstanceOf(Carbon::class, Carbon::parse($result));
    $this->assertGreaterThan($createdAt, Carbon::parse($result));
  }

  public function testWillExpireAtWithin72Hours()
  {
    // Arrange
    $dueTime = Carbon::now()->addHours(60); // Due time 60 hours from now
    $createdAt = Carbon::now(); // Current time

    // Act
    $result = TeHelper::willExpireAt($dueTime, $createdAt);

    // Assert
    $this->assertNotNull($result);
    $this->assertInstanceOf(Carbon::class, Carbon::parse($result));
    $this->assertEquals($createdAt->addHours(16)->format('Y-m-d H:i:s'), $result);
  }

  public function testWillExpireAtMoreThan72Hours()
  {
    // Arrange
    $dueTime = Carbon::now()->addHours(100); // Due time 100 hours from now
    $createdAt = Carbon::now(); // Current time

    // Act
    $result = TeHelper::willExpireAt($dueTime, $createdAt);

    // Assert
    $this->assertNotNull($result);
    $this->assertInstanceOf(Carbon::class, Carbon::parse($result));
    $this->assertEquals($dueTime->subHours(48)->format('Y-m-d H:i:s'), $result);
  }
}
