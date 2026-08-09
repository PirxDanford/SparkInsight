# Test Templates for SparkInsight

This directory contains reusable test templates for common patterns in SparkInsight.

## Files

- `ServiceTest.template` - Template for testing services
- `ControllerTest.template` - Template for testing controllers
- `CommandTest.template` - Template for testing console commands
- `RepositoryTest.template` - Template for testing data repositories

## How to Use Templates

1. Copy the relevant template file
2. Rename it to match your class (e.g., `UserService` → `UserServiceTest.php`)
3. Replace placeholders (e.g., `[CLASS_NAME]`, `[METHOD_NAME]`)
4. Add specific test cases for your implementation
5. Run `composer coverage` to verify coverage

## Key Principles

✅ **Do:**
- Write tests BEFORE implementation (Red → Green → Refactor)
- One test method per behavior/case
- Use meaningful test names
- Mock external dependencies
- Test both success and failure paths
- Keep tests focused and isolated

❌ **Don't:**
- Write tests after code (defeats TDD purpose)
- Test multiple behaviors in one test
- Use generic names like `test1()` or `testMethod()`
- Mock the service being tested
- Skip error cases
- Leave tests with TODO comments

## Running Tests

```bash
# Run all tests
php vendor/bin/phpunit

# Run tests in a specific file
php vendor/bin/phpunit tests/Unit/Service/UserServiceTest.php

# Run with coverage
composer coverage

# Run a specific test method
php vendor/bin/phpunit --filter testCreateUserWithValidEmail
```

## Coverage Requirements

- **New code:** 100% line coverage
- **Existing code:** Don't decrease coverage
- Check coverage reports: `composer coverage` or `composer coverage-html`

## Need Help?

1. Read `docs/tdd-guide.md` for detailed TDD practices
2. Look at existing tests in `tests/Unit/` for examples
3. Check `tests/Integration/` for integration test patterns
4. Ask during code review - reviewers can help improve test quality
