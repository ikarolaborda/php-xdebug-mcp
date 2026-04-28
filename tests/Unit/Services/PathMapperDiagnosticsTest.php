<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PhpXdebugMcp\Domain\Paths\PathMappingRule;
use PhpXdebugMcp\Services\PathMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PathMapperDiagnosticsTest extends TestCase
{
    private function dockerMapper(): PathMapper
    {
        return new PathMapper([
            new PathMappingRule(localRoot: '/home/me/proj', remoteRoot: '/var/www/html', label: 'docker'),
        ]);
    }

    #[Test]
    public function it_finds_rule_covering_a_remote_uri(): void
    {
        $rule = $this->dockerMapper()->findRuleForRemote('file:///var/www/html/app/Index.php');
        self::assertNotNull($rule);
        self::assertSame('docker', $rule->label);
    }

    #[Test]
    public function it_returns_null_when_no_rule_covers_the_remote_uri(): void
    {
        $rule = $this->dockerMapper()->findRuleForRemote('file:///srv/code/Foo.php');
        self::assertNull($rule);
    }

    #[Test]
    public function it_returns_null_for_synthetic_uris(): void
    {
        self::assertNull($this->dockerMapper()->findRuleForRemote('dbgp://eval/12'));
        self::assertNull($this->dockerMapper()->findRuleForRemote('xdebug://internal/0'));
    }

    #[Test]
    public function it_reports_rules_configured_state(): void
    {
        self::assertFalse((new PathMapper([]))->rulesConfigured());
        self::assertTrue($this->dockerMapper()->rulesConfigured());
    }

    #[Test]
    public function it_suggests_a_local_root_when_workspace_tail_overlaps(): void
    {
        $tmp = sys_get_temp_dir() . '/php-xdebug-mcp-suggest-' . uniqid();
        @mkdir($tmp . '/projects/site/app', 0o755, true);
        try {
            $mapper = new PathMapper([]);
            $suggestion = $mapper->suggestLikelyLocalRoot([$tmp . '/projects'], 'file:///var/www/html/app/Index.php');
            self::assertNotNull($suggestion);
            self::assertGreaterThanOrEqual(1, $suggestion->overlapSegments);
            self::assertStringContainsString('site', $suggestion->localRoot);
        } finally {
            self::deleteDir($tmp);
        }
    }

    #[Test]
    public function it_returns_null_when_no_workspace_root_overlaps_at_all(): void
    {
        $tmp = sys_get_temp_dir() . '/php-xdebug-mcp-suggest-' . uniqid();
        @mkdir($tmp . '/wholly-unrelated', 0o755, true);
        try {
            $mapper = new PathMapper([]);
            $suggestion = $mapper->suggestLikelyLocalRoot([$tmp], 'file:///var/www/html/app/Index.php');
            self::assertNull($suggestion);
        } finally {
            self::deleteDir($tmp);
        }
    }

    #[Test]
    public function it_prefers_the_shallower_candidate_on_overlap_ties_in_a_monorepo(): void
    {
        $tmp = sys_get_temp_dir() . '/php-xdebug-mcp-tiebreak-' . uniqid();
        @mkdir($tmp . '/site-a/app', 0o755, true);
        @mkdir($tmp . '/projects/legacy/site-b/app', 0o755, true);
        try {
            $mapper = new PathMapper([]);
            $suggestion = $mapper->suggestLikelyLocalRoot([$tmp], 'file:///var/www/html/app/Index.php');
            self::assertNotNull($suggestion);
            self::assertStringContainsString('site-a', $suggestion->localRoot);
            self::assertStringNotContainsString('legacy', $suggestion->localRoot);
        } finally {
            self::deleteDir($tmp);
        }
    }

    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                self::deleteDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($dir);
    }
}
