<?php
namespace EduCom\SessionAutomerge;

use \Predis\Client;

class SessionhandlerPRedis extends SessionHandlerBase {
    /** @var  \Predis\Client */
    protected $instance;

    public function __construct(Client $instance) {
        $this->instance = $instance;
    }

    public function get($key) {
        $raw = $this->instance->get($key);
        $data = $this->unserialize($raw);
        return $data;
    }

    public function set($key, array $session_data) {
        $serialized = $this->serialize($session_data);
        $this->instance->set($key, $serialized);
        $this->instance->expire($key, $this->ttl);
        return true;
    }

    public function delete($key) {
        $this->instance->del($key);
        return true;
    }
}