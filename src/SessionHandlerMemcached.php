<?php
namespace EduCom\SessionAutomerge;

use Memcached;

class SessionhandlerMemcached extends SessionHandlerBase {
    /** @var  Memcached */
    protected $instance;

    public function __construct(Memcached $instance) {
        $this->instance = $instance;
    }

    public function get($key) {
        return $this->instance->get($key);
    }

    public function set($key, array $session_data) {
        $this->instance->set($key, $session_data, $this->ttl);
        return true;
    }

    public function delete($session_id) {
        $this->instance->delete($session_id);
        return true;
    }
}