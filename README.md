# Basecamp Reader

CLI tool to browse Basecamp 3/4 card tables (Kanban boards), view card details, move cards between columns, add comments, and generate PRD content from cards using Claude.

## Requirements

- PHP 8.1+ (uses `declare(strict_types=1)` and PHP 8 syntax)
- Basecamp account and a project with at least one card table
- OAuth2 app credentials from [Basecamp Launchpad](https://launchpad.37signals.com/)
- Optional: Claude API key for the “add to PRD” feature

## Setup

1. **Copy the example env file and fill in your values:**

   ```bash
   cp example.env .env
   ```

2. **Configure `.env`:**

   - **Basecamp:** `BASECAMP_ACCOUNT_ID`, `BASECAMP_PROJECT_ID`, `BASECAMP_CLIENT_ID`, `BASECAMP_CLIENT_SECRET`, `BASECAMP_REDIRECT_URI`, `BASECAMP_USER_AGENT`
   - **Optional:** `BASECAMP_ACCESS_TOKEN` if you already have a token; otherwise the script will guide you through OAuth.
   - **Optional (for PRD feature):** `CLAUDE_API_KEY`, `CLAUDE_MODEL`, `PRD_PATH`

3. **First-time OAuth:**

   ```bash
   php basecamp_list_cards.php --print-auth-url
   ```

   Open the URL in a browser, authorize the app, then run:

   ```bash
   php basecamp_list_cards.php --code=YOUR_AUTHORIZATION_CODE
   ```

   The script stores the token in `BASECAMP_TOKEN_STORAGE_PATH` (default: `basecamp_token.json`). After that you can run without `--code=`.

## Usage

**Interactive mode (default):**

```bash
php basecamp_list_cards.php
```

- Choose a card table → column → see cards.
- **Enter a number** – view that card’s details.
- **Comma-separated list** (e.g. `1,3,5`) – add selected cards to PRD (Claude generates epic/tasks into `PRD_PATH`).
- **`ctx N`** – add a “Please can I have more context” comment to card N.
- **`mv N`** – move card N to another column (you’ll be asked which column).
- **`r`** – refetch the card list.
- **`b`** – back; **`q`** – quit.

**Non-interactive list (all columns and cards):**

```bash
php basecamp_list_cards.php --list
```

**Override card table:**

```bash
php basecamp_list_cards.php --card-table-id=123456789
# or
php basecamp_list_cards.php --card-table-url="https://3.basecampapi.com/ACCOUNT_ID/buckets/PROJECT_ID/card_tables/CARD_TABLE_ID.json"
```

## Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `BASECAMP_ACCOUNT_ID` | Yes | Your Basecamp account ID |
| `BASECAMP_PROJECT_ID` | Yes | Project (bucket) ID that contains the card table |
| `BASECAMP_CLIENT_ID` | Yes* | OAuth2 client ID from Launchpad |
| `BASECAMP_CLIENT_SECRET` | Yes* | OAuth2 client secret |
| `BASECAMP_REDIRECT_URI` | Yes* | OAuth2 redirect URI (must match Launchpad) |
| `BASECAMP_USER_AGENT` | Yes | User-Agent string for API requests (e.g. `MyApp (you@example.com)`) |
| `BASECAMP_ACCESS_TOKEN` | No | Pre-obtained token; if set, skips token file / OAuth |
| `BASECAMP_TOKEN_STORAGE_PATH` | No | Where to store the OAuth token (default: `./basecamp_token.json`) |
| `BASECAMP_CLIENT_ID` etc. | * | Required only when not using a stored or provided access token |
| `CLAUDE_API_KEY` | No | For “add to PRD” feature |
| `CLAUDE_MODEL` | No | Claude model (default: `claude-haiku-4-5`) |
| `PRD_PATH` | No | Path to PRD JSON file (default: `./PRD.json`) |

## File structure

- `basecamp_list_cards.php` – main script
- `example.env` – example environment template
- `.env` – your config (create from `example.env`, not committed)
- `basecamp_token.json` – stored OAuth token (created on first auth)
- `PRD.json` – optional; used when adding epics/tasks from cards
