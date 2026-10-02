<?php

namespace App\Services;

use App\Enums\AuditSource;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns the only two state transitions a Membership may legally undergo:
 * activation (grants a user operational access to one tenant) and
 * deactivation (revokes it, atomically, along with every Sanctum token the
 * affected user currently holds).
 *
 * This is the only supported way to close a membership — see
 * docs/audits/2026-09-12-undsurf-auditoria-ciclo-vida-usuarios.md. A
 * mailbox being disabled at the mining company is never itself a
 * revocation signal here; there is no HR/IdP integration wired in yet, so
 * closing access is always an explicit call into this service (today, from
 * a test or a console/tinker operator — an admin HTTP endpoint is Paso 5,
 * not this step).
 *
 * No HTTP endpoint exists for this service yet. It is exercised directly
 * (tests, console) until the admin endpoint step.
 */
class MembershipLifecycleService
{
    /**
     * Grants (or restores) a user's operational access to one tenant.
     *
     * Rejects the call outright if the user already holds a different
     * active membership — this service never closes one membership to open
     * another; that is two explicit calls (deactivate, then activate), so
     * a caller can never accidentally lose track of which tenant a user
     * was let go from. Re-activating the same (user, tenant) pair reuses
     * the existing historical row instead of violating the unique
     * (user_id, tenant_id) constraint, which is also how that tenant's
     * history with this person survives a re-hire.
     *
     * @throws RuntimeException if the user already has an active membership
     */
    public function activate(
        User $user,
        Tenant $tenant,
        string $role,
        ?string $corporateEmail,
        ?string $externalHrId,
        ?User $actor,
        AuditSource|string $source = AuditSource::System,
    ): Membership {
        return DB::transaction(function () use ($user, $tenant, $role, $corporateEmail, $externalHrId, $actor, $source) {
            $hasActiveElsewhere = Membership::query()
                ->where('user_id', $user->id)
                ->where('status', Membership::STATUS_ACTIVE)
                ->lockForUpdate()
                ->exists();

            if ($hasActiveElsewhere) {
                throw new RuntimeException('MEMBERSHIP_ALREADY_ACTIVE');
            }

            $membership = Membership::query()
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            $normalizedEmail = $corporateEmail !== null
                ? Str::lower(trim($corporateEmail))
                : null;

            $oldValues = $membership?->only(['status', 'role', 'corporate_email']);

            $attributes = [
                'role' => $role,
                'status' => Membership::STATUS_ACTIVE,
                'corporate_email' => $normalizedEmail,
                'external_hr_id' => $externalHrId,
                'started_at' => now(),
                'ended_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ];

            if ($membership) {
                $membership->fill($attributes)->save();
            } else {
                $membership = Membership::create([
                    'id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'tenant_id' => $tenant->id,
                    ...$attributes,
                ]);
            }

            AuditLogger::log(
                $actor,
                Membership::class,
                // AuditLog.entity_id is unsignedBigInteger (see 2026-07-08
                // migration); Membership uses a ULID primary key, so it
                // cannot travel in entity_id without corrupting the column
                // on Postgres. Minimal, documented workaround: entity_id
                // anchors to the affected user (always a valid bigint),
                // and the membership's own id travels inside new_values.
                // Proper fix (widening entity_id to string, or adding an
                // entity_ulid column) is out of scope for this step — see
                // the audit report's "brechas pendientes" section.
                $user->id,
                'membership.activated',
                $oldValues,
                [
                    'membership_id' => $membership->id,
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'status' => Membership::STATUS_ACTIVE,
                    'corporate_email' => $normalizedEmail,
                    'external_hr_id' => $externalHrId,
                ],
                $source,
            );

            return $membership->fresh();
        });
    }

    /**
     * Closes a membership: status becomes inactive, ended_at/deactivated_by
     * /deactivation_reason are recorded, and every Sanctum token the
     * affected user holds is revoked — atomically, in one transaction.
     *
     * Idempotent by design: calling this again on an already-inactive
     * membership is a no-op that returns the existing row unchanged rather
     * than throwing or rewriting history. A caller that isn't sure whether
     * a membership was already closed (e.g. a retried request) should not
     * have to distinguish "already done" from "failed" — but it also never
     * overwrites the original ended_at/reason/actor with a second, possibly
     * different, call.
     *
     * Never deletes the user, the tenant, or the membership row itself —
     * closing access is not erasing history.
     *
     * @throws InvalidArgumentException if $reason is blank
     */
    public function deactivate(
        Membership $membership,
        User $actor,
        string $reason,
        AuditSource|string $source = AuditSource::System,
    ): Membership {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('DEACTIVATION_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($membership, $actor, $reason, $source) {
            /** @var Membership $locked */
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Membership::STATUS_INACTIVE) {
                return $locked;
            }

            $oldValues = $locked->only(['status', 'ended_at', 'deactivated_by', 'deactivation_reason']);

            $locked->forceFill([
                'status' => Membership::STATUS_INACTIVE,
                'ended_at' => now(),
                'deactivated_by' => $actor->id,
                'deactivation_reason' => $reason,
            ])->save();

            // Immediate revocation: every Sanctum token of this user, scoped
            // by user_id — never another user's tokens. EnsureUserIsActive
            // and ResolveTenantContext re-read users.active and the active
            // membership on later requests, so a token that survives this
            // delete, or a status change that never calls this method, is
            // still rejected before tenant data or a Connector command.
            $locked->user->tokens()->delete();

            AuditLogger::log(
                $actor,
                Membership::class,
                $locked->user_id,
                'membership.deactivated',
                $oldValues,
                [
                    'membership_id' => $locked->id,
                    'tenant_id' => $locked->tenant_id,
                    'user_id' => $locked->user_id,
                    'status' => Membership::STATUS_INACTIVE,
                    'ended_at' => $locked->ended_at?->toISOString(),
                    'deactivated_by' => $locked->deactivated_by,
                    'reason' => $reason,
                ],
                $source,
            );

            return $locked->fresh();
        });
    }
}
