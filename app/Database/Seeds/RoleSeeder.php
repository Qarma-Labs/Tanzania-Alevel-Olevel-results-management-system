<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            [
                'name'        => 'admin',
                'description' => 'Full system access (superuser, bypasses role checks and school scoping).',
                'type'        => 'system',
            ],
            [
                'name'        => 'head_of_school',
                'description' => 'Head of School: manages exams, allocations, settings and publishes results.',
                'type'        => 'school',
            ],
            [
                'name'        => 'teacher',
                'description' => 'Teacher: enters and views marks and results within their school.',
                'type'        => 'school',
            ],
        ];

        foreach ($roles as $role) {
            $exists = $this->db->table('user_roles')->where('name', $role['name'])->countAllResults();
            if ($exists > 0) {
                continue;
            }

            $this->db->table('user_roles')->insert([
                'id'          => $this->generateUuid(),
                'name'        => $role['name'],
                'description' => $role['description'],
                'type'        => $role['type'],
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        // Backfill: every user without a pivot row gets one from their legacy
        // users.role column (legacy "user" maps to "teacher").
        $users = $this->db->table('users')->select('id, role')->get()->getResultArray();
        foreach ($users as $user) {
            $count = $this->db->table('user_user_roles')
                ->where('user_id', $user['id'])
                ->countAllResults();
            if ($count > 0) {
                continue;
            }

            $legacy = strtolower(trim((string) ($user['role'] ?? '')));
            $roleName = $legacy === 'admin' ? 'admin' : 'teacher';

            $roleRow = $this->db->table('user_roles')->where('name', $roleName)->get()->getRowArray();
            if (! $roleRow) {
                continue;
            }

            $this->db->table('user_user_roles')->insert([
                'id'           => $this->generateUuid(),
                'user_id'      => $user['id'],
                'user_role_id' => $roleRow['id'],
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
