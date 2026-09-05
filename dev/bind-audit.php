<?php
/*
  Flags prepared statements whose bindings and placeholders disagree.

      podman exec wallos-dev php /var/www/html/dev/bind-audit.php
      podman exec wallos-dev php /var/www/html/dev/bind-audit.php --report
      podman exec wallos-dev php /var/www/html/dev/bind-audit.php --update

  Issue #157 (the #147 class). A prepared statement binds a parameter the SQL has
  no placeholder for, or names a :placeholder it never binds. SQLite ignores the
  stray bind and tolerates the missing one; PDO/PostgreSQL, with real prepares
  and ERRMODE_EXCEPTION, makes execute() return false, and the fetchArray(false)
  that follows is fatal — so the endpoint 500s. get_admin_settings.php bound
  :userId to SELECT * FROM "admin"; it works on SQLite and is a live 500 on
  PostgreSQL. Every such mismatch is invisible on one backend and fatal on the
  other, which is exactly the kind of defect a gate has to catch before it ships.

  ## What it flags

    stray     a bindValue(':name', ...) whose ':name' is not a placeholder in the
              statement's SQL — the #147 shape.
    missing   a ':name' placeholder in the SQL that no bind supplies, when every
              bind on that handle is a literal name (so the set is complete).

  ## What it can prove, and where it stops

  The check is only sound when the two sets are fully known, so it draws a line
  it will not cross rather than guess:

    * The SQL must be reconstructable from string literals alone. A query built
      with a concatenated variable, an interpolated "$var", sprintf(), implode()
      or the like has placeholders this cannot see, so a "stray" verdict on it
      could be wrong — those statements are classed dynamic and not flagged for
      stray binds.
    * The binds must all be literal names. A handle bound with ':' . $key, a bare
      $variable, or a positional index has a bind set this cannot enumerate, so a
      "missing" verdict could be wrong — those handles are not flagged for
      missing binds. This is the shape of every IN (...) list and every
      column-map UPDATE in the tree, and it is self-consistent by construction:
      the same loop that writes ":$k" into the SQL binds ':' . $k.

  A handle reused for several queries ($stmt = prepare(A) ... $stmt = prepare(B))
  is segmented by prepare() in token order, and each segment's binds are compared
  against its own SQL. Binds through a helper, a stored handle ($this->stmt), or a
  handle passed to another function are outside a segment and are not seen.

  So a statement this does not flag is not proven balanced; it is either proven
  balanced or proven to be a shape the static reading cannot settle. The dynamic
  shapes are counted, per file, in the report, so the coverage is on the record.

  ## Parsing rather than searching

  token_get_all(), for the reason dev/write-audit.php gives: a text search cannot
  tell $stmt->bindValue(':x') from the same words in a docblock, nor a ':name' in
  live SQL from one inside a quoted string or a PostgreSQL ::cast.

  Baseline: dev/bind-audit-baseline.txt, one line per file with a residual count,
  the same ratchet dev/write-audit.php and dev/db-audit.sh run. A clean tree
  records nothing and the gate then demands zero; a shape that cannot be settled
  and cannot be reshaped is recorded there with a reason rather than alarming on
  every run.

  Exit codes: 0 pass, 1 regression, 2 usage error.
*/

/**
 * Directories the audit does not read.
 *
 * Only the three trees the issue names are scanned. includes/database is the
 * adapter itself, where bindValue() is the implementation, not a call site.
 *
 * @return string[]
 */
function bind_audit_roots()
{
    return ['api', 'endpoints', 'includes'];
}

/**
 * @return string[]
 */
function bind_audit_excluded()
{
    return ['includes/database'];
}

/**
 * Every PHP file the audit reads, root-relative and sorted.
 *
 * @param string $root
 * @return string[]
 */
function bind_audit_files($root)
{
    $excluded = bind_audit_excluded();
    $files = [];

    foreach (bind_audit_roots() as $tree) {
        $base = $root . '/' . $tree;

        if (!is_dir($base)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace($root . '/', '', $file->getPathname());

            foreach ($excluded as $prefix) {
                if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                    continue 2;
                }
            }

            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * The tokens that carry meaning, with whitespace and comments dropped.
 *
 * Comments go first for the same reason write-audit drops them: a bindValue in a
 * docblock is prose, not a call, and a text search would count it.
 *
 * @param string $source
 * @return array<int, array{0: int|string, 1: string, 2: int}>
 */
function bind_audit_tokens($source)
{
    $tokens = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = [$token[0], $token[1], $token[2]];

            continue;
        }

        $line = $tokens === [] ? 0 : $tokens[count($tokens) - 1][2];
        $tokens[] = [$token, $token, $line];
    }

    return $tokens;
}

/**
 * The ':name' placeholders in a piece of SQL.
 *
 * The two things that look like a placeholder and are not are removed first: a
 * quoted string literal (WHERE note = ':x' is data, not a parameter) and a
 * PostgreSQL ::cast (value::text). What is left is a colon that begins a
 * parameter name.
 *
 * @param string $sql
 * @return string[] normalised to include the leading colon, unique
 */
function bind_audit_placeholders($sql)
{
    // Blank out single-quoted SQL string literals, '' escapes included.
    $text = preg_replace("/'(?:[^']|'')*'/", " ", $sql);
    // A cast is two colons; neither is a parameter.
    $text = str_replace('::', '  ', $text);

    if (!preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches)) {
        return [];
    }

    return array_values(array_unique(array_map(function ($name) {
        return ':' . $name;
    }, $matches[1])));
}

/**
 * Reads a value-producing expression from $start until the statement ends,
 * reconstructing the string literals in it and judging whether they are the
 * whole story.
 *
 * static is true only when every part of the expression is a literal string or
 * the concatenation of them. A variable, a function call, an interpolated
 * "$var" or anything else that could contribute unseen SQL sets it false — and
 * then the reconstructed text is a fragment, safe to read placeholders *out of*
 * but never safe to treat as complete.
 *
 * @param array<int, array> $tokens
 * @param int               $start   first token of the expression
 * @param bool              $stopAtComma
 * @return array{text: string, static: bool, end: int}
 */
function bind_audit_expression(array $tokens, $start, $stopAtComma = false)
{
    $count = count($tokens);
    $text = '';
    $static = true;
    $depth = 0;
    $i = $start;

    for (; $i < $count; $i++) {
        $type = $tokens[$i][0];

        if (in_array($type, ['(', '['], true)) {
            $depth++;
            $static = false; // a call or subscript contributing to the value

            continue;
        }

        if (in_array($type, [')', ']'], true)) {
            if ($depth === 0) {
                break; // the ) that closes an enclosing call — end of our argument
            }

            $depth--;

            continue;
        }

        if ($depth === 0) {
            if ($type === ';' || $type === T_CLOSE_TAG) {
                break;
            }

            if ($stopAtComma && $type === ',') {
                break;
            }
        }

        // Only the top-level literals are the SQL. Strings deeper in — implode's
        // glue, a function's arguments — are not, and collecting them would
        // invent placeholders.
        if ($depth === 0 && $type === T_CONSTANT_ENCAPSED_STRING) {
            $text .= bind_audit_unquote($tokens[$i][1]);

            continue;
        }

        // A literal run inside an interpolated "..." string. Real SQL text, but
        // the interpolation around it means the whole is not static.
        if ($type === T_ENCAPSED_AND_WHITESPACE) {
            if ($depth === 0) {
                $text .= $tokens[$i][1];
            }
            $static = false;

            continue;
        }

        if ($type === '"') {
            $static = false; // an interpolated double-quoted string boundary

            continue;
        }

        if ($type === '.' || $type === T_OPEN_TAG) {
            continue; // concatenation joins the pieces; nothing added, nothing lost
        }

        if ($depth === 0) {
            // Anything else at the top level — a variable, a constant, a ternary's
            // ? and :, a function name — means the value is more than its literals.
            // A separating space is written so that two literals sitting on either
            // side of it do not fuse: the ternary "... :userId" : "INSERT ..."
            // would otherwise read as a phantom :userIdINSERT placeholder. A real
            // concatenation ("a" . "b") passes through the branch above and is not
            // separated, so it still fuses exactly as PHP builds it.
            $text .= ' ';
            $static = false;
        }
    }

    return ['text' => $text, 'static' => $static, 'end' => $i];
}

/**
 * The content of a PHP string literal token, quotes stripped and the handful of
 * escapes that matter for reading SQL undone.
 *
 * @param string $literal
 * @return string
 */
function bind_audit_unquote($literal)
{
    if ($literal === '') {
        return '';
    }

    $quote = $literal[0];
    $inner = substr($literal, 1, -1);

    if ($quote === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
    }

    // Double-quoted: \" and \\ are the ones that change what SQL text is present;
    // the rest (\n and friends) do not introduce or hide a colon.
    return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
}

/**
 * Where a bindValue/bindParam names its parameter: a literal ':name', or a shape
 * this cannot read (a variable, a concatenation, a positional integer).
 *
 * @param array<int, array> $tokens
 * @param int               $open   index of the '(' after the method name
 * @return array{dynamic: bool, name: string|null}
 */
function bind_audit_bind_name(array $tokens, $open)
{
    $first = $tokens[$open + 1] ?? null;
    $after = $tokens[$open + 2] ?? null;

    if ($first !== null && $first[0] === T_CONSTANT_ENCAPSED_STRING
        && $after !== null && in_array($after[0], [',', ')'], true)) {
        $name = bind_audit_unquote($first[1]);

        // SQLite accepts ':name', 'name' and '@name'; the adapter normalises to a
        // leading colon, so the comparison does too.
        if ($name !== '' && $name[0] !== ':') {
            $name = ':' . $name;
        }

        return ['dynamic' => false, 'name' => $name];
    }

    return ['dynamic' => true, 'name' => null];
}

/**
 * Walks a file's tokens and gathers, per statement handle, the union of the
 * placeholders its prepare(s) declare and the binds made against it.
 *
 * @param array<int, array> $tokens
 * @return array<string, array>
 */
function bind_audit_statements(array $tokens)
{
    $count = count($tokens);
    $sqlVars = [];
    $prepares = [];
    $binds = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // $sql = ...   and   $sql .= ...
        if ($token[0] === T_VARIABLE) {
            $next = $tokens[$i + 1][0] ?? null;

            // The expression is read for its placeholders, but $i is deliberately
            // not advanced past it: $stmt = $db->prepare($sql) is an assignment
            // whose right-hand side is the prepare() this audit exists to see, and
            // jumping over it would swallow the call. Re-reading the literal string
            // tokens of a SQL assignment on the way past costs nothing and triggers
            // nothing.
            //
            // A variable assigned twice before a prepare reads it is the query
            // written one way in an if and another in the else: the placeholder
            // set is then the union of both, and which one runs is a branch this
            // cannot follow, so the statement is marked multi and its missing-bind
            // check stands down. A fresh assignment *after* a prepare has consumed
            // the variable is a different statement reusing the name, and starts
            // over.
            if ($next === '=') {
                $expr = bind_audit_expression($tokens, $i + 2);
                $ph = bind_audit_placeholders($expr['text']);
                $prev = $sqlVars[$token[1]] ?? null;

                if ($prev === null || $prev['consumed']) {
                    $sqlVars[$token[1]] = ['placeholders' => $ph, 'static' => $expr['static'],
                        'multi' => false, 'consumed' => false];
                } else {
                    $sqlVars[$token[1]] = [
                        'placeholders' => array_values(array_unique(
                            array_merge($prev['placeholders'], $ph))),
                        'static' => $prev['static'] && $expr['static'],
                        'multi' => true,
                        'consumed' => false,
                    ];
                }

                continue;
            }

            if ($next === T_CONCAT_EQUAL) {
                $expr = bind_audit_expression($tokens, $i + 2);
                $ph = bind_audit_placeholders($expr['text']);
                $prev = $sqlVars[$token[1]]
                    ?? ['placeholders' => [], 'static' => true, 'multi' => false, 'consumed' => false];
                $sqlVars[$token[1]] = [
                    'placeholders' => array_values(array_unique(
                        array_merge($prev['placeholders'], $ph))),
                    'static' => $prev['static'] && $expr['static'],
                    'multi' => $prev['multi'],
                    'consumed' => false,
                ];

                continue;
            }
        }

        if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$i + 1] ?? null;

        if ($name === null || $name[0] !== T_STRING) {
            continue;
        }

        $method = strtolower($name[1]);
        $open = $i + 2;

        if (($tokens[$open][0] ?? null) !== '(') {
            continue;
        }

        // $handle = $obj->prepare( <sql> )
        if ($method === 'prepare') {
            $assign = $tokens[$i - 2] ?? null;
            $handle = $tokens[$i - 3] ?? null;

            if ($assign === null || $assign[0] !== '=' || $handle === null
                || $handle[0] !== T_VARIABLE) {
                continue; // a prepare whose handle we cannot name — nothing to pair
            }

            $argFirst = $tokens[$open + 1] ?? null;
            $argSecond = $tokens[$open + 2] ?? null;

            if ($argFirst !== null && $argFirst[0] === T_VARIABLE
                && $argSecond !== null && $argSecond[0] === ')') {
                if (isset($sqlVars[$argFirst[1]])) {
                    $sql = $sqlVars[$argFirst[1]];
                    $placeholders = $sql['placeholders'];
                    $sqlStatic = $sql['static'];
                    $multi = $sql['multi'];
                    // The variable has now given its value to a statement; a later
                    // assignment to it is a new query, not another branch of this one.
                    $sqlVars[$argFirst[1]]['consumed'] = true;
                } else {
                    // A prepare from a variable never seen assigned — SQL built
                    // somewhere this did not read. Unknown, so never flagged.
                    $placeholders = [];
                    $sqlStatic = false;
                    $multi = false;
                }
            } else {
                $expr = bind_audit_expression($tokens, $open + 1, true);
                $placeholders = bind_audit_placeholders($expr['text']);
                $sqlStatic = $expr['static'];
                $multi = false;
            }

            $prepares[] = [
                'index' => $i,
                'handle' => $handle[1],
                'line' => $name[2],
                'sqlStatic' => $sqlStatic,
                'multi' => $multi,
                'placeholders' => $placeholders,
            ];

            continue;
        }

        if ($method === 'bindvalue' || $method === 'bindparam') {
            $receiver = $tokens[$i - 1] ?? null;

            if ($receiver === null || $receiver[0] !== T_VARIABLE) {
                continue; // $this->stmt and other non-simple handles are not paired
            }

            $bind = bind_audit_bind_name($tokens, $open);
            $binds[] = [
                'index' => $i,
                'handle' => $receiver[1],
                'line' => $name[2],
                'dynamic' => $bind['dynamic'],
                'name' => $bind['name'],
            ];
        }
    }

    // Aggregate by handle rather than by individual prepare. A handle prepared
    // more than once in a file is either a query written two ways across an
    // if/else — where the binds after it must satisfy whichever branch ran — or
    // the same name reused for unrelated statements. Neither can be segmented
    // soundly without following the branches, so the placeholders of every
    // prepare on a handle are unioned, and a bind is stray only when it matches
    // none of them. The cost is that a stray bind which happens to name another
    // query's placeholder in the same file is not caught; the gain is that no
    // correct branch is ever reported wrong. The missing-bind check, which needs
    // the exact set, is confined to handles prepared exactly once.
    $handles = [];

    foreach ($prepares as $prepare) {
        $handle = $prepare['handle'];

        if (!isset($handles[$handle])) {
            $handles[$handle] = [
                'placeholders' => [],
                'static' => true,
                'multi' => false,
                'prepareCount' => 0,
                'binds' => [],
                'hasDynamicBind' => false,
                'line' => $prepare['line'],
                'firstIndex' => $prepare['index'],
            ];
        }

        $handles[$handle]['placeholders'] = array_values(array_unique(
            array_merge($handles[$handle]['placeholders'], $prepare['placeholders'])));
        $handles[$handle]['static'] = $handles[$handle]['static'] && $prepare['sqlStatic'];
        $handles[$handle]['multi'] = $handles[$handle]['multi'] || $prepare['multi'];
        $handles[$handle]['prepareCount']++;
    }

    foreach ($binds as $bind) {
        $handle = $bind['handle'];

        if (!isset($handles[$handle]) || $bind['index'] < $handles[$handle]['firstIndex']) {
            continue; // a bind with no prepare of that handle before it — not ours
        }

        if ($bind['dynamic']) {
            $handles[$handle]['hasDynamicBind'] = true;
        } else {
            $handles[$handle]['binds'][$bind['name']] = $bind['line'];
        }
    }

    return $handles;
}

/**
 * The mismatches in one file.
 *
 * @param string $source
 * @return array{findings: array<int, array>, dynamicSql: int, dynamicBinds: int, statements: int}
 */
function bind_audit_scan($source)
{
    $tokens = bind_audit_tokens($source);
    $handles = bind_audit_statements($tokens);

    $findings = [];
    $dynamicSql = 0;
    $dynamicBinds = 0;

    foreach ($handles as $handle) {
        $placeholders = $handle['placeholders'];
        $boundNames = $handle['binds'];

        // stray: a literal bind matching none of the handle's placeholders. Sound
        // only when every prepare on the handle has fully known SQL — otherwise
        // the placeholder might live in a part this could not read.
        if ($handle['static']) {
            foreach ($boundNames as $bound => $line) {
                if (!in_array($bound, $placeholders, true)) {
                    $findings[] = [
                        'kind' => 'stray',
                        'line' => $line,
                        'name' => $bound,
                        'placeholders' => $placeholders,
                    ];
                }
            }
        } else {
            $dynamicSql++;
        }

        // missing: a declared placeholder no bind supplies. The exact set has to
        // be known for this, so it is confined to a handle prepared exactly once,
        // from a single-branch static query, bound only by literal names. A
        // branch-dependent query (multi) or a dynamic bind could supply the
        // placeholder in a way this cannot see, and a handle bound nowhere at all
        // is far more likely bound through a helper than genuinely forgotten.
        if ($handle['static'] && $handle['prepareCount'] === 1 && !$handle['multi']
            && !$handle['hasDynamicBind'] && $boundNames !== []) {
            foreach ($placeholders as $placeholder) {
                if (!isset($boundNames[$placeholder])) {
                    $findings[] = [
                        'kind' => 'missing',
                        'line' => $handle['line'],
                        'name' => $placeholder,
                        'bound' => array_keys($boundNames),
                    ];
                }
            }
        }

        if ($handle['hasDynamicBind']) {
            $dynamicBinds++;
        }
    }

    return [
        'findings' => $findings,
        'dynamicSql' => $dynamicSql,
        'dynamicBinds' => $dynamicBinds,
        'statements' => count($handles),
    ];
}

/**
 * @param string $root
 * @return array<string, array>
 */
function bind_audit_measure($root)
{
    $measured = [];

    foreach (bind_audit_files($root) as $path) {
        $source = file_get_contents($root . '/' . $path);

        if ($source === false) {
            continue;
        }

        $scan = bind_audit_scan($source);

        if ($scan['findings'] !== []) {
            $measured[$path] = $scan;
        }
    }

    return $measured;
}

/**
 * @param array<string, array> $measured
 * @return string
 */
function bind_audit_render($measured)
{
    $total = 0;

    foreach ($measured as $scan) {
        $total += count($scan['findings']);
    }

    $out = "# Prepared-statement bind/placeholder mismatches — generated by\n"
        . "# dev/bind-audit.php --update. One line per file: <path><TAB><mismatch count>.\n#\n"
        . "# Issue #157 (the #147 class). Every entry is a statement whose binds and\n"
        . "# placeholders disagree — a live PostgreSQL 500 that SQLite hides. The\n"
        . "# intended state is empty: the real bugs are fixed, not recorded. A line\n"
        . "# here is a shape the static reader cannot settle and cannot reshape, and\n"
        . "# it carries a reason. No count may rise and no file may join without one.\n#\n"
        . "# Do not edit by hand — run dev/bind-audit.php --update and commit the diff.\n#\n"
        . '# ' . count($measured) . ' file(s), ' . $total . " mismatch(es).\n\n";

    foreach ($measured as $path => $scan) {
        $out .= $path . "\t" . count($scan['findings']) . "\n";
    }

    return $out;
}

/**
 * @param string $path
 * @return array<string, int>
 */
function bind_audit_read_baseline($path)
{
    $baseline = [];

    if (!is_file($path)) {
        return $baseline;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $parts = explode("\t", $line);

        if (count($parts) !== 2) {
            continue;
        }

        $baseline[$parts[0]] = (int) $parts[1];
    }

    return $baseline;
}

/**
 * @param array<string, array> $measured
 * @param array<string, int>   $baseline
 * @return array{regressions: string[], improvements: string[]}
 */
function bind_audit_compare($measured, $baseline)
{
    $regressions = [];
    $improvements = [];

    foreach ($measured as $path => $scan) {
        $now = count($scan['findings']);

        if (!isset($baseline[$path])) {
            $regressions[] = sprintf('%s is not in the baseline (%d mismatch(es))', $path, $now);

            continue;
        }

        if ($now > $baseline[$path]) {
            $regressions[] = sprintf('%s: %d -> %d mismatch(es)', $path, $baseline[$path], $now);
        } elseif ($now < $baseline[$path]) {
            $improvements[] = sprintf('%s: %d -> %d mismatch(es)', $path, $baseline[$path], $now);
        }
    }

    foreach ($baseline as $path => $was) {
        if (!isset($measured[$path])) {
            $improvements[] = $path . ': cleared';
        }
    }

    return ['regressions' => $regressions, 'improvements' => $improvements];
}

// Everything above is callable from a test; everything below runs only as the
// program. The split dev/write-audit.php and dev/generate-pgsql-schema.php use.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    return;
}

$root = dirname(__DIR__);
$baselinePath = __DIR__ . '/bind-audit-baseline.txt';
$mode = 'check';

foreach (array_slice($argv, 1) as $argument) {
    switch ($argument) {
        case '--check':
            $mode = 'check';
            break;
        case '--report':
            $mode = 'report';
            break;
        case '--update':
            $mode = 'update';
            break;
        case '-h':
        case '--help':
            $source = file(__FILE__);
            foreach (array_slice($source, 1) as $line) {
                if (strpos($line, '*/') !== false) {
                    break;
                }
                echo preg_replace('/^\s*(\*\/?|\/\*)\s?/', '', $line);
            }
            exit(0);
        default:
            fwrite(STDERR, 'bind-audit: unknown argument: ' . $argument . " (try --help)\n");
            exit(2);
    }
}

$measured = bind_audit_measure($root);

if ($mode === 'report') {
    $files = 0;
    $mismatches = 0;
    $dynamicSql = 0;
    $dynamicBinds = 0;
    $statements = 0;

    foreach (bind_audit_files($root) as $path) {
        $source = file_get_contents($root . '/' . $path);

        if ($source === false) {
            continue;
        }

        $scan = bind_audit_scan($source);
        $statements += $scan['statements'];
        $dynamicSql += $scan['dynamicSql'];
        $dynamicBinds += $scan['dynamicBinds'];

        if ($scan['findings'] === []) {
            continue;
        }

        $files++;
        $mismatches += count($scan['findings']);

        echo $path, "\n";

        foreach ($scan['findings'] as $finding) {
            if ($finding['kind'] === 'stray') {
                printf("  line %d  STRAY    binds %s — placeholders: %s\n",
                    $finding['line'], $finding['name'],
                    $finding['placeholders'] === [] ? '(none)' : implode(' ', $finding['placeholders']));
            } else {
                printf("  line %d  MISSING  placeholder %s never bound — bound: %s\n",
                    $finding['line'], $finding['name'],
                    $finding['bound'] === [] ? '(none)' : implode(' ', $finding['bound']));
            }
        }
    }

    echo "\n";
    printf("%d prepared-statement handle(s) read across api/ endpoints/ includes/.\n", $statements);
    printf("Not statically settleable (counted, not flagged): %d with dynamic SQL, "
        . "%d with dynamic binds.\n", $dynamicSql, $dynamicBinds);
    printf("%d mismatch(es) in %d file(s).\n", $mismatches, $files);

    exit(0);
}

if ($mode === 'update') {
    file_put_contents($baselinePath, bind_audit_render($measured));
    $total = 0;
    foreach ($measured as $scan) {
        $total += count($scan['findings']);
    }
    echo 'baseline updated: ', $baselinePath, ' — ', count($measured), ' file(s), ',
        $total, " mismatch(es).\n";

    exit(0);
}

$baseline = bind_audit_read_baseline($baselinePath);
$comparison = bind_audit_compare($measured, $baseline);

foreach ($comparison['improvements'] as $line) {
    echo '  improved  ', $line, "\n";
}

$total = 0;
foreach ($measured as $scan) {
    $total += count($scan['findings']);
}
$summary = sprintf('%d mismatch(es) in %d file(s)', $total, count($measured));

if ($comparison['regressions'] === []) {
    echo 'bind-audit: ok — ', $summary, "\n";

    if ($comparison['improvements'] !== []) {
        echo "Run dev/bind-audit.php --update to record the improvement.\n";
    }

    exit(0);
}

foreach ($comparison['regressions'] as $line) {
    fwrite(STDERR, '  REGRESSION  ' . $line . "\n");
}

fwrite(STDERR, "\nA prepared statement whose binds and placeholders disagree works on SQLite\n"
    . "and is a fatal 500 on PostgreSQL: PDO rejects the stray or missing parameter,\n"
    . "execute() returns false, and the fetchArray(false) after it dies. That is\n"
    . "issue #157, the class #147 was one instance of.\n\n"
    . "  * A stray bind: remove the bindValue for the parameter the SQL does not name.\n"
    . "  * A missing bind: add the bindValue for the :placeholder the SQL declares.\n"
    . "  * dev/bind-audit.php --report shows the placeholders and the binds side by side.\n\n"
    . "Recording this in the baseline to make it pass keeps the 500 in production. Do\n"
    . "it only for a shape the static reader cannot settle, with a reason in the PR.\n");

exit(1);
