<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class ActivationLinkServer implements MessageComponentInterface {
    protected $clients;
    protected $httpConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->httpConnections = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn) {
        // Check if this is an HTTP request (before WebSocket upgrade)
        $uri = $conn->httpRequest->getUri()->getPath();
        if ($uri === '/send-activation-link') {
            // Handle HTTP POST request to /send-activation-link
            $method = $conn->httpRequest->getMethod();
            if ($method === 'POST') {
                $body = (string)$conn->httpRequest->getBody();
                $data = json_decode($body, true);

                if (isset($data['destination']) && $data['destination'] === '/hello') {
                    $display_name = $data['display_name'] ?? 'User';
                    $activation_token = $data['activation_token'] ?? '';
                    $user_id = $data['user_id'] ?? '';

                    // Construct the activation link
                    $activationLink = "http://localhost/activate.php?token=$activation_token&id=$user_id";
                    $response = [
                        'destination' => '/topic/greetings',
                        'message' => "Hello, $display_name! Please activate your account: <a href='$activationLink' target='_blank'>Click here to activate</a>"
                    ];

                    // Simulate a delay (like in the original Node.js example)
                    sleep(1);

                    // Broadcast the message to all connected WebSocket clients
                    foreach ($this->clients as $client) {
                        $client->send(json_encode($response));
                    }

                    // Send response to the HTTP client (e.g., the curl request from register.php)
                    $conn->send('Activation link sent to WebSocket clients');
                } else {
                    $conn->send('Invalid request data');
                }
            } else {
                $conn->send('Method not allowed');
            }
            $conn->close();
        } else {
            // This is a WebSocket connection
            $this->clients->attach($conn);
            echo "New WebSocket connection! ({$conn->resourceId})\n";
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Parse the incoming WebSocket message
        $data = json_decode($msg, true);
        if (isset($data['destination']) && $data['destination'] === '/hello') {
            $display_name = $data['display_name'] ?? 'User';
            $activation_token = $data['activation_token'] ?? '';
            $user_id = $data['user_id'] ?? '';

            // Construct the activation link
            $activationLink = "http://localhost/activate.php?token=$activation_token&id=$user_id";
            $response = [
                'destination' => '/topic/greetings',
                'message' => "Hello, $display_name! Please activate your account: <a href='$activationLink' target='_blank'>Click here to activate</a>"
            ];

            // Simulate a delay (like in the original Node.js example)
            sleep(1);

            // Broadcast the message to all connected WebSocket clients
            foreach ($this->clients as $client) {
                $client->send(json_encode($response));
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Remove the connection if it's a WebSocket client
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
            echo "WebSocket connection closed! ({$conn->resourceId})\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Create an instance of ActivationLinkServer
$activationServer = new ActivationLinkServer();

// HTTP server to handle WebSocket requests and HTTP endpoints
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            $activationServer
        )
    ),
    8080
);

// Start the WebSocket server
echo "WebSocket server running on port 8080\n";
$server->run();