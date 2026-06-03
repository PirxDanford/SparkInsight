# SOLID Principles Guide for SparkInsight

This guide explains how to apply SOLID Principles (ADR 0007) in SparkInsight code. SOLID principles, combined with TDD (ADR 0006), naturally produce clean, maintainable, testable code.

## Quick Reference

| Principle | Definition | Benefit |
|-----------|-----------|---------|
| **S** - SRP | Single Responsibility | Each class has one reason to change |
| **O** - OCP | Open/Closed | Extend without modifying existing code |
| **L** - LSP | Liskov Substitution | Subtypes are substitutable for base types |
| **I** - ISP | Interface Segregation | Don't depend on interfaces you don't use |
| **D** - DIP | Dependency Inversion | Depend on abstractions, not concrete implementations |

---

## 1. Single Responsibility Principle (SRP)

**Definition:** A class should have one, and only one, reason to change.

Each class should do ONE thing and do it well. If a class needs to change for multiple reasons, it violates SRP.

### ❌ Bad Example (Violates SRP)

```php
class UserManager
{
    // ❌ Too many responsibilities: user CRUD, auth, email, notifications
    
    public function createUser(string $email, string $password): User { /* ... */ }
    public function authenticateUser(string $email, string $password): ?User { /* ... */ }
    public function sendPasswordResetEmail(User $user): void { /* ... */ }
    public function sendWelcomeEmail(User $user): void { /* ... */ }
    public function logUserActivity(User $user, string $action): void { /* ... */ }
}
```

**Problems:**
- Changes to email logic require modifying UserManager
- Changes to logging logic require modifying UserManager
- Hard to test (too many mocks needed)
- Hard to reuse (can't use just the auth part)

### ✅ Good Example (Follows SRP)

```php
// Responsibility: User data management
final class UserService
{
    public function __construct(private readonly UserRepository $repository) {}
    
    public function createUser(string $email, string $password): User
    {
        // Validation, hashing, storage
    }
}

// Responsibility: Authentication
final class AuthService
{
    public function __construct(private readonly UserService $userService) {}
    
    public function authenticate(string $email, string $password): ?User
    {
        // Only authentication logic
    }
}

// Responsibility: Email notifications
final class EmailService
{
    public function sendPasswordResetEmail(User $user): void { /* ... */ }
    public function sendWelcomeEmail(User $user): void { /* ... */ }
}

// Responsibility: Activity logging
final class ActivityLogger
{
    public function logUserActivity(User $user, string $action): void { /* ... */ }
}
```

**Benefits:**
- Each class is easy to test (fewer mocks)
- Changes to email don't affect UserService
- Easy to reuse AuthService elsewhere
- Clear, focused responsibilities

### Applying SRP in SparkInsight

```php
// Each service has ONE responsibility:

// ✅ Config: Load environment configuration
final class Config
{
    public static function fromEnvironment(): self { /* ... */ }
}

// ✅ UserService: User CRUD and password management
final class UserService
{
    public function createUser(array $data): User { /* ... */ }
    public function findByEmail(string $email): ?User { /* ... */ }
}

// ✅ InvitationService: Generate and validate invitation codes
final class InvitationService
{
    public function generateInvitation(array $roles, ?string $email): string { /* ... */ }
    public function validateInvitation(string $code): ?array { /* ... */ }
}

// ✅ OAuthProviderFactory: Create and configure OAuth providers
final class OAuthProviderFactory
{
    public function createProvider(string $provider): GenericProvider { /* ... */ }
}
```

---

## 2. Open/Closed Principle (OCP)

**Definition:** Software entities should be open for extension, but closed for modification.

You should be able to add new features WITHOUT changing existing code.

### ❌ Bad Example (Violates OCP)

```php
class InvitationCodeGenerator
{
    public function generate(string $type): string
    {
        if ($type === 'numeric') {
            return (string)random_int(100000, 999999);
        } elseif ($type === 'alphanumeric') {
            return bin2hex(random_bytes(16));
        } elseif ($type === 'uuid') {
            return (new UUID4())->toString();
        } else {
            throw new \InvalidArgumentException('Unknown type');
        }
    }
}

// ❌ Adding a new type requires modifying the class!
```

**Problems:**
- Every new type requires changing InvitationCodeGenerator
- Risk breaking existing functionality
- Violates "closed for modification" principle

### ✅ Good Example (Follows OCP)

```php
// Define an abstraction
interface CodeGenerator
{
    public function generate(): string;
}

// Implement specific generators
final class NumericCodeGenerator implements CodeGenerator
{
    public function generate(): string
    {
        return (string)random_int(100000, 999999);
    }
}

final class AlphanumericCodeGenerator implements CodeGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}

final class UUIDCodeGenerator implements CodeGenerator
{
    public function generate(): string
    {
        return (new UUID4())->toString();
    }
}

// Factory to select the right generator
final class CodeGeneratorFactory
{
    private array $generators = [
        'numeric' => NumericCodeGenerator::class,
        'alphanumeric' => AlphanumericCodeGenerator::class,
        'uuid' => UUIDCodeGenerator::class,
    ];
    
    public function create(string $type): CodeGenerator
    {
        if (!isset($this->generators[$type])) {
            throw new \InvalidArgumentException("Unknown type: $type");
        }
        return new $this->generators[$type]();
    }
}

// ✅ Adding a new type: just add a new class, no modification needed!
```

**Benefits:**
- Add new generators without modifying existing code
- Each generator is tested independently
- Easy to understand (one generator per class)
- Extension through inheritance/interfaces, not modification

### Applying OCP in SparkInsight

Example: OAuth provider support

```php
// Open for extension: Add new providers without modifying existing code

interface OAuthProvider
{
    public function getAuthorizeUrl(): string;
    public function getAccessToken(string $code): AccessToken;
    public function getUserProfile(AccessToken $token): array;
}

// Concrete implementations
final class GitHubOAuthProvider implements OAuthProvider { /* ... */ }
final class GoogleOAuthProvider implements OAuthProvider { /* ... */ }

// Factory: Closed for modification when adding providers
final class OAuthProviderFactory
{
    public function createProvider(string $provider): OAuthProvider
    {
        return match($provider) {
            'github' => new GitHubOAuthProvider($this->config),
            'google' => new GoogleOAuthProvider($this->config),
            default => throw new \InvalidArgumentException("Unsupported provider: $provider"),
        };
    }
}

// ✅ To add LinkedIn support: create LinkendinOAuthProvider, no other changes!
```

---

## 3. Liskov Substitution Principle (LSP)

**Definition:** Objects of a superclass should be replaceable with objects of its subclasses without breaking the application.

Derived classes must be substitutable for their base classes.

### ❌ Bad Example (Violates LSP)

```php
abstract class Repository
{
    abstract public function find(int $id): ?Entity;
    abstract public function save(Entity $entity): void;
}

class CachedRepository extends Repository
{
    public function find(int $id): ?Entity
    {
        // ❌ This might return different data than expected
        // because cache might be stale
        return $this->cache->get("entity_$id");
    }
    
    public function save(Entity $entity): void
    {
        // ❌ This doesn't actually save to database!
        $this->cache->set("entity_{$entity->id}", $entity);
    }
}

// When using CachedRepository instead of Repository, behavior changes unexpectedly
```

**Problem:** CachedRepository violates the contract of Repository. Code expecting Repository will break.

### ✅ Good Example (Follows LSP)

```php
interface Repository
{
    public function find(int $id): ?Entity;
    public function save(Entity $entity): void;
}

// Database-backed repository
final class DatabaseRepository implements Repository
{
    public function find(int $id): ?Entity
    {
        // Fetch from database
    }
    
    public function save(Entity $entity): void
    {
        // Save to database
    }
}

// In-memory repository for testing
final class InMemoryRepository implements Repository
{
    public function find(int $id): ?Entity
    {
        // Return from memory (consistent behavior)
    }
    
    public function save(Entity $entity): void
    {
        // Store in memory (consistent behavior)
    }
}

// Both repositories can be used interchangeably
function processEntity(Repository $repository, int $id): void
{
    $entity = $repository->find($id);  // Works with either implementation
    $entity->update();
    $repository->save($entity);        // Works with either implementation
}
```

**Benefits:**
- Repository implementations are interchangeable
- Easy to test (use InMemoryRepository in tests)
- Consistent behavior across implementations

### Applying LSP in SparkInsight

Example: Different OAuth providers

```php
interface OAuthProvider
{
    public function getAccessToken(string $code): AccessToken;  // Contract
    public function getUserProfile(AccessToken $token): UserProfile;
}

// Both implementations honor the contract
final class GitHubProvider implements OAuthProvider
{
    public function getAccessToken(string $code): AccessToken
    {
        // Returns AccessToken with consistent interface
    }
}

final class GoogleProvider implements OAuthProvider
{
    public function getAccessToken(string $code): AccessToken
    {
        // Returns AccessToken with same interface
    }
}

// Either provider works the same way
$provider = OAuthProviderFactory::create('github');  // or 'google'
$token = $provider->getAccessToken($code);          // Works the same
$profile = $provider->getUserProfile($token);       // Works the same
```

---

## 4. Interface Segregation Principle (ISP)

**Definition:** Clients should not be forced to depend on interfaces they do not use.

Many client-specific interfaces are better than one general-purpose interface.

### ❌ Bad Example (Violates ISP)

```php
interface UserManager
{
    public function createUser(array $data): User;
    public function updateUser(int $id, array $data): User;
    public function deleteUser(int $id): void;
    public function sendWelcomeEmail(User $user): void;
    public function logUserActivity(User $user, string $action): void;
    public function hashPassword(string $password): string;
    public function validateEmail(string $email): bool;
}

class UserController
{
    // ❌ Forced to depend on ALL methods, even though it only needs user CRUD
    public function __construct(private readonly UserManager $manager) {}
    
    public function create(Request $request): Response
    {
        // Can only use createUser(), but has access to email, logging, hashing, etc.
        $user = $this->manager->createUser($request->data());
    }
}
```

**Problems:**
- UserController is forced to depend on too much
- Hard to mock (need to mock every method)
- Tightly coupled to implementation details
- Difficult to test

### ✅ Good Example (Follows ISP)

```php
// Segregated interfaces - each for specific client needs

interface UserRepository
{
    public function create(array $data): User;
    public function update(int $id, array $data): User;
    public function delete(int $id): void;
}

interface PasswordHasher
{
    public function hash(string $password): string;
}

interface EmailService
{
    public function sendWelcomeEmail(User $user): void;
}

interface ActivityLogger
{
    public function logUserActivity(User $user, string $action): void;
}

// Each class depends only on what it needs

class UserController
{
    // ✅ Only depends on UserRepository (what it needs)
    public function __construct(private readonly UserRepository $repository) {}
    
    public function create(Request $request): Response
    {
        $user = $this->repository->create($request->data());
        // ...
    }
}

class AuthService
{
    // ✅ Only depends on UserRepository and PasswordHasher
    public function __construct(
        private readonly UserRepository $repository,
        private readonly PasswordHasher $hasher
    ) {}
    
    public function authenticate(string $email, string $password): ?User
    {
        // Only uses repository and hasher
    }
}

class UserNotifier
{
    // ✅ Only depends on EmailService and ActivityLogger
    public function __construct(
        private readonly EmailService $emailService,
        private readonly ActivityLogger $logger
    ) {}
    
    public function notifyNewUser(User $user): void
    {
        $this->emailService->sendWelcomeEmail($user);
        $this->logger->logUserActivity($user, 'signup');
    }
}
```

**Benefits:**
- Each class depends only on what it needs
- Easy to mock in tests
- Loose coupling
- Easy to refactor

### Applying ISP in SparkInsight

```php
// Good: segregated interfaces

interface UserService
{
    public function createUser(array $data): User;
    public function findByEmail(string $email): ?User;
}

interface InvitationService
{
    public function generateInvitation(array $roles): string;
    public function validateInvitation(string $code): ?array;
}

// AuthController only depends on what it needs
class AuthController
{
    public function __construct(
        private readonly InvitationService $invitations,  // Only what it needs
        private readonly UserService $users               // Only what it needs
    ) {}
}
```

---

## 5. Dependency Inversion Principle (DIP)

**Definition:** High-level modules should not depend on low-level modules. Both should depend on abstractions.

### ❌ Bad Example (Violates DIP)

```php
class AuthService
{
    // ❌ Direct dependency on MySQLDatabase (low-level implementation)
    private readonly MySQLDatabase $database;
    
    public function __construct()
    {
        $this->database = new MySQLDatabase(); // Tightly coupled!
    }
    
    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->database->query("SELECT * FROM users WHERE email = ?", [$email]);
        // ...
    }
}

// Problem: Can't use with different database, can't test easily
```

**Problems:**
- AuthService depends on MySQLDatabase implementation
- Can't switch to PostgreSQL without changing AuthService
- Hard to test (need real database)
- Tightly coupled

### ✅ Good Example (Follows DIP)

```php
// Abstraction: what we depend on
interface UserRepository
{
    public function findByEmail(string $email): ?User;
}

// Low-level implementations
final class MySQLUserRepository implements UserRepository
{
    public function __construct(private readonly Connection $connection) {}
    
    public function findByEmail(string $email): ?User
    {
        // MySQL-specific query
    }
}

// High-level module: depends on abstraction, not implementation
final class AuthService
{
    // ✅ Depends on UserRepository interface (abstraction)
    public function __construct(private readonly UserRepository $repository) {}
    
    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->repository->findByEmail($email);
        // ...
    }
}

// In production
$repository = new MySQLUserRepository($connection);
$authService = new AuthService($repository);

// In tests
$repositoryMock = $this->createMock(UserRepository::class);
$authService = new AuthService($repositoryMock);
```

**Benefits:**
- AuthService is independent of database choice
- Easy to swap implementations
- Easy to mock for testing
- Loose coupling

### Applying DIP in SparkInsight

```php
// Abstraction
interface OAuthProviderFactory
{
    public function createProvider(string $name): OAuthProvider;
}

// Implementation
final class StandardOAuthProviderFactory implements OAuthProviderFactory
{
    public function __construct(private readonly Config $config) {}
    
    public function createProvider(string $name): OAuthProvider
    {
        // Create based on config
    }
}

// AuthController depends on abstraction, not implementation
final class AuthController
{
    public function __construct(
        private readonly OAuthProviderFactory $providerFactory  // ✅ Abstraction
    ) {}
    
    public function login(string $provider): Response
    {
        $provider = $this->providerFactory->createProvider($provider);
        // ...
    }
}
```

---

## Combining SOLID with TDD

SOLID principles and TDD work together naturally:

**TDD → SOLID:**
- Write tests FIRST → Forces dependency injection (DIP)
- One test per behavior → Encourages SRP
- Easy to extend with new tests → Encourages OCP
- Each test is simple → Encourages ISP

**SOLID → Testable Code:**
- SRP → Classes are small, easy to test
- OCP → Easy to add tests without modifying code
- LSP → Can swap implementations in tests
- ISP → Mock only what's needed
- DIP → Easy to inject mocks

---

## Quick Checklist

When writing code, ask yourself:

✅ **SRP:** Does this class do one thing?
✅ **OCP:** Can I add features without modifying this code?
✅ **LSP:** Can subclasses be used interchangeably?
✅ **ISP:** Does this class depend only on what it uses?
✅ **DIP:** Does this depend on abstractions, not implementations?

If you answer "No" to any question, refactor before proceeding.

---

## References

- [SOLID Principles on Wikipedia](https://en.wikipedia.org/wiki/SOLID)
- [Uncle Bob's SOLID Principles](https://blog.cleancoder.com/)
- [SOLID Design Principles in PHP](https://www.php.net/manual/en/class.spl.php)
- [Dependency Injection in PHP](https://www.phptherightway.com/#dependency_injection)
