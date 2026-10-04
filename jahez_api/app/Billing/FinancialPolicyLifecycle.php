<?php

namespace App\Billing;

use App\Enums\AuditEvent;
use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Enums\FinancialPolicyVersionStatus;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use App\Models\Sector;
use App\Models\ServiceProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Every change to a financial policy and its versions (ADR-023). Each method authorizes
 * the actor itself (not only the HTTP layer), runs in one transaction with the policy row
 * locked for update, and writes its audit entry in that transaction (ADR-012).
 *
 * Invariants:
 * - One policy per kind and scope (unique `scope_key`); at most one draft or pending
 *   version per policy.
 * - Values change only in a draft. An approved version never changes, except for an
 *   earlier end date when it is superseded or ended.
 * - Approval is by an administrator holding financial_policies.approve who did not
 *   draft, edit or submit the version, and only while its start date has not passed:
 *   nothing is ever approved retroactively.
 * - The approved versions of a policy never overlap. A new version may supersede the
 *   one in effect on its start date, whose period then ends the day before; any other
 *   overlap is refused with 409.
 */
final class FinancialPolicyLifecycle
{
    /**
     * Create a policy for a kind and scope with its first draft version.
     *
     * @param  array{parameters: array<string, mixed>, effective_from: string, effective_to: string|null, change_reason: string}  $draft
     */
    public static function createPolicy(User $actor, FinancialPolicyKind $kind, FinancialPolicyScope $scope, ?int $scopeId, string $nameAr, ?string $descriptionAr, array $draft): FinancialPolicyVersion
    {
        Gate::forUser($actor)->authorize('create', FinancialPolicy::class);

        if (! in_array($scope, $kind->allowedScopes(), true)) {
            throw ValidationException::withMessages(['scope_type' => "A {$kind->value} policy cannot be set for this scope."]);
        }
        self::ensureScopeExists($scope, $scopeId);
        $parameters = PolicyParameters::normalize($kind, $draft['parameters']);
        self::ensurePeriod($draft['effective_from'], $draft['effective_to']);

        return DB::transaction(function () use ($actor, $kind, $scope, $scopeId, $nameAr, $descriptionAr, $draft, $parameters): FinancialPolicyVersion {
            $key = $scope->key($scopeId);
            if (FinancialPolicy::query()->where('kind', $kind)->where('scope_key', $key)->lockForUpdate()->exists()) {
                throw new ConflictHttpException('A policy of this kind already exists for this scope; add a new version to it instead.');
            }

            $policy = new FinancialPolicy;
            $policy->kind = $kind;
            $policy->scope_type = $scope;
            $policy->scope_id = $scope === FinancialPolicyScope::Global ? null : $scopeId;
            $policy->scope_key = $key;
            $policy->name_ar = $nameAr;
            $policy->description_ar = $descriptionAr;
            $policy->created_by_user_id = $actor->id;
            $policy->save();

            AuditLog::record(AuditEvent::FinancialPolicyCreated, $actor, $policy, ['kind' => $kind->value, 'scope' => $key]);

            return self::newVersion($actor, $policy, 1, $parameters, $draft);
        });
    }

    /**
     * Add the next draft version to a policy, for example to change a rate from a date.
     *
     * @param  array{parameters: array<string, mixed>, effective_from: string, effective_to: string|null, change_reason: string}  $draft
     */
    public static function draftVersion(User $actor, FinancialPolicy $policy, array $draft): FinancialPolicyVersion
    {
        Gate::forUser($actor)->authorize('draft', $policy);
        $parameters = PolicyParameters::normalize($policy->kind, $draft['parameters']);
        self::ensurePeriod($draft['effective_from'], $draft['effective_to']);

        return DB::transaction(function () use ($actor, $policy, $parameters, $draft): FinancialPolicyVersion {
            $locked = FinancialPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $open = $locked->versions()->whereIn('status', [FinancialPolicyVersionStatus::Draft, FinancialPolicyVersionStatus::PendingApproval])->first();
            if ($open !== null) {
                throw new ConflictHttpException("Version {$open->version} of this policy is still {$open->status->value}; finish or archive it before drafting another.");
            }

            return self::newVersion($actor, $locked, (int) $locked->versions()->max('version') + 1, $parameters, $draft);
        });
    }

    /**
     * Change a draft. Only the fields given change; every change is audited with its
     * previous and new value.
     *
     * @param  array{parameters?: array<string, mixed>, effective_from?: string, effective_to?: string|null, change_reason?: string}  $changes
     */
    public static function updateDraft(User $actor, FinancialPolicyVersion $version, array $changes): FinancialPolicyVersion
    {
        Gate::forUser($actor)->authorize('prepare', $version);

        return DB::transaction(function () use ($actor, $version, $changes): FinancialPolicyVersion {
            [$policy, $locked] = self::lock($version);
            self::ensureStatus($locked, FinancialPolicyVersionStatus::Draft, 'edited');

            $before = self::auditedValues($locked);
            if (array_key_exists('parameters', $changes)) {
                $locked->parameters = PolicyParameters::normalize($policy->kind, $changes['parameters']);
            }
            if (array_key_exists('effective_from', $changes)) {
                $locked->effective_from = PolicyCalendar::parse($changes['effective_from']);
            }
            if (array_key_exists('effective_to', $changes)) {
                $locked->effective_to = $changes['effective_to'] === null ? null : PolicyCalendar::parse($changes['effective_to']);
            }
            if (array_key_exists('change_reason', $changes)) {
                $locked->change_reason = $changes['change_reason'];
            }
            self::ensurePeriod($locked->effective_from->toDateString(), $locked->effective_to?->toDateString());

            $diff = self::diff($before, self::auditedValues($locked));
            if ($diff === []) {
                return $locked;
            }

            $locked->updated_by_user_id = $actor->id;
            $locked->save();
            AuditLog::record(AuditEvent::FinancialPolicyVersionUpdated, $actor, $locked, ['policy_id' => $policy->id, 'version' => $locked->version, 'changes' => $diff]);

            return $locked;
        });
    }

    /**
     * Send a complete draft for approval. Its values are validated again against the
     * current rules, and its start date must not have passed.
     */
    public static function submit(User $actor, FinancialPolicyVersion $version): FinancialPolicyVersion
    {
        Gate::forUser($actor)->authorize('prepare', $version);

        return DB::transaction(function () use ($actor, $version): FinancialPolicyVersion {
            [$policy, $locked] = self::lock($version);
            self::ensureStatus($locked, FinancialPolicyVersionStatus::Draft, 'submitted');
            $locked->parameters = PolicyParameters::normalize($policy->kind, $locked->parameters);
            self::ensurePeriod($locked->effective_from->toDateString(), $locked->effective_to?->toDateString());
            self::conflictsOf($policy, $locked);

            $locked->status = FinancialPolicyVersionStatus::PendingApproval;
            $locked->submitted_by_user_id = $actor->id;
            $locked->submitted_at = now();
            self::stamp($locked, $actor, null);
            $locked->save();

            AuditLog::record(AuditEvent::FinancialPolicyVersionSubmitted, $actor, $locked, ['policy_id' => $policy->id, 'version' => $locked->version]);

            return $locked;
        });
    }

    /**
     * Approve a submitted version. Supersedes the version in effect on its start date.
     */
    public static function approve(User $actor, FinancialPolicyVersion $version, ?string $note): FinancialPolicyVersion
    {
        return DB::transaction(function () use ($actor, $version, $note): FinancialPolicyVersion {
            [$policy, $locked] = self::lock($version);
            Gate::forUser($actor)->authorize('decide', $locked);
            self::ensureStatus($locked, FinancialPolicyVersionStatus::PendingApproval, 'approved');

            if ($locked->effective_from->toDateString() < PolicyCalendar::today()->toDateString()) {
                throw new ConflictHttpException('The start date of this version has passed, and nothing is approved retroactively; reject it and draft a new version.');
            }

            $predecessor = self::conflictsOf($policy, $locked);

            $locked->status = FinancialPolicyVersionStatus::Approved;
            $locked->decided_by_user_id = $actor->id;
            $locked->decided_at = now();
            $locked->decision_note = $note;
            self::stamp($locked, $actor, $note);
            $locked->save();

            if ($predecessor !== null) {
                $previousEnd = $predecessor->effective_to?->toDateString();
                $predecessor->effective_to = $locked->effective_from->subDay();
                $predecessor->status = FinancialPolicyVersionStatus::Superseded;
                $predecessor->superseded_by_version_id = $locked->id;
                self::stamp($predecessor, $actor, "Superseded by version {$locked->version}.");
                $predecessor->save();

                AuditLog::record(AuditEvent::FinancialPolicyVersionSuperseded, $actor, $predecessor, [
                    'policy_id' => $policy->id,
                    'version' => $predecessor->version,
                    'superseded_by_version' => $locked->version,
                    'effective_to' => ['from' => $previousEnd, 'to' => $locked->effective_from->subDay()->toDateString()],
                ]);
            }

            AuditLog::record(AuditEvent::FinancialPolicyVersionApproved, $actor, $locked, array_filter([
                'policy_id' => $policy->id,
                'version' => $locked->version,
                'effective_from' => $locked->effective_from->toDateString(),
                'effective_to' => $locked->effective_to?->toDateString(),
                'supersedes_version' => $predecessor?->version,
                'note' => $note,
            ], fn (mixed $value): bool => $value !== null));

            return $locked;
        });
    }

    public static function reject(User $actor, FinancialPolicyVersion $version, string $reason): FinancialPolicyVersion
    {
        return DB::transaction(function () use ($actor, $version, $reason): FinancialPolicyVersion {
            [$policy, $locked] = self::lock($version);
            Gate::forUser($actor)->authorize('decide', $locked);
            self::ensureStatus($locked, FinancialPolicyVersionStatus::PendingApproval, 'rejected');

            $locked->status = FinancialPolicyVersionStatus::Rejected;
            $locked->decided_by_user_id = $actor->id;
            $locked->decided_at = now();
            $locked->decision_note = $reason;
            self::stamp($locked, $actor, $reason);
            $locked->save();

            AuditLog::record(AuditEvent::FinancialPolicyVersionRejected, $actor, $locked, ['policy_id' => $policy->id, 'version' => $locked->version, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Discard a draft or a rejected version, or withdraw an approved version that has not
     * started. A version in effect is ended instead (end()). A scheduled version that
     * superseded an earlier one cannot be withdrawn: the earlier version's end date would
     * have to move later, which an approved version never does (approve a new version
     * with the earlier terms instead).
     */
    public static function archive(User $actor, FinancialPolicyVersion $version, string $reason): FinancialPolicyVersion
    {
        return DB::transaction(function () use ($actor, $version, $reason): FinancialPolicyVersion {
            [$policy, $locked] = self::lock($version);
            Gate::forUser($actor)->authorize('archive', $locked);

            if (! $locked->status->canBecome(FinancialPolicyVersionStatus::Archived)) {
                throw new ConflictHttpException("This version is {$locked->status->value} and cannot be archived.");
            }
            if ($locked->status === FinancialPolicyVersionStatus::Approved) {
                if ($locked->effectiveStatus() !== 'scheduled') {
                    throw new ConflictHttpException('This version is already in effect; end it instead of archiving it.');
                }
                $superseded = $policy->versions()->where('superseded_by_version_id', $locked->id)->first();
                if ($superseded !== null) {
                    throw new ConflictHttpException("This version replaced version {$superseded->version}, whose end date cannot move later; approve a new version instead.");
                }
            }

            $previous = $locked->status;
            $locked->status = FinancialPolicyVersionStatus::Archived;
            self::stamp($locked, $actor, $reason);
            $locked->save();

            AuditLog::record(AuditEvent::FinancialPolicyVersionArchived, $actor, $locked, ['policy_id' => $policy->id, 'version' => $locked->version, 'from' => $previous->value, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * End an approved version early: its last day becomes $lastDay (today or later).
     * Records already made under it keep it; nothing made after its last day can use it.
     */
    public static function end(User $actor, FinancialPolicyVersion $version, CarbonImmutable $lastDay, string $reason): FinancialPolicyVersion
    {
        return DB::transaction(function () use ($actor, $version, $lastDay, $reason): FinancialPolicyVersion {
            [$policy, $locked] = self::lock($version);
            Gate::forUser($actor)->authorize('end', $locked);

            if ($locked->status !== FinancialPolicyVersionStatus::Approved) {
                throw new ConflictHttpException("This version is {$locked->status->value} and cannot be ended.");
            }
            $day = $lastDay->toDateString();
            $errors = match (true) {
                $locked->effectiveStatus() === 'scheduled' => 'This version has not started; archive it instead of ending it.',
                $day < PolicyCalendar::today()->toDateString() => 'The last day cannot be in the past.',
                $locked->effective_to !== null && $day >= $locked->effective_to->toDateString() => 'The last day must be earlier than the current end date.',
                default => null,
            };
            if ($errors !== null) {
                throw ValidationException::withMessages(['effective_to' => $errors]);
            }

            $previousEnd = $locked->effective_to?->toDateString();
            $locked->effective_to = $lastDay;
            $locked->status = FinancialPolicyVersionStatus::Ended;
            self::stamp($locked, $actor, $reason);
            $locked->save();

            AuditLog::record(AuditEvent::FinancialPolicyVersionEnded, $actor, $locked, [
                'policy_id' => $policy->id,
                'version' => $locked->version,
                'effective_to' => ['from' => $previousEnd, 'to' => $day],
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * The approved version this one would supersede, or null. Refuses with 409 any other
     * overlap with an approved version of the same policy.
     */
    private static function conflictsOf(FinancialPolicy $policy, FinancialPolicyVersion $candidate): ?FinancialPolicyVersion
    {
        $from = $candidate->effective_from->toDateString();
        $to = $candidate->effective_to?->toDateString();
        $predecessor = null;

        $approved = $policy->versions()
            ->whereIn('status', FinancialPolicyVersionStatus::resolvable())
            ->whereKeyNot($candidate->id)
            ->lockForUpdate()
            ->get();

        foreach ($approved as $other) {
            $otherFrom = $other->effective_from->toDateString();
            $otherTo = $other->effective_to?->toDateString();

            if ($otherFrom < $from && ($otherTo === null || $otherTo >= $from)) {
                $predecessor = $other;
            } elseif ($otherFrom >= $from && ($to === null || $otherFrom <= $to)) {
                throw new ConflictHttpException("This version's period overlaps approved version {$other->version} (from {$otherFrom}); change its dates, or archive or end version {$other->version} first.");
            }
        }

        return $predecessor;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array{effective_from: string, effective_to: string|null, change_reason: string}  $draft
     */
    private static function newVersion(User $actor, FinancialPolicy $policy, int $number, array $parameters, array $draft): FinancialPolicyVersion
    {
        $version = new FinancialPolicyVersion;
        $version->financial_policy_id = $policy->id;
        $version->version = $number;
        $version->parameters = $parameters;
        $version->effective_from = PolicyCalendar::parse($draft['effective_from']);
        $version->effective_to = $draft['effective_to'] === null ? null : PolicyCalendar::parse($draft['effective_to']);
        $version->change_reason = $draft['change_reason'];
        $version->created_by_user_id = $actor->id;
        $version->save();

        AuditLog::record(AuditEvent::FinancialPolicyVersionDrafted, $actor, $version, [
            'policy_id' => $policy->id,
            'version' => $number,
            'effective_from' => $draft['effective_from'],
            'effective_to' => $draft['effective_to'],
            'change_reason' => $draft['change_reason'],
        ]);

        return $version->setRelation('policy', $policy);
    }

    /**
     * @return array{0: FinancialPolicy, 1: FinancialPolicyVersion}
     */
    private static function lock(FinancialPolicyVersion $version): array
    {
        $policy = FinancialPolicy::query()->lockForUpdate()->findOrFail($version->financial_policy_id);
        $locked = FinancialPolicyVersion::query()->lockForUpdate()->findOrFail($version->id);
        $locked->setRelation('policy', $policy);

        return [$policy, $locked];
    }

    private static function ensureStatus(FinancialPolicyVersion $version, FinancialPolicyVersionStatus $expected, string $action): void
    {
        if ($version->status !== $expected) {
            throw new ConflictHttpException("This version is {$version->status->value} and cannot be {$action}.");
        }
    }

    private static function ensurePeriod(string $from, ?string $to): void
    {
        $today = PolicyCalendar::today()->toDateString();
        if ($from < $today) {
            throw ValidationException::withMessages(['effective_from' => 'A policy version cannot start in the past; nothing is applied retroactively.']);
        }
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date must be on or after the start date.']);
        }
    }

    private static function ensureScopeExists(FinancialPolicyScope $scope, ?int $scopeId): void
    {
        $exists = match ($scope) {
            FinancialPolicyScope::Global => $scopeId === null,
            FinancialPolicyScope::Sector => $scopeId !== null && Sector::query()->whereKey($scopeId)->exists(),
            FinancialPolicyScope::CatalogService => $scopeId !== null && CatalogService::query()->whereKey($scopeId)->exists(),
            FinancialPolicyScope::ServiceProvider => $scopeId !== null && ServiceProvider::query()->whereKey($scopeId)->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages(['scope_id' => $scope === FinancialPolicyScope::Global ? 'A global policy has no scope id.' : 'The selected scope does not exist.']);
        }
    }

    private static function stamp(FinancialPolicyVersion $version, User $actor, ?string $reason): void
    {
        $version->status_changed_by_user_id = $actor->id;
        $version->status_changed_at = now();
        $version->status_reason = $reason;
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditedValues(FinancialPolicyVersion $version): array
    {
        return [
            'effective_from' => $version->effective_from->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(),
            'change_reason' => $version->change_reason,
            'parameters' => $version->parameters,
        ];
    }

    /**
     * The changed fields with their old and new values. Contract clauses can be long, so
     * a clause change is recorded as the clause counts, not the text.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (['effective_from', 'effective_to', 'change_reason'] as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
            }
        }

        foreach (array_unique([...array_keys($before['parameters']), ...array_keys($after['parameters'])]) as $key) {
            $old = $before['parameters'][$key] ?? null;
            $new = $after['parameters'][$key] ?? null;
            if ($old == $new) {
                continue;
            }
            $changes["parameters.{$key}"] = $key === 'clauses'
                ? ['from' => ['count' => is_array($old) ? count($old) : 0], 'to' => ['count' => is_array($new) ? count($new) : 0]]
                : ['from' => $old, 'to' => $new];
        }

        return $changes;
    }
}
