<?php
/**
 * Ownership checks for the ids a write accepts from a request.
 *
 * A subscription points at four rows the account owns — a currency, a category,
 * a payment method and a household member — plus a billing cycle from the
 * shared cycles table, and it repeats that cycle every `frequency` times. Two
 * write paths accept those six values from a request: the form endpoint
 * endpoints/subscription/add.php and the REST endpoint
 * api/subscriptions/set_subscriptions.php.
 *
 * The two disagreed about what a valid subscription is (issue #82). The REST
 * endpoint checked four of the ids for existence and ownership and forgot the
 * frequency entirely; the form endpoint checked nothing at all and bound $_POST
 * straight into the insert. Both call this file now, so the answer is the same
 * whichever way the row is written.
 *
 * Ownership is the half that matters beyond the foreign key itself: an id that
 * exists but belongs to another account satisfies any foreign key and still
 * crosses the account boundary. It also defeats the guarded deletes elsewhere —
 * endpoints/categories/category.php and its siblings count referencing
 * subscriptions for the owner only, so a category referenced by a stranger's
 * subscription looks unused and can be deleted out from under them.
 *
 * The queries here read one value through the boundary's scalar() rather than
 * spelling out prepare, bind with a SQLite type constant, execute and fetch.
 * This file is new, and dev/db-audit.sh exists precisely so new code stops
 * adding to the SQLite leakage it is ratcheting down.
 */

/**
 * The largest frequency a subscription may carry.
 *
 * subscriptions.php offers 1..366 in the form and includes/getdbkeys.php builds
 * the same range in PHP, so that is what "valid" means here. It is deliberately
 * not the frequencies table: that table holds 1..31, no code reads it, and the
 * PostgreSQL baseline drops the foreign key on purpose (see
 * wallos_pgsql_schema_suppressed_foreign_keys() in dev/generate-pgsql-schema.php)
 * because frequency is a multiplier that getPricePerMonth() divides by, not a
 * reference to a row.
 *
 * @return int
 */
function wallos_subscription_frequency_max()
{
    return 366;
}

/**
 * The parent tables behind the subscription columns that reference an account's
 * own rows, keyed by the request parameter that carries them.
 *
 * `required` marks the value the form and the API both always send; the other
 * three are legitimately empty when an account has no categories, no payment
 * methods or no household members yet.
 *
 * `shared` marks payment methods, the one table whose rows can belong to nobody:
 * older installations carry system rows with user_id 0 or NULL, and the REST
 * endpoint has always accepted those. Dropping that would break accounts that
 * still reference them.
 *
 * The table names come from this list and never from a request, which is what
 * makes it safe to interpolate them into the SQL below — no placeholder can
 * stand in for a table name.
 *
 * @return array<string, array>
 */
function wallos_subscription_reference_fields()
{
    return [
        'currency_id' => [
            'table' => 'currencies',
            'required' => true,
            'shared' => false,
            'title' => 'Invalid currency ID',
            'message' => 'The specified currency does not exist or does not belong to you.',
            'numeric_message' => 'Parameter "currency_id" must be the numeric id of one of your currencies.',
            'missing_message' => 'Parameter "currency_id" is required.',
            'translation' => 'invalid_currency',
        ],
        'category_id' => [
            'table' => 'categories',
            'required' => false,
            'shared' => false,
            'title' => 'Invalid category ID',
            'message' => 'The specified category does not exist or does not belong to you.',
            'numeric_message' => 'Parameter "category_id" must be the numeric id of one of your categories.',
            'missing_message' => 'Parameter "category_id" is required.',
            'translation' => 'invalid_category',
        ],
        'payer_user_id' => [
            'table' => 'household',
            'required' => false,
            'shared' => false,
            'title' => 'Invalid payer ID',
            'message' => 'The specified household member does not exist or does not belong to you.',
            'numeric_message' => 'Parameter "payer_user_id" must be the numeric id of one of your household members.',
            'missing_message' => 'Parameter "payer_user_id" is required.',
            'translation' => 'invalid_payer',
        ],
        'payment_method_id' => [
            'table' => 'payment_methods',
            'required' => false,
            'shared' => true,
            'title' => 'Invalid payment method ID',
            'message' => 'The specified payment method does not exist or does not belong to you.',
            'numeric_message' => 'Parameter "payment_method_id" must be the numeric id of one of your payment methods.',
            'missing_message' => 'Parameter "payment_method_id" is required.',
            'translation' => 'invalid_payment_method',
        ],
    ];
}

/**
 * Whether a request value is a whole number.
 *
 * intval() is not a validator: it turns "abc" into 0 and "3 monkeys" into 3.
 * A 0 reaches the database as an id no parent table has, which PostgreSQL
 * rejects with a foreign-key error naming a constraint instead of the field the
 * user got wrong, and which SQLite happily stores.
 *
 * @param mixed $value
 * @return bool
 */
function wallos_is_integer_input($value)
{
    if (is_int($value)) {
        return true;
    }

    if (!is_string($value)) {
        return false;
    }

    return preg_match('/^-?[0-9]+$/', trim($value)) === 1;
}

/**
 * Whether $id names a row of $table that $userId owns.
 *
 * @param WallosDatabase $db
 * @param string         $table  A table name from wallos_subscription_reference_fields(),
 *                               or a literal in the caller — never a request value.
 * @param int            $id
 * @param int            $userId
 * @param bool           $shared Whether rows with no owner count as the caller's.
 * @return bool
 */
function wallos_reference_is_owned($db, $table, $id, $userId, $shared = false)
{
    $ownership = $shared
        ? '(user_id = :userId OR user_id = 0 OR user_id IS NULL)'
        : 'user_id = :userId';

    $found = $db->scalar(
        'SELECT 1 FROM ' . $table . ' WHERE id = :id AND ' . $ownership . ' LIMIT 1',
        [':id' => $id, ':userId' => $userId]
    );

    return $found !== null;
}

/**
 * A rejection both write paths can render in their own response shape.
 *
 * `title` and `message` are the REST endpoint's wording, kept verbatim so its
 * clients see no change; `translation` is an i18n key for the form endpoint,
 * which speaks the user's language. `field` names the parameter that was wrong,
 * because "error" tells nobody what to fix.
 *
 * @param string $field
 * @param string $title
 * @param string $message
 * @param string $translation
 * @return array
 */
function wallos_subscription_input_error($field, $title, $message, $translation)
{
    return [
        'valid' => false,
        'field' => $field,
        'title' => $title,
        'message' => $message,
        'translation' => $translation,
        'values' => [],
    ];
}

/**
 * Reads one request value, treating "absent" and "empty" as the same thing.
 *
 * A <select> with no options submits nothing at all, and a JSON client sends ""
 * for "leave it unset"; both mean the same to every field here.
 *
 * @param array  $input
 * @param string $key
 * @return mixed|null
 */
function wallos_subscription_input_value(array $input, $key)
{
    if (!isset($input[$key])) {
        return null;
    }

    if (is_string($input[$key]) && trim($input[$key]) === '') {
        return null;
    }

    return $input[$key];
}

/**
 * Validates the six values a subscription write takes from a request.
 *
 * @param WallosDatabase $db
 * @param int            $userId The caller. Every id must belong to them.
 * @param array          $input  Request values keyed as the columns are named,
 *                               already merged with the stored row when editing.
 * @return array{valid: bool, field?: string, title?: string, message?: string,
 *               translation?: string, values: array<string, int|null>}
 *               On success, `values` holds the normalised integers to bind.
 */
function wallos_validate_subscription_input($db, $userId, array $input)
{
    $values = [];

    // The cycle is a genuine foreign key into a table every installation seeds,
    // so the table decides what exists rather than a list written in code. The
    // REST endpoint used to hardcode 1..4 and by doing so rejected the
    // "One-time" cycle that migration 000046 added and that the form has
    // offered ever since.
    $cycle = wallos_subscription_input_value($input, 'cycle');

    if ($cycle === null) {
        return wallos_subscription_input_error(
            'cycle',
            'Missing parameter',
            'Parameter "cycle" is required.',
            'invalid_cycle'
        );
    }

    if (!wallos_is_integer_input($cycle)) {
        return wallos_subscription_input_error(
            'cycle',
            'Invalid cycle',
            'Parameter "cycle" must be the numeric id of a billing cycle.',
            'invalid_cycle'
        );
    }

    $cycle = (int) $cycle;

    if ($db->scalar('SELECT 1 FROM cycles WHERE id = :id LIMIT 1', [':id' => $cycle]) === null) {
        return wallos_subscription_input_error(
            'cycle',
            'Invalid cycle',
            'Parameter "cycle" must be the id of an existing billing cycle.',
            'invalid_cycle'
        );
    }

    $values['cycle'] = $cycle;

    // The frequency is a multiplier on that cycle, not a reference, so it is
    // checked as a range: the one the form offers, which is the only range a
    // legitimate request can contain.
    $frequency = wallos_subscription_input_value($input, 'frequency');
    $maximum = wallos_subscription_frequency_max();

    if ($frequency === null) {
        return wallos_subscription_input_error(
            'frequency',
            'Missing parameter',
            'Parameter "frequency" is required.',
            'invalid_frequency'
        );
    }

    if (!wallos_is_integer_input($frequency)
        || (int) $frequency < 1
        || (int) $frequency > $maximum) {
        return wallos_subscription_input_error(
            'frequency',
            'Invalid frequency',
            'Parameter "frequency" must be a whole number between 1 and ' . $maximum . '.',
            'invalid_frequency'
        );
    }

    $values['frequency'] = (int) $frequency;

    foreach (wallos_subscription_reference_fields() as $field => $rules) {
        $value = wallos_subscription_input_value($input, $field);

        if ($value === null) {
            if ($rules['required']) {
                return wallos_subscription_input_error(
                    $field,
                    'Missing parameter',
                    $rules['missing_message'],
                    $rules['translation']
                );
            }

            // Nothing selected is a legitimate answer for the optional three,
            // and NULL is what the column is for.
            $values[$field] = null;
            continue;
        }

        if (!wallos_is_integer_input($value)) {
            return wallos_subscription_input_error(
                $field,
                $rules['title'],
                $rules['numeric_message'],
                $rules['translation']
            );
        }

        $value = (int) $value;

        if (!wallos_reference_is_owned($db, $rules['table'], $value, $userId, $rules['shared'])) {
            return wallos_subscription_input_error(
                $field,
                $rules['title'],
                $rules['message'],
                $rules['translation']
            );
        }

        $values[$field] = $value;
    }

    return ['valid' => true, 'values' => $values];
}
