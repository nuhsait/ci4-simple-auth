<?php

namespace Nuhsait\Ci4SimpleAuth\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Nuhsait\Ci4SimpleAuth\IdentityFields;
use Nuhsait\Ci4SimpleAuth\IdentityNumber\Registry;
use Throwable;

/**
 * Interactively generates the auth_user migration and app/Config/SimpleAuth.php.
 * Intended for the initial installation only.
 */
class Setup extends BaseCommand
{
    protected $group       = 'SimpleAuth';
    protected $name        = 'simpleauth:setup';
    protected $description = 'Generates the migration and config files for the auth_user table.';
    protected $usage       = 'simpleauth:setup';

    private const TABLE           = 'auth_user';
    private const MIGRATION_GLOB  = '*_CreateAuthUserTable.php';
    private const MIGRATION_NAME  = '_CreateAuthUserTable.php';
    private const MIN_LENGTH      = 1;
    private const MAX_LENGTH      = 255;
    private const SAFE_COST_MIN   = 10;
    private const SAFE_COST_MAX   = 14;

    private const FIELD_INFO = [
        'phone'           => 'Phone (international E.164 format, e.g. +905321234567)',
        'identity_number' => 'Identity number (validated per country)',
        'email'           => 'Email (converted to lowercase)',
        'username'        => 'Username (a-z only, converted to lowercase)',
    ];

    private const LENGTH_INFO = [
        'email'    => ['default' => 254, 'text' => 'Length (per RFC 5321 an email address is at most 254 characters)'],
        'username' => ['default' => 32, 'text' => 'Length (maximum number of characters in a username)'],
    ];

    public function run(array $params)
    {
        $filesToDelete = $this->checkExisting();

        if ($filesToDelete === null) {
            return EXIT_ERROR;
        }

        $previous = [];

        while (true) {
            [$fields, $identityCountry] = $this->askFields($previous);
            $previous                   = $fields;

            $result = $this->checkFields($fields);

            if ($result === 'cancel') {
                CLI::write('Setup cancelled.', 'yellow');

                return EXIT_ERROR;
            }

            if ($result === 'back') {
                continue;
            }

            $fields = $result;

            break;
        }

        $loginField      = $this->askLoginField($fields);
        $hashCost        = $this->askHashCost();
        $maxAttempts     = $this->askInt('Failed attempt limit', 5, 1, 1000);
        $cooldownMinutes = $this->askInt('Cooldown (minutes)', 15, 1, 1440);

        $data = [
            'fields'          => array_map(static fn (array $o) => ['nullable' => $o['nullable'], 'length' => $o['length']], $fields),
            'loginField'      => $loginField,
            'identityCountry' => $identityCountry,
            'hashCost'        => $hashCost,
            'maxAttempts'     => $maxAttempts,
            'cooldownMinutes' => $cooldownMinutes,
        ];

        $this->printSummary($data);

        if (! $this->askYesNo('Do you confirm?')) {
            CLI::write('Setup cancelled.', 'yellow');

            return EXIT_ERROR;
        }

        foreach ($filesToDelete as $file) {
            unlink($file);
            CLI::write('Deleted: ' . clean_path($file), 'yellow');
        }

        $this->writeFiles($data);

        CLI::newLine();
        CLI::write('Setup complete. To create the table run: php spark migrate', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * Checks for an existing installation.
     * Returns null if setup cannot continue (the table exists), otherwise the files to delete.
     *
     * @return list<string>|null
     */
    private function checkExisting(): ?array
    {
        try {
            $tableExists = db_connect()->tableExists(self::TABLE, false);
        } catch (Throwable $e) {
            CLI::error('Could not connect to the database: ' . $e->getMessage());

            return null;
        }

        if ($tableExists) {
            CLI::error('The "' . self::TABLE . '" table already exists.');
            CLI::write('The table must be removed before running setup again: php spark migrate:rollback');
            CLI::write('WARNING: This deletes every user in the table.', 'light_red');

            return null;
        }

        $files = glob(APPPATH . 'Database/Migrations/' . self::MIGRATION_GLOB) ?: [];

        if (is_file(APPPATH . 'Config/SimpleAuth.php')) {
            $files[] = APPPATH . 'Config/SimpleAuth.php';
        }

        if ($files === []) {
            return [];
        }

        CLI::write('Previously generated SimpleAuth files were found:', 'yellow');

        foreach ($files as $file) {
            CLI::write('  ' . clean_path($file));
        }

        CLI::newLine();
        CLI::write('If you continue, all of these files will be deleted and generated again.', 'light_red');
        CLI::write('If a database depends on these files (e.g. the migration was run in another environment) its structure may break.', 'light_red');

        foreach (['Are you sure?', 'Are you really sure?'] as $question) {
            if (strtolower(trim(CLI::prompt($question . ' Type "yes" to continue'))) !== 'yes') {
                CLI::write('Setup cancelled.', 'yellow');

                return null;
            }
        }

        CLI::write('The files will be deleted at the end of setup, after your confirmation.', 'yellow');

        return $files;
    }

    /**
     * Asks about each field in turn. Previous answers are offered as defaults.
     *
     * @param array<string, array{selected: bool, nullable: bool, length: int}> $previous
     *
     * @return array{0: array<string, array{selected: bool, nullable: bool, length: int}>, 1: string|null}
     */
    private function askFields(array $previous): array
    {
        $fields          = [];
        $identityCountry = null;

        foreach (IdentityFields::ALL as $field) {
            $prev = $previous[$field] ?? null;

            CLI::newLine();
            CLI::write('[' . $field . '] ' . self::FIELD_INFO[$field], 'light_cyan');

            if (! $this->askYesNo('Include this field?', $prev['selected'] ?? null)) {
                $fields[$field] = ['selected' => false, 'nullable' => false, 'length' => 0];

                continue;
            }

            if ($field === 'identity_number') {
                $identityCountry = $this->askCountry();
                $length          = Registry::get($identityCountry)->length();
            }

            $nullable = $this->askYesNo('Can it be left empty?', $prev['nullable'] ?? null);

            $length = match ($field) {
                'phone'           => IdentityFields::PHONE_LENGTH,
                'identity_number' => $length,
                default           => $this->askInt(
                    self::LENGTH_INFO[$field]['text'],
                    $prev !== null && $prev['selected'] ? $prev['length'] : self::LENGTH_INFO[$field]['default'],
                    self::MIN_LENGTH,
                    self::MAX_LENGTH,
                ),
            };

            if ($field === 'phone') {
                CLI::write('Length: ' . $length . ' (fixed for E.164)');
            } elseif ($field === 'identity_number') {
                CLI::write('Length: ' . $length . ' (from the country rule)');
            }

            $fields[$field] = ['selected' => true, 'nullable' => $nullable, 'length' => $length];
        }

        return [$fields, $identityCountry];
    }

    /**
     * Validates the field selection.
     *
     * @return 'back'|'cancel'|array<string, array{selected: bool, nullable: bool, length: int}>
     */
    private function checkFields(array $fields): array|string
    {
        $selected = array_filter($fields, static fn (array $o) => $o['selected']);

        CLI::newLine();

        if ($selected === []) {
            CLI::error('You must select at least one field.');

            return $this->askBackOrCancel();
        }

        if (count($selected) === 1) {
            $field                        = array_key_first($selected);
            $selected[$field]['nullable'] = false;

            CLI::write('Since only one field was selected, "' . $field . '" is required and is your login field.', 'yellow');

            return $selected;
        }

        if (array_filter($selected, static fn (array $o) => ! $o['nullable']) === []) {
            CLI::error('All fields were marked as optional. At least one field must be required to serve as the login field.');

            return $this->askBackOrCancel();
        }

        return $selected;
    }

    private function askBackOrCancel(): string
    {
        while (true) {
            $answer = trim(CLI::prompt('1) Go back  2) Cancel'));

            if ($answer === '1') {
                return 'back';
            }

            if ($answer === '2') {
                return 'cancel';
            }
        }
    }

    private function askCountry(): string
    {
        $codes   = Registry::codes();
        $default = count($codes) === 1 ? $codes[0] : null;

        while (true) {
            $answer = strtolower(trim(CLI::prompt('Country code (supported: ' . implode(', ', $codes) . ')', $default)));

            if (Registry::has($answer)) {
                CLI::write(Registry::get($answer)->label());

                return $answer;
            }

            CLI::error('Unsupported country code: ' . $answer);
        }
    }

    /**
     * The login field is chosen by number, among required fields only.
     */
    private function askLoginField(array $fields): string
    {
        $required = array_keys(array_filter($fields, static fn (array $o) => ! $o['nullable']));

        if (count($fields) === 1) {
            // The message was already shown by checkFields().
            return $required[0];
        }

        CLI::newLine();

        if (count($required) === 1) {
            CLI::write('Since "' . $required[0] . '" is the only required field, it is your login field.', 'yellow');

            return $required[0];
        }

        CLI::write('Select the login field (required fields only):', 'light_cyan');

        foreach ($required as $i => $field) {
            CLI::write('  ' . ($i + 1) . ') ' . $field);
        }

        return $required[$this->askInt('Your choice', null, 1, count($required)) - 1];
    }

    private function askHashCost(): int
    {
        CLI::newLine();
        CLI::write('bcrypt cost', 'light_cyan');
        CLI::write('  Reference: 4 → ~1 ms | 10 → ~60 ms | 12 → ~250 ms | 14 → ~1 s');
        CLI::write('  Times are for a typical server; each +1 doubles the time.');
        CLI::write('  Basic Auth verifies on every request, so this time is added to every request.');

        while (true) {
            $cost = $this->askInt('Cost (4–31)', 12, 4, 31);

            if ($cost < self::SAFE_COST_MIN) {
                CLI::write('Warning: values below ' . self::SAFE_COST_MIN . ' are insecure; if the database leaks, passwords can be cracked quickly.', 'light_red');
            } elseif ($cost > self::SAFE_COST_MAX) {
                CLI::write('Warning: with this value every request may take seconds.', 'light_red');
            } else {
                return $cost;
            }

            if ($this->askYesNo('Continue with this value?')) {
                return $cost;
            }
        }
    }

    private function askInt(string $question, ?int $default, int $min, int $max): int
    {
        while (true) {
            $answer = trim(CLI::prompt($question, $default === null ? null : (string) $default));

            if (preg_match('/^\d+$/', $answer) === 1 && (int) $answer >= $min && (int) $answer <= $max) {
                return (int) $answer;
            }

            CLI::error('Enter a number between ' . $min . ' and ' . $max . '.');
        }
    }

    private function askYesNo(string $question, ?bool $default = null): bool
    {
        $defaultText = $default === null ? null : ($default ? 'y' : 'n');

        while (true) {
            $answer = strtolower(trim(CLI::prompt($question . ' (y/n)', $defaultText)));

            if (in_array($answer, ['y', 'yes'], true)) {
                return true;
            }

            if (in_array($answer, ['n', 'no'], true)) {
                return false;
            }
        }
    }

    private function printSummary(array $data): void
    {
        CLI::newLine();
        CLI::write('Summary', 'light_cyan');
        CLI::write('  Table          : ' . self::TABLE);
        CLI::write('  Fixed columns  : id, name, password_hash, created_at, updated_at');

        foreach ($data['fields'] as $field => $options) {
            $extra = $field === 'identity_number' ? ', country: ' . $data['identityCountry'] : '';

            CLI::write(sprintf(
                '  %-15s: %s, length %d%s%s',
                $field,
                $options['nullable'] ? 'optional' : 'required',
                $options['length'],
                $extra,
                $field === $data['loginField'] ? ', LOGIN FIELD' : '',
            ));
        }

        CLI::write('  bcrypt cost    : ' . $data['hashCost']);
        $minutes = $data['cooldownMinutes'] . ' minute' . ($data['cooldownMinutes'] === 1 ? '' : 's');
        $attempts = $data['maxAttempts'] . ' failed attempt' . ($data['maxAttempts'] === 1 ? '' : 's');

        CLI::write('  Rate limit     : ' . $attempts . ' in ' . $minutes . ' → locked for ' . $minutes);
        CLI::newLine();
    }

    private function writeFiles(array $data): void
    {
        $data['date']      = date('Y-m-d H:i:s');
        $data['table']     = self::TABLE;
        $data['namespace'] = APP_NAMESPACE;

        $migrationDir = APPPATH . 'Database/Migrations/';

        if (! is_dir($migrationDir)) {
            mkdir($migrationDir, 0755, true);
        }

        $files = [
            $migrationDir . date('Y-m-d-His') . self::MIGRATION_NAME => 'migration.tpl.php',
            APPPATH . 'Config/SimpleAuth.php'                     => 'config.tpl.php',
        ];

        foreach ($files as $path => $template) {
            file_put_contents($path, $this->render($template, $data));
            CLI::write('Created: ' . clean_path($path), 'green');
        }
    }

    private function render(string $template, array $data): string
    {
        extract($data);

        ob_start();

        require __DIR__ . '/Views/' . $template;

        return str_replace('<@php', '<?php', ob_get_clean());
    }
}
