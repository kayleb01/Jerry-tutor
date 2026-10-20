<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\User;

class ClassGroupPolicy
{
    /**
     * Only a tutor who teaches a (non-removed) slot of an active class can add students to it.
     */
    public function addStudent(User $user, ClassGroup $classGroup): bool
    {
        return $user->hasRole(Role::Tutor)
            && $classGroup->is_active
            && $classGroup->slots()->where('tutor_id', $user->id)->exists();
    }
}
