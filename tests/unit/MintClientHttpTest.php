<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\MintClient;
use PHPUnit\Framework\TestCase;

/**
 * N1 / L-1: the protocol restriction must work on every supported PHP/libcurl
 * combination. CURLOPT_PROTOCOLS_STR only exists on PHP >= 8.3 with libcurl >= 7.85;
 * referencing it unguarded made every mint request a fatal \Error elsewhere.
 */
final class MintClientHttpTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $docroot = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        self::$docroot = sys_get_temp_dir() . '/cashu-http-' . bin2hex(random_bytes(6));
        mkdir(self::$docroot);
        file_put_contents(self::$docroot . '/router.php', <<<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'body' => json_decode(file_get_contents('php://input') ?: 'null', true),
]);
PHP);
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        self::$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$docroot . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        ) ?: null;
        for ($i = 0; $i < 50 && self::$server !== null; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(self::$docroot . '/router.php');
        @rmdir(self::$docroot);
    }

    public function testProtocolOptionsUseTheConstantsThisBuildHas(): void
    {
        $options = MintClient::curlProtocolOptions();
        $this->assertCount(2, $options);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $this->assertSame('https,http', $options[constant('CURLOPT_PROTOCOLS_STR')]);
            $this->assertSame('https,http', $options[constant('CURLOPT_REDIR_PROTOCOLS_STR')]);
        } else {
            $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $options[CURLOPT_PROTOCOLS]);
            $this->assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $options[CURLOPT_REDIR_PROTOCOLS]);
        }

        // The options must be accepted by the running libcurl as-is.
        $ch = curl_init();
        $this->assertTrue(curl_setopt_array($ch, $options));
    }

    public function testMintRequestsReachARealHttpServer(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('Could not start the PHP built-in web server');
        }
        $client = new MintClient('http://127.0.0.1:' . self::$port . '/prefix/', 5);

        $get = $client->get('keysets');
        $this->assertSame('GET', $get['method']);
        $this->assertSame('/prefix/v1/keysets', $get['uri']);

        $post = $client->post('checkstate', ['Ys' => ['02ab']]);
        $this->assertSame('POST', $post['method']);
        $this->assertSame(['Ys' => ['02ab']], $post['body']);
    }
}
