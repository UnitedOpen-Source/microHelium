<?php

namespace Tests\Unit;

use App\Models\Backup;
use App\Models\Contest;
use App\Models\Site;
use Tests\TestCase;

/**
 * Found by PHPStan, not by a failing test, which is the point of adding it.
 *
 * Backup::user() named `User::class` with no import, so it resolved to
 * App\Models\User -- a class that has never existed in this repository. The
 * user model is Helium\User. Reading `$backup->user` was a fatal
 * "Class not found", on a model that BackupService writes on every backup,
 * and the whole suite was green because nothing ever read the relation.
 *
 * Relations are exactly the kind of thing that is easy to leave untested:
 * declaring one costs three lines and never runs until something reads it.
 */
class BackupRelationsTest extends TestCase
{
    public function test_a_backup_resolves_the_user_who_made_it(): void
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $user = $this->createTestUser(['contest_id' => $contest->id, 'site_id' => $site->id]);

        $backup = Backup::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'backup_number' => 1,
            'filename' => 'backup.zip',
            'file_path' => 'backups/backup.zip',
            'file_size' => 1024,
        ]);

        // The assertion is that this line runs at all. Before the fix it
        // threw Class "App\Models\User" not found.
        $this->assertSame($user->user_id, $backup->user->user_id);

        // The other two relations, for the same reason: none of them had a
        // test either.
        $this->assertSame($contest->id, $backup->contest->id);
        $this->assertSame($site->id, $backup->site->id);
    }
}
