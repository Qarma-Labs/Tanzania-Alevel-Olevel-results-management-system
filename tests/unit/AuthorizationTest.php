<?php

use App\Libraries\Authorization;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AuthorizationTest extends CIUnitTestCase
{
    public function testNormalizesLegacyAndAliasRoles(): void
    {
        $this->assertSame('teacher', Authorization::normalizeRole('user'));
        $this->assertSame('admin', Authorization::normalizeRole('Administrator'));
        $this->assertSame('head_of_school', Authorization::normalizeRole('Head of School'));
        $this->assertSame('head_of_school', Authorization::normalizeRole('head'));
        $this->assertNull(Authorization::normalizeRole('not-a-role'));
    }

    public function testTeacherCannotSatisfyAdminGuard(): void
    {
        $this->assertFalse(Authorization::hasRole(['teacher'], ['admin', 'head_of_school']));
        $this->assertTrue(Authorization::hasRole(['teacher'], ['admin', 'head_of_school', 'teacher']));
    }

    public function testAdminBypassesEveryCheck(): void
    {
        $this->assertTrue(Authorization::hasRole(['admin'], ['teacher']));
        $this->assertTrue(Authorization::hasRole(['admin'], ['head_of_school']));
    }

    public function testEmptyRequiredRolesDenyByDefault(): void
    {
        $this->assertFalse(Authorization::hasRole(['teacher'], []));
        $this->assertFalse(Authorization::hasRole([], ['teacher']));
    }

    public function testMergeRolesKeepsBothSources(): void
    {
        $merged = Authorization::mergeRoles(['teacher'], 'admin');

        $this->assertContains('admin', $merged);
        $this->assertContains('teacher', $merged);
        $this->assertSame('admin', Authorization::primaryRole($merged));
    }

    public function testMergeRolesDefaultsToTeacher(): void
    {
        $this->assertSame(['teacher'], Authorization::mergeRoles([], null));
    }
}
