<?php

namespace App\Services\Source;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\TenantSourceConfig;
use App\Models\TenantSourcePolicy;
use App\Models\User;
use App\Services\Connector\PlatformAuditor;
use App\Support\DestinationPolicy;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SourceConfigService
{
    public function __construct(
        private readonly SourceCommandGateway $commands,
        private readonly PlatformAuditor $auditor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(TenantContext $context, bool $requestIsSecure): array
    {
        $this->assertAdmin($context);
        $connector = $this->connector($context);
        $policy = TenantSourcePolicy::resolved($context->tenantId);
        $active = $this->latest($connector, [TenantSourceConfig::STATE_ACTIVE]);
        $pending = $this->latest($connector, [TenantSourceConfig::STATE_DRAFT, TenantSourceConfig::STATE_TESTED]);

        return [
            'connector_id' => $connector->id,
            'can_manage_source' => true,
            'request_secure' => $requestIsSecure,
            'active' => $active ? $this->publicConfig($active) : null,
            'pending' => $pending ? $this->publicConfig($pending) : null,
            'reported' => $this->publicReport($connector->reported_source),
            'reported_at' => $connector->reported_source_at?->toIso8601String(),
            'last_hole_query' => $this->publicHoleQuery($connector->last_hole_query),
            'policy' => [
                'allow_loopback' => (bool) $policy->allow_loopback,
                'allow_private' => (bool) $policy->allow_private,
                'allowed_hosts' => $policy->allowed_hosts ?? [],
                'allowed_ports' => $policy->allowed_ports ?? [],
                'engines' => ['postgres', 'mysql'],
                'tls_modes' => [
                    'postgres' => ['verify-full', 'verify-ca'],
                    'mysql' => ['verify'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(TenantContext $context, array $input): array
    {
        $this->assertAdmin($context);
        $connector = $this->connector($context);
        $normalized = $this->validate($context, $input, $connector);
        $version = (int) TenantSourceConfig::query()->where('connector_id', $connector->id)->max('version') + 1;

        $config = DB::transaction(function () use ($context, $connector, $normalized, $version) {
            TenantSourceConfig::query()
                ->where('connector_id', $connector->id)
                ->whereIn('state', [TenantSourceConfig::STATE_DRAFT, TenantSourceConfig::STATE_TESTED])
                ->update(['state' => TenantSourceConfig::STATE_CANCELLED]);

            return TenantSourceConfig::query()->create([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context->tenantId,
                'connector_id' => $connector->id,
                'version' => $version,
                'state' => TenantSourceConfig::STATE_DRAFT,
                'created_by' => $context->userId,
                ...$normalized,
            ]);
        });

        $this->audit($context, $connector, 'source_config.saved', $config, 'saved');

        return $this->publicConfig($config);
    }

    public function cancel(TenantContext $context): void
    {
        $this->assertAdmin($context);
        $connector = $this->connector($context);
        $pending = $this->latest($connector, [TenantSourceConfig::STATE_DRAFT, TenantSourceConfig::STATE_TESTED]);

        if ($pending === null) {
            return;
        }

        $pending->forceFill(['state' => TenantSourceConfig::STATE_CANCELLED])->save();
        $this->audit($context, $connector, 'source_config.cancelled', $pending, 'cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    public function test(TenantContext $context, ?string $correlationId): array
    {
        return $this->dispatch($context, 'source_config.test@1', false, $correlationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(TenantContext $context, bool $confirm, ?string $correlationId): array
    {
        if (! $confirm) {
            throw new GatewayException('CONFIRMATION_REQUIRED', 'Tenés que confirmar el cambio de origen.', 422, null, $correlationId);
        }

        return $this->dispatch($context, 'source_config.apply@1', true, $correlationId);
    }

    public function ingest(ConnectorCommand $command): void
    {
        if (! in_array($command->op, ['source_config.test@1', 'source_config.apply@1'], true)) {
            return;
        }

        $configId = $command->payload_json['config_id'] ?? null;
        if (! is_string($configId)) {
            return;
        }

        DB::transaction(function () use ($command, $configId) {
            $config = TenantSourceConfig::query()
                ->whereKey($configId)
                ->where('connector_id', $command->connector_id)
                ->where('tenant_id', $command->tenant_id)
                ->lockForUpdate()
                ->first();

            if ($config === null || in_array($config->state, [TenantSourceConfig::STATE_CANCELLED, TenantSourceConfig::STATE_SUPERSEDED], true)) {
                return;
            }

            $payload = is_array($command->result_json) ? $command->result_json : [];
            $failed = $command->status !== ConnectorCommand::STATUS_COMPLETED;
            $config->forceFill([
                'tested_at' => now(),
                'test_error_code' => $failed ? ($command->error_code ?: 'ADAPTER_ERROR') : null,
                'test_latency_ms' => $payload['latency_ms'] ?? $command->duration_ms,
                'test_tls_verified' => array_key_exists('tls_verified', $payload) ? (bool) $payload['tls_verified'] : null,
                'test_read_only' => array_key_exists('read_only', $payload) ? (bool) $payload['read_only'] : null,
            ]);

            $applied = ! $failed
                && $command->op === 'source_config.apply@1'
                && ($payload['applied'] ?? false) === true
                && (int) ($payload['version'] ?? 0) === (int) $config->version
                && ($payload['fingerprint'] ?? null) === $config->fingerprint;

            if ($applied && $config->state !== TenantSourceConfig::STATE_ACTIVE) {
                TenantSourceConfig::query()
                    ->where('connector_id', $config->connector_id)
                    ->where('state', TenantSourceConfig::STATE_ACTIVE)
                    ->update(['state' => TenantSourceConfig::STATE_SUPERSEDED]);
                $config->state = TenantSourceConfig::STATE_ACTIVE;
                $config->applied_at = now();
            } elseif (! $failed && $command->op === 'source_config.test@1' && $config->state !== TenantSourceConfig::STATE_ACTIVE) {
                $config->state = TenantSourceConfig::STATE_TESTED;
            } elseif ($failed && $config->state === TenantSourceConfig::STATE_TESTED && $command->op === 'source_config.apply@1') {
                $config->state = TenantSourceConfig::STATE_TESTED;
            } elseif ($failed && $config->state !== TenantSourceConfig::STATE_ACTIVE) {
                $config->state = TenantSourceConfig::STATE_DRAFT;
            }

            $config->save();
        });
    }

    /**
     * @param  array<string, mixed>|null  $report
     */
    public function rememberReport(Connector $connector, ?array $report): bool
    {
        if ($report === null) {
            return false;
        }

        if ($this->hasBannedKey($report)) {
            $this->auditor->log(
                event: 'source_report.rejected',
                actorType: 'connector',
                actorId: $connector->id,
                tenantId: $connector->tenant_id,
                connectorId: $connector->id,
                op: 'heartbeat',
                resultSummary: ['reason' => 'secret_field'],
            );

            return false;
        }

        $connector->forceFill([
            'reported_source' => $this->publicReport($report),
            'reported_source_at' => now(),
        ])->save();

        return true;
    }

    public function recordHoleQuery(Connector $connector, ?ConnectorCommand $command, ?string $errorCode): void
    {
        $origin = is_array($command?->result_json) ? ($command->result_json['origin_id'] ?? null) : null;
        $connector->forceFill([
            'last_hole_query' => [
                'at' => now()->toIso8601String(),
                'ok' => $command?->status === ConnectorCommand::STATUS_COMPLETED,
                'error_code' => $errorCode,
                'duration_ms' => $command?->duration_ms,
                'origin_id' => is_string($origin) ? $origin : null,
            ],
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(TenantContext $context, string $op, bool $applying, ?string $correlationId): array
    {
        $this->assertAdmin($context);
        $connector = $this->connector($context);
        $config = $this->latest($connector, [TenantSourceConfig::STATE_DRAFT, TenantSourceConfig::STATE_TESTED, TenantSourceConfig::STATE_ACTIVE]);

        if ($config === null || ($applying && $config->state === TenantSourceConfig::STATE_ACTIVE)) {
            throw new GatewayException('TEST_REQUIRED', 'No hay cambios pendientes para aplicar.', 422, null, $correlationId);
        }

        if ($applying && ($config->state !== TenantSourceConfig::STATE_TESTED || $config->tested_at === null || $config->tested_at->lt(now()->subMinutes(15)) || $config->test_error_code !== null)) {
            throw new GatewayException('TEST_REQUIRED', 'Hay que probar la configuración antes de aplicarla.', 422, null, $correlationId);
        }

        if (! $applying && $config->state === TenantSourceConfig::STATE_ACTIVE) {
            throw new GatewayException('TEST_REQUIRED', 'No hay cambios pendientes para probar.', 422, null, $correlationId);
        }

        $command = $this->commands->execute(
            $connector,
            $op,
            ['config_id' => $config->id],
            $context->userId,
            $correlationId,
        );
        $this->ingest($command->fresh());
        $config->refresh();
        $outcome = $config->state === TenantSourceConfig::STATE_ACTIVE ? 'applied' : ($config->test_error_code === null ? 'tested' : 'rejected');
        $this->audit($context, $connector, 'source_config.'.$outcome, $config, $outcome, $command);

        if ($command->status === ConnectorCommand::STATUS_TIMEOUT) {
            throw new GatewayException('TIMEOUT', 'El Connector no respondió. La configuración anterior sigue activa.', 504, $command->request_id, $command->correlation_id);
        }

        if ($command->status !== ConnectorCommand::STATUS_COMPLETED || ($applying && $config->state !== TenantSourceConfig::STATE_ACTIVE)) {
            throw new GatewayException(
                $command->error_code ?: 'ADAPTER_ERROR',
                $this->errorText($command->error_code ?: 'ADAPTER_ERROR'),
                502,
                $command->request_id,
                $command->correlation_id,
            );
        }

        return $this->publicConfig($config);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(TenantContext $context, array $input, Connector $connector): array
    {
        $engine = $input['engine'] ?? null;
        $tls = $input['tls_mode'] ?? null;
        $display = trim((string) ($input['display_name'] ?? ''));
        $database = trim((string) ($input['database'] ?? ''));
        $schema = trim((string) ($input['schema'] ?? ''));
        $username = (string) ($input['username'] ?? '');
        $location = trim((string) ($input['installation_location'] ?? ''));
        $host = trim((string) ($input['host'] ?? ''));
        $port = $input['port'] ?? null;

        if (! in_array($engine, ['postgres', 'mysql'], true)) {
            throw new GatewayException('VALIDATION_ERROR', 'El motor no está soportado. Los motores disponibles son PostgreSQL y MySQL.', 422);
        }
        if ($display === '' || mb_strlen($display) > 80) {
            throw new GatewayException('VALIDATION_ERROR', 'El nombre descriptivo es obligatorio y no puede superar 80 caracteres.', 422);
        }
        if (! is_int($port) && ! (is_string($port) && ctype_digit($port))) {
            throw new GatewayException('VALIDATION_ERROR', 'El puerto tiene que estar entre 1 y 65535.', 422);
        }
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new GatewayException('VALIDATION_ERROR', 'El puerto tiene que estar entre 1 y 65535.', 422);
        }
        if ($this->hostLooksCombined($host) || ! $this->hostShapeIsValid($host)) {
            throw new GatewayException('VALIDATION_ERROR', 'El host o la IP no es válido. El puerto va en su propio campo.', 422);
        }
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,62}$/', $database) || strcasecmp($database, 'undsurf') === 0) {
            throw new GatewayException('VALIDATION_ERROR', 'El nombre de la base no es válido. La base interna de UndSurf no puede ser el origen.', 422);
        }
        if ($engine === 'mysql' && $schema !== '') {
            throw new GatewayException('VALIDATION_ERROR', 'MySQL no usa un esquema aparte. El nombre de la base ya identifica el origen.', 422);
        }
        if ($schema !== '' && ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $schema)) {
            throw new GatewayException('VALIDATION_ERROR', 'El esquema solo puede tener letras, números y guion bajo.', 422);
        }
        if (trim($username) === '' || strlen($username) > 128 || preg_match('/[\x00-\x1F]/', $username)) {
            throw new GatewayException('VALIDATION_ERROR', 'El usuario de conexión es obligatorio.', 422);
        }
        $allowedTls = $engine === 'postgres' ? ['verify-full', 'verify-ca'] : ['verify'];
        if (! in_array($tls, $allowedTls, true)) {
            throw new GatewayException('VALIDATION_ERROR', 'Ese modo TLS no está disponible. No se puede desactivar la verificación del certificado.', 422);
        }
        if ($location !== '' && mb_strlen($location) > 160) {
            throw new GatewayException('VALIDATION_ERROR', 'La ubicación de instalación es un texto descriptivo de hasta 160 caracteres.', 422);
        }

        try {
            DestinationPolicy::fromModel(TenantSourcePolicy::resolved($context->tenantId))->assert($host, $port);
        } catch (\InvalidArgumentException) {
            throw new GatewayException('DESTINATION_REJECTED', 'Ese destino no está permitido por la política de la organización.', 422);
        }

        $password = $input['password'] ?? null;
        if ($password === null || $password === '') {
            $previous = $this->latest($connector, [
                TenantSourceConfig::STATE_ACTIVE,
                TenantSourceConfig::STATE_TESTED,
                TenantSourceConfig::STATE_DRAFT,
            ]);
            if ($previous === null) {
                throw new GatewayException('VALIDATION_ERROR', 'La contraseña es obligatoria la primera vez.', 422);
            }
            $ciphertext = $previous->password_ciphertext;
        } else {
            if (! is_string($password) || strlen($password) > 256 || str_contains($password, "\0")) {
                throw new GatewayException('VALIDATION_ERROR', 'La contraseña no es válida.', 422);
            }
            $ciphertext = Crypt::encryptString($password);
        }

        $ca = $input['tls_ca_pem'] ?? null;
        $caCiphertext = null;
        if (is_string($ca) && $ca !== '') {
            if (! str_contains($ca, 'BEGIN CERTIFICATE') || strlen($ca) > 16 * 1024) {
                throw new GatewayException('VALIDATION_ERROR', 'El certificado de la autoridad no tiene un formato PEM.', 422);
            }
            $caCiphertext = Crypt::encryptString($ca);
        }

        $normalizedHost = DestinationPolicy::normalizeHost($host);

        return [
            'display_name' => $display,
            'engine' => $engine,
            'host' => $normalizedHost,
            'port' => $port,
            'database_name' => $database,
            'schema_name' => $schema !== '' ? $schema : null,
            'username' => $username,
            'password_ciphertext' => $ciphertext,
            'ca_ciphertext' => $caCiphertext,
            'tls_mode' => $tls,
            'installation_location' => $location !== '' ? $location : null,
            'fingerprint' => DestinationPolicy::fingerprint($engine, $normalizedHost, $port, $database, $schema),
        ];
    }

    private function connector(TenantContext $context): Connector
    {
        $connector = Connector::query()
            ->where('tenant_id', $context->tenantId)
            ->orderByDesc('last_seen_at')
            ->first();

        if ($connector === null) {
            throw new GatewayException('CONNECTOR_OFFLINE', 'No hay un Connector asociado a esta organización.', 422);
        }

        return $connector;
    }

    /**
     * @param  list<string>  $states
     */
    private function latest(Connector $connector, array $states): ?TenantSourceConfig
    {
        return TenantSourceConfig::query()
            ->where('connector_id', $connector->id)
            ->whereIn('state', $states)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(TenantSourceConfig $config): array
    {
        $config->loadMissing('creator');

        return [
            'id' => $config->id,
            'version' => $config->version,
            'state' => $config->state,
            'display_name' => $config->display_name,
            'engine' => $config->engine,
            'host' => $config->host,
            'port' => $config->port,
            'database' => $config->database_name,
            'schema' => $config->schema_name,
            'username' => $config->username,
            'password_configured' => true,
            'ca_configured' => $config->ca_ciphertext !== null,
            'tls_mode' => $config->tls_mode,
            'tls_configured' => in_array($config->tls_mode, ['verify-full', 'verify-ca', 'verify'], true),
            'tls_verified' => $config->test_tls_verified,
            'installation_location' => $config->installation_location,
            'fingerprint' => $config->fingerprint,
            'tested_at' => $config->tested_at?->toIso8601String(),
            'test_error_code' => $config->test_error_code,
            'test_error' => $config->test_error_code ? $this->errorText($config->test_error_code) : null,
            'test_latency_ms' => $config->test_latency_ms,
            'read_only' => $config->test_read_only,
            'applied_at' => $config->applied_at?->toIso8601String(),
            'updated_at' => $config->updated_at?->toIso8601String(),
            'changed_by' => $config->creator?->name,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @return array<string, mixed>|null
     */
    private function publicReport(mixed $report): ?array
    {
        if (! is_array($report)) {
            return null;
        }

        $allowed = [
            'version', 'origin', 'fingerprint', 'engine', 'display_name', 'host', 'port',
            'database', 'schema', 'username', 'tls_mode', 'tls_configured', 'installation_location',
        ];
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $report)) {
                $clean[$key] = $report[$key];
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicHoleQuery(mixed $query): ?array
    {
        if (! is_array($query)) {
            return null;
        }

        return [
            'at' => $query['at'] ?? null,
            'ok' => $query['ok'] ?? null,
            'error_code' => $query['error_code'] ?? null,
            'error' => $this->errorText(is_string($query['error_code'] ?? null) ? $query['error_code'] : null),
            'duration_ms' => $query['duration_ms'] ?? null,
            'origin_id' => $query['origin_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function hasBannedKey(array $report): bool
    {
        $banned = ['password', 'passwd', 'dsn', 'secret', 'tls_ca_pem', 'sslrootcert', 'connector_token', 'enrollment_token'];
        foreach ($report as $key => $value) {
            if (in_array(strtolower((string) $key), $banned, true)) {
                return true;
            }
            if (is_array($value) && $this->hasBannedKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function hostLooksCombined(string $host): bool
    {
        return str_contains($host, '://')
            || str_contains($host, '@')
            || str_contains($host, '/')
            || (substr_count($host, ':') === 1 && ! str_contains($host, '['));
    }

    private function hostShapeIsValid(string $host): bool
    {
        $host = trim($host, " \t[]");
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host);
    }

    public function errorText(?string $code): string
    {
        return match ($code) {
            null, '' => '',
            'DESTINATION_UNREACHABLE' => 'El Connector no pudo abrir la base en ese host y puerto.',
            'AUTHENTICATION_FAILED' => 'La base rechazó el usuario o la contraseña.',
            'TLS_ERROR' => 'La conexión TLS no pudo verificarse. La verificación del certificado sigue activa.',
            'DESTINATION_REJECTED' => 'Ese destino no está permitido por la política de la organización.',
            'CONNECTOR_OFFLINE' => 'El Connector está desconectado. La configuración anterior sigue activa.',
            'TIMEOUT' => 'El Connector no respondió. La configuración anterior sigue activa.',
            'UNSUPPORTED_OP' => 'Este Connector todavía no puede aplicar una configuración. La base en uso no cambió.',
            'SECRET_ECHO' => 'La respuesta del Connector incluía un secreto y fue descartada. La base en uso no cambió.',
            'NOT_FOUND' => 'La configuración no está disponible para este Connector.',
            default => 'La prueba no pudo completarse.',
        };
    }

    private function assertAdmin(TenantContext $context): void
    {
        if ($context->role !== 'admin') {
            throw new GatewayException('FORBIDDEN', 'No tenés permiso para ver o cambiar la base de origen.', 403);
        }
    }

    private function audit(
        TenantContext $context,
        Connector $connector,
        string $event,
        TenantSourceConfig $config,
        string $outcome,
        ?ConnectorCommand $command = null,
    ): void {
        $this->auditor->log(
            event: $event,
            requestId: $command?->request_id,
            correlationId: $command?->correlation_id,
            actorType: 'user',
            actorId: (string) $context->userId,
            tenantId: $context->tenantId,
            connectorId: $connector->id,
            op: $command?->op ?? 'source_config.save',
            resultSummary: [
                'outcome' => $outcome,
                'version' => $config->version,
                'engine' => $config->engine,
                'host' => $config->host,
                'port' => $config->port,
                'database' => $config->database_name,
                'schema' => $config->schema_name,
                'fingerprint' => $config->fingerprint,
                'tls_mode' => $config->tls_mode,
                'error_code' => $config->test_error_code,
            ],
            durationMs: $command?->duration_ms,
        );
    }

    public function materialForConnector(TenantSourceConfig $config): array
    {
        $policy = TenantSourcePolicy::resolved($config->tenant_id);

        try {
            $password = Crypt::decryptString($config->password_ciphertext);
            $ca = $config->ca_ciphertext ? Crypt::decryptString($config->ca_ciphertext) : '';
        } catch (Throwable) {
            throw new GatewayException('ADAPTER_ERROR', 'No se pudo leer la configuración guardada.', 500);
        }

        return [
            'id' => $config->id,
            'version' => $config->version,
            'display_name' => $config->display_name,
            'engine' => $config->engine,
            'host' => $config->host,
            'port' => $config->port,
            'database' => $config->database_name,
            'schema' => $config->schema_name ?? '',
            'username' => $config->username,
            'password' => $password,
            'tls_mode' => $config->tls_mode,
            'tls_ca_pem' => $ca,
            'installation_location' => $config->installation_location ?? '',
            'allow_loopback' => (bool) $policy->allow_loopback,
            'allow_private' => (bool) $policy->allow_private,
            'allowed_hosts' => $policy->allowed_hosts ?? [],
            'allowed_ports' => $policy->allowed_ports ?? [],
        ];
    }

    public function echoesSecret(TenantSourceConfig $config, mixed $payload): bool
    {
        try {
            $password = Crypt::decryptString($config->password_ciphertext);
        } catch (Throwable) {
            return false;
        }

        if ($password === '') {
            return false;
        }

        return str_contains(json_encode($payload) ?: '', $password);
    }

    public function changedByName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return User::query()->whereKey($userId)->value('name');
    }
}
