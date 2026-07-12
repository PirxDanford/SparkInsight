# Test-Driven Development (TDD) Guide for SparkInsight

This guide explains how to implement features using Test-Driven Development (ADR 0006) while following SOLID Principles (ADR 0007). Every piece of new code in SparkInsight should be written using TDD.

## The TDD Cycle

TDD follows a simple three-step cycle:

1. **Red** 🔴 - Write a failing test that describes the desired behavior
2. **Green** 🟢 - Write minimal code to make the test pass
3. **Refactor** 🔵 - Improve code quality while keeping tests green

### Example: Red → Green → Refactor

**Red Phase:**
```php
public function testGenerateInvitationReturnsValidCode(): void
{
    $code = $this->invitationService->generateInvitation(['reviewer']);
    
    $this->assertIsString($code);
    $this->assertEquals(64, strlen($code)); // 32 bytes hex-encoded
}
```

**Green Phase:** Write minimal implementation
```php
public function generateInvitation(array $roles): string
{
    return bin2hex(random_bytes(32));
}
```

**Refactor Phase:** Add expiration logic, validation, etc.
```php
public function generateInvitation(array $roles, ?string $email = null, int $expirationHours = 24): string
{
    $code = bin2hex(random_bytes(32));
    $expiresAt = new \DateTime("+{$expirationHours} hours");
    
    // Store in database...
    
    return $code;
}
```

## Test Types

SparkInsight uses three types of tests:

### 1. **Unit Tests** (Fast, Isolated)
**Location:** `tests/Unit/`

Test a single class or method in isolation using mocks for dependencies.

**When to use:**
- Testing business logic
- Testing error handling
- Testing complex algorithms

**Example:**
```php
public function testUserServiceCreatesUserWithHashedPassword(): void
{
    $passwordHasher = $this->createMock(PasswordHasher::class);
    $passwordHasher->method('hash')->willReturn('hashed_password');
    
    $userService = new UserService($passwordHasher);
    $user = $userService->createUser('user@example.com', 'password');
    
    $this->assertEquals('hashed_password', $user->getPassword());
}
```

**Benefits:**
- Run in ~1ms per test
- Easy to debug failures
- Document expected behavior
- Support safe refactoring

### 2. **Integration Tests** (Moderate speed, Real dependencies)
**Location:** `tests/Integration/`

Test multiple classes working together with a real database or service.

**When to use:**
- Testing command execution with database
- Testing API flows (controller → service → repository)
- Testing workflow completeness

**Example:**
```php
public function testGenerateInvitationCommandStoresInDatabase(): void
{
    $command = new GenerateInvitationCommand($this->connection);
    $tester = new CommandTester($command);
    
    $exitCode = $tester->execute(['--reviewer' => true]);
    
    $this->assertSame(0, $exitCode);
    
    // Verify in real database
    $result = $this->connection->executeQuery('SELECT * FROM invitations LIMIT 1')
        ->fetchAssociative();
    $this->assertNotNull($result);
}
```

**Benefits:**
- Catch integration bugs
- Verify end-to-end workflows
- Test with real database (SQLite in-memory)

### 3. **Smoke Tests** (Quick sanity checks)
**Location:** `tests/Smoke/`

Basic tests verifying the application starts and main routes work.

**When to use:**
- Deployment verification
- Quick regression checks
- Health checks

## Coverage Requirements

- **Project code (src/):** 100% coverage required for new code
- **Vendor code:** Excluded from coverage calculation
- **Private methods:** Test through public methods, or test directly via reflection

Run coverage reports:
```bash
composer coverage          # Text summary
composer coverage-html     # HTML report in coverage-report/
```

Coverage must never decrease with new code.

## Writing Good Tests

### Test Naming Conventions

Use the pattern: `test[What][Condition][Expected Result]`

**Good:**
```php
public function testGenerateInvitationWithReviewerRoleReturnsValidCode(): void
public function testValidateInvitationWithExpiredCodeReturnsNull(): void
public function testMarkInvitationAsUsedUpdatesDatabase(): void
```

**Avoid:**
```php
public function testGenerateInvitation(): void  // Too vague
public function test1(): void                    // No meaning
```

### Arrange-Act-Assert Pattern

Every test should follow this structure:

```php
public function testUserCanLoginWithValidCredentials(): void
{
    // Arrange - Set up test data
    $user = new User('user@example.com', 'hashed_password');
    $passwordVerifier = $this->createMock(PasswordVerifier::class);
    $passwordVerifier->method('verify')->willReturn(true);
    
    // Act - Perform the action
    $authService = new AuthService($passwordVerifier);
    $result = $authService->authenticate('user@example.com', 'password');
    
    // Assert - Verify the result
    $this->assertTrue($result);
}
```

### One Assertion Per Test (When Possible)

```php
// Good - Each test has a clear purpose
public function testUserEmailIsValidated(): void { /* ... */ }
public function testUserPasswordIsHashed(): void { /* ... */ }

// Less ideal - Multiple concerns
public function testUserCreation(): void 
{
    $this->assertEmail(...);
    $this->assertPassword(...);
    $this->assertDatabase(...);
}
```

Exception: Related assertions are OK
```php
public function testInvitationContainsExpectedFields(): void
{
    $this->assertArrayHasKey('code', $invitation);      // Related
    $this->assertArrayHasKey('expiration', $invitation); // Related
    $this->assertArrayHasKey('roles', $invitation);      // Related
}
```

### Test Both Success and Failure Cases

For every feature, write tests for:

**Happy Path:**
```php
public function testValidateInvitationWithValidCodeReturnsInvitation(): void
{
    $invitation = $this->invitationService->validateInvitation('valid_code');
    $this->assertNotNull($invitation);
}
```

**Error Cases:**
```php
public function testValidateInvitationWithInvalidCodeReturnsNull(): void
{
    $invitation = $this->invitationService->validateInvitation('invalid_code');
    $this->assertNull($invitation);
}

public function testValidateInvitationWithExpiredCodeReturnsNull(): void
{
    // Expired code
    $invitation = $this->invitationService->validateInvitation('expired_code');
    $this->assertNull($invitation);
}
```

## Dependency Injection for Testability

SOLID's Dependency Inversion Principle means dependencies are injected, not created inside methods.

**Hard to test (tight coupling):**
```php
class AuthController
{
    public function login(Request $request): Response
    {
        $userService = new UserService();  // ❌ Can't replace with mock
        $user = $userService->findByEmail($request->post('email'));
        // ...
    }
}
```

**Easy to test (loose coupling):**
```php
class AuthController
{
    public function __construct(private readonly UserService $userService) {}  // ✅ Injected
    
    public function login(Request $request): Response
    {
        $user = $this->userService->findByEmail($request->post('email'));
        // ...
    }
}

// In test:
$userServiceMock = $this->createMock(UserService::class);
$controller = new AuthController($userServiceMock);  // ✅ Easy to inject mock
```

## Mocking Best Practices

### Mock External Dependencies, Not Business Logic

```php
// ❌ Don't mock the service being tested
$invitationServiceMock = $this->createMock(InvitationService::class);

// ✅ Mock external dependencies
$connectionMock = $this->createMock(Connection::class);
$invitationService = new InvitationService($connectionMock);
```

### Use Callbacks for Complex Assertions

```php
$this->connection->expects($this->once())
    ->method('executeStatement')
    ->with(
        'INSERT INTO invitations (code, roles) VALUES (?, ?)',
        $this->callback(function ($params) {
            return strlen($params[0]) === 64 &&      // Valid hex code
                   is_array(json_decode($params[1])); // Valid JSON
        })
    );
```

### Prefer Real Objects in Integration Tests

```php
// Integration test - use REAL database
$connection = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'path' => ':memory:',
]);

$invitationService = new InvitationService($connection);
$code = $invitationService->generateInvitation(['reviewer']);

// Verify by actually querying
$result = $connection->executeQuery(
    'SELECT * FROM invitations WHERE code = ?',
    [$code]
)->fetchAssociative();

$this->assertNotNull($result);
```

## Running Tests

```bash
# Run all tests
php vendor/bin/phpunit

# Run specific test file
php vendor/bin/phpunit tests/Unit/Service/InvitationServiceTest.php

# Run specific test method
php vendor/bin/phpunit tests/Unit/Service/InvitationServiceTest.php::InvitationServiceTest::testGenerateInvitation

# Run with coverage
composer coverage

# Run specific test class
php vendor/bin/phpunit --filter InvitationServiceTest

# Stop on first failure
php vendor/bin/phpunit --stop-on-failure

# Verbose output
php vendor/bin/phpunit --verbose
```

## What NOT to Test

- **Trivial getters/setters** - If they just return a property, skip the test
- **Framework code** - Don't test PHP, Symfony, or Slim internals
- **Database schema** - Test data access logic, not SQL syntax
- **Third-party libraries** - Mock them, don't test them

## Debugging Failing Tests

1. **Read the error message carefully**
   ```
   Failed asserting that 'test' equals 'expected'
   ```
   This tells you exactly what went wrong.

2. **Add debug output**
   ```php
   var_dump($result);
   $this->fail('Debug: ' . json_encode($result));
   ```

3. **Run the single test in isolation**
   ```bash
   php vendor/bin/phpunit tests/Unit/Service/UserServiceTest.php::UserServiceTest::testCreateUser
   ```

4. **Check test setup** - Is setUp() initializing correctly?

5. **Verify mocks** - Are mocks configured with correct methods/return values?

## Quick Reference

| Task | Command |
|------|---------|
| Run all tests | `php vendor/bin/phpunit` |
| Run with coverage | `composer coverage` |
| Coverage report (HTML) | `composer coverage-html` then open `coverage-report/index.html` |
| Watch for failures | `php vendor/bin/phpunit --stop-on-failure` |
| Run specific test | `php vendor/bin/phpunit --filter testName` |
| Verbose output | `php vendor/bin/phpunit --verbose` |

## Further Reading

- [PHPUnit Documentation](https://phpunit.de/)
- [Uncle Bob: The Three Rules of TDD](http://butunclebob.com/ArticleS.UncleBob.TheThreeRulesOfTdd)
- [SOLID Principles](https://en.wikipedia.org/wiki/SOLID)
- [Growing Object-Oriented Software, Guided by Tests](https://www.amazon.com/Growing-Object-Oriented-Software-Guided-Tests/dp/0321503627)

## Next Steps

- Read the test templates in `docs/test-templates/`
- Look at examples in `tests/Unit/` and `tests/Integration/`
- Write a test for your first feature
- Submit PR - code review will verify TDD compliance
