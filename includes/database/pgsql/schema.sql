-- Wallos PostgreSQL baseline schema — generated, do not edit by hand.
--
-- Produced by dev/generate-pgsql-schema.php from a SQLite database that has
-- run createdatabase.php and the full migration chain. Issue #21 asks for a
-- current-schema baseline for fresh PostgreSQL installations instead of a port
-- of the historical migrations, so the migrations table below is seeded with
-- every migration already marked as applied and includes/run_migrations.php
-- finds nothing to do.
--
-- Regenerate with:
--   podman exec wallos-dev php /var/www/html/dev/generate-pgsql-schema.php
--
-- tests/cases/pgsql_schema_test.php regenerates it and fails on any difference,
-- so the baseline cannot go stale behind a new migration.
--
-- Three translations are deliberate and none of them is an improvement:
--   * BOOLEAN columns are INTEGER. Wallos writes 0 and 1 and compares them with
--     == 1 everywhere; a real BOOLEAN returns true and false and breaks all of it.
--   * DATE columns are TEXT. Wallos stores and compares '2026-01-01' strings.
--   * Every identifier is quoted, because "user" and "order" are reserved words
--     and a keyword list kept in the generator would be wrong eventually.

-- 42 tables, 74 migrations recorded as applied.

CREATE TABLE "admin" (
    "id" SERIAL PRIMARY KEY,
    "registrations_open" INTEGER DEFAULT 0,
    "max_users" INTEGER DEFAULT 0,
    "require_email_verification" INTEGER DEFAULT 0,
    "server_url" TEXT,
    "smtp_address" TEXT,
    "smtp_port" INTEGER DEFAULT 587,
    "smtp_username" TEXT,
    "smtp_password" TEXT,
    "from_email" TEXT,
    "encryption" TEXT DEFAULT 'tls',
    "login_disabled" INTEGER DEFAULT 0,
    "latest_version" TEXT DEFAULT 'v2.21.1',
    "update_notification" INTEGER DEFAULT 0,
    "oidc_oauth_enabled" INTEGER DEFAULT 0,
    "local_webhook_notifications_allowlist" TEXT DEFAULT '',
    "smtp_from_name" TEXT DEFAULT '',
    "allow_standard_users_local_webhooks" INTEGER DEFAULT 0
);

CREATE TABLE "ai_recommendations" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "type" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "description" TEXT NOT NULL,
    "savings" TEXT DEFAULT '' NOT NULL,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE "ai_settings" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "type" TEXT NOT NULL,
    "enabled" INTEGER DEFAULT 0 NOT NULL,
    "api_key" TEXT,
    "model" TEXT NOT NULL,
    "url" TEXT,
    "run_schedule" TEXT DEFAULT 'manual' NOT NULL,
    "last_successful_run" TIMESTAMP,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "provider_mode" TEXT DEFAULT 'instance'
);

CREATE TABLE "categories" (
    "id" SERIAL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "order" INTEGER DEFAULT 0,
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "cron_runs" (
    "job" TEXT,
    "status" TEXT NOT NULL,
    "started_at" TEXT NOT NULL,
    "finished_at" TEXT NOT NULL,
    "duration_ms" INTEGER DEFAULT 0 NOT NULL,
    "detail" TEXT DEFAULT '' NOT NULL,
    "last_failure_at" TEXT,
    "last_failure_detail" TEXT DEFAULT '' NOT NULL,
    "failure_count" INTEGER DEFAULT 0 NOT NULL,
    PRIMARY KEY ("job")
);

CREATE TABLE "currencies" (
    "id" SERIAL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "symbol" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "rate" TEXT NOT NULL,
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "custom_colors" (
    "main_color" TEXT NOT NULL,
    "accent_color" TEXT NOT NULL,
    "hover_color" TEXT NOT NULL,
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "custom_css_style" (
    "css" TEXT DEFAULT '',
    "user_id" INTEGER
);

CREATE TABLE "cycles" (
    "id" SERIAL PRIMARY KEY,
    "days" INTEGER NOT NULL,
    "name" TEXT NOT NULL
);

CREATE TABLE "discord_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "webhook_url" TEXT DEFAULT '',
    "bot_username" TEXT DEFAULT '',
    "bot_avatar_url" TEXT DEFAULT '',
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "email_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "smtp_address" TEXT DEFAULT '',
    "smtp_port" INTEGER DEFAULT 587,
    "smtp_username" TEXT DEFAULT '',
    "smtp_password" TEXT DEFAULT '',
    "from_email" TEXT DEFAULT '',
    "encryption" TEXT DEFAULT 'tls',
    "user_id" INTEGER DEFAULT 1,
    "other_emails" TEXT DEFAULT '',
    "smtp_mode" TEXT DEFAULT 'instance'
);

CREATE TABLE "email_verification" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER,
    "email" TEXT,
    "token" TEXT,
    "email_sent" INTEGER DEFAULT 0
);

CREATE TABLE "fixer" (
    "api_key" TEXT NOT NULL,
    "provider" INTEGER DEFAULT 0,
    "user_id" INTEGER DEFAULT 1,
    "usage_used" INTEGER DEFAULT NULL,
    "usage_limit" INTEGER DEFAULT NULL,
    "usage_updated_at" TEXT DEFAULT NULL,
    "provider_mode" TEXT DEFAULT 'instance',
    "local_calls" INTEGER DEFAULT 0,
    "local_calls_month" TEXT DEFAULT '',
    "usage_used_day" INTEGER DEFAULT NULL,
    "usage_limit_day" INTEGER DEFAULT NULL
);

CREATE TABLE "frequencies" (
    "id" SERIAL PRIMARY KEY,
    "name" INTEGER NOT NULL
);

CREATE TABLE "google_search" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "api_key" TEXT DEFAULT '' NOT NULL
);

CREATE TABLE "gotify_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "url" TEXT DEFAULT '',
    "token" TEXT DEFAULT '',
    "user_id" INTEGER DEFAULT 1,
    "ignore_ssl" INTEGER DEFAULT 0
);

CREATE TABLE "household" (
    "id" SERIAL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "email" TEXT DEFAULT '',
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "integration_settings" (
    "integration" TEXT NOT NULL,
    "setting_key" TEXT NOT NULL,
    "setting_value" TEXT DEFAULT '',
    "is_secret" INTEGER DEFAULT 0,
    PRIMARY KEY ("integration", "setting_key")
);

CREATE TABLE "last_exchange_update" (
    "date" TEXT NOT NULL,
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "last_update_next_payment_date" (
    "date" TEXT NOT NULL
);

CREATE TABLE "login_tokens" (
    "user_id" INTEGER NOT NULL,
    "token" TEXT NOT NULL,
    "timestamp" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE "mattermost_notifications" (
    "enabled" INTEGER DEFAULT 0 NOT NULL,
    "user_id" INTEGER,
    "webhook_url" TEXT DEFAULT '',
    "bot_username" TEXT DEFAULT '',
    "bot_icon_emoji" TEXT DEFAULT ''
);

CREATE TABLE "migrations" (
    "id" SERIAL PRIMARY KEY,
    "migration" TEXT NOT NULL,
    "migrated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE "notification_settings" (
    "days" INTEGER DEFAULT 0,
    "user_id" INTEGER DEFAULT 1,
    "period_summary_at_period_start" INTEGER DEFAULT 0
);

CREATE TABLE "ntfy_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "host" TEXT DEFAULT '',
    "topic" TEXT DEFAULT '',
    "headers" TEXT DEFAULT '',
    "user_id" INTEGER,
    "ignore_ssl" INTEGER DEFAULT 0
);

CREATE TABLE "oauth_settings" (
    "id" SERIAL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "client_id" TEXT NOT NULL,
    "client_secret" TEXT NOT NULL,
    "authorization_url" TEXT NOT NULL,
    "token_url" TEXT NOT NULL,
    "user_info_url" TEXT NOT NULL,
    "redirect_url" TEXT NOT NULL,
    "logout_url" TEXT,
    "user_identifier_field" TEXT DEFAULT 'sub' NOT NULL,
    "scopes" TEXT DEFAULT 'openid email profile' NOT NULL,
    "auth_style" TEXT DEFAULT 'auto',
    "auto_create_user" INTEGER DEFAULT 0,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "password_login_disabled" INTEGER DEFAULT 0,
    "require_email_verified" INTEGER DEFAULT 1,
    "admin_claim" TEXT DEFAULT '',
    "admin_value" TEXT DEFAULT '',
    "post_logout_redirect_url" TEXT DEFAULT '',
    "issuer" TEXT DEFAULT ''
);

CREATE TABLE "oidc_discovery_cache" (
    "issuer" TEXT,
    "document" TEXT NOT NULL,
    "fetched_at" INTEGER NOT NULL,
    PRIMARY KEY ("issuer")
);

CREATE TABLE "oidc_sessions" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "sid" TEXT DEFAULT '' NOT NULL,
    "session_id" TEXT NOT NULL,
    "login_token" TEXT DEFAULT '' NOT NULL,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "id_token" TEXT DEFAULT '',
    "refresh_token" TEXT DEFAULT '',
    "access_token_issued_at" INTEGER DEFAULT 0,
    "access_token_expires_at" INTEGER DEFAULT 0,
    "refresh_failed_at" INTEGER DEFAULT 0,
    "refresh_error" TEXT DEFAULT ''
);

CREATE TABLE "password_resets" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER,
    "email" TEXT,
    "token" TEXT,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "email_sent" INTEGER DEFAULT 0
);

CREATE TABLE "payment_methods" (
    "id" SERIAL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "icon" TEXT,
    "enabled" INTEGER DEFAULT 1,
    "order" INTEGER DEFAULT 0,
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "pushover_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "user_key" TEXT DEFAULT '',
    "token" TEXT DEFAULT '',
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "pushplus_notifications" (
    "enabled" INTEGER DEFAULT 0 NOT NULL,
    "token" TEXT,
    "user_id" INTEGER
);

CREATE TABLE "serverchan_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "sendkey" TEXT DEFAULT '',
    "user_id" INTEGER
);

CREATE TABLE "settings" (
    "dark_theme" INTEGER DEFAULT 0,
    "monthly_price" INTEGER DEFAULT 0,
    "convert_currency" INTEGER DEFAULT 0,
    "remove_background" INTEGER DEFAULT 0,
    "color_theme" TEXT DEFAULT 'blue',
    "hide_disabled" INTEGER DEFAULT 0,
    "user_id" INTEGER DEFAULT 1,
    "disabled_to_bottom" INTEGER DEFAULT 0,
    "show_original_price" INTEGER DEFAULT 0,
    "mobile_nav" INTEGER DEFAULT 0,
    "show_subscription_progress" INTEGER DEFAULT 0,
    "week_starts_sunday" INTEGER DEFAULT 0
);

CREATE TABLE "subscriptions" (
    "id" SERIAL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "logo" TEXT,
    "price" DOUBLE PRECISION NOT NULL,
    "currency_id" INTEGER,
    "next_payment" TEXT,
    "cycle" INTEGER,
    "frequency" INTEGER,
    "notes" TEXT,
    "payment_method_id" INTEGER,
    "payer_user_id" INTEGER,
    "category_id" INTEGER,
    "notify" INTEGER DEFAULT 0,
    "url" VARCHAR(255),
    "inactive" INTEGER DEFAULT 0,
    "notify_days_before" INTEGER DEFAULT 0,
    "user_id" INTEGER DEFAULT 1,
    "cancellation_date" TEXT,
    "replacement_subscription_id" INTEGER DEFAULT NULL,
    "start_date" TEXT DEFAULT NULL,
    "auto_renew" INTEGER DEFAULT 1,
    "logo_text_color" TEXT DEFAULT NULL,
    "logo_variant" TEXT DEFAULT NULL
);

CREATE TABLE "telegram_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "bot_token" TEXT DEFAULT '',
    "chat_id" TEXT DEFAULT '',
    "user_id" INTEGER DEFAULT 1
);

CREATE TABLE "total_yearly_cost" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "date" TEXT NOT NULL,
    "cost" DOUBLE PRECISION NOT NULL,
    "currency" TEXT NOT NULL
);

CREATE TABLE "totp" (
    "user_id" INTEGER NOT NULL,
    "totp_secret" TEXT NOT NULL,
    "backup_codes" TEXT NOT NULL,
    "last_totp_used" INTEGER DEFAULT 0,
    "failed_attempts" INTEGER DEFAULT 0,
    "lockout_until" INTEGER DEFAULT 0
);

CREATE TABLE "uploaded_avatars" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "path" TEXT NOT NULL
);

CREATE TABLE "user" (
    "id" SERIAL PRIMARY KEY,
    "username" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "password" TEXT NOT NULL,
    "main_currency" INTEGER NOT NULL,
    "avatar" TEXT,
    "language" TEXT DEFAULT 'en',
    "budget" DOUBLE PRECISION DEFAULT 0,
    "totp_enabled" INTEGER DEFAULT 0,
    "api_key" TEXT,
    "firstname" TEXT DEFAULT '',
    "lastname" TEXT DEFAULT '',
    "oidc_sub" TEXT,
    "budget_period_type" TEXT DEFAULT 'monthly',
    "budget_period_anchor_date" TEXT DEFAULT to_char(CURRENT_DATE, 'YYYY-MM-DD'),
    "period_budget" DOUBLE PRECISION DEFAULT 0
);

CREATE TABLE "user_roles" (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "role" TEXT NOT NULL,
    "source" TEXT NOT NULL,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE ("user_id", "role", "source")
);

CREATE TABLE "webhook_notifications" (
    "enabled" INTEGER DEFAULT 0,
    "headers" TEXT DEFAULT '',
    "url" TEXT DEFAULT '',
    "request_method" TEXT DEFAULT 'POST',
    "payload" TEXT DEFAULT '',
    "user_id" INTEGER DEFAULT 1,
    "ignore_ssl" INTEGER DEFAULT 0,
    "cancelation_payload" TEXT DEFAULT ''
);

-- Foreign keys, added once every table exists rather than inside the CREATE
-- TABLE statements, so this file does not depend on the order tables appear in.
--
-- SQLite does not enforce these unless foreign_keys is switched on, which Wallos
-- never does, so PostgreSQL is the first backend that actually holds the
-- application to them.

ALTER TABLE "custom_css_style" ADD CONSTRAINT "custom_css_style_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id");
ALTER TABLE "login_tokens" ADD CONSTRAINT "login_tokens_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "ntfy_notifications" ADD CONSTRAINT "ntfy_notifications_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id");
ALTER TABLE "oidc_sessions" ADD CONSTRAINT "oidc_sessions_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "serverchan_notifications" ADD CONSTRAINT "serverchan_notifications_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id");
ALTER TABLE "subscriptions" ADD CONSTRAINT "subscriptions_category_id_fkey"
    FOREIGN KEY ("category_id") REFERENCES "categories" ("id");
ALTER TABLE "subscriptions" ADD CONSTRAINT "subscriptions_currency_id_fkey"
    FOREIGN KEY ("currency_id") REFERENCES "currencies" ("id");
ALTER TABLE "subscriptions" ADD CONSTRAINT "subscriptions_cycle_fkey"
    FOREIGN KEY ("cycle") REFERENCES "cycles" ("id");
ALTER TABLE "subscriptions" ADD CONSTRAINT "subscriptions_payer_user_id_fkey"
    FOREIGN KEY ("payer_user_id") REFERENCES "household" ("id");
ALTER TABLE "subscriptions" ADD CONSTRAINT "subscriptions_payment_method_id_fkey"
    FOREIGN KEY ("payment_method_id") REFERENCES "payment_methods" ("id");
ALTER TABLE "totp" ADD CONSTRAINT "totp_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id");
ALTER TABLE "user" ADD CONSTRAINT "user_main_currency_fkey"
    FOREIGN KEY ("main_currency") REFERENCES "currencies" ("id");
ALTER TABLE "user_roles" ADD CONSTRAINT "user_roles_user_id_fkey"
    FOREIGN KEY ("user_id") REFERENCES "user" ("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- Indexes.

CREATE UNIQUE INDEX "idx_ai_settings_user" ON "ai_settings" ("user_id");
CREATE UNIQUE INDEX "idx_email_notifications_user" ON "email_notifications" ("user_id");
CREATE UNIQUE INDEX "idx_gotify_notifications_user" ON "gotify_notifications" ("user_id");
CREATE UNIQUE INDEX "idx_notification_settings_user" ON "notification_settings" ("user_id");
CREATE UNIQUE INDEX "idx_ntfy_notifications_user" ON "ntfy_notifications" ("user_id");
CREATE INDEX "idx_oidc_sessions_session" ON "oidc_sessions" ("session_id");
CREATE INDEX "idx_oidc_sessions_sid" ON "oidc_sessions" ("sid");
CREATE INDEX "idx_oidc_sessions_user" ON "oidc_sessions" ("user_id");
CREATE UNIQUE INDEX "idx_pushover_notifications_user" ON "pushover_notifications" ("user_id");
CREATE INDEX "idx_subscriptions_user_inactive_next_payment" ON "subscriptions" ("user_id", "inactive", "next_payment");
CREATE INDEX "idx_subscriptions_user_notify_inactive" ON "subscriptions" ("user_id", "notify", "inactive");
CREATE UNIQUE INDEX "idx_telegram_notifications_user" ON "telegram_notifications" ("user_id");
CREATE INDEX "idx_user_roles_user_role" ON "user_roles" ("user_id", "role");

-- The rows a fresh installation starts with: the reference data
-- createdatabase.php seeds, and the admin and settings rows the migration
-- chain creates. Columns defaulting to CURRENT_TIMESTAMP are omitted so they
-- record the moment of installation rather than the moment of generation.

INSERT INTO "admin" ("id", "registrations_open", "max_users", "require_email_verification", "server_url", "smtp_address", "smtp_port", "smtp_username", "smtp_password", "from_email", "encryption", "login_disabled", "latest_version", "update_notification", "oidc_oauth_enabled", "local_webhook_notifications_allowlist", "smtp_from_name", "allow_standard_users_local_webhooks") VALUES
    (1, 0, 0, 0, '', '', 587, '', '', '', 'tls', 0, 'v2.21.1', 0, 0, '', '', 0);

INSERT INTO "categories" ("id", "name", "order", "user_id") VALUES
    (1, 'No category', 1, 1),
    (2, 'Entertainment', 2, 1),
    (3, 'Music', 3, 1),
    (4, 'Utilities', 4, 1),
    (5, 'Food & Beverages', 5, 1),
    (6, 'Health & Wellbeing', 6, 1),
    (7, 'Productivity', 7, 1),
    (8, 'Banking', 8, 1),
    (9, 'Transport', 9, 1),
    (10, 'Education', 10, 1),
    (11, 'Insurance', 11, 1),
    (12, 'Gaming', 12, 1),
    (13, 'News & Magazines', 13, 1),
    (14, 'Software', 14, 1),
    (15, 'Technology', 15, 1),
    (16, 'Cloud Services', 16, 1),
    (17, 'Charity & Donations', 17, 1);

INSERT INTO "currencies" ("id", "name", "symbol", "code", "rate", "user_id") VALUES
    (1, 'Euro', '€', 'EUR', '1', 1),
    (2, 'US Dollar', '$', 'USD', '1', 1),
    (3, 'Japanese Yen', '¥', 'JPY', '1', 1),
    (4, 'Bulgarian Lev', 'лв', 'BGN', '1', 1),
    (5, 'Czech Republic Koruna', 'Kč', 'CZK', '1', 1),
    (6, 'Danish Krone', 'kr', 'DKK', '1', 1),
    (7, 'British Pound Sterling', '£', 'GBP', '1', 1),
    (8, 'Hungarian Forint', 'Ft', 'HUF', '1', 1),
    (9, 'Polish Zloty', 'zł', 'PLN', '1', 1),
    (10, 'Romanian Leu', 'lei', 'RON', '1', 1),
    (11, 'Swedish Krona', 'kr', 'SEK', '1', 1),
    (12, 'Swiss Franc', 'Fr', 'CHF', '1', 1),
    (13, 'Icelandic Króna', 'kr', 'ISK', '1', 1),
    (14, 'Norwegian Krone', 'kr', 'NOK', '1', 1),
    (15, 'Russian Ruble', '₽', 'RUB', '1', 1),
    (16, 'Turkish Lira', '₺', 'TRY', '1', 1),
    (17, 'Australian Dollar', '$', 'AUD', '1', 1),
    (18, 'Brazilian Real', 'R$', 'BRL', '1', 1),
    (19, 'Canadian Dollar', '$', 'CAD', '1', 1),
    (20, 'Chinese Yuan', '¥', 'CNY', '1', 1),
    (21, 'Hong Kong Dollar', 'HK$', 'HKD', '1', 1),
    (22, 'Indonesian Rupiah', 'Rp', 'IDR', '1', 1),
    (23, 'Israeli New Sheqel', '₪', 'ILS', '1', 1),
    (24, 'Indian Rupee', '₹', 'INR', '1', 1),
    (25, 'South Korean Won', '₩', 'KRW', '1', 1),
    (26, 'Mexican Peso', 'Mex$', 'MXN', '1', 1),
    (27, 'Malaysian Ringgit', 'RM', 'MYR', '1', 1),
    (28, 'New Zealand Dollar', 'NZ$', 'NZD', '1', 1),
    (29, 'Philippine Peso', '₱', 'PHP', '1', 1),
    (30, 'Singapore Dollar', 'S$', 'SGD', '1', 1),
    (31, 'Thai Baht', '฿', 'THB', '1', 1),
    (32, 'South African Rand', 'R', 'ZAR', '1', 1),
    (33, 'Ukrainian Hryvnia', '₴', 'UAH', '1', 1),
    (34, 'New Taiwan Dollar', 'NT$', 'TWD', '1', 1);

INSERT INTO "cycles" ("id", "days", "name") VALUES
    (1, 1, 'Daily'),
    (2, 7, 'Weekly'),
    (3, 30, 'Monthly'),
    (4, 365, 'Yearly'),
    (5, 0, 'One-time');

INSERT INTO "frequencies" ("id", "name") VALUES
    (1, 1),
    (2, 2),
    (3, 3),
    (4, 4),
    (5, 5),
    (6, 6),
    (7, 7),
    (8, 8),
    (9, 9),
    (10, 10),
    (11, 11),
    (12, 12),
    (13, 13),
    (14, 14),
    (15, 15),
    (16, 16),
    (17, 17),
    (18, 18),
    (19, 19),
    (20, 20),
    (21, 21),
    (22, 22),
    (23, 23),
    (24, 24),
    (25, 25),
    (26, 26),
    (27, 27),
    (28, 28),
    (29, 29),
    (30, 30),
    (31, 31);

INSERT INTO "migrations" ("id", "migration") VALUES
    (1, 'migrations/000001.php'),
    (2, 'migrations/000002.php'),
    (3, 'migrations/000003.php'),
    (4, 'migrations/000004.php'),
    (5, 'migrations/000005.php'),
    (6, 'migrations/000006.php'),
    (7, 'migrations/000007.php'),
    (8, 'migrations/000008.php'),
    (9, 'migrations/000009.php'),
    (10, 'migrations/000010.php'),
    (11, 'migrations/000011.php'),
    (12, 'migrations/000012.php'),
    (13, 'migrations/000013.php'),
    (14, 'migrations/000014.php'),
    (15, 'migrations/000015.php'),
    (16, 'migrations/000016.php'),
    (17, 'migrations/000017.php'),
    (18, 'migrations/000018.php'),
    (19, 'migrations/000019.php'),
    (20, 'migrations/000020.php'),
    (21, 'migrations/000021.php'),
    (22, 'migrations/000022.php'),
    (23, 'migrations/000023.php'),
    (24, 'migrations/000024.php'),
    (25, 'migrations/000025.php'),
    (26, 'migrations/000026.php'),
    (27, 'migrations/000027.php'),
    (28, 'migrations/000028.php'),
    (29, 'migrations/000029.php'),
    (30, 'migrations/000030.php'),
    (31, 'migrations/000031.php'),
    (32, 'migrations/000032.php'),
    (33, 'migrations/000033.php'),
    (34, 'migrations/000034.php'),
    (35, 'migrations/000035.php'),
    (36, 'migrations/000036.php'),
    (37, 'migrations/000037.php'),
    (38, 'migrations/000038.php'),
    (39, 'migrations/000039.php'),
    (40, 'migrations/000040.php'),
    (41, 'migrations/000041.php'),
    (42, 'migrations/000042.php'),
    (43, 'migrations/000043.php'),
    (44, 'migrations/000044.php'),
    (45, 'migrations/000045.php'),
    (46, 'migrations/000046.php'),
    (47, 'migrations/000047.php'),
    (48, 'migrations/000048.php'),
    (49, 'migrations/000050.php'),
    (50, 'migrations/000051.php'),
    (51, 'migrations/000052.php'),
    (52, 'migrations/000053.php'),
    (53, 'migrations/000054.php'),
    (54, 'migrations/000055.php'),
    (55, 'migrations/000056.php'),
    (56, 'migrations/000057.php'),
    (57, 'migrations/000058.php'),
    (58, 'migrations/000059.php'),
    (59, 'migrations/000060.php'),
    (60, 'migrations/000061.php'),
    (61, 'migrations/000062.php'),
    (62, 'migrations/000063.php'),
    (63, 'migrations/000064.php'),
    (64, 'migrations/000065.php'),
    (65, 'migrations/000066.php'),
    (66, 'migrations/000067.php'),
    (67, 'migrations/000068.php'),
    (68, 'migrations/000069.php'),
    (69, 'migrations/000070.php'),
    (70, 'migrations/000071.php'),
    (71, 'migrations/000072.php'),
    (72, 'migrations/000073.php'),
    (73, 'migrations/000074.php'),
    (74, 'migrations/000075.php');

INSERT INTO "payment_methods" ("id", "name", "icon", "enabled", "order", "user_id") VALUES
    (1, 'PayPal', 'images/uploads/icons/paypal.png', 1, 1, 1),
    (2, 'Credit Card', 'images/uploads/icons/creditcard.png', 1, 2, 1),
    (3, 'Bank Transfer', 'images/uploads/icons/banktransfer.png', 1, 3, 1),
    (4, 'Direct Debit', 'images/uploads/icons/directdebit.png', 1, 4, 1),
    (5, 'Money', 'images/uploads/icons/money.png', 1, 5, 1),
    (6, 'Google Pay', 'images/uploads/icons/googlepay.png', 1, 6, 1),
    (7, 'Samsung Pay', 'images/uploads/icons/samsungpay.png', 1, 7, 1),
    (8, 'Apple Pay', 'images/uploads/icons/applepay.png', 1, 8, 1),
    (9, 'Crypto', 'images/uploads/icons/crypto.png', 1, 9, 1),
    (10, 'Klarna', 'images/uploads/icons/klarna.png', 1, 10, 1),
    (11, 'Amazon Pay', 'images/uploads/icons/amazonpay.png', 1, 11, 1),
    (12, 'SEPA', 'images/uploads/icons/sepa.png', 1, 12, 1),
    (13, 'Skrill', 'images/uploads/icons/skrill.png', 1, 13, 1),
    (14, 'Sofort', 'images/uploads/icons/sofort.png', 1, 14, 1),
    (15, 'Stripe', 'images/uploads/icons/stripe.png', 1, 15, 1),
    (16, 'Affirm', 'images/uploads/icons/affirm.png', 1, 16, 1),
    (17, 'AliPay', 'images/uploads/icons/alipay.png', 1, 17, 1),
    (18, 'Elo', 'images/uploads/icons/elo.png', 1, 18, 1),
    (19, 'Facebook Pay', 'images/uploads/icons/facebookpay.png', 1, 19, 1),
    (20, 'GiroPay', 'images/uploads/icons/giropay.png', 1, 20, 1),
    (21, 'iDeal', 'images/uploads/icons/ideal.png', 1, 21, 1),
    (22, 'Union Pay', 'images/uploads/icons/unionpay.png', 1, 22, 1),
    (23, 'Interac', 'images/uploads/icons/interac.png', 1, 23, 1),
    (24, 'WeChat', 'images/uploads/icons/wechat.png', 1, 24, 1),
    (25, 'Paysafe', 'images/uploads/icons/paysafe.png', 1, 25, 1),
    (26, 'Poli', 'images/uploads/icons/poli.png', 1, 26, 1),
    (27, 'Qiwi', 'images/uploads/icons/qiwi.png', 1, 27, 1),
    (28, 'ShopPay', 'images/uploads/icons/shoppay.png', 1, 28, 1),
    (29, 'Venmo', 'images/uploads/icons/venmo.png', 1, 29, 1),
    (30, 'VeriFone', 'images/uploads/icons/verifone.png', 1, 30, 1),
    (31, 'WebMoney', 'images/uploads/icons/webmoney.png', 1, 31, 1);

INSERT INTO "settings" ("dark_theme", "monthly_price", "convert_currency", "remove_background", "color_theme", "hide_disabled", "user_id", "disabled_to_bottom", "show_original_price", "mobile_nav", "show_subscription_progress", "week_starts_sunday") VALUES
    (0, 0, 0, 0, 'blue', 0, 1, 0, 0, 0, 0, 0);

-- The rows above carry their original ids, which leaves every sequence at 1 and
-- the next insert colliding with seeded data.

SELECT setval(pg_get_serial_sequence('admin', 'id'), (SELECT MAX("id") FROM "admin"));
SELECT setval(pg_get_serial_sequence('categories', 'id'), (SELECT MAX("id") FROM "categories"));
SELECT setval(pg_get_serial_sequence('currencies', 'id'), (SELECT MAX("id") FROM "currencies"));
SELECT setval(pg_get_serial_sequence('cycles', 'id'), (SELECT MAX("id") FROM "cycles"));
SELECT setval(pg_get_serial_sequence('frequencies', 'id'), (SELECT MAX("id") FROM "frequencies"));
SELECT setval(pg_get_serial_sequence('migrations', 'id'), (SELECT MAX("id") FROM "migrations"));
SELECT setval(pg_get_serial_sequence('payment_methods', 'id'), (SELECT MAX("id") FROM "payment_methods"));
