<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedDefaultUserRoles extends Migration
{
    public function up()
    {
        $this->seedRoles();
        $this->backfillUserRoles();
    }

    public function down()
    {
        // Seed data only; nothing structural to roll back.
    }

    private function seedRoles(): void
    {
        $roles = [
            ['admin', 'Full system access (superuser).', 'system'],
            ['head_of_school', 'Head of School: manages exams, allocations, settings and publishes results.', 'school'],
            ['teacher', 'Teacher: enters and views marks and results within their school.', 'school'],
        ];

        foreach ($roles as [$name, $description, $type]) {
            $exists = $this->db->table('user_roles')->where('name', $name)->countAllResults();
            if ($exists > 0) {
                continue;
            }

            $this->db->table('user_roles')->insert([
                'id'          => $this->generateUuid(),
                'name'        => $name,
                'description' => $description,
                'type'        => $type,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function backfillUserRoles(): void
    {
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
