// Fragmento para `php artisan tinker --execute`. No es un seeder.
// Lee LAB_EMAIL, LAB_PASSWORD y LAB_TENANT_ID del entorno. No los imprime.
// Crea el usuario solo si el email no existe y le abre una membership
// con MembershipLifecycleService::activate.

$email = getenv('LAB_EMAIL');
$password = getenv('LAB_PASSWORD');
$tenantId = getenv('LAB_TENANT_ID');

if (! is_string($email) || $email === '' || ! is_string($password) || $password === '' || ! is_string($tenantId) || $tenantId === '') {
    fwrite(STDERR, "missing LAB_EMAIL, LAB_PASSWORD or LAB_TENANT_ID\n");
    exit(1);
}

if (App\Models\User::query()->where('email', $email)->exists()) {
    fwrite(STDERR, "user exists; stop\n");
    exit(2);
}

$tenant = App\Models\Tenant::query()->find($tenantId);

if ($tenant === null) {
    fwrite(STDERR, "tenant missing; stop\n");
    exit(3);
}

$user = App\Models\User::query()->create([
    'name' => 'Lab listado',
    'email' => $email,
    'password' => $password,
    'role' => App\Enums\UserRole::Supervisor,
    'active' => true,
]);

$membership = app(App\Services\MembershipLifecycleService::class)->activate(
    $user,
    $tenant,
    'supervisor',
    null,
    null,
    null,
    App\Enums\AuditSource::System,
);

echo 'user_id='.$user->id.PHP_EOL;
echo 'membership_id='.$membership->id.PHP_EOL;
echo 'membership_status='.$membership->status.PHP_EOL;
'done';
