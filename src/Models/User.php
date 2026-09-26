<?php

namespace Nuhsait\Ci4SimpleAuth\Models;

use CodeIgniter\Model;
use Nuhsait\Ci4SimpleAuth\Config\SimpleAuth;
use Nuhsait\Ci4SimpleAuth\Entities\AuthUser;
use Nuhsait\Ci4SimpleAuth\IdentityFields;

/**
 * Model for the auth_user table. Returns rows as AuthUser entities.
 *
 * The password is given in plain text through the `password` field; the model
 * hashes it with bcrypt into the `password_hash` column. Identity fields are
 * normalized before saving (lowercase, spaces/dashes/parentheses in phone, etc.).
 */
class User extends Model
{
    protected $table            = 'auth_user';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = AuthUser::class;
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Validation
    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks    = true;
    protected $beforeInsert      = ['hashPassword'];
    protected $beforeUpdate      = ['hashPassword'];
    protected $beforeInsertBatch = ['hashPasswordBatch'];
    protected $beforeUpdateBatch = ['hashPasswordBatch'];

    protected SimpleAuth $authConfig;

    protected function initialize()
    {
        $this->authConfig = config('SimpleAuth');
        $this->authConfig->assertConfigured();

        // password_hash cannot be written directly, it only comes from `password` through the callback.
        $this->allowedFields   = ['name', 'password', ...array_keys($this->authConfig->fields)];
        $this->validationRules = $this->buildRules();
    }

    /**
     * Finds a user by the login field for Basic Auth.
     */
    public function findForLogin(string $value): ?AuthUser
    {
        $field = $this->authConfig->loginField;
        $value = IdentityFields::normalize($field, $value, $this->authConfig);

        if ($value === '') {
            return null;
        }

        return $this->where($field, $value)->first();
    }

    public function insertBatch(?array $set = null, ?bool $escape = null, int $batchSize = 100, bool $testing = false)
    {
        // is_unique cannot catch duplicates within the same batch; the database unique constraint does.
        return $this->withRules($this->buildRules(unique: false), fn () => parent::insertBatch($set, $escape, $batchSize, $testing));
    }

    public function update($id = null, $row = null): bool
    {
        $ids = $id === null ? [] : (array) $id;

        // When updating a single row its own value is excluded from is_unique.
        // For multi-row updates the database enforces uniqueness.
        $rules = count($ids) === 1
            ? $this->buildRules(ignoreId: (int) reset($ids))
            : $this->buildRules(unique: false);

        return $this->withRules($rules, fn () => parent::update($id, $row));
    }

    public function updateBatch(?array $set = null, ?string $index = null, int $batchSize = 100, bool $returnSQL = false)
    {
        return $this->withRules($this->buildRules(unique: false), fn () => parent::updateBatch($set, $index, $batchSize, $returnSQL));
    }

    protected function hashPassword(array $data): array
    {
        $data['data'] = $this->hashRow($data['data']);

        return $data;
    }

    protected function hashPasswordBatch(array $data): array
    {
        $data['data'] = array_map($this->hashRow(...), $data['data']);

        return $data;
    }

    private function hashRow(array $row): array
    {
        if (isset($row['password'])) {
            $row['password_hash'] = password_hash($row['password'], PASSWORD_BCRYPT, ['cost' => $this->authConfig->hashCost]);
            unset($row['password']);
        }

        return $row;
    }

    /**
     * CI4 converts data (array, entity or object) to an array here before
     * validation in insert/update/batch. Normalizing here means validation
     * always sees the normalized value, whatever form the data came in.
     */
    protected function transformDataToArray($row, string $type): array
    {
        return $this->normalizeRow(parent::transformDataToArray($row, $type));
    }

    private function normalizeRow(array $row): array
    {
        if (isset($row['name']) && is_string($row['name'])) {
            $row['name'] = trim($row['name']);
        }

        foreach ($this->authConfig->fields as $field => $options) {
            if (! array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field] === null ? '' : IdentityFields::normalize($field, (string) $row[$field], $this->authConfig);

            // Empty values in nullable fields are stored as NULL, otherwise they would hit the unique constraint.
            $row[$field] = ($value === '' && $options['nullable']) ? null : $value;
        }

        return $row;
    }

    private function buildRules(?int $ignoreId = null, bool $unique = true): array
    {
        $rules = [
            'name' => [
                'label' => 'name',
                'rules' => 'required|max_length[64]',
            ],
            'password' => [
                'label' => 'password',
                'rules' => 'required|simpleauth_password',
            ],
        ];

        foreach ($this->authConfig->fields as $field => $options) {
            $fieldRules = [
                $options['nullable'] ? 'permit_empty' : 'required',
                match ($field) {
                    'phone'           => 'simpleauth_phone',
                    'identity_number' => 'simpleauth_identity_number',
                    'email'           => 'valid_email',
                    'username'        => 'simpleauth_username',
                },
                'max_length[' . $options['length'] . ']',
            ];

            if ($unique) {
                $fieldRules[] = $ignoreId === null
                    ? "is_unique[{$this->table}.{$field}]"
                    : "is_unique[{$this->table}.{$field},{$this->primaryKey},{$ignoreId}]";
            }

            $rules[$field] = [
                'label' => $field,
                'rules' => implode('|', $fieldRules),
            ];
        }

        return $rules;
    }

    /**
     * Swaps the rules for the duration of the call, then restores the defaults.
     */
    private function withRules(array $rules, callable $callback): mixed
    {
        $this->validationRules = $rules;

        try {
            return $callback();
        } finally {
            $this->validationRules = $this->buildRules();
        }
    }
}
