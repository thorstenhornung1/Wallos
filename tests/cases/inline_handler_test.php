<?php
/*
  Inline event handlers, and the two ways they stop working silently.

  Every element carrying an id is also exposed as a property of `window` under
  that name. When a handler function has the same name as an element's id, the
  identifier in `onClick="x()"` can resolve to the element rather than to the
  function, and the button does nothing at all — no request, no message, no
  error anywhere the operator would look. That is what issue #95 was: the OIDC
  settings could not be saved through the interface, and the Safari console
  said `saveOidcSettingsButton is not a function ... is an instance of
  HTMLInputElement`.

  Which one wins in a given engine depends on details nothing should rest on.
  A name used for both is a defect whichever way it resolves, and the cases
  below refuse the collision rather than the symptom.

  Eighteen elements carried it, not the seven the issue names — profile.php's
  two-factor buttons and settings.php's budget and notification buttons have the
  same shape. They were found by comparing the two sets rather than by reading
  the pages, which is also why this is a gate and not a fixed list: the next one
  is caught the day it is written.

  The second case is the other half. Renaming across nine files is exactly the
  kind of change that leaves one call site behind, and a handler that names a
  function nobody declares fails the same silent way. Both directions are
  cheaper to assert than to notice.
*/

/**
 * Every id="…" in the rendered markup, and every function name an inline
 * handler calls, keyed by name.
 *
 * @return array{ids: array<string, string[]>, calls: array<string, string[]>}
 */
function inline_handler_index()
{
    static $index = null;

    if ($index !== null) {
        return $index;
    }

    $index = ['ids' => [], 'calls' => []];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WALLOS_ROOT, RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = str_replace(WALLOS_ROOT . '/', '', $file->getPathname());

        if ($file->getExtension() !== 'php' || preg_match('#^(libs|tests|dev|\.)#', $path) === 1) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        // Escaped quotes as well: some of this markup is built inside PHP
        // strings, and an id written as \"x\" clobbers exactly as well.
        if (preg_match_all('/\bid\s*=\s*\\\\?["\']([A-Za-z_][\w-]*)\\\\?["\']/', $source, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $index['ids'][$id][] = $path;
            }
        }

        if (preg_match_all('/\bon[A-Za-z]+\s*=\s*\\\\?["\']\s*([A-Za-z_]\w*)\s*\(/', $source, $matches) > 0) {
            foreach ($matches[1] as $call) {
                $index['calls'][$call][] = $path;
            }
        }
    }

    return $index;
}

/**
 * Everything scripts/ declares as a top-level function.
 *
 * @return string[]
 */
function inline_handler_declarations()
{
    static $declared = null;

    if ($declared !== null) {
        return $declared;
    }

    $source = '';

    foreach (glob(WALLOS_ROOT . '/scripts/*.js') as $path) {
        $source .= file_get_contents($path) . "\n";
    }

    preg_match_all('/\bfunction\s+([A-Za-z_]\w*)\s*\(/', $source, $matches);
    $declared = array_unique($matches[1]);

    return $declared;
}

wallos_test('no element id shadows the handler of the same name', function () {
    $index = inline_handler_index();
    $clashes = array_intersect(array_keys($index['ids']), array_keys($index['calls']));

    foreach ($clashes as $name) {
        wallos_test_fail(sprintf('id="%s" carries the name of the handler %s() called from %s',
            $name, $name, implode(', ', array_unique($index['calls'][$name]))));
    }

    assert_same([], array_values($clashes), 'no id and inline handler share a name');

    // The negative control: an index that found nothing would pass the line
    // above, and this gate would be decoration from the day it was written.
    assert_true(count($index['ids']) > 100, 'the markup was actually read');
    assert_true(count($index['calls']) > 20, 'and so were its inline handlers');
});

wallos_test('every inline handler names a function that exists', function () {
    $index = inline_handler_index();
    $declared = inline_handler_declarations();

    // The handlers the pages define next to the markup instead of in scripts/.
    // Listed rather than skipped, so that a name added here is a decision.
    $inPage = ['toggleMenu'];

    foreach (array_keys($index['calls']) as $call) {
        if (in_array($call, $inPage, true)) {
            continue;
        }

        assert_true(in_array($call, $declared, true),
            $call . '() is called from ' . implode(', ', array_unique($index['calls'][$call]))
            . ' and declared in scripts/');
    }
});
