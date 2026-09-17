<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'id',
        'email',
        'username',
        'password',
        'role',
        'active',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $validationRules  = [
        'email'    => 'required|valid_email|is_unique[users.email,id,{id}]',
        'username' => 'required|min_length[3]|is_unique[users.username,id,{id}]',
        'password' => 'required|min_length[8]',
    ];
    protected $skipValidation   = false;
    protected $beforeInsert     = ['ensureUuid', 'hashPassword'];
    protected $beforeUpdate     = ['hashPassword'];

    protected function ensureUuid(array $data): array
    {
        if (empty($data['data'][$this->primaryKey])) {
            $data['data'][$this->primaryKey] = $this->generateUuid();
        }

        return $data;
    }

    protected function hashPassword(array $data): array
    {
        if (! empty($data['data']['password'])) {
            $data['data']['password'] = password_hash($data['data']['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['data']['password']);
        }

        return $data;
    }

    /**
     * Role names assigned via the user_user_roles pivot table.
     *
     * @return list<string>
     */
    public function getPivotRoleNames(string $userId): array
    {
        try {
            $rows = $this->db->table('user_user_roles')
                ->select('user_roles.name')
                ->join('user_roles', 'user_roles.id = user_user_roles.user_role_id')
                ->where('user_user_roles.user_id', $userId)
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            // Table may not exist yet on older installs; fall back to legacy column.
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            if (isset($row['name']) && is_string($row['name'])) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * Attach a pivot role to a user by role name (no-op when missing).
     */
    public function attachRoleByName(string $userId, string $roleName): bool
    {
        try {
            $role = $this->db->table('user_roles')
                ->where('name', $roleName)
                ->get()
                ->getRowArray();

            if (! $role) {
                return false;
            }

            $exists = $this->db->table('user_user_roles')
                ->where('user_id', $userId)
                ->where('user_role_id', $role['id'])
                ->countAllResults();

            if ($exists > 0) {
                return true;
            }

            return (bool) $this->db->table('user_user_roles')->insert([
                'id'           => $this->generateUuid(),
                'user_id'      => $userId,
                'user_role_id' => $role['id'],
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
