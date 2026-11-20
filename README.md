# HavenLand

HavenLand is a browser-based fantasy MMORPG-style game. Players create an account, manage saved heroes, and take those characters into an adventure featuring combat, dungeons, training, quests, equipment, and progression.

## Player flow

1. Register with a username and password, or sign in to an existing account.
2. Open the character page to select a saved hero, create a new character, or delete a character.
3. Play the selected hero. Character progress is saved to the account as the game changes it.
4. Sign out from the character page or the game interface.

Each account can have multiple saved characters. Character data is associated with its account, so different accounts do not share saves. Existing legacy local-player saves may be imported into an empty account when signing in from the same browser.

## Database-backed accounts and saves

The browser interface is in `index.html`; `api.php` handles registration, login, logout, and game saves. The API uses PHP sessions, PDO, and MySQL. Configure these environment variables on the PHP host before using account features:

- `DB_HOST` — MySQL server host
- `DB_PORT` — MySQL server port
- `DB_NAME` — an existing database name
- `DB_USERNAME` — database user
- `DB_PASSWORD` — database password (may be empty if the server allows it)

The configured database must already exist and the database user needs permission to create and update tables. On its first request, the API creates the `game_accounts` table if needed. That table stores a unique username, a **password hash** (never the plaintext password), the account's character data, and a save version used to detect conflicting saves.

Use a PHP-capable web host with PDO's MySQL driver and serve the project through that host so requests to `api.php` execute as PHP. Opening `index.html` directly as a static file is not enough for database login or persistent account saves. Do not publish database credentials in client-side files or commit them to the repository.

## Account rules and security

- Usernames are 1–32 ASCII letters, numbers, or underscores.
- Passwords must be 8–72 bytes. PHP's password hashing and verification functions protect stored passwords.
- The API uses prepared database statements, session cookies, and CSRF tokens for state-changing requests.
- Character data is stored as JSON in the account row. Save-version checks detect when another session has changed that account's data.

## Project files

- `index.html` — game, login/registration screen, character selection/creation page, and game interface.
- `api.php` — session and database API for accounts and character saves.
