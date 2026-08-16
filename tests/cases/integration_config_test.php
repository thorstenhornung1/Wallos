<?php
/*
  Effective configuration for the shared integrations: resolution order,
  per-user override modes, validation and failure semantics.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

/**
 * @param SQLite3 $db
 */
function integration_fixture($db)
{
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    $stmt = $db->prepare("UPDATE admin SET smtp_address = 'smtp.db.example', smtp_port = 2525,
                          smtp_username = 'dbuser', smtp_password = 'dbpassword',
                          from_email = 'db@example.com', encryption = 'ssl' WHERE id = 1");
    $stmt->execute();

    // Alice keeps her own transport, bob inherits the instance one.
    $stmt = $db->prepare("INSERT INTO email_notifications
        (enabled, smtp_mode, smtp_address, smtp_port, smtp_username, smtp_password, from_email, other_emails, encryption, user_id)
        VALUES (1, 'custom', 'smtp.user.example', 1025, 'olduser', 'oldpassword', 'alice@example.com', 'team@example.com', 'tls', 1)");
    $stmt->execute();

    $stmt = $db->prepare("INSERT INTO email_notifications (enabled, smtp_mode, other_emails, user_id)
                          VALUES (1, 'instance', '', 2)");
    $stmt->execute();
}

wallos_test('instance SMTP falls back to the admin table', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    $config = wallos_get_instance_smtp_config($db);

    assert_same('smtp.db.example', $config['values']['host'], 'host comes from the database');
    assert_same(2525, (int) $config['values']['port'], 'port comes from the database');
    assert_same('admin', $config['source']['host'], 'the source is reported as admin');
    assert_true(empty($config['managed']['host']), 'a database value is not environment managed');
    assert_true($config['valid'], 'the configuration is usable');

    $db->close();
});

wallos_test('the environment overrides the database per field', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    putenv('WALLOS_SMTP_HOST=smtp.env.example');
    putenv('WALLOS_SMTP_PASSWORD=env-password');

    $config = wallos_get_instance_smtp_config($db);

    assert_same('smtp.env.example', $config['values']['host'], 'the environment wins');
    assert_same('environment', $config['source']['host'], 'the source is reported');
    assert_same('WALLOS_SMTP_HOST', $config['managed_by']['host'], 'the managing variable is reported');
    assert_same('dbuser', $config['values']['username'], 'unmanaged fields still come from the database');
    assert_same('admin', $config['source']['username'], 'their source is still admin');

    $db->close();
});

wallos_test('an unreadable secret file invalidates instead of falling back', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    putenv('WALLOS_SMTP_PASSWORD_FILE=/nonexistent/smtp_password');
    $config = wallos_get_instance_smtp_config($db);

    assert_true(!$config['valid'], 'the configuration is invalid');
    assert_same('', $config['values']['password'], 'the stale database secret is not used');
    assert_contains('not readable', implode(' ', $config['notes']), 'the reason is reported');

    $db->close();
});

wallos_test('a custom user keeps their own transport, an instance user inherits', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    $alice = wallos_get_effective_smtp_config($db, 1);
    assert_same('custom', $alice['mode'], 'alice runs a custom transport');
    assert_same('smtp.user.example', $alice['values']['host'], 'her host is used');
    assert_same('team@example.com', $alice['values']['other_emails'], 'her recipients are kept');

    $bob = wallos_get_effective_smtp_config($db, 2);
    assert_same('instance', $bob['mode'], 'bob inherits');
    assert_same('smtp.db.example', $bob['values']['host'], 'the instance host is used');
    assert_same(1, (int) $bob['values']['enabled'], 'his own enable flag is preserved');

    $db->close();
});

wallos_test('system email always uses the instance transport', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    $config = wallos_get_effective_smtp_config($db);
    assert_same('instance', $config['mode'], 'no user means the instance transport');
    assert_same('smtp.db.example', $config['values']['host'], 'the instance host is used');

    $db->close();
});

wallos_test('SMTP validation rejects impossible values', function () {
    $config = wallos_smtp_config_from_input([
        'smtpaddress' => 'smtp.form.example',
        'smtpport' => '70000',
        'encryption' => 'rot13',
    ]);

    assert_true(!$config['valid'], 'an out-of-range port is invalid');
    assert_same('tls', $config['values']['encryption'], 'an unsupported encryption falls back to tls');

    $config = wallos_smtp_config_from_input(['smtpaddress' => '', 'smtpport' => '587']);
    assert_true(!$config['valid'], 'a missing host is invalid');
});

wallos_test('currency credentials follow the user mode', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    $stmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id) VALUES ('user-key', 1, 'custom', 1)");
    $stmt->execute();

    assert_same('user-key', wallos_get_effective_currency_config($db, 1)['values']['api_key'], 'a custom key is used');

    $bob = wallos_get_effective_currency_config($db, 2);
    assert_same('instance', $bob['mode'], 'a user without a row inherits');
    assert_true(!$bob['valid'], 'inheriting nothing is reported as unconfigured');

    wallos_set_instance_setting($db, 'currency', 'provider', 'apilayer');
    wallos_set_instance_setting($db, 'currency', 'api_key', 'instance-key', true);

    $bob = wallos_get_effective_currency_config($db, 2);
    assert_true($bob['valid'], 'the instance credential makes it usable');
    assert_same(1, (int) $bob['values']['provider'], 'the provider name maps onto the stored id');
    assert_same('instance-key', $bob['values']['api_key'], 'the instance key is used');

    $db->close();
});

wallos_test('an invalid provider name is a configuration error', function () {
    $db = wallos_test_open_database();

    putenv('WALLOS_CURRENCY_PROVIDER=nonsense');
    assert_true(!wallos_get_instance_currency_config($db)['valid'], 'the configuration is invalid');

    // Configuration is resolved once per request; changing it starts a new one.
    wallos_reset_config_cache($db);
    putenv('WALLOS_CURRENCY_PROVIDER=fixer');
    putenv('WALLOS_CURRENCY_API_KEY=k');
    assert_same(0, (int) wallos_get_instance_currency_config($db)['values']['provider'], 'fixer maps to 0');

    wallos_reset_config_cache($db);
    putenv('WALLOS_CURRENCY_PROVIDER=apilayer');
    assert_same(1, (int) wallos_get_instance_currency_config($db)['values']['provider'], 'apilayer maps to 1');

    $db->close();
});

wallos_test('AI infrastructure is inherited while preferences stay with the user', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    $stmt = $db->prepare("INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule, provider_mode)
                          VALUES (2, '', 1, '', '', '', 'weekly', 'instance')");
    $stmt->execute();

    putenv('WALLOS_AI_PROVIDER=openai-compatible');
    putenv('WALLOS_AI_BASE_URL=http://llm.example/v1');
    putenv('WALLOS_AI_MODEL=instance-model');
    putenv('WALLOS_AI_API_KEY_FILE=' . wallos_test_secret_file('ai_key', "instance-ai-key\r\n"));

    $config = wallos_get_effective_ai_config($db, 2);

    assert_true($config['valid'], 'the inherited configuration is usable');
    assert_same('openai-compatible', $config['values']['type'], 'the provider is inherited');
    assert_same('instance-ai-key', $config['values']['api_key'], 'the secret file is read');
    assert_same('weekly', $config['values']['run_schedule'], 'the schedule stays with the user');
    assert_same(1, (int) $config['values']['enabled'], 'the enable flag stays with the user');

    $stmt = $db->prepare("UPDATE ai_settings SET model = 'user-model' WHERE user_id = 2");
    $stmt->execute();
    wallos_reset_config_cache($db);

    $config = wallos_get_effective_ai_config($db, 2);
    assert_same('user-model', $config['values']['model'], 'the user may override the model');
    assert_same('user', $config['source']['model'], 'the override is attributed to the user');
    assert_same('openai-compatible', $config['values']['type'], 'the provider is still inherited');

    $db->close();
});

wallos_test('ollama never carries an API key', function () {
    $db = wallos_test_open_database();

    putenv('WALLOS_AI_PROVIDER=ollama');
    putenv('WALLOS_AI_BASE_URL=http://ollama.example:11434');
    putenv('WALLOS_AI_MODEL=llama3');
    putenv('WALLOS_AI_API_KEY=should-be-dropped');

    $config = wallos_get_instance_ai_config($db);
    assert_same('', $config['values']['api_key'], 'the key is dropped');
    assert_true($config['valid'], 'ollama is usable without a key');

    $db->close();
});

wallos_test('a hosted AI provider without a key is invalid', function () {
    $db = wallos_test_open_database();

    putenv('WALLOS_AI_PROVIDER=chatgpt');
    putenv('WALLOS_AI_MODEL=gpt-4o-mini');

    assert_true(!wallos_get_instance_ai_config($db)['valid'], 'a missing key is reported');

    $db->close();
});

wallos_test('the API payload exposes status, never secrets', function () {
    $db = wallos_test_open_database();
    integration_fixture($db);

    putenv('WALLOS_SMTP_HOST=smtp.env.example');
    putenv('WALLOS_SMTP_USERNAME=infrastructure@example.com');
    putenv('WALLOS_SMTP_PASSWORD=top-secret');

    $payload = wallos_smtp_public_payload(wallos_get_effective_smtp_config($db, 2));
    $encoded = json_encode($payload);

    assert_not_contains('top-secret', $encoded, 'the password never appears');
    assert_not_contains('infrastructure@example.com', $encoded, 'the instance username is not exposed to users');
    assert_same(true, $payload['password']['configured'], 'its presence is reported');
    assert_same('environment', $payload['password']['source'], 'its source is reported');

    $db->close();
});

wallos_test('a usable transport does not mean notifications are enabled', function () {
    // From the 2026-08-16 test run: a green test button was read as proof that
    // scheduled mail would arrive. It is not — the transport can be perfectly
    // valid while the user has never enabled or saved notifications, and the
    // cron job only sends for users who did.
    $db = wallos_test_open_database();
    integration_fixture($db);

    $stmt = $db->prepare('DELETE FROM email_notifications WHERE user_id = 2');
    $stmt->execute();
    wallos_reset_config_cache($db);

    $config = wallos_get_effective_smtp_config($db, 2);

    assert_true($config['valid'], 'the inherited transport is usable');
    assert_same(0, (int) $config['values']['enabled'],
        'a user without a saved row has notifications disabled');

    $db->close();
});
