<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

/** Parse grant scopes, not credential-bearing account text. Uncertainty fails closed. */
final class GrantPolicy
{
    public static function inspect(\PDO $pdo, string $database, string $version = ''): array
    {
        $queries = ['SHOW GRANTS FOR CURRENT_USER()', 'SHOW GRANTS FOR CURRENT_USER', 'SHOW GRANTS'];
        $grants = null;
        $method = '';
        foreach ($queries as $query) {
            try {
                $statement = $pdo->query($query);
                if ($statement === false) throw new SafeException('Unable to verify privileges: current-account grant inspection returned no result. No missing SELECT grant was inferred.');
                $grants = $statement->fetchAll(\PDO::FETCH_COLUMN);
                $method = $query;
                break;
            } catch (\PDOException $error) {
                // Fall back only for unsupported syntax, never denied access.
                if (!in_array((int) ($error->errorInfo[1] ?? 0), [1064, 1235], true)) {
                    throw new SafeException('Unable to verify privileges: the server did not permit current-account grant inspection. No missing SELECT grant was inferred.');
                }
            }
        }
        if ($grants === null) throw new SafeException('Unable to verify privileges: current-account SHOW GRANTS syntax is unsupported. No missing SELECT grant was inferred.');
        $context = [];
        try {
            foreach ($pdo->query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('partial_revokes','lower_case_table_names')")->fetchAll() as $row) {
                if ($row['Variable_name'] === 'partial_revokes' && in_array(strtoupper((string) $row['Value']), ['ON', 'OFF', '0', '1'], true)) $context['partial_revokes'] = in_array(strtoupper((string) $row['Value']), ['ON', '1'], true);
                if ($row['Variable_name'] === 'lower_case_table_names' && in_array((string) $row['Value'], ['0', '1', '2'], true)) $context['case_insensitive'] = (int) $row['Value'] !== 0;
            }
        } catch (\PDOException) {
            // Literal identifiers may still prove coverage without this
            // optional metadata. Ambiguous patterns must not be guessed.
        }
        if (!isset($context['partial_revokes']) && (stripos($version, 'MariaDB') !== false || ($version !== '' && version_compare($version, '8.0.16', '<')))) {
            $context['partial_revokes'] = false;
        }

        return self::verify($grants, $database, $context) + ['inspection_method' => $method];
    }

    public static function verify(#[\SensitiveParameter] array $grants, string $database, array $context = []): array
    {
        $matched = [];
        $scopes = [];
        $unknown = false;
        $forbidden = false;
        $globalSelect = false;
        if (!$grants || count($grants) > 4096) self::unverified('none / unavailable');
        foreach ($grants as $grant) {
            $tokens = is_string($grant) ? self::tokens($grant) : null;
            if (!$tokens || !self::word($tokens[0], 'GRANT')) { $unknown = true; continue; }
            $on = self::keyword($tokens, 'ON', 1);
            $to = $on === null ? null : self::keyword($tokens, 'TO', $on + 1);
            if ($on === null || $to === null || !isset($tokens[$to + 1]) || $tokens[$to + 1]['kind'] === 'symbol') {
                $unknown = true; $scopes['role / unsupported grant'] = true; continue;
            }
            $afterAccount = $to + 2;
            if (isset($tokens[$afterAccount]) && $tokens[$afterAccount]['kind'] === 'symbol' && $tokens[$afterAccount]['value'] === '@') {
                if (!isset($tokens[$afterAccount + 1]) || $tokens[$afterAccount + 1]['kind'] === 'symbol') { $unknown = true; continue; }
                $afterAccount += 2;
            }
            if (isset($tokens[$afterAccount]) && ($tokens[$afterAccount]['kind'] !== 'word' || !in_array(strtoupper($tokens[$afterAccount]['value']), ['IDENTIFIED', 'REQUIRE', 'WITH', 'PASSWORD', 'ACCOUNT'], true))) {
                $unknown = true; continue;
            }
            // Ignore quoted account/authentication strings when finding delegation.
            for ($i = $to + 1; $i + 2 < count($tokens); ++$i) {
                if (self::word($tokens[$i], 'WITH') && (self::word($tokens[$i + 1], 'GRANT') || self::word($tokens[$i + 1], 'ADMIN')) && self::word($tokens[$i + 2], 'OPTION')) $forbidden = true;
            }
            $scope = array_slice($tokens, $on + 1, $to - $on - 1);
            if (count($scope) !== 3 || $scope[1]['value'] !== '.' || $scope[1]['kind'] !== 'symbol') {
                $unknown = true; $scopes['unsupported scope'] = true; continue;
            }
            $global = $scope[0]['kind'] === 'symbol' && $scope[0]['value'] === '*';
            $wholeDatabase = $scope[2]['kind'] === 'symbol' && $scope[2]['value'] === '*';
            if ((!$global && $scope[0]['kind'] === 'symbol') || (!$wholeDatabase && $scope[2]['kind'] === 'symbol')) {
                $unknown = true; continue;
            }
            $privileges = self::privileges(array_slice($tokens, 1, $on - 1));
            if ($privileges === null) { $unknown = true; continue; }
            foreach ($privileges as [$name, $columns]) {
                if (!in_array($name, ['USAGE', 'SELECT'], true)) $forbidden = true;
                if (($columns && ($global || $wholeDatabase)) || ($name === 'USAGE' && $columns)) { $unknown = true; continue; }
                if ($name === 'USAGE') continue;
                $label = $global ? 'global' : ($wholeDatabase ? 'database' : ($columns ? 'column' : 'table'));
                $scopes[$label . ' ' . ($name === 'SELECT' ? 'SELECT' : 'non-SELECT')] = true;
                if ($global && $name === 'SELECT') $globalSelect = true;
                if ($name !== 'SELECT' || $global || !$wholeDatabase || $columns) continue;
                $coverage = self::coverage($scope[0]['value'], $database, $context);
                if ($coverage === null) $unknown = true;
                elseif ($coverage !== false) $matched[$coverage] = true;
            }
        }
        $detected = $scopes ? implode(', ', array_keys($scopes)) : 'USAGE only / no SELECT';
        if ($forbidden) throw new SafeException('Insufficient read-only protection: the source account has privileges beyond SELECT or delegation rights. Detected scopes: ' . $detected . '. Import refused; use a dedicated SELECT-only account.');
        if ($globalSelect) throw new SafeException('Insufficient privilege isolation: global SELECT is unnecessarily broad. Detected scopes: ' . $detected . '. Grant SELECT on the WHMCS database, not globally.');
        if ($unknown) self::unverified($detected);
        if (!$matched) throw new SafeException('Insufficient privileges: no database-wide SELECT grant covers the selected database. Detected scopes: ' . $detected . '. Table/column SELECT alone cannot establish complete source coverage.');

        return [
            'status' => 'verified', 'scope' => 'database-wide SELECT',
            'matched_by' => array_keys($matched), 'detected_scopes' => array_keys($scopes),
            'write_privileges' => 'none detected',
            'summary' => 'Database-wide SELECT verified; no non-SELECT privileges or delegation detected.',
        ];
    }

    private static function unverified(string $scopes): never
    {
        throw new SafeException('Unable to verify privileges: grant syntax, roles/restrictions or database-pattern semantics are inconclusive. Detected scopes: ' . $scopes . '. Access refused; no missing database SELECT grant was inferred.');
    }

    private static function coverage(string $identifier, string $database, array $context): string|false|null
    {
        $pattern = '';
        $escaped = false;
        $wildcards = false;
        for ($i = 0; $i < strlen($identifier); ++$i) {
            $char = $identifier[$i];
            if ($char === '\\') {
                if (!isset($identifier[++$i])) return null;
                $char = $identifier[$i];
                $escaped = true;
                $pattern .= preg_quote($char, '~');
            } else {
                $pattern .= match ($char) { '%' => '.*', '_' => '.', default => preg_quote($char, '~') };
                if ($char === '%' || $char === '_') $wildcards = true;
            }
        }
        $matches = [];
        // partial_revokes makes stored schema privilege names literal, including
        // pre-existing backslashes. Do not strip escapes under that policy.
        foreach (isset($context['partial_revokes']) ? [$context['partial_revokes']] : [false, true] as $literalNames) {
            foreach (isset($context['case_insensitive']) ? [$context['case_insensitive']] : [false, true] as $foldCase) {
                $match = $literalNames
                    ? (int) ($foldCase ? strcasecmp($identifier, $database) === 0 : $identifier === $database)
                    : preg_match('~\\A' . $pattern . '\\z~' . ($foldCase ? 'isD' : 'sD'), $database);
                if ($match === false) return null;
                $matches[] = $match === 1;
            }
        }
        if (!in_array(true, $matches, true)) return false;
        if (in_array(false, $matches, true)) return null;
        // Do not allow an all-database wildcard to bypass the global-SELECT rule.
        if (preg_match('/\\A[%_]+\\z/D', $identifier)) self::unverified('all-database wildcard SELECT');
        if ($identifier === $database || (!empty($context['case_insensitive']) && strcasecmp($identifier, $database) === 0)) return 'literal database identifier';
        if ($escaped && !$wildcards) return 'escaped database identifier';

        return 'database pattern SELECT';
    }

    private static function word(array $token, string $word): bool
    {
        return $token['kind'] === 'word' && strcasecmp($token['value'], $word) === 0;
    }

    private static function keyword(#[\SensitiveParameter] array $tokens, string $keyword, int $start): ?int
    {
        $depth = 0;
        for ($i = $start; $i < count($tokens); ++$i) {
            if ($tokens[$i]['kind'] === 'symbol' && $tokens[$i]['value'] === '(') ++$depth;
            if ($tokens[$i]['kind'] === 'symbol' && $tokens[$i]['value'] === ')') --$depth;
            if ($depth === 0 && self::word($tokens[$i], $keyword)) return $i;
        }

        return null;
    }

    private static function privileges(array $tokens): ?array
    {
        $groups = [[]];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token['kind'] === 'symbol' && $token['value'] === ',' && $depth === 0) { $groups[] = []; continue; }
            if ($token['kind'] === 'symbol' && $token['value'] === '(') ++$depth;
            if ($token['kind'] === 'symbol' && $token['value'] === ')') --$depth;
            if ($depth < 0 || $depth > 1) return null;
            $groups[array_key_last($groups)][] = $token;
        }
        if ($depth !== 0) return null;
        $result = [];
        foreach ($groups as $group) {
            $name = [];
            $i = 0;
            while (isset($group[$i]) && $group[$i]['kind'] === 'word') $name[] = strtoupper($group[$i++]['value']);
            if (!$name) return null;
            $columns = $i < count($group);
            if ($columns) {
                if ($group[$i]['kind'] !== 'symbol' || $group[$i]['value'] !== '(' || end($group)['kind'] !== 'symbol' || end($group)['value'] !== ')' || count($group) - $i < 3 || (count($group) - $i - 2) % 2 !== 1) return null;
                for ($j = $i + 1; $j < count($group) - 1; ++$j) {
                    if (($j - $i) % 2 === 1 ? $group[$j]['kind'] === 'symbol' : $group[$j]['value'] !== ',') return null;
                }
            }
            $result[] = [implode(' ', $name), $columns];
        }

        return $result;
    }

    private static function tokens(#[\SensitiveParameter] string $grant): ?array
    {
        if (strlen($grant) > 65536) return null;
        $tokens = [];
        for ($i = 0; $i < strlen($grant);) {
            $char = $grant[$i];
            if (ctype_space($char)) { ++$i; continue; }
            if (str_contains('`\'"', $char)) {
                $quote = $char;
                $value = '';
                $closed = false;
                for (++$i; $i < strlen($grant); ++$i) {
                    if ($grant[$i] === $quote) {
                        if (isset($grant[$i + 1]) && $grant[$i + 1] === $quote) { $value .= $quote; ++$i; continue; }
                        ++$i; $closed = true; break;
                    }
                    // Preserve identifier wildcard escapes. Account/auth strings
                    // may escape their quote with a backslash; never expose them.
                    if ($quote === "'" && $grant[$i] === '\\' && isset($grant[$i + 1]) && in_array($grant[$i + 1], ["'", '\\'], true)) $value .= $grant[$i++];
                    $value .= $grant[$i];
                }
                if (!$closed) return null;
                $tokens[] = ['kind' => 'quoted', 'value' => $value];
            } elseif (str_contains('.*(),@', $char)) {
                $tokens[] = ['kind' => 'symbol', 'value' => $char];
                ++$i;
            } elseif ($char === ';' && trim(substr($grant, $i + 1)) === '') {
                break;
            } elseif (preg_match('/\\G[A-Za-z0-9_$%\\\\-]+/A', $grant, $match, 0, $i)) {
                $tokens[] = ['kind' => 'word', 'value' => $match[0]];
                $i += strlen($match[0]);
            } else {
                return null;
            }
            if (count($tokens) > 8192) return null;
        }

        return $tokens;
    }
}
