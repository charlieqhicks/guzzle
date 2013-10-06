<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\HttpErrorPlugin;
use Guzzle\Http\Message\Post\PostBody;
use Guzzle\Http\Message\Post\PostFile;
use Guzzle\Http\RedirectPlugin;
use Guzzle\Stream\Stream;
use Guzzle\Url\Url;

/**
 * Default HTTP request factory used to create {@see Request} and {@see Response} objects
 */
class MessageFactory implements MessageFactoryInterface
{
    /** @var HttpErrorPlugin */
    private $errorPlugin;

    /** @var RedirectPlugin */
    private $redirectPlugin;

    public function __construct()
    {
        $this->errorPlugin = new HttpErrorPlugin();
        $this->redirectPlugin = new RedirectPlugin();
    }

    public function createResponse($statusCode , array $headers = [], $body = null, array $options = [])
    {
        if (null !== $body) {
            $body = Stream::factory($body);
        }

        return new Response($statusCode, $headers, $body, $options);
    }

    public function createRequest($method, $url, array $headers = [], $body = null, array $options = [])
    {
        $request = new Request(
            $method,
            $url,
            $headers,
            null,
            isset($options['constructor_options']) ? $options['constructor_options'] : []
        );

        unset($options['constructor_options']);

        if ($body) {
            if (is_array($body)) {
                $this->addPostData($request, $body);
            } else {
                $request->setBody(Stream::factory($body));
            }
        }

        if ($options) {
            $this->applyOptions($request, $options);
        }

        return $request;
    }

    /**
     * Create a request or response object from an HTTP message string
     *
     * @param string $message Message to parse
     *
     * @return RequestInterface|ResponseInterface
     * @throws \InvalidArgumentException if unable to parse a message
     */
    public function fromMessage($message)
    {
        static $parser;
        if (!$parser) {
            $parser = new MessageParser();
        }

        // Parse a response
        if (strtoupper(substr($message, 0, 4)) == 'HTTP') {
            $data = $parser->parseResponse($message);
            return $this->createResponse(
                $data['code'],
                $data['headers'],
                $data['body'] === '' ? null : $data['body'],
                $data
            );
        }

        // Parse a request
        if (!($data = ($parser->parseRequest($message)))) {
            throw new \InvalidArgumentException('Unable to parse request message');
        }

        return $this->createRequest(
            $data['method'],
            Url::buildUrl($data['request_url']),
            $data['headers'],
            $data['body'] === '' ? null : $data['body'],
            [
                'constructor_options' => [
                    'protocol_version' => $data['protocol_version']
                ]
            ]
        );
    }

    /**
     * Apply POST fields and files to a request to attempt to give an accurate representation
     *
     * @param RequestInterface $request Request to update
     * @param array            $body    Body to apply
     */
    protected function addPostData(RequestInterface $request, array $body)
    {
        $post = new PostBody();
        foreach ($body as $key => $value) {
            if (is_string($value) || is_array($value)) {
                $post->setField($key, $value);
            } else {
                $post->addFile(PostFile::create($key, $value));
            }
        }

        $request->setBody($post);
        $post->applyRequestHeaders($request);
    }

    protected function applyOptions(RequestInterface $request, array $options = array())
    {
        static $methods;
        static $map = [
            'connect_timeout' => 1, 'timeout' => 1, 'verify' => 1, 'future' => 1, 'ssl_key' => 1, 'ssl_cert' => 1,
            'proxy' => 1, 'debug' => 1, 'save_to' => 1, 'stream' => 1
        ];

        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        // Iterate over each key value pair and attempt to apply a config using function visitors
        foreach ($options as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $value);
            } elseif (isset($map[$key])) {
                $request->getConfig()->set($key, $value);
            } else {
                throw new \InvalidArgumentException("No method is configured to handle the {$key} config key");
            }
        }
    }

    private function visit_config(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('config value must be an associative array');
        }

        $request->getConfig()->overwriteWith($value);
    }

    private function visit_allow_redirects(RequestInterface $request, $value)
    {
        if ($value !== false) {
            if ($value === 'strict') {
                $request->getConfig()[RedirectPlugin::STRICT_REDIRECTS] = true;
            } elseif (is_int($value)) {
                $request->getConfig()[RedirectPlugin::MAX_REDIRECTS] = $value;
            }
            $request->getEventDispatcher()->addSubscriber($this->redirectPlugin);
        }
    }

    private function visit_exceptions(RequestInterface $request, $value)
    {
        if ($value === true) {
            $request->getEventDispatcher()->addSubscriber($this->errorPlugin);
        }
    }

    private function visit_auth(RequestInterface $request, $value)
    {
        if (!is_array($value) || count($value) < 2) {
            throw new \InvalidArgumentException(
                'auth value must be an array that contains a username, password, and optional authentication scheme'
            );
        }

        if (!isset($value[2]) || strtolower($value[2]) === 'basic') {
            // We can easily handle simple basic Auth in the factory
            $request->setHeader('Authorization', 'Basic ' . base64_encode("$value[0]:$value[1]"));
        } else {
            // Rely on an adapter to implement the authorization protocol (e.g. cURL)
            $request->getConfig()->set('auth', $value);
        }
    }

    private function visit_query(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('query value must be an array');
        }

        // Do not overwrite existing query string variables
        $query = $request->getQuery();
        foreach ($value as $k => $v) {
            if (!isset($query[$k])) {
                $query[$k] = $v;
            }
        }
    }

    private function visit_headers(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('header value must be an array');
        }

        // Do not overwrite existing headers
        foreach ($value as $k => $v) {
            if (!$request->hasHeader($k)) {
                $request->setHeader($k, $v);
            }
        }
    }

    private function visit_cookies(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('cookies value must be an array');
        }

        $cookies = [];
        foreach ($value as $name => $cookie) {
            // Quote the value if it is not already and contains problematic characters
            if (substr($cookie, 0, 1) !== '"' && substr($cookie, -1, 1) !== '"' && strpbrk($cookie, ';,')) {
                $cookie = '"' . str_replace('"', '\\"', $cookie) . '"';
            }
            $cookies[] = "{$name}={$cookie}";
        }

        $request->setHeader('Cookie', implode('; ', $cookies));
    }

    private function visit_events(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('events value must be an array');
        }

        foreach ($value as $name => $method) {
            if (is_array($method)) {
                $request->getEventDispatcher()->addListener($name, $method[0], $method[1]);
            } else {
                $request->getEventDispatcher()->addListener($name, $method);
            }
        }
    }

    private function visit_plugins(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('plugins value must be an array');
        }

        foreach ($value as $plugin) {
            $request->getEventDispatcher()->addSubscriber($plugin);
        }
    }
}
