<?php
declare(strict_types=1);

/** Passwords people pick most often; also caught with numbers or symbols added ("Password2024!"). */
const COMMON_PASSWORDS = [
    'password', 'passw0rd', 'qwerty', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'abcdef', 'abcdefgh', 'abcdefghij', 'abc',
    'letmein', 'welcome', 'iloveyou', 'monkey', 'dragon', 'football', 'baseball', 'sunshine', 'princess', 'master',
    'admin', 'administrator', 'login', 'changeme', 'secret', 'trustno', 'starwars', 'whatever', 'freedom', 'shadow',
    'superman', 'batman', 'computer', 'internet', 'qazwsx', 'michael', 'jesus', 'jesuslovesme', 'blessed', 'godisgood',
    'kenya', 'nairobi', 'homabay', 'oyugis', 'kisumu', 'mombasa', 'safaricom', 'mpesa', 'judes', 'judescbo', 'cbo',
    'community', 'youth', 'donate', 'charity', 'foundation', 'organization', 'organisation', 'website', 'dashboard',
];
const COMMON_NUMBERS = ['1234567890', '0987654321', '1111111111', '0000000000', '1234512345', '1122334455', '1qaz2wsx3edc', '0712345678'];

/**
 * Returns why a password isn't good enough, or null if it's fine.
 * $about holds words the password mustn't contain: name, username, email.
 */
function password_problem(string $password, array $about = []): ?string
{
    if (mb_strlen($password) < 10) return 'Passwords must be at least 10 characters.';
    if (mb_strlen($password) > 200) return 'Passwords can be at most 200 characters.';
    $lower = mb_strtolower($password);
    // Strip numbers and symbols added at either end, then undo look-alike swaps (p@ssw0rd → password)
    $core = preg_replace('/^[\d\W_]+|[\d\W_]+$/u', '', $lower) ?? '';
    $letters = preg_replace('/[^a-z]/', '', strtr($core, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '@' => 'a', '$' => 's', '!' => 'i']));
    $plain = preg_replace('/[^a-z]/', '', $lower);
    if (in_array($lower, COMMON_NUMBERS, true) || in_array($plain, COMMON_PASSWORDS, true) || in_array($letters, COMMON_PASSWORDS, true)) {
        return 'That password is too common and easy to guess. Try three or four random words together.';
    }
    if (count(array_unique(mb_str_split($lower))) < 5) return 'That password repeats the same few characters. Use more variety.';
    if (preg_match('/^(?:0123456789|1234567890|abcdefghij|qwertyuiop)/', $lower)) return 'That password is a simple sequence. Try three or four random words together.';
    foreach ($about as $value) {
        foreach (preg_split('/[\s@._\-]+/', mb_strtolower((string) $value)) ?: [] as $part) {
            if (mb_strlen($part) >= 4 && str_contains($lower, $part)) {
                return "Passwords mustn't contain your name, username or email.";
            }
        }
    }
    return null;
}
