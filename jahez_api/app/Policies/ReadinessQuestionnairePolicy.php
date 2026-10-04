<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Administration of the readiness questionnaire versions (ADR-018 addendum): IMC
 * administrators with readiness_questionnaires.manage. Every signed-in account reads the
 * current version through GET /readiness-questionnaire instead.
 */
class ReadinessQuestionnairePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission(Permission::ReadinessQuestionnairesManage);
    }
}
