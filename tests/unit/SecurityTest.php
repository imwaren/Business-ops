<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SecurityTest extends CIUnitTestCase
{
    public function testPasswordHashUsesPasswordHashApi(): void
    {
        $hash = app_password_hash('Secret123!');
        $this->assertNotSame('Secret123!', $hash);
        $this->assertTrue(password_verify('Secret123!', $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    public function testSqlLimitClauseRejectsInjectionAndCapsSize(): void
    {
        $this->assertSame('', sql_limit_clause(null, null));
        $this->assertSame(' LIMIT 0, 20', sql_limit_clause(1, 0));
        $this->assertSame(' LIMIT 0, 100', sql_limit_clause(1, 10000));
        $this->assertSame(' LIMIT 20, 20', sql_limit_clause('2; DROP TABLE user', '20 OR 1=1'));
        $this->assertStringNotContainsString('DROP', sql_limit_clause('1; DROP TABLE x', 50));
    }

    public function testListPageParamsDefaultsAndPageSizeAlias(): void
    {
        $this->assertSame([1, 20], list_page_params([]));
        $this->assertSame([1, 20], list_page_params(['page' => '', 'data_size' => '']));
        $this->assertSame([2, 10], list_page_params(['page' => 2, 'page_size' => 10]));
        $this->assertSame([3, 50], list_page_params(['page' => 3, 'data_size' => 50]));
    }

    public function testSanitizePrinterNameBlocksTraversal(): void
    {
        $this->assertNull(sanitize_printer_name('../etc/passwd'));
        $this->assertNull(sanitize_printer_name('C:\\Windows'));
        $this->assertNull(sanitize_printer_name('printer/name'));
        $this->assertSame('Counter 1', sanitize_printer_name('Counter 1'));
    }

    public function testSanitizeOrderByBlocksInjection(): void
    {
        $this->assertNull(sanitize_order_by('id; DROP TABLE user'));
        $this->assertNull(sanitize_order_by('id ASC UNION SELECT password'));
        $this->assertSame('last_name ASC, first_name DESC', sanitize_order_by('last_name ASC, first_name DESC'));
    }

    public function testRedactSensitiveRemovesSecrets(): void
    {
        $redacted = redact_sensitive([
            'email'    => 'a@b.com',
            'password' => 'secret',
            'token'    => 'abc',
            'nested'   => ['new_password' => 'x'],
        ]);

        $this->assertSame('a@b.com', $redacted['email']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['token']);
        $this->assertSame('[REDACTED]', $redacted['nested']['new_password']);
    }

    public function testStripSensitiveUserFields(): void
    {
        $rows = [
            ['id' => 1, 'email' => 'a@b.com', 'password' => 'hash', 'token' => 't', 'token_expiry' => 'x'],
        ];
        $clean = strip_sensitive_user_fields($rows);
        $this->assertSame(1, $clean[0]['id']);
        $this->assertArrayNotHasKey('password', $clean[0]);
        $this->assertArrayNotHasKey('token', $clean[0]);
    }

    public function testEmailViewEscapesXss(): void
    {
        $html = view('emails/forgot_password', [
            'user'       => (object) ['username' => '<script>alert(1)</script>'],
            'reset_link' => 'https://example.com/reset?x="onclick=alert(1)',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testSourceNoLongerHardcodesApiKey(): void
    {
        $constants = file_get_contents(APPPATH . 'Config/Constants.php');
        $this->assertIsString($constants);
        $this->assertStringNotContainsString('clinicaRiKOYqlwdTJ', $constants);
        $this->assertStringNotContainsString('s3cr3tK3y!@#2025', $constants);
    }

    public function testRoleAdminConstantIsFour(): void
    {
        $this->assertSame(4, (int) ROLE_ADMIN);
        $this->assertSame(17, (int) ROLE_IT);
    }

    public function testShouldSkipApiResponseLoggingForGetMethods(): void
    {
        $this->assertTrue(should_skip_api_response_logging('get'));
        $this->assertTrue(should_skip_api_response_logging('get_medrecord'));
        $this->assertFalse(should_skip_api_response_logging('create'));
        $this->assertFalse(should_skip_api_response_logging('getting'));
        $this->assertFalse(should_skip_api_response_logging('insert'));
    }

    public function testCronSecretAcceptsHeaderOrQuery(): void
    {
        $this->assertTrue(cron_secret_is_valid('abc', 'abc', ''));
        $this->assertTrue(cron_secret_is_valid('abc', '', 'abc'));
        $this->assertFalse(cron_secret_is_valid('abc', 'nope', 'nope'));
        $this->assertFalse(cron_secret_is_valid('', 'abc', 'abc'));
    }

    public function testCorsNormalizeStripsQuotesAndSlash(): void
    {
        $this->assertSame(
            'https://zuat-accounting-front.myt-emr.com',
            cors_normalize_origin("'https://zuat-accounting-front.myt-emr.com/'")
        );
    }

    public function testPrintJobIsNotAPublicRoute(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Prints.php');
        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression('/public\s+function\s+printJob\s*\(/', $source);
        $this->assertMatchesRegularExpression('/protected\s+function\s+printJob\s*\(/', $source);
    }
}
