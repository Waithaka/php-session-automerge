<?php
namespace EduCom\SessionAutomerge;

use Redis;

class SessionhandlerPhpRedis extends SessionHandlerBase {
    /** @var  Redis */
    protected $instance;

    public function __construct(Redis $instance) {
        $this->instance = $instance;
    }

    public function get($key) {
        $raw = $this->instance->get($key);
        $data = $this->unserialize($raw);
        return $data;
    }

    public function set($key, array $session_data) {
        $serialized = $this->serialize($session_data);
        $this->instance->set($key, $serialized, $this->ttl);
        return true;
    }

    public function delete($key) {
        $this->instance->delete($key);
        return true;
    }
}