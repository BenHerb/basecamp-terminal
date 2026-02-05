<?php

declare(strict_types=1);

load_env_file(__DIR__ . '/.env');

// Hardcoded config for now. Replace with your values.
$config = [
    'account_id' => env_int('BASECAMP_ACCOUNT_ID', 0), // Basecamp account ID
    'project_id' => env_int('BASECAMP_PROJECT_ID', 0), // Basecamp project (bucket) ID

    // OAuth2 credentials (Basecamp 3/4 via Launchpad).
    'client_id' => env_str('BASECAMP_CLIENT_ID', ''),
    'client_secret' => env_str('BASECAMP_CLIENT_SECRET', ''),
    'redirect_uri' => env_str('BASECAMP_REDIRECT_URI', ''),

    // Optional: set if you already have a token.
    'access_token' => env_str('BASECAMP_ACCESS_TOKEN', ''),

    // Token storage (written by this script).
    'token_storage_path' => env_str('BASECAMP_TOKEN_STORAGE_PATH', __DIR__ . '/basecamp_token.json'),

    // Optional: set directly to skip discovery.
    // 'card_table_url' => 'https://3.basecampapi.com/ACCOUNT_ID/buckets/PROJECT_ID/card_tables/CARD_TABLE_ID.json',
    // 'card_table_id' => 123456789,

    // Required by Basecamp API for every request.
    'user_agent' => env_str('BASECAMP_USER_AGENT', ''),

    // Claude API config (hardcoded as requested).
    'claude_api_key' => env_str('CLAUDE_API_KEY', ''),
    'claude_model' => env_str('CLAUDE_MODEL', 'claude-haiku-4-5'),
    'prd_path' => env_str('PRD_PATH', __DIR__ . '/PRD.json'),
];

$cli = parse_cli_args($argv);
$config = apply_cli_overrides($config, $cli);
$config = bootstrap_access_token($config, $cli);

$baseUrl = 'https://3.basecampapi.com/' . $config['account_id'];

if (!empty($cli['list'])) {
    list_cards_non_interactive($baseUrl, $config);
    exit(0);
}

interactive_menu($baseUrl, $config);

function api_get_json(string $url, array $config): array
{
    [$status, $body] = api_get($url, $config);
    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "Request failed ({$status}) for {$url}\n");
        fwrite(STDERR, substr($body, 0, 500) . "\n");
        exit(1);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        fwrite(STDERR, "Invalid JSON from {$url}\n");
        exit(1);
    }

    return $json;
}

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '') {
            continue;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function env_str(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function env_int(string $key, int $default = 0): int
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return (int) $value;
}

function api_get(string $url, array $config): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "Unable to init curl\n");
        exit(1);
    }

    $headers = [
        'Authorization: Bearer ' . $config['access_token'],
        'Accept: application/json',
        'User-Agent: ' . $config['user_agent'],
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "Curl error for {$url}\n");
        exit(1);
    }

    return [$status, $body];
}

function api_post_json_basecamp(string $url, array $payload, array $config): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "Unable to init curl\n");
        exit(1);
    }

    $headers = [
        'Authorization: Bearer ' . $config['access_token'],
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: ' . $config['user_agent'],
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "Curl error for {$url}\n");
        exit(1);
    }

    return [$status, $body];
}

function create_card_comment(string $baseUrl, array $config, array $card, string $content): bool
{
    $cardId = $card['id'] ?? null;
    if ($cardId === null) {
        fwrite(STDERR, "Card has no id.\n");
        return false;
    }

    $projectId = $config['project_id'];
    $url = $baseUrl . '/buckets/' . $projectId . '/recordings/' . $cardId . '/comments.json';

    [$status, $body] = api_post_json_basecamp($url, ['content' => $content], $config);

    if ($status >= 200 && $status < 300) {
        return true;
    }

    fwrite(STDERR, "Comment failed ({$status}): " . substr($body, 0, 300) . "\n");
    return false;
}

function move_card_to_column(string $baseUrl, array $config, array $card, int|string $columnId): bool
{
    $cardId = $card['id'] ?? null;
    if ($cardId === null) {
        fwrite(STDERR, "Card has no id.\n");
        return false;
    }

    $projectId = $config['project_id'];
    $url = $baseUrl . '/buckets/' . $projectId . '/card_tables/cards/' . $cardId . '/moves.json';

    [$status, $body] = api_post_json_basecamp($url, ['column_id' => (int) $columnId], $config);

    if ($status === 204 || ($status >= 200 && $status < 300)) {
        return true;
    }

    fwrite(STDERR, "Move card failed ({$status}): " . substr($body, 0, 300) . "\n");
    return false;
}

function api_post_form(string $url, array $formData, array $config): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "Unable to init curl\n");
        exit(1);
    }

    $headers = [
        'Accept: application/json',
        'User-Agent: ' . $config['user_agent'],
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($formData),
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "Curl error for {$url}\n");
        exit(1);
    }

    return [$status, $body];
}

function parse_cli_args(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--code=')) {
            $out['code'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--card-table-id=')) {
            $out['card_table_id'] = substr($arg, 16);
        } elseif (str_starts_with($arg, '--card-table-url=')) {
            $out['card_table_url'] = substr($arg, 17);
        } elseif ($arg === '--print-auth-url') {
            $out['print_auth_url'] = true;
        } elseif ($arg === '--list') {
            $out['list'] = true;
        }
    }

    return $out;
}

function apply_cli_overrides(array $config, array $cli): array
{
    if (isset($cli['card_table_id']) && $cli['card_table_id'] !== '') {
        $config['card_table_id'] = is_numeric($cli['card_table_id'])
            ? (int) $cli['card_table_id']
            : $cli['card_table_id'];
    }

    if (isset($cli['card_table_url']) && $cli['card_table_url'] !== '') {
        $config['card_table_url'] = $cli['card_table_url'];
    }

    return $config;
}

function bootstrap_access_token(array $config, array $cli): array
{
    if (!empty($config['access_token'])) {
        return $config;
    }

    $stored = load_token_file($config['token_storage_path']);
    if (isset($stored['access_token']) && is_string($stored['access_token']) && $stored['access_token'] !== '') {
        $config['access_token'] = $stored['access_token'];
        return $config;
    }

    if (!empty($cli['print_auth_url'])) {
        echo build_authorize_url($config) . "\n";
        exit(0);
    }

    if (empty($cli['code'])) {
        echo "No access token found.\n";
        echo "Open this URL to authorize, then rerun with --code=YOUR_CODE:\n";
        echo build_authorize_url($config) . "\n";
        exit(1);
    }

    $token = exchange_code_for_token($cli['code'], $config);
    save_token_file($config['token_storage_path'], $token);
    if (!isset($token['access_token']) || !is_string($token['access_token']) || $token['access_token'] === '') {
        fwrite(STDERR, "Token response missing access_token\n");
        exit(1);
    }

    $config['access_token'] = $token['access_token'];
    return $config;
}

function build_authorize_url(array $config): string
{
    $query = http_build_query([
        'type' => 'web_server',
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
    ]);

    return 'https://launchpad.37signals.com/authorization/new?' . $query;
}

function exchange_code_for_token(string $code, array $config): array
{
    $formData = [
        'type' => 'web_server',
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'client_secret' => $config['client_secret'],
        'code' => $code,
    ];

    [$status, $body] = api_post_form('https://launchpad.37signals.com/authorization/token', $formData, $config);
    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "Token request failed ({$status})\n");
        fwrite(STDERR, substr($body, 0, 500) . "\n");
        exit(1);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        fwrite(STDERR, "Invalid JSON from token endpoint\n");
        exit(1);
    }

    if (isset($json['expires_in']) && is_numeric($json['expires_in'])) {
        $json['expires_at'] = time() + (int) $json['expires_in'];
    }

    return $json;
}

function load_token_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $json = json_decode($contents, true);
    return is_array($json) ? $json : [];
}

function save_token_file(string $path, array $token): void
{
    $payload = json_encode($token, JSON_PRETTY_PRINT);
    if ($payload === false) {
        fwrite(STDERR, "Failed to serialize token\n");
        exit(1);
    }

    if (file_put_contents($path, $payload) === false) {
        fwrite(STDERR, "Failed to write token file\n");
        exit(1);
    }
}

function list_cards_non_interactive(string $baseUrl, array $config): void
{
    $project = fetch_project($baseUrl, $config);
    $cardTableUrl = resolve_card_table_url($project, $config);

    if ($cardTableUrl === null) {
        fwrite(STDERR, "Could not find a card table URL. Set card_table_url explicitly or use --card-table-id.\n");
        exit(1);
    }

    $cardTable = api_get_json($cardTableUrl, $config);
    $columns = extract_columns($cardTable, $config);

    if (count($columns) === 0) {
        fwrite(STDERR, "No columns found for this card table.\n");
        exit(1);
    }

    $cardTableId = $config['card_table_id'] ?? extract_card_table_id($cardTableUrl);

    foreach ($columns as $column) {
        $columnName = $column['name'] ?? $column['title'] ?? ('Column ' . ($column['id'] ?? 'unknown'));
        echo "\n# {$columnName}\n";

        $cards = fetch_cards_for_column($baseUrl, $config, $cardTableId, $column);

        if (!is_array($cards) || count($cards) === 0) {
            echo "(no cards)\n";
            continue;
        }

        foreach ($cards as $card) {
            $cardId = $card['id'] ?? 'unknown';
            $title = $card['title'] ?? $card['name'] ?? '(untitled)';
            echo "- [{$cardId}] {$title}\n";
        }
    }
}

function interactive_menu(string $baseUrl, array $config): void
{
    $project = fetch_project($baseUrl, $config);
    $cardTables = discover_card_tables($project, $config);

    if (count($cardTables) === 0) {
        fwrite(STDERR, "No card tables found for this project.\n");
        exit(1);
    }

    while (true) {
        clear_screen();
        $tableChoice = prompt_choice('Card Tables', array_map(fn ($t) => $t['title'], $cardTables), false);
        if ($tableChoice === 'quit') {
            exit(0);
        }

        $selectedTable = $cardTables[$tableChoice];
        $cardTable = api_get_json($selectedTable['url'], $config);
        $columns = extract_columns($cardTable, $config);
        if (count($columns) === 0) {
            echo "No columns found for this card table.\n";
            continue;
        }

        $cardTableId = $selectedTable['id'] ?? ($config['card_table_id'] ?? extract_card_table_id($selectedTable['url']));

        $goBackToTables = false;
        while (!$goBackToTables) {
            clear_screen();
            $columnChoice = prompt_choice('Columns', array_map(fn ($c) => column_label($c), $columns), true);
            if ($columnChoice === 'back') {
                $goBackToTables = true;
                continue;
            }
            if ($columnChoice === 'quit') {
                exit(0);
            }

            $column = $columns[$columnChoice];
            $cards = fetch_cards_for_column($baseUrl, $config, $cardTableId, $column);
            if (count($cards) === 0) {
                echo "No cards in this column.\n";
                continue;
            }

            $goBackToColumns = false;
            while (!$goBackToColumns) {
                clear_screen();
                $cardAction = prompt_cards_menu($cards);
                if ($cardAction['action'] === 'back') {
                    $goBackToColumns = true;
                    continue;
                }
                if ($cardAction['action'] === 'quit') {
                    exit(0);
                }
                if ($cardAction['action'] === 'refetch') {
                    $cards = fetch_cards_for_column($baseUrl, $config, $cardTableId, $column);
                    continue;
                }
                if ($cardAction['action'] === 'add_context_comment') {
                    $card = $cards[$cardAction['index']];
                    $ok = create_card_comment($baseUrl, $config, $card, 'Please can I have more context');
                    echo $ok ? "Comment added.\n" : "Failed to add comment.\n";
                    $action = prompt_back_or_quit();
                    if ($action === 'quit') {
                        exit(0);
                    }
                    continue;
                }

                if ($cardAction['action'] === 'move') {
                    $card = $cards[$cardAction['index']];
                    $currentColumnId = $column['id'] ?? null;
                    $otherColumns = array_values(array_filter($columns, fn ($c) => ($c['id'] ?? null) != $currentColumnId));
                    if (count($otherColumns) === 0) {
                        echo "No other columns to move to.\n";
                        $action = prompt_back_or_quit();
                        if ($action === 'quit') {
                            exit(0);
                        }
                        continue;
                    }
                    $columnLabels = array_map(fn ($c) => column_label($c), $otherColumns);
                    $destChoice = prompt_choice('Move card to column', $columnLabels, true);
                    if ($destChoice === 'back' || $destChoice === 'quit') {
                        if ($destChoice === 'quit') {
                            exit(0);
                        }
                        continue;
                    }
                    $destColumn = $otherColumns[$destChoice];
                    $ok = move_card_to_column($baseUrl, $config, $card, $destColumn['id']);
                    echo $ok ? "Card moved.\n" : "Failed to move card.\n";
                    $cards = fetch_cards_for_column($baseUrl, $config, $cardTableId, $column);
                    $action = prompt_back_or_quit();
                    if ($action === 'quit') {
                        exit(0);
                    }
                    continue;
                }

                if ($cardAction['action'] === 'details') {
                    $card = $cards[$cardAction['index']];
                    $details = fetch_card_details($baseUrl, $config, $selectedTable['url'], $card);
                    clear_screen();
                    display_card_details($details);

                    $action = prompt_back_or_quit();
                    if ($action === 'quit') {
                        exit(0);
                    }
                } elseif ($cardAction['action'] === 'add_to_prd') {
                    $selected = $cardAction['indexes'];
                    $selectedCards = [];
                    foreach ($selected as $idx) {
                        if (isset($cards[$idx])) {
                            $selectedCards[] = $cards[$idx];
                        }
                    }

                    if (count($selectedCards) === 0) {
                        echo "No valid cards selected.\n";
                        continue;
                    }

                    $detailsList = [];
                    foreach ($selectedCards as $card) {
                        $detailsList[] = fetch_card_details($baseUrl, $config, $selectedTable['url'], $card);
                    }

                    $prd = load_prd($config['prd_path']);
                    $result = claude_generate_epic_tasks($detailsList, $config, $prd);
                    $prd = append_prd_epic($prd, $result);
                    save_prd($config['prd_path'], $prd);

                    echo "Added epic and tasks to PRD.json.\n";
                    $action = prompt_back_or_quit();
                    if ($action === 'quit') {
                        exit(0);
                    }
                }
            }
        }
    }
}

function fetch_project(string $baseUrl, array $config): array
{
    $projectUrl = $baseUrl . '/projects/' . $config['project_id'] . '.json';
    return api_get_json($projectUrl, $config);
}

function resolve_card_table_url(array $project, array $config): ?string
{
    if (!empty($config['card_table_url'])) {
        return $config['card_table_url'];
    }

    if (!empty($config['card_table_id'])) {
        return build_card_table_url($config['account_id'], $config['project_id'], (string) $config['card_table_id']);
    }

    return find_card_table_url($project);
}

function build_card_table_url(int $accountId, int $projectId, string $cardTableId): string
{
    return 'https://3.basecampapi.com/' . $accountId . '/buckets/' . $projectId . '/card_tables/' . $cardTableId . '.json';
}

function discover_card_tables(array $project, array $config): array
{
    $tables = [];
    if (isset($project['dock']) && is_array($project['dock'])) {
        foreach ($project['dock'] as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = $tool['name'] ?? '';
            if ($name !== 'card_table' && $name !== 'kanban_board') {
                continue;
            }

            $url = $tool['url'] ?? null;
            if (!is_string($url)) {
                continue;
            }

            $title = $tool['title'] ?? $tool['name'] ?? 'Card Table';
            $id = $tool['id'] ?? extract_card_table_id($url);
            $tables[] = [
                'id' => $id,
                'title' => $title,
                'url' => $url,
            ];
        }
    }

    if (count($tables) === 0 && !empty($config['card_table_id'])) {
        $tables[] = [
            'id' => $config['card_table_id'],
            'title' => 'Card Table ' . $config['card_table_id'],
            'url' => build_card_table_url($config['account_id'], $config['project_id'], (string) $config['card_table_id']),
        ];
    }

    return $tables;
}

function prompt_choice(string $title, array $items, bool $allowBack): string|int
{
    echo "\n== {$title} ==\n";
    foreach ($items as $index => $label) {
        $number = $index + 1;
        echo "{$number}. {$label}\n";
    }

    $prompt = $allowBack ? 'Select number (b=back, q=quit): ' : 'Select number (q=quit): ';
    while (true) {
        $input = trim((string) readline($prompt));
        if ($input === 'q') {
            return 'quit';
        }
        if ($allowBack && $input === 'b') {
            return 'back';
        }
        if (ctype_digit($input)) {
            $idx = (int) $input - 1;
            if ($idx >= 0 && $idx < count($items)) {
                return $idx;
            }
        }
        echo "Invalid selection.\n";
    }
}

function prompt_cards_menu(array $cards): array
{
    echo "\n== Cards ==\n";
    foreach ($cards as $index => $card) {
        $number = $index + 1;
        echo "{$number}. " . card_label($card) . "\n";
    }
    echo "\nOptions:\n";
    echo "Enter a number to view details.\n";
    echo "Enter a comma-separated list to add to PRD (e.g., 1,3,5).\n";
    echo "ctx N = add \"Please can I have more context\" comment to card N.\n";
    echo "mv N = move card N to a different column.\n";
    echo "r = refetch list, b = back, q = quit\n";

    while (true) {
        $input = trim((string) readline('Selection: '));
        if ($input === 'q') {
            return ['action' => 'quit'];
        }
        if ($input === 'b') {
            return ['action' => 'back'];
        }
        if ($input === 'r') {
            return ['action' => 'refetch'];
        }
        if (preg_match('/^ctx\s+(\d+)$/i', $input, $m)) {
            $idx = (int) $m[1] - 1;
            if ($idx >= 0 && $idx < count($cards)) {
                return ['action' => 'add_context_comment', 'index' => $idx];
            }
        }
        if (preg_match('/^mv\s+(\d+)$/i', $input, $m)) {
            $idx = (int) $m[1] - 1;
            if ($idx >= 0 && $idx < count($cards)) {
                return ['action' => 'move', 'index' => $idx];
            }
        }

        if (ctype_digit($input)) {
            $idx = (int) $input - 1;
            if ($idx >= 0 && $idx < count($cards)) {
                return ['action' => 'details', 'index' => $idx];
            }
        }

        $indexes = parse_csv_indexes($input, count($cards));
        if (count($indexes) > 0) {
            return ['action' => 'add_to_prd', 'indexes' => $indexes];
        }

        echo "Invalid selection.\n";
    }
}

function prompt_back_or_quit(): string
{
    while (true) {
        $input = trim((string) readline('Press b to go back or q to quit: '));
        if ($input === 'b') {
            return 'back';
        }
        if ($input === 'q') {
            return 'quit';
        }
    }
}

function parse_csv_indexes(string $input, int $count): array
{
    $parts = array_filter(array_map('trim', explode(',', $input)), fn ($p) => $p !== '');
    if (count($parts) === 0) {
        return [];
    }

    $indexes = [];
    foreach ($parts as $part) {
        if (!ctype_digit($part)) {
            return [];
        }
        $idx = (int) $part - 1;
        if ($idx < 0 || $idx >= $count) {
            return [];
        }
        $indexes[] = $idx;
    }

    return array_values(array_unique($indexes));
}

function column_label(array $column): string
{
    return $column['name'] ?? $column['title'] ?? ('Column ' . ($column['id'] ?? 'unknown'));
}

function card_label(array $card): string
{
    $title = $card['title'] ?? $card['name'] ?? '(untitled)';
    $id = $card['id'] ?? 'unknown';
    return "[{$id}] {$title}";
}

function fetch_cards_for_column(string $baseUrl, array $config, $cardTableId, array $column): array
{
    if (isset($column['cards_url']) && is_string($column['cards_url'])) {
        $cards = api_get_json($column['cards_url'], $config);
        return is_array($cards) ? $cards : [];
    }

    if ($cardTableId !== null && isset($column['id'])) {
        return fetch_cards_by_guess($baseUrl, $config['project_id'], (string) $cardTableId, (string) $column['id'], $config);
    }

    return [];
}

function fetch_card_details(string $baseUrl, array $config, string $cardTableUrl, array $card): array
{
    if (isset($card['url']) && is_string($card['url'])) {
        return api_get_json($card['url'], $config);
    }

    $cardTableId = extract_card_table_id($cardTableUrl);
    if ($cardTableId !== null && isset($card['id'])) {
        $url = $baseUrl . '/buckets/' . $config['project_id'] . '/card_tables/' . $cardTableId . '/cards/' . $card['id'] . '.json';
        return api_get_json($url, $config);
    }

    return $card;
}

function display_card_details(array $card): void
{
    $title = $card['title'] ?? $card['name'] ?? '(untitled)';
    $id = $card['id'] ?? 'unknown';
    $status = $card['status'] ?? ($card['archived'] ?? null ? 'archived' : 'active');
    $created = $card['created_at'] ?? null;
    $updated = $card['updated_at'] ?? null;
    $description = $card['content'] ?? $card['description'] ?? $card['notes'] ?? null;
    $url = $card['url'] ?? null;

    echo "\n== Card Details ==\n";
    echo "ID: {$id}\n";
    echo "Title: {$title}\n";
    if ($status !== null) {
        echo "Status: {$status}\n";
    }
    if ($created !== null) {
        echo "Created: {$created}\n";
    }
    if ($updated !== null) {
        echo "Updated: {$updated}\n";
    }
    if ($url !== null) {
        echo "URL: {$url}\n";
    }
    if ($description !== null && is_string($description) && $description !== '') {
        echo "\nDescription:\n{$description}\n";
    }
}

function claude_generate_epic_tasks(array $cards, array $config, array $prd): array
{
    $prompt = build_claude_prompt($cards, $prd);
    $payload = [
        'model' => $config['claude_model'],
        'max_tokens' => 1200,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
    ];

    [$status, $body] = api_post_json('https://api.anthropic.com/v1/messages', $payload, [
        'x-api-key: ' . $config['claude_api_key'],
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ]);

    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "Claude request failed ({$status})\n");
        fwrite(STDERR, substr($body, 0, 500) . "\n");
        exit(1);
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['content']) || !is_array($json['content'])) {
        fwrite(STDERR, "Invalid Claude response\n");
        exit(1);
    }

    $text = '';
    foreach ($json['content'] as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $text .= $block['text'] ?? '';
        }
    }

    $result = extract_json_from_text($text);
    if (!is_array($result) || !isset($result['epic']) || !isset($result['tasks'])) {
        fwrite(STDERR, "Claude response missing epic/tasks\n");
        exit(1);
    }

    return $result;
}

function build_claude_prompt(array $cards, array $prd): string
{
    $ticketLines = [];
    foreach ($cards as $card) {
        $title = $card['title'] ?? $card['name'] ?? '(untitled)';
        $id = $card['id'] ?? 'unknown';
        $description = $card['content'] ?? $card['description'] ?? $card['notes'] ?? '';
        $ticketLines[] = "- ID: {$id}\n  Title: {$title}\n  Description: {$description}";
    }

    $ticketsText = implode("\n", $ticketLines);
    $prdJson = json_encode($prd, JSON_PRETTY_PRINT);
    if ($prdJson === false) {
        $prdJson = '[]';
    }

    return <<<PROMPT
You are helping convert Basecamp tickets into a PRD epic and tasks.

Return ONLY valid JSON with this exact shape:
{
  "epic": "string",
  "tasks": [
    {
      "id": "string",
      "title": "string",
      "passes": false,
      "scope": ["string"],
      "acceptance": ["string"]
    }
  ]
}

Rules:
- The epic should summarize the overall objective of the selected tickets.
- Create multiple tasks as needed to complete the tickets (one ticket can map to multiple tasks).
- Keep scope and acceptance concise, focused, and specific.
- Use lowercase, short, unique ids (e.g., "pm-1", "pm-2").
- Consider the existing PRD to avoid duplicating tasks or epics.

Existing PRD (JSON array):
{$prdJson}

Tickets:
{$ticketsText}
PROMPT;
}

function api_post_json(string $url, array $payload, array $headers): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "Unable to init curl\n");
        exit(1);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "Curl error for {$url}\n");
        exit(1);
    }

    return [$status, $body];
}

function extract_json_from_text(string $text): array
{
    $trimmed = trim($text);
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\\{.*\\}/s', $trimmed, $matches) !== 1) {
        return [];
    }

    $decoded = json_decode($matches[0], true);
    return is_array($decoded) ? $decoded : [];
}

function load_prd(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $json = json_decode($contents, true);
    return is_array($json) ? $json : [];
}

function save_prd(string $path, array $prd): void
{
    $payload = json_encode($prd, JSON_PRETTY_PRINT);
    if ($payload === false) {
        fwrite(STDERR, "Failed to serialize PRD\n");
        exit(1);
    }

    if (file_put_contents($path, $payload) === false) {
        fwrite(STDERR, "Failed to write PRD.json\n");
        exit(1);
    }
}

function append_prd_epic(array $prd, array $newEpic): array
{
    $existingIds = [];
    foreach ($prd as $epic) {
        if (!isset($epic['tasks']) || !is_array($epic['tasks'])) {
            continue;
        }
        foreach ($epic['tasks'] as $task) {
            if (isset($task['id'])) {
                $existingIds[$task['id']] = true;
            }
        }
    }

    if (isset($newEpic['tasks']) && is_array($newEpic['tasks'])) {
        foreach ($newEpic['tasks'] as &$task) {
            if (!isset($task['id']) || !is_string($task['id'])) {
                continue;
            }
            $task['id'] = ensure_unique_id($task['id'], $existingIds);
            $existingIds[$task['id']] = true;
        }
        unset($task);
    }

    $prd[] = $newEpic;
    return $prd;
}

function ensure_unique_id(string $id, array $existingIds): string
{
    if (!isset($existingIds[$id])) {
        return $id;
    }

    $i = 2;
    while (isset($existingIds[$id . '-' . $i])) {
        $i++;
    }
    return $id . '-' . $i;
}

function clear_screen(): void
{
    echo "\033[2J\033[H";
}

function find_card_table_url(array $project): ?string
{
    if (!isset($project['dock']) || !is_array($project['dock'])) {
        return null;
    }

    foreach ($project['dock'] as $tool) {
        if (!is_array($tool)) {
            continue;
        }

        $name = $tool['name'] ?? '';
        if ($name === 'card_table' || $name === 'kanban_board') {
            $url = $tool['url'] ?? null;
            if (is_string($url)) {
                return $url;
            }
        }
    }

    return null;
}

function extract_columns(array $cardTable, array $config): array
{
    if (isset($cardTable['lists_url']) && is_string($cardTable['lists_url'])) {
        return api_get_json($cardTable['lists_url'], $config);
    }

    if (isset($cardTable['columns_url']) && is_string($cardTable['columns_url'])) {
        return api_get_json($cardTable['columns_url'], $config);
    }

    if (isset($cardTable['lists']) && is_array($cardTable['lists'])) {
        return $cardTable['lists'];
    }

    if (isset($cardTable['columns']) && is_array($cardTable['columns'])) {
        return $cardTable['columns'];
    }

    return [];
}

function extract_card_table_id(string $cardTableUrl): ?string
{
    if (preg_match('#/card_tables/(\\d+)#', $cardTableUrl, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

function fetch_cards_by_guess(string $baseUrl, int $projectId, string $cardTableId, string $columnId, array $config): array
{
    $candidates = [
        $baseUrl . '/buckets/' . $projectId . '/card_tables/' . $cardTableId . '/lists/' . $columnId . '/cards.json',
        $baseUrl . '/buckets/' . $projectId . '/card_tables/' . $cardTableId . '/columns/' . $columnId . '/cards.json',
    ];

    foreach ($candidates as $url) {
        [$status, $body] = api_get($url, $config);
        if ($status >= 200 && $status < 300) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                return $json;
            }
        }
    }

    return [];
}
