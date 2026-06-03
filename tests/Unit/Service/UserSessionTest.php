<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\UserSession;

class UserSessionTest extends TestCase
{
    private UserSession $session;

    protected function setUp(): void
    {
        // Clean session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];

        $this->session = new UserSession();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testSetAndGetUser(): void
    {
        $user = ['id' => 1, 'name' => 'Test'];

        $this->session->setUser($user);

        $this->assertEquals($user, $this->session->getUser());
    }

    public function testGetUserWhenNotSet(): void
    {
        $this->assertNull($this->session->getUser());
    }

    public function testSetAndGetState(): void
    {
        $this->session->setState('abc123');

        $this->assertEquals('abc123', $this->session->getState());
    }

    public function testGetStateWhenNotSet(): void
    {
        $this->assertNull($this->session->getState());
    }

    public function testIsLoggedIn(): void
    {
        $this->assertFalse($this->session->isLoggedIn());

        $this->session->setUser(['id' => 1]);

        $this->assertTrue($this->session->isLoggedIn());
    }

    public function testSetAndGetData(): void
    {
        $this->session->setData('key', 'value');

        $this->assertEquals('value', $this->session->getData('key'));
    }

    public function testGetDataWhenNotSet(): void
    {
        $this->assertNull($this->session->getData('nonexistent'));
    }

    public function testSecureSessionCookieSettings(): void
    {
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('1', ini_get('session.use_strict_mode'));
        $this->assertSame('1', ini_get('session.use_only_cookies'));
        $this->assertSame('1', ini_get('session.cookie_httponly'));
        $this->assertSame('Lax', ini_get('session.cookie_samesite'));

        $params = session_get_cookie_params();
        $this->assertSame('sparkinsight_session', session_name());
        $this->assertSame('/', $params['path']);
        $this->assertSame('Lax', $params['samesite']);
    }

    public function testClear(): void
    {
        $this->session->setUser(['id' => 1]);
        $this->session->setState('state');
        $this->session->setData('key', 'value');

        $this->session->clear();

        $this->assertNull($this->session->getUser());
        $this->assertNull($this->session->getState());
        $this->assertNull($this->session->getData('key'));
    }

    public function testRegenerateChangesSessionId(): void
    {
        $initialSessionId = session_id();

        $this->session->regenerate();

        $this->assertNotSame($initialSessionId, session_id());
        $this->assertNotEmpty(session_id());
    }
}