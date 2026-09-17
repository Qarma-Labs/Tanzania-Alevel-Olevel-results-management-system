<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

abstract class BaseModel extends Model
{
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $beforeInsert = ['applyCreateAudit'];
    protected $beforeUpdate = ['applyUpdateAudit'];
    protected $beforeFind   = ['applyOwnershipScope'];

    protected function applyCreateAudit(array $data): array
    {
        $data = $this->ensureIdentifier($data);

        $userUuid = $this->resolveCurrentUserUuid();
        if ($userUuid) {
            $data['data']['created_by'] = $data['data']['created_by'] ?? $userUuid;
            $data['data']['updated_by'] = $data['data']['updated_by'] ?? $userUuid;
        }

        // Only set school_id if the model doesn't skip it
        if (!isset($this->skipSchoolId) || !$this->skipSchoolId) {
            $schoolId = $this->resolveCurrentSchoolUuid();
            if ($schoolId && empty($data['data']['school_id'])) {
                $data['data']['school_id'] = $schoolId;
            }
        }

        return $data;
    }

    protected function applyUpdateAudit(array $data): array
    {
        $userUuid = $this->resolveCurrentUserUuid();
        if ($userUuid) {
            $data['data']['updated_by'] = $userUuid;
        }

        // Only set school_id if the model doesn't skip it
        if (!isset($this->skipSchoolId) || !$this->skipSchoolId) {
            $schoolId = $this->resolveCurrentSchoolUuid();
            if ($schoolId && empty($data['data']['school_id'])) {
                $data['data']['school_id'] = $schoolId;
            }
        }

        return $data;
    }

    protected function applyOwnershipScope(array $data): array
    {
        $schoolId = $this->resolveCurrentSchoolUuid();

        if ($this->resolveCurrentUserIsAdmin() || !$schoolId) {
            return $data;
        }

        if (isset($data['builder']) && $data['builder'] instanceof BaseBuilder) {
            $data['builder']->where($this->table . '.school_id', $schoolId);
            return $data;
        }

        $data['where'][$this->table . '.school_id'] = $schoolId;

        return $data;
    }

    protected function ensureIdentifier(array $data): array
    {
        if (! isset($data['data'][$this->primaryKey]) || empty($data['data'][$this->primaryKey])) {
            $data['data'][$this->primaryKey] = $this->generateUuid();
        }

        return $data;
    }

    protected function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant

        $hex = bin2hex($bytes);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    protected function resolveCurrentUserUuid(): ?string
    {
        $session = service('session');
        if (! $session) {
            return null;
        }

        $uuid = $session->get('user_uuid');
        if (is_string($uuid) && $this->isValidUuid($uuid)) {
            return $uuid;
        }

        $userId = $session->get('user_id');
        if (empty($userId)) {
            return null;
        }

        return $this->uuidFromValue((string) $userId);
    }

    protected function resolveCurrentSchoolUuid(): ?string
    {
        $session = service('session');
        if (! $session) {
            return null;
        }

        $schoolId = $session->get('school_id');
        if (empty($schoolId)) {
            return null;
        }

        if (is_string($schoolId) && $this->isValidUuid($schoolId)) {
            return $schoolId;
        }

        return $this->uuidFromValue((string) $schoolId);
    }

    protected function resolveCurrentUserRole(): ?string
    {
        $roles = $this->resolveCurrentUserRoles();

        return $roles[0] ?? null;
    }

    /**
     * @return list<string>
     */
    protected function resolveCurrentUserRoles(): array
    {
        $session = service('session');
        if (! $session) {
            return [];
        }

        $roles = $session->get('roles');
        if (is_array($roles) && $roles !== []) {
            return \App\Libraries\Authorization::normalizeRoles($roles);
        }

        return \App\Libraries\Authorization::normalizeRoles($session->get('role'));
    }

    protected function resolveCurrentUserIsAdmin(): bool
    {
        return \App\Libraries\Authorization::isAdmin($this->resolveCurrentUserRoles());
    }

    protected function uuidFromValue(string $value): string
    {
        $hash = md5('audit-owner:' . $value);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hash, 4));
    }

    protected function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/'
            ,
            $uuid
        );
    }
}
