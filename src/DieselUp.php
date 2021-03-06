<?php

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Unirest\Method;
use Unirest\Request;
use Unirest\Request\Body;

class DieselUp
{
    const BASE_URL = 'https://diesel.elcat.kg/index.php';

    /**
     * @var ConsoleOutputInterface
     */
    private $output;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->output = new ConsoleOutput;

        Request::verifyPeer(false);
        Request::verifyHost(false);
        Request::cookieFile('/tmp/cookie.dat');
    }

    /**
     * @param array $query
     * @return string
     */
    public static function getUrl(array $query = [])
    {
        return static::BASE_URL.'?'.http_build_query($query);
    }

    /**
     * @return void
     */
    public function invoke()
    {
        try {
            $this->login();
            $this->post();
        } catch (\Exception $e) {
            $app = new Symfony\Component\Console\Application;
            $app->renderException($e, $this->output);
        }
    }

    /**
     * @return void
     */
    protected function login()
    {
        $this->output->writeln('<comment>Checking access</comment>');

        // request homepage
        $response = $this->request(static::getUrl());

        $document = new \DOMDocument;
        $document->loadHTML($response);

        // if not logged in
        if (!$document->getElementById('userlinks')) {
            $this->output->writeln('<comment>Logging in</comment>');

            $url = static::getUrl(['act' => 'Login', 'CODE' => '01']);

            $body = Body::form(['UserName' => getenv('USERNAME'), 'PassWord' => getenv('PASSWORD')]);

            // request login
            $this->request($url, Method::POST, $body);
        }

        $this->output->writeln('<info>Logged in</info>');
    }

    /**
     * @throws ErrorException
     * @throws LengthException
     */
    protected function post()
    {
        // get arguments
        $argv = $_SERVER['argv'];

        // remove first argument (i.e. path)
        array_shift($argv);

        if (!count($argv)) {
            // topic ID is required argument
            throw new \LengthException('Not enough arguments');
        }

        // request topic page
        $response = $this->request(static::getUrl(['showtopic' => $argv[0]]));

        $document = new \DOMDocument;
        $document->loadHTML($response);

        $xpath = new \DomXpath($document);

        // find latest post(s)
        $deleteLinks = $xpath->query('//a[contains(@href, "javascript:delete_post")]');

        // any posts?
        // todo: delete ONLY "UP" post
        if ($deleteLinks->length) {
            /** @var $lastLink \DOMElement */
            $lastLink = $deleteLinks->item($deleteLinks->length - 1);

            preg_match('/https?:[^\']+/', $lastLink->getAttribute('href'), $matches);

            // delete last UP post
            if (!empty($matches)) {
                $this->output->writeln('<comment>Deleting last UP</comment>');
                $this->request($matches[0]);
            }
        }

        $replyParams = ['Post' => 'UP'];

        $replyForms = $xpath->query('//form[contains(@name, "REPLIER")]');

        $hiddenInputs = $xpath->query('.//input[contains(@type, "hidden")]', $replyForms->item(0));

        foreach ($hiddenInputs as $input) {
            /** @var $input \DOMElement */
            $replyParams[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $this->output->writeln('<info>Posting new UP</info>');

        // and reply new UP post
        $this->request(static::getUrl(), Method::POST, Body::form($replyParams));
    }

    /**
     * @param string $url
     * @param string $method
     * @param string $body
     * @return string
     * @throws ErrorException
     */
    protected function request($url, $method = Method::GET, $body = null)
    {
        $this->output->writeln(sprintf('<fg=white>%s %s</>', $method, $url));

        /** @var $response Unirest\Response */
        $response = (strtoupper($method) === Method::POST)
            ? Request::post($url, [], $body)
            : Request::get($url);

        $result = (string) $response->body;

        $document = new \DOMDocument;
        $document->loadHTML($result);

        $xpath = new \DomXpath($document);

        $errors = $xpath->query('//div[contains(@class, "errorwrap")]');

        if ($errors->length) {
            throw new \ErrorException($errors->item(0)->getElementsByTagName('p')->item(0)->textContent);
        }

        return $result;
    }
}
