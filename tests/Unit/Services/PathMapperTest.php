<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Paths\FrameKind;
use PhpXdebugMcp\Domain\Paths\MappingStatus;
use PhpXdebugMcp\Domain\Paths\PathMappingRule;
use PhpXdebugMcp\Services\PathMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PathMapperTest extends TestCase
{
    private function dockerMapper(): PathMapper
    {
        return new PathMapper([
            new PathMappingRule(localRoot: '/home/me/proj', remoteRoot: '/var/www/html', label: 'docker'),
        ]);
    }

    #[Test]
    public function it_maps_local_to_remote_file_uri(): void
    {
        $r = $this->dockerMapper()->toRemoteUri('/home/me/proj/app/Index.php');
        self::assertSame(MappingStatus::Mapped, $r->status);
        self::assertSame('file:///var/www/html/app/Index.php', $r->remoteUri);
        self::assertSame(FrameKind::File, $r->kind);
    }

    #[Test]
    public function it_url_encodes_unsafe_characters(): void
    {
        $r = $this->dockerMapper()->toRemoteUri('/home/me/proj/My App/index.php');
        self::assertSame('file:///var/www/html/My%20App/index.php', $r->remoteUri);
    }

    #[Test]
    public function it_falls_back_to_identity_when_no_rule_matches(): void
    {
        $mapper = new PathMapper([]);
        $r = $mapper->toRemoteUri('/srv/code/lib.php');
        self::assertSame(MappingStatus::Mapped, $r->status);
        self::assertSame('file:///srv/code/lib.php', $r->remoteUri);
        self::assertNotEmpty($r->warnings);
    }

    #[Test]
    public function it_maps_remote_uri_back_to_local_via_rule(): void
    {
        $r = $this->dockerMapper()->fromRemoteUri('file:///var/www/html/app/Index.php');
        self::assertSame(MappingStatus::Mapped, $r->status);
        self::assertSame('/home/me/proj' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Index.php', $r->localPath);
    }

    #[Test]
    public function it_marks_eval_uris_as_synthetic(): void
    {
        $r = $this->dockerMapper()->fromRemoteUri('dbgp://eval/123');
        self::assertSame(FrameKind::Eval, $r->kind);
        self::assertSame(MappingStatus::NotApplicable, $r->status);
        self::assertNull($r->localPath);
    }

    #[Test]
    public function it_marks_xdebug_internal_frames_as_synthetic(): void
    {
        $r = $this->dockerMapper()->fromRemoteUri('xdebug://internal/0');
        self::assertSame(FrameKind::Internal, $r->kind);
        self::assertSame(MappingStatus::NotApplicable, $r->status);
    }

    #[Test]
    public function it_throws_path_mapping_failed_for_relative_paths(): void
    {
        $this->expectException(AdapterException::class);
        $this->dockerMapper()->toRemoteUri('relative/path.php');
    }

    #[Test]
    public function it_supports_exact_file_overrides(): void
    {
        $mapper = new PathMapper([
            new PathMappingRule(
                localRoot: '/x',
                remoteRoot: '/y',
                exactFiles: ['/special/local/path.php' => '/special/remote/path.php'],
                label: 'overrides',
            ),
        ]);
        $r = $mapper->toRemoteUri('/special/local/path.php');
        self::assertSame('file:///special/remote/path.php', $r->remoteUri);
        self::assertSame('overrides', $r->ruleLabel);
    }
}
