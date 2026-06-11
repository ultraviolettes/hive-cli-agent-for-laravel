<?php

namespace App\Support;

/**
 * Central validator for branch names crossing a trust boundary (AI output,
 * CLI arguments) before they reach git/gh as command arguments. The rules
 * are a strict subset of `git check-ref-format`: in particular a leading
 * '-' is rejected so a branch name can never be parsed as an option.
 */
final class BranchName
{
    private const MAX_LENGTH = 255;

    /**
     * @throws \InvalidArgumentException when the name is unsafe or malformed
     */
    public static function assert(string $branch): string
    {
        if ($branch === '' || strlen($branch) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Invalid branch name: must be 1-' . self::MAX_LENGTH . ' characters.');
        }

        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch)) {
            throw new \InvalidArgumentException(
                "Invalid branch name '{$branch}': only letters, digits, '.', '_', '-' and '/' are allowed, starting with a letter or digit."
            );
        }

        if (str_contains($branch, '..')) {
            throw new \InvalidArgumentException("Invalid branch name '{$branch}': '..' is not allowed.");
        }

        // git check-ref-format rules apply per slash-separated component.
        foreach (explode('/', $branch) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || str_ends_with($segment, '.') || str_ends_with($segment, '.lock')) {
                throw new \InvalidArgumentException(
                    "Invalid branch name '{$branch}': each segment must be non-empty, must not start or end with '.', and must not end with '.lock'."
                );
            }
        }

        return $branch;
    }

    public static function isValid(string $branch): bool
    {
        try {
            self::assert($branch);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
