<?php

namespace Tests\Feature;

use Illuminate\Database\Connectors\SqlServerConnector;
use Tests\TestCase;

class SqlServerEnvironmentTest extends TestCase
{
    public function test_local_and_azure_dsn_profiles_use_the_same_native_connector_without_azure_access(): void
    {
        $connector = new class extends SqlServerConnector
        {
            public function dsn(array $config): string
            {
                return $this->getSqlSrvDsn($config);
            }
        };
        $common = ['database' => 'probe_test', 'encrypt' => 'yes'];
        $local = $connector->dsn($common + ['host' => '.\AUDITORIA', 'port' => '', 'trust_server_certificate' => 'yes']);
        $azure = $connector->dsn($common + ['host' => 'CHANGE_ME.database.windows.net', 'port' => '1433', 'trust_server_certificate' => 'no']);
        $this->assertStringContainsString('Server=.\AUDITORIA;', $local);
        $this->assertStringContainsString('Encrypt=yes;TrustServerCertificate=yes', $local);
        $this->assertStringContainsString('Server=CHANGE_ME.database.windows.net,1433;', $azure);
        $this->assertStringContainsString('Encrypt=yes;TrustServerCertificate=no', $azure);
        $this->assertSame('sqlsrv_testing', config('database.default'));
        $this->assertSame('UTC', config('app.timezone'));
    }
}
