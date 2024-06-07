<?php

namespace Tests\Repositories;

use DTApi\Models\User;
use DTApi\Models\UserMeta;
use DTApi\Models\UsersBlacklist;
use DTApi\Models\Type;
use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Town;
use DTApi\Models\UserLanguages;
use DTApi\Models\UserTowns;
use DTApi\Repository\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRepositoryTest extends TestCase
{
  use RefreshDatabase;

  protected $userRepository;

  public function setUp(): void
  {
    parent::setUp();
    $this->userRepository = new UserRepository(new User());
  }

  public function testCreateOrUpdateCustomer()
  {
    // Arrange
    $requestData = [
      'role' => 1, // Customer role ID
      'name' => 'John Doe',
      'company_id' => 1,
      'department_id' => 1,
      'email' => 'john@example.com',
      'dob_or_orgid' => 'DOB123',
      'phone' => '123456789',
      'mobile' => '987654321',
      'password' => 'password',
      'consumer_type' => 'paid',
      'customer_type' => 'type',
      'username' => 'User1',
      'post_code' => '12345',
      'address' => '123 Street, City',
      'city' => 'City',
      'town' => 'Town',
      'country' => 'Country',
      'reference' => 'yes',
      'additional_info' => 'Additional info',
      'cost_place' => 'cost place',
      'fee' => '50',
      'time_to_charge' => '30',
      'time_to_pay' => '40',
      'charge_ob' => 'Charge OB',
      'customer_id' => 'Customer ID',
      'charge_km' => 'Charge KM',
      'maximum_km' => 'Maximum KM',
      'translator_ex' => [1, 2, 3], // Translator IDs
    ];

    // Act
    $result = $this->userRepository->createOrUpdate(null, $requestData);

    // Assert
    $this->assertNotNull($result);
    $this->assertInstanceOf(User::class, $result);

    // Assert user fields
    $this->assertEquals($requestData['role'], $result->user_type);
    $this->assertEquals($requestData['name'], $result->name);
    $this->assertEquals($requestData['company_id'], $result->company_id);
    $this->assertEquals($requestData['department_id'], $result->department_id);
    $this->assertEquals($requestData['email'], $result->email);
    $this->assertEquals($requestData['dob_or_orgid'], $result->dob_or_orgid);
    $this->assertEquals($requestData['phone'], $result->phone);
    $this->assertEquals($requestData['mobile'], $result->mobile);

    // Assert user meta fields
    $userMeta = UserMeta::where('user_id', $result->id)->first();
    $this->assertEquals($requestData['consumer_type'], $userMeta->consumer_type);
    $this->assertEquals($requestData['customer_type'], $userMeta->customer_type);
    $this->assertEquals($requestData['username'], $userMeta->username);
    $this->assertEquals($requestData['post_code'], $userMeta->post_code);
    $this->assertEquals($requestData['address'], $userMeta->address);
    $this->assertEquals($requestData['city'], $userMeta->city);
    $this->assertEquals($requestData['town'], $userMeta->town);
    $this->assertEquals($requestData['country'], $userMeta->country);
    $this->assertEquals(1, $userMeta->reference); // 'yes' is converted to '1'

    // Assert blacklist
    $blacklist = UsersBlacklist::where('user_id', $result->id)->get();
    $this->assertCount(3, $blacklist); // Ensure 3 blacklisted translators were added

    // Assert company and department creation
    $this->assertNotNull($result->company_id);
    $this->assertNotNull($result->department_id);
  }

  public function testCreateOrUpdateTranslator()
  {
    // Arrange
    $requestData = [
      'role' => 2, // Translator role ID
      'name' => 'Jane Doe',
      'email' => 'jane@example.com',
      'dob_or_orgid' => 'DOB321',
      'phone' => '987654321',
      'mobile' => '123456789',
      'translator_type' => 'professional',
      'worked_for' => 'yes',
      'organization_number' => '12345',
      'gender' => 'female',
      'translator_level' => 'advanced',
      'additional_info' => 'Additional info',
      'post_code' => '54321',
      'address' => '456 Street, City',
      'address_2' => 'Address 2',
      'town' => 'Town',
      'user_language' => [1, 2, 3], // Language IDs
      'user_towns_projects' => [1, 2, 3], // Town IDs
    ];

    // Act
    $result = $this->userRepository->createOrUpdate(null, $requestData);

    // Assert
    $this->assertNotNull($result);
    $this->assertInstanceOf(User::class, $result);

    // Assert user fields
    $this->assertEquals($requestData['role'], $result->user_type);
    $this->assertEquals($requestData['name'], $result->name);
    $this->assertEquals($requestData['email'], $result->email);
    $this->assertEquals($requestData['dob_or_orgid'], $result->dob_or_orgid);
    $this->assertEquals($requestData['phone'], $result->phone);
    $this->assertEquals($requestData['mobile'], $result->mobile);

    // Assert user meta fields
    $userMeta = UserMeta::where('user_id', $result->id)->first();
    $this->assertEquals($requestData['translator_type'], $userMeta->translator_type);
    $this->assertEquals($requestData['worked_for'], $userMeta->worked_for);
    $this->assertEquals($requestData['organization_number'], $userMeta->organization_number);
    $this->assertEquals($requestData['gender'], $userMeta->gender);
    $this->assertEquals($requestData['translator_level'], $userMeta->translator_level);
    $this->assertEquals($requestData['additional_info'], $userMeta->additional_info);
    $this->assertEquals($requestData['post_code'], $userMeta->post_code);
    $this->assertEquals($requestData['address'], $userMeta->address);
    $this->assertEquals($requestData['address_2'], $userMeta->address_2);
    $this->assertEquals($requestData['town'], $userMeta->town);

    // Assert user languages
    $userLanguages = UserLanguages::where('user_id', $result->id)->get();
    $this->assertCount(3, $userLanguages); // Ensure 3 languages were added

    // Assert user towns
    $userTowns = UserTowns::where('user_id', $result->id)->get();
    $this->assertCount(3, $userTowns); // Ensure 3 towns were added
  }

  // Add more tests as needed for other scenarios and edge cases

  protected function tearDown(): void
  {
    parent::tearDown();
  }
}

