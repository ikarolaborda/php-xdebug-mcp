<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PhpXdebugMcp\App\Config;
use PhpXdebugMcp\Mcp\Tools\DockerHelperTools;
use PhpXdebugMcp\Services\AuditLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fixtures\StubProcessSpawner;

final class DockerHelperToolsTest extends TestCase
{
    private function tool(StubProcessSpawner $spawner): DockerHelperTools
    {
        $cfg = Config::fromArray([
            'listen_host' => '127.0.0.1',
            'listen_port' => 9003,
            'docker_helpers_enabled' => true,
            'docker_extra_hosts' => ['host.docker.internal:host-gateway'],
        ]);

        return new DockerHelperTools($cfg, $spawner, new AuditLogger(new NullLogger()));
    }

    #[Test]
    public function it_builds_docker_exec_argv_with_xdebug_env_flags(): void
    {
        $spawner = new StubProcessSpawner();
        $result = $this->tool($spawner)->dockerExec('app', '/var/www/html/index.php');

        self::assertTrue($result['ok']);
        self::assertCount(1, $spawner->calls);
        $argv = $spawner->calls[0]['argv'];
        self::assertSame(['docker', 'exec'], array_slice($argv, 0, 2));
        self::assertContains('XDEBUG_MODE=debug', $argv);
        self::assertContains('XDEBUG_TRIGGER=1', $argv);
        self::assertContains('XDEBUG_CONFIG=client_host=host.docker.internal client_port=9003', $argv);
        self::assertSame('app', $argv[count($argv) - 3]);
        self::assertSame('php', $argv[count($argv) - 2]);
        self::assertSame('/var/www/html/index.php', $argv[count($argv) - 1]);
    }

    #[Test]
    public function it_switches_to_docker_compose_exec_when_use_compose_is_true(): void
    {
        $spawner = new StubProcessSpawner();
        $this->tool($spawner)->dockerExec('app', '/var/www/html/queue.php', use_compose: true);

        $argv = $spawner->calls[0]['argv'];
        self::assertSame(['docker', 'compose', 'exec', '-T'], array_slice($argv, 0, 4));
    }

    #[Test]
    public function it_rejects_a_container_name_with_shell_metacharacters(): void
    {
        $spawner = new StubProcessSpawner();
        $result = $this->tool($spawner)->dockerExec('app;rm -rf /', '/x.php');

        self::assertFalse($result['ok']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
        self::assertCount(0, $spawner->calls);
    }

    #[Test]
    public function it_rejects_an_env_override_key_that_is_not_an_uppercase_identifier(): void
    {
        $spawner = new StubProcessSpawner();
        $result = $this->tool($spawner)->dockerExec(
            'app',
            '/x.php',
            env_overrides: ['lowercase_key' => '1'],
        );
        self::assertFalse($result['ok']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
    }

    #[Test]
    public function it_builds_docker_run_argv_with_default_extra_hosts_and_optional_network(): void
    {
        $spawner = new StubProcessSpawner();
        $this->tool($spawner)->dockerRun(
            image: 'php:8.3-cli',
            command: ['php', '-r', 'echo 1;'],
            volumes: ['/host/path:/var/www/html'],
            network: 'app_default',
        );

        $argv = $spawner->calls[0]['argv'];
        self::assertSame(['docker', 'run', '--rm'], array_slice($argv, 0, 3));
        self::assertContains('--add-host', $argv);
        self::assertContains('host.docker.internal:host-gateway', $argv);
        self::assertContains('-v', $argv);
        self::assertContains('/host/path:/var/www/html', $argv);
        self::assertContains('--network', $argv);
        self::assertContains('app_default', $argv);
        self::assertContains('php:8.3-cli', $argv);
    }

    #[Test]
    public function it_rejects_a_volume_with_invalid_format(): void
    {
        $spawner = new StubProcessSpawner();
        $result = $this->tool($spawner)->dockerRun(
            image: 'php:8.3-cli',
            volumes: ['no-colon-here'],
        );
        self::assertFalse($result['ok']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
    }

    #[Test]
    public function it_returns_a_structured_error_when_the_spawner_reports_failure(): void
    {
        $spawner = new StubProcessSpawner(started: false, pid: null, error: 'docker: command not found');
        $result = $this->tool($spawner)->dockerExec('app', '/x.php');
        self::assertFalse($result['ok']);
        self::assertSame('ENGINE_DISCONNECTED', $result['error']['code']);
    }
}
