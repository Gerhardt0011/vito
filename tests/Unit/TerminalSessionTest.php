<?php

use App\Models\Server;
use App\WebSocket\TerminalSession;
use phpseclib3\Net\SSH2;
use React\EventLoop\StreamSelectLoop;

test('close removes the originally registered stream before disconnecting and only runs once', function (string $socketState) {
    $stream = tmpfile();
    $replacement = null;
    $loop = new StreamSelectLoop;
    $readStreams = new ReflectionProperty(StreamSelectLoop::class, 'readStreams');
    $closeCount = 0;

    $ssh = new class($loop, $readStreams) extends SSH2
    {
        public int $disconnectCount = 0;

        public mixed $streamsAtDisconnect = null;

        public function __construct(private StreamSelectLoop $loop, private ReflectionProperty $readStreams) {}

        public function disconnect(): void
        {
            $this->disconnectCount++;
            $this->streamsAtDisconnect = $this->readStreams->getValue($this->loop);
        }
    };
    $ssh->fsock = $stream;

    $session = new class(new Server, 'vito', $loop, function (string $output): void {}, function () use (&$closeCount): void {
        $closeCount++;
    }) extends TerminalSession
    {
        public function initialize(SSH2 $ssh): void
        {
            $this->ssh = $ssh;
            $this->registerStream();
        }
    };

    try {
        $session->initialize($ssh);

        expect($readStreams->getValue($loop))->toBe([(int) $stream => $stream]);

        if ($socketState !== 'open') {
            fclose($stream);

            expect(is_resource($stream))->toBeFalse();
        }

        if ($socketState === 'cleared') {
            $ssh->fsock = null;
        } elseif ($socketState === 'replaced') {
            $replacement = tmpfile();
            $ssh->fsock = $replacement;
        }

        $session->close();
        $session->close();

        expect($readStreams->getValue($loop))->toBeEmpty()
            ->and($ssh->streamsAtDisconnect)->toBeEmpty()
            ->and($ssh->disconnectCount)->toBe(1)
            ->and($session->isClosed())->toBeTrue()
            ->and($closeCount)->toBe(1);

        $loop->run();
    } finally {
        $loop->removeReadStream($stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (is_resource($replacement)) {
            fclose($replacement);
        }
    }
})->with(['open', 'closed', 'cleared', 'replaced']);
