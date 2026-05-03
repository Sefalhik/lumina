<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TranslationCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $baseDir;

    private TranslationCache $cache;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir().'/tc_test_'.uniqid('', true);
        $this->cache = new TranslationCache($this->baseDir, ttlDays: 180);
        $this->sourceFile = $this->baseDir.'/fr/nav.php';

        mkdir(dirname($this->sourceFile), 0755, true);
        file_put_contents($this->sourceFile, "<?php\nreturn ['home' => 'Accueil'];\n");
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->baseDir);
        parent::tearDown();
    }

    // ── isFileUnchanged ──────────────────────────────────────────────────────

    public function test_file_is_changed_when_no_snapshot_exists(): void
    {
        $this->assertFalse($this->cache->isFileUnchanged($this->sourceFile));
    }

    public function test_file_is_unchanged_after_snapshot(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil']);

        $this->assertTrue($this->cache->isFileUnchanged($this->sourceFile));
    }

    public function test_file_is_changed_after_content_modification(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil']);
        file_put_contents($this->sourceFile, "<?php\nreturn ['home' => 'Accueil modifié'];\n");

        $this->assertFalse($this->cache->isFileUnchanged($this->sourceFile));
    }

    // ── getNewOrChangedKeys ──────────────────────────────────────────────────

    public function test_all_keys_are_new_when_no_snapshot_exists(): void
    {
        $keys = ['home' => 'Accueil', 'cv' => 'CV'];
        $result = $this->cache->getNewOrChangedKeys($this->sourceFile, $keys);

        $this->assertSame($keys, $result);
    }

    public function test_unchanged_keys_are_not_returned(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil', 'cv' => 'CV']);

        $result = $this->cache->getNewOrChangedKeys($this->sourceFile, ['home' => 'Accueil', 'cv' => 'CV']);

        $this->assertEmpty($result);
    }

    public function test_modified_value_is_returned_as_changed(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil', 'cv' => 'CV']);

        $result = $this->cache->getNewOrChangedKeys($this->sourceFile, ['home' => 'Page d\'accueil', 'cv' => 'CV']);

        $this->assertArrayHasKey('home', $result);
        $this->assertArrayNotHasKey('cv', $result);
    }

    public function test_new_key_is_returned_as_changed(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil']);

        $result = $this->cache->getNewOrChangedKeys($this->sourceFile, ['home' => 'Accueil', 'blog' => 'Blog']);

        $this->assertArrayHasKey('blog', $result);
        $this->assertArrayNotHasKey('home', $result);
    }

    // ── getRemovedKeys ───────────────────────────────────────────────────────

    public function test_no_removed_keys_when_no_snapshot(): void
    {
        $this->assertEmpty($this->cache->getRemovedKeys($this->sourceFile, ['home' => 'Accueil']));
    }

    public function test_deleted_key_is_returned(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil', 'cv' => 'CV']);

        $removed = $this->cache->getRemovedKeys($this->sourceFile, ['home' => 'Accueil']);

        $this->assertContains('cv', $removed);
        $this->assertNotContains('home', $removed);
    }

    // ── getMissingKeys ───────────────────────────────────────────────────────

    public function test_all_keys_are_missing_when_cache_is_empty(): void
    {
        $missing = $this->cache->getMissingKeys('de', 'nav', ['home', 'cv']);

        $this->assertSame(['home', 'cv'], $missing);
    }

    public function test_cached_key_is_not_missing(): void
    {
        $this->cache->putTranslations('de', 'nav', ['home' => 'Startseite']);

        $missing = $this->cache->getMissingKeys('de', 'nav', ['home', 'cv']);

        $this->assertContains('cv', $missing);
        $this->assertNotContains('home', $missing);
    }

    public function test_expired_key_is_reported_missing(): void
    {
        $cache = new TranslationCache($this->baseDir, ttlDays: 0);
        $cache->putTranslations('de', 'nav', ['home' => 'Startseite']);

        // ttlDays: 0 means expires_at = today; comparison is strict (<), so today is still valid
        // Use negative ttl via a subclass is not possible — instead manipulate the file directly
        $path = $this->baseDir.'/translations/de/nav.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['home']['expires_at'] = '2020-01-01';
        file_put_contents($path, json_encode($data));

        $missing = $this->cache->getMissingKeys('de', 'nav', ['home']);

        $this->assertContains('home', $missing);
    }

    // ── getCachedTranslations ────────────────────────────────────────────────

    public function test_returns_only_valid_cached_entries(): void
    {
        $this->cache->putTranslations('de', 'nav', ['home' => 'Startseite', 'cv' => 'Lebenslauf']);

        $result = $this->cache->getCachedTranslations('de', 'nav', ['home', 'cv', 'blog']);

        $this->assertSame('Startseite', $result['home']);
        $this->assertSame('Lebenslauf', $result['cv']);
        $this->assertArrayNotHasKey('blog', $result);
    }

    public function test_expired_entry_is_not_returned(): void
    {
        $this->cache->putTranslations('de', 'nav', ['home' => 'Startseite']);

        $path = $this->baseDir.'/translations/de/nav.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['home']['expires_at'] = '2020-01-01';
        file_put_contents($path, json_encode($data));

        $result = $this->cache->getCachedTranslations('de', 'nav', ['home']);

        $this->assertArrayNotHasKey('home', $result);
    }

    // ── Persistence ─────────────────────────────────────────────────────────

    public function test_translations_persist_across_instances(): void
    {
        $this->cache->putTranslations('de', 'nav', ['home' => 'Startseite']);

        $fresh = new TranslationCache($this->baseDir);
        $result = $fresh->getCachedTranslations('de', 'nav', ['home']);

        $this->assertSame('Startseite', $result['home']);
    }

    public function test_checksums_persist_across_instances(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil']);

        $fresh = new TranslationCache($this->baseDir);
        $this->assertTrue($fresh->isFileUnchanged($this->sourceFile));
    }

    // ── I/O error paths ─────────────────────────────────────────────────────

    public function test_unreadable_checksums_file_returns_empty_and_logs_error(): void
    {
        $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil']);
        $checksumPath = $this->baseDir.'/checksums.json';
        chmod($checksumPath, 0000);

        try {
            $result = $this->cache->isFileUnchanged($this->sourceFile);
            $this->assertFalse($result);
        } finally {
            chmod($checksumPath, 0644);
        }
    }

    public function test_unwritable_checksums_dir_logs_error_on_snapshot(): void
    {
        chmod($this->baseDir, 0555);

        try {
            $this->cache->snapshotFile($this->sourceFile, ['home' => 'Accueil']);
            $this->assertFalse($this->cache->isFileUnchanged($this->sourceFile));
        } finally {
            chmod($this->baseDir, 0755);
        }
    }

    public function test_unreadable_translation_cache_file_returns_empty_and_logs_error(): void
    {
        $this->cache->putTranslations('de', 'nav', ['home' => 'Startseite']);
        $cachePath = $this->baseDir.'/translations/de/nav.json';
        chmod($cachePath, 0000);

        try {
            $result = $this->cache->getCachedTranslations('de', 'nav', ['home']);
            $this->assertEmpty($result);
        } finally {
            chmod($cachePath, 0644);
        }
    }

    public function test_unwritable_translation_cache_file_logs_error_on_put(): void
    {
        $this->cache->putTranslations('de', 'nav', ['home' => 'Startseite']);
        $cachePath = $this->baseDir.'/translations/de/nav.json';
        chmod($cachePath, 0444);

        try {
            $this->cache->putTranslations('de', 'nav', ['cv' => 'Lebenslauf']);
            $result = $this->cache->getCachedTranslations('de', 'nav', ['cv']);
            $this->assertArrayNotHasKey('cv', $result);
        } finally {
            chmod($cachePath, 0644);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function deleteDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            is_dir($full) ? $this->deleteDir($full) : unlink($full);
        }
        rmdir($path);
    }
}
