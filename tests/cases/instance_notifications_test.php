<?php
/*
  Milestone C: the notification providers whose credential has a shared half and
  a personal half. The instance supplies the shared secret (the Telegram bot
  token, the Pushover application token, the ntfy server and auth, the Gotify
  host); the user supplies only their own destination. These cases assert the
  resolution — instance credential plus this user's identifier — the way the
  milestone B cases assert it for SMTP, currency and AI.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

/* -------------------------------------------------------------------------
   Telegram (issue #12)
   ------------------------------------------------------------------------- */

wallos_test('an instance Telegram bot serves a user who set only a chat id', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    wallos_set_instance_setting($db, 'telegram', 'bot_token', 'instance-bot-token', true);
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token_mode, bot_token, chat_id, user_id)
               VALUES (1, 'instance', '', '111', 1)");

    $config = wallos_get_effective_telegram_config($db, 1);

    assert_same('instance', $config['mode'], 'the user inherits the instance bot');
    assert_same('instance-bot-token', $config['values']['bot_token'], 'the instance token is used');
    assert_same('111', $config['values']['chat_id'], "the chat id stays the user's own");
    assert_true($config['values']['deliverable'], 'both halves present means deliverable');
    assert_true($config['valid'], 'a deliverable config is valid');

    $db->close();
});

wallos_test("one instance Telegram bot serves several users, each with their own chat id", function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    wallos_set_instance_setting($db, 'telegram', 'bot_token', 'shared-bot', true);
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token_mode, chat_id, user_id)
               VALUES (1, 'instance', 'alice-chat', 1)");
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token_mode, chat_id, user_id)
               VALUES (1, 'instance', 'bob-chat', 2)");

    $alice = wallos_get_effective_telegram_config($db, 1);
    $bob = wallos_get_effective_telegram_config($db, 2);

    assert_same('shared-bot', $alice['values']['bot_token'], 'alice sends through the instance bot');
    assert_same('shared-bot', $bob['values']['bot_token'], 'so does bob');
    assert_same('alice-chat', $alice['values']['chat_id'], 'alice keeps her chat id');
    assert_same('bob-chat', $bob['values']['chat_id'], "and never receives bob's");

    $db->close();
});

wallos_test('a Telegram user without a chat id is not deliverable', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    wallos_set_instance_setting($db, 'telegram', 'bot_token', 'instance-bot-token', true);
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token_mode, chat_id, user_id)
               VALUES (1, 'instance', '', 1)");

    $config = wallos_get_effective_telegram_config($db, 1);

    assert_true(!$config['values']['deliverable'], 'no chat id means there is nowhere to deliver');
    assert_true(!$config['valid'], 'and the configuration is reported invalid');

    $db->close();
});

wallos_test('an existing custom Telegram bot keeps working', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    // The instance offers a bot, but a user who runs their own keeps it.
    wallos_set_instance_setting($db, 'telegram', 'bot_token', 'instance-bot-token', true);
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token_mode, bot_token, chat_id, user_id)
               VALUES (1, 'custom', 'user-bot-token', '222', 1)");

    $config = wallos_get_effective_telegram_config($db, 1);

    assert_same('custom', $config['mode'], 'the user runs a custom bot');
    assert_same('user-bot-token', $config['values']['bot_token'], 'their own token is used, not the instance one');
    assert_same('222', $config['values']['chat_id'], 'their chat id is kept');
    assert_true($config['values']['deliverable'], 'a full custom config is deliverable');

    $db->close();
});

wallos_test('a pre-migration Telegram row with a token is treated as custom', function () {
    // No mode column at all — a database part-way through the migration chain.
    // A stored bot token is what marks the row as a custom bot.
    $config = wallos_effective_telegram_config(wallos_config_result(),
        ['bot_token' => 'legacy-token', 'chat_id' => '333', 'enabled' => 1]);

    assert_same('custom', $config['mode'], 'a stored token without a mode column means a custom bot');
    assert_same('legacy-token', $config['values']['bot_token'], 'the stored token is used');
    assert_true($config['values']['deliverable'], 'the legacy configuration still delivers');
});

wallos_test('the Telegram payload reports status but never the bot token', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    putenv('WALLOS_TELEGRAM_BOT_TOKEN=super-secret-bot-token');
    $db->exec("INSERT INTO telegram_notifications (enabled, bot_token_mode, chat_id, user_id)
               VALUES (1, 'instance', '444', 1)");

    $payload = wallos_telegram_public_payload(wallos_get_effective_telegram_config($db, 1));
    $encoded = json_encode($payload);

    assert_not_contains('super-secret-bot-token', $encoded, 'the instance bot token never reaches a user');
    assert_same(true, $payload['bot_token']['configured'], 'its presence is reported');
    assert_same('environment', $payload['bot_token']['source'], 'its source is reported');
    assert_same('444', $payload['chat_id'], 'the personal chat id is returned');

    $db->close();
});

/* -------------------------------------------------------------------------
   Pushover (issue #13)
   ------------------------------------------------------------------------- */

wallos_test('one instance Pushover application serves several users, each with their own user key', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    wallos_set_instance_setting($db, 'pushover', 'app_token', 'instance-app-token', true);
    $db->exec("INSERT INTO pushover_notifications (enabled, token_mode, user_key, user_id)
               VALUES (1, 'instance', 'alice-key', 1)");
    $db->exec("INSERT INTO pushover_notifications (enabled, token_mode, user_key, user_id)
               VALUES (1, 'instance', 'bob-key', 2)");

    $alice = wallos_get_effective_pushover_config($db, 1);
    $bob = wallos_get_effective_pushover_config($db, 2);

    assert_same('instance-app-token', $alice['values']['token'], 'alice sends through the instance application');
    assert_same('instance-app-token', $bob['values']['token'], 'so does bob');
    assert_same('alice-key', $alice['values']['user_key'], 'alice keeps her user key');
    assert_same('bob-key', $bob['values']['user_key'], "and never receives bob's");
    assert_true($alice['values']['deliverable'], 'both halves present means deliverable');

    $db->close();
});

wallos_test('a Pushover user without a user key is not deliverable', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    wallos_set_instance_setting($db, 'pushover', 'app_token', 'instance-app-token', true);
    $db->exec("INSERT INTO pushover_notifications (enabled, token_mode, user_key, user_id)
               VALUES (1, 'instance', '', 1)");

    $config = wallos_get_effective_pushover_config($db, 1);

    assert_true(!$config['values']['deliverable'], 'no user key means there is no person to deliver to');
    assert_true(!$config['valid'], 'and the configuration is reported invalid');

    $db->close();
});

wallos_test('an existing custom Pushover application keeps working', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    wallos_set_instance_setting($db, 'pushover', 'app_token', 'instance-app-token', true);
    $db->exec("INSERT INTO pushover_notifications (enabled, token_mode, token, user_key, user_id)
               VALUES (1, 'custom', 'user-app-token', 'user-key', 1)");

    $config = wallos_get_effective_pushover_config($db, 1);

    assert_same('custom', $config['mode'], 'the user runs their own application');
    assert_same('user-app-token', $config['values']['token'], 'their own token is used, not the instance one');
    assert_same('user-key', $config['values']['user_key'], 'their user key is kept');
    assert_true($config['values']['deliverable'], 'a full custom config is deliverable');

    $db->close();
});

wallos_test('a pre-migration Pushover row with a token is treated as custom', function () {
    $config = wallos_effective_pushover_config(wallos_config_result(),
        ['token' => 'legacy-app-token', 'user_key' => 'legacy-key', 'enabled' => 1]);

    assert_same('custom', $config['mode'], 'a stored token without a mode column means a custom application');
    assert_same('legacy-app-token', $config['values']['token'], 'the stored token is used');

    // No database is opened in this case; nothing to close.
    assert_true($config['values']['deliverable'], 'the legacy configuration still delivers');
});

wallos_test('the Pushover payload reports status but never the application token', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    putenv('WALLOS_PUSHOVER_APP_TOKEN=super-secret-app-token');
    $db->exec("INSERT INTO pushover_notifications (enabled, token_mode, user_key, user_id)
               VALUES (1, 'instance', 'visible-key', 1)");

    $payload = wallos_pushover_public_payload(wallos_get_effective_pushover_config($db, 1));
    $encoded = json_encode($payload);

    assert_not_contains('super-secret-app-token', $encoded, 'the instance application token never reaches a user');
    assert_same(true, $payload['token']['configured'], 'its presence is reported');
    assert_same('environment', $payload['token']['source'], 'its source is reported');
    assert_same('visible-key', $payload['user_key'], 'the personal user key is returned');

    $db->close();
});
