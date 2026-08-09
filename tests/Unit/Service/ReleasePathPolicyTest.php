<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ReleasePathPolicy;

final class ReleasePathPolicyTest extends TestCase
{
    public function testNormalizeConvertsBackslashesToForwardSlashes(): void
    {
        $policy = new ReleasePathPolicy();

        $this->assertSame('src/Controller/HomeController.php', $policy->normalize('src\\Controller\\HomeController.php'));
    }

    public function testNormalizeRejectsReservedDeploymentPaths(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reserved deployment path is not allowed');
        $policy->normalize('.env');
    }

    public function testNormalizeRejectsTraversalPaths(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parent traversal and empty path segments are not allowed');
        $policy->normalize('../src/Controller/HomeController.php');
    }

    public function testNormalizeRejectsAbsolutePaths(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Absolute paths are not allowed');
        $policy->normalize('/tmp/file.txt');
    }

    public function testEnsureAllowedPayloadPathStripsPayloadPrefix(): void
    {
        $policy = new ReleasePathPolicy();

        $this->assertSame('src/App.php', $policy->ensureAllowedPayloadPath('payload/src/App.php'));
    }

    public function testEnsureAllowedPayloadPathRejectsReservedEntryAfterPrefixRemoval(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reserved deployment path is not allowed: payload/.env');
        $policy->ensureAllowedPayloadPath('payload/.env');
    }

    public function testNormalizeRejectsEmptyPathAfterTrim(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path must not be empty.');
        $policy->normalize('   ');
    }

    public function testNormalizeRejectsDriveQualifiedPath(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Absolute paths are not allowed');
        $policy->normalize('C:/tmp/file.txt');
    }

    public function testNormalizeRejectsControlCharacters(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Control characters are not allowed in paths.');
        $policy->normalize("src/ok\x01bad.php");
    }

    public function testNormalizeRejectsInvalidSegmentCharacters(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid path segment');
        $policy->normalize('src/invalid segment.php');
    }

    public function testNormalizeRejectsTooLongPath(): void
    {
        $policy = new ReleasePathPolicy();
        $tooLong = str_repeat('a', 241);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path is too long.');
        $policy->normalize($tooLong);
    }

    public function testNormalizeRejectsNestedReservedPaths(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reserved deployment path is not allowed: src/.deploy/state.json');
        $policy->normalize('src/.deploy/state.json');
    }

    public function testNormalizeRejectsReservedPrefixPaths(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reserved deployment path is not allowed: .deploy/state.json');
        $policy->normalize('.deploy/state.json');
    }

    public function testEnsureAllowedPayloadPathRejectsReservedEntryAfterNormalization(): void
    {
        $policy = new ReleasePathPolicy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Package entry is reserved: .htaccess');
        $policy->ensureAllowedPayloadPath('payload/.htaccess');
    }

    public function testEnsureAllowedPayloadPathPassesSafePathWithoutPrefix(): void
    {
        $policy = new ReleasePathPolicy();

        $this->assertSame('templates/home.php', $policy->ensureAllowedPayloadPath('templates/home.php'));
    }
}