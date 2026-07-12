<?php

declare(strict_types=1);

namespace SparkInsightTest\Smoke;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testAppBoots(): void
    {
        // Test that essential classes can be loaded
        $this->assertTrue(class_exists('SparkInsight\Controller\HomeController'));
        $this->assertTrue(class_exists('SparkInsight\Controller\DashboardController'));
        $this->assertTrue(class_exists('SparkInsight\Controller\AuthController'));
        $this->assertTrue(class_exists('SparkInsight\Controller\AdminController'));
        $this->assertTrue(class_exists('SparkInsight\Config\Config'));
        $this->assertTrue(class_exists('SparkInsight\Service\UserService'));
    }
}