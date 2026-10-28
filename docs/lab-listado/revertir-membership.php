// Fragmento para `php artisan tinker --execute`. No borra usuario ni tenant.
// Cierra la membership de LAB_EMAIL en LAB_TENANT_ID y revoca los tokens
// Sanctum de ese usuario, vía MembershipLifecycleService::deactivate.

$email = getenv('LAB_EMAIL');
$tenantId = getenv('LAB_TENANT_ID');

if (! is_string($email) || $email === '' || ! is_string($tenantId) || $tenantId === '') {
    fwrite(STDERR, "missing LAB_EMAIL or LAB_TENANT_ID\n");
    exit(1);
}

$user = App\Models\User::query()->where('email', $email)->first();
$tenant = App\Models\Tenant::query()->find($tenantId);

if ($user === null || $tenant === null) {
    fwrite(STDERR, "user or tenant missing; stop\n");
    exit(2);
}

$membership = App\Models\Membership::query()
    ->where('user_id', $user->id)
    ->where('tenant_id', $tenant->id)
    ->first();

if ($membership === null) {
    fwrite(STDERR, "membership missing; stop\n");
    exit(3);
}

$closed = app(App\Services\MembershipLifecycleService::class)->deactivate(
    $membership,
    $user,
    'Reversion del listado de laboratorio',
    App\Enums\AuditSource::System,
);

echo 'user_id='.$user->id.PHP_EOL;
echo 'membership_id='.$closed->id.PHP_EOL;
echo 'membership_status='.$closed->status.PHP_EOL;
'done';
