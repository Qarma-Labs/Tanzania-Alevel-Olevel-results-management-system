<?php

namespace App\Filters;

use App\Libraries\Authorization;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route-level RBAC enforcement.
 *
 * Usage in Routes.php:
 *   $routes->get('settings', 'SettingsController::index', ['filter' => ['auth', 'role:admin,head_of_school']]);
 *
 * Admin always passes. Unknown/empty role arguments deny by default.
 */
class RoleGuard implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('isLoggedIn')) {
            $session->setFlashdata('error', 'Please log in to continue.');

            return redirect()->to('/login');
        }

        $required = $this->parseArguments($arguments);

        // Deny by default: a `role` filter without roles must never open access.
        if ($required === []) {
            return $this->forbidden($request, 'Access denied: insufficient permissions.');
        }

        $userRoles = $session->get('roles');
        if (! is_array($userRoles) || $userRoles === []) {
            // Backwards compatibility for sessions created before `roles` existed.
            $legacy = $session->get('role');
            $userRoles = $legacy !== null ? [$legacy] : [];
        }

        if (! Authorization::hasRole($userRoles, $required)) {
            return $this->forbidden($request, 'Access denied: insufficient permissions.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing required.
    }

    /**
     * @param mixed $arguments
     * @return list<string>
     */
    private function parseArguments($arguments): array
    {
        if (is_string($arguments)) {
            $arguments = explode(',', $arguments);
        }

        if (! is_array($arguments)) {
            return [];
        }

        // CodeIgniter may pass ['admin,teacher'] as a single element.
        $flat = [];
        foreach ($arguments as $item) {
            if (! is_string($item)) {
                continue;
            }
            foreach (explode(',', $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $flat[] = $part;
                }
            }
        }

        return Authorization::normalizeRoles($flat);
    }

    private function forbidden(RequestInterface $request, string $message)
    {
        // AJAX / fetch / API callers get JSON, page navigations get redirected.
        $isAjax = strtolower((string) $request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || stripos((string) $request->getHeaderLine('Accept'), 'application/json') !== false;

        if ($isAjax) {
            return service('response')->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => $message,
            ]);
        }

        return redirect()->to('/dashboard')->with('error', $message);
    }
}
