<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;
use Psr\Log\LoggerInterface;

/**
 * Class BaseController
 *
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 * Extend this class in any new controllers:
 *     class Home extends BaseController
 *
 * For security be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var list<string>
     */
    protected $helpers = [];

    protected ?string $currentUserUuid = null;
    protected ?string $currentSchoolId = null;
    protected ?string $currentUserRole = null;

    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */
    protected Session $session;

    /**
     * @return void
     */
    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger,
    ) {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        $this->session = service("session");
        $this->currentUserUuid = $this->resolveCurrentUserUuid();
        $this->currentSchoolId = $this->resolveCurrentSchoolUuid();
        $this->currentUserRole = $this->resolveCurrentUserRole();
    }

    protected function resolveCurrentUserUuid(): ?string
    {
        $uuid = $this->session->get('user_uuid');

        if (is_string($uuid) && $this->isValidUuid($uuid)) {
            return $uuid;
        }

        $userId = $this->session->get('user_id');

        if (empty($userId)) {
            return null;
        }

        return $this->uuidFromValue((string) $userId);
    }

    protected function resolveCurrentSchoolUuid(): ?string
    {
        $schoolId = $this->session->get('school_id');

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
        $roles = $this->session->get('roles');
        if (is_array($roles) && $roles !== []) {
            return \App\Libraries\Authorization::primaryRole($roles);
        }

        $role = $this->session->get('role');

        return is_string($role) ? $role : null;
    }

    /**
     * Current user's normalized roles (pivot + legacy column merged at login).
     *
     * @return list<string>
     */
    protected function getCurrentUserRoles(): array
    {
        $roles = $this->session->get('roles');
        if (is_array($roles) && $roles !== []) {
            return \App\Libraries\Authorization::normalizeRoles($roles);
        }

        return \App\Libraries\Authorization::normalizeRoles(
            $this->session->get('role')
        );
    }

    protected function hasRole(string|array $required): bool
    {
        return \App\Libraries\Authorization::hasRole(
            $this->getCurrentUserRoles(),
            $required
        );
    }

    protected function isAdmin(): bool
    {
        return \App\Libraries\Authorization::isAdmin($this->getCurrentUserRoles());
    }

    protected function getCurrentUserUuid(): ?string
    {
        return $this->currentUserUuid;
    }

    protected function getCurrentSchoolId(): ?string
    {
        return $this->currentSchoolId;
    }

    protected function getCurrentUserRole(): ?string
    {
        return $this->currentUserRole;
    }

    protected function isValidUuid(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }

        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $uuid
        );
    }

    protected function uuidFromValue(string $value): string
    {
        $hash = md5('controller-context:' . $value);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hash, 4));
    }

    protected function applySchoolScopeToBuilder(BaseBuilder $builder, ?string $tableAlias = null): BaseBuilder
    {
        if ($this->isAdmin() || empty($this->currentSchoolId)) {
            return $builder;
        }

        $column = ($tableAlias ?? $builder->getTable()) . '.school_id';

        return $builder->where($column, $this->currentSchoolId);
    }

    protected function withAuditForInsert(array $data): array
    {
        if (! isset($data['id']) || empty($data['id'])) {
            $data['id'] = $this->generateUuid();
        }

        if (! isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        $data['updated_at'] = $data['updated_at'] ?? $data['created_at'];

        if ($this->currentSchoolId && empty($data['school_id'])) {
            $data['school_id'] = $this->currentSchoolId;
        }

        if ($this->currentUserUuid) {
            $data['created_by'] = $data['created_by'] ?? $this->currentUserUuid;
            $data['updated_by'] = $data['updated_by'] ?? $this->currentUserUuid;
        }

        return $data;
    }

    protected function withAuditForUpdate(array $data): array
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($this->currentSchoolId && empty($data['school_id'])) {
            $data['school_id'] = $this->currentSchoolId;
        }

        if ($this->currentUserUuid) {
            $data['updated_by'] = $this->currentUserUuid;
        }

        return $data;
    }

    protected function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
