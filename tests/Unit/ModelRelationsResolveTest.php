<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Every relation this application declares, actually queried.
 *
 * Declaring a relation costs three lines and never runs until something
 * reads it, so a broken one sits in the model indefinitely with a green
 * suite above it. Two were found this way, neither of which could fail any
 * existing test:
 *
 *   - Backup::user() named `User::class` with no import, so inside
 *     namespace App\Models it resolved to App\Models\User -- a class that
 *     has never existed here. Reading $backup->user was a fatal, on a model
 *     BackupService writes on every backup.
 *   - Role::users() was a hasMany on `users.role_id`, a column that does
 *     not exist: the Entrust schema links users to roles through the
 *     `role_user` pivot, never through a column on `users`. Reading
 *     $role->users was "no such column".
 *
 * PHPStan (see phpstan.neon) catches the first kind, because a missing
 * class is visible without running anything. It cannot catch the second:
 * a foreign key that names a column the table does not have is a perfectly
 * well-typed string. Only touching the database finds that one, which is
 * what this does.
 *
 * The models are discovered rather than listed, so a model added next month
 * is covered without anyone remembering to add it here -- which is the same
 * reason tests/Feature/Api/ApiRouteAuthorizationTest.php walks the live
 * route list instead of enumerating routes by hand.
 */
class ModelRelationsResolveTest extends TestCase
{
    public function test_every_declared_relation_can_actually_be_queried(): void
    {
        $broken = [];
        $checked = 0;

        foreach ($this->relationMethods() as [$class, $method]) {
            $checked++;

            try {
                // An unsaved instance is enough and is deliberate: the
                // parent key is null, so the query returns nothing and the
                // only thing that can fail is the query itself -- a missing
                // class, a missing column, a pivot that is not there.
                (new $class)->{$method}()->getQuery()->get();
            } catch (\Throwable $e) {
                $broken[] = sprintf(
                    '%s::%s() -- %s: %s',
                    $class,
                    $method,
                    class_basename($e),
                    explode("\n", $e->getMessage())[0]
                );
            }
        }

        // Guards the guard. If the discovery below ever stops finding
        // methods -- a refactor to attribute-based relations, a change in
        // how return types are written -- this test would pass by checking
        // nothing at all, which is the failure mode it exists to prevent
        // elsewhere.
        $this->assertGreaterThan(
            40,
            $checked,
            'Only '.$checked.' relations were discovered, which is fewer than this application has. '
            .'The discovery is broken, so this test is passing without measuring anything.'
        );

        $this->assertSame(
            [],
            $broken,
            "These relations are declared but cannot be queried:\n  ".implode("\n  ", $broken)
        );
    }

    /**
     * Every no-argument public method on an App\Models class that the class
     * itself declares and that returns a Relation.
     *
     * Restricted to methods the class declares so an inherited helper is
     * not probed once per subclass, and to methods with no parameters
     * because a relation that takes arguments is not one Eloquent can
     * resolve by name anyway.
     *
     * @return list<array{0: class-string<Model>, 1: string}>
     */
    private function relationMethods(): array
    {
        $found = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class
                    || $method->isStatic()
                    || $method->getNumberOfParameters() > 0) {
                    continue;
                }

                $type = $method->getReturnType();

                if ($type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true)) {
                    $found[] = [$class, $method->getName()];
                }
            }
        }

        return $found;
    }
}
