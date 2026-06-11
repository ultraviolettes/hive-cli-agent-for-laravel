<?php

namespace App\Commands\Concerns;

use App\Support\BranchName;

/**
 * Guards the commands that take a branch argument: a name that would not
 * survive BranchName validation never reaches git/gh.
 */
trait ValidatesBranch
{
    protected function validBranch(string $branch): bool
    {
        if (BranchName::isValid($branch)) {
            return true;
        }

        $this->error("Invalid branch name '{$branch}'.");

        return false;
    }
}
