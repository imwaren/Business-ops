<?php

use CodeIgniter\Test\CIUnitTestCase;

final class ProductionConfigurationTest extends CIUnitTestCase
{
    public function testModelQueriesUseConfiguredEncryption(): void
    {
        foreach (['Wallet', 'Patient_emr', 'Booking_doctors_fee'] as $model) {
            $source = file_get_contents(APPPATH . 'Models/' . $model . '.php');
            $this->assertTrue(str_contains($source, '$this->encryption_key') || str_contains($source, 'ENCRYPTION_KEY'));
        }
    }

    public function testDatabaseSettingsAndDsnUseTheSameEnvironment(): void
    {
        $values = ['database.default.hostname' => 'fixture-db.local', 'database.default.port' => '3307',
            'database.default.username' => 'fixture-user', 'database.default.password' => 'fixture-password',
            'MASTER_DB' => 'fixture_master', 'OAKRIDGE_DB' => 'fixture_oakridge',
            'database.oakridge.hostname' => 'fixture-clinic.local', 'database.oakridge.database' => 'fixture_override'];
        $saved = [];
        foreach ($values as $key => $value) {
            $saved[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
            $_ENV[$key] = $value;
        }
        try {
            $database = new Config\Database();
            $this->assertSame('fixture-db.local', $database->default['hostname']);
            $this->assertSame(3307, $database->default['port']);
            $this->assertSame('fixture-user', $database->default['username']);
            $this->assertSame('fixture-password', $database->default['password']);
            $this->assertSame('fixture_master', $database->default['database']);
            $this->assertSame('fixture-clinic.local', $database->oakridge['hostname']);
            $this->assertSame('fixture_override', $database->oakridge['database']);
            $this->assertSame('', $database->default['DSN']);
            $this->assertSame('', $database->oakridge['DSN']);
            $this->assertSame('tests', $database->defaultGroup);
        } finally {
            foreach ($saved as $key => [$exists, $value]) {
                unset($_ENV[$key]);
                if ($exists) {
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}
