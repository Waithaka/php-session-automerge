<?php
namespace EduCom\SessionAutomerge;

use SessionHandlerInterface;

abstract class SessionHandlerBase implements SessionHandlerInterface {
    public $ttl = 3600;
    public $prefix = 'session_';
    public $readonly = false;

    protected $initialState;

    /**
     * Not necessary for Memcached or similar systems that handle garbage collection internally.
     * Can implement in child classes if needed.
     * @param int $maxlifetime
     * @return bool true on success
     */
    public function gc($maxlifetime)
    {
        return true;
    }

    /**
     * Open a connection to the session storage mechanism.
     * Can implement in child classes if needed.
     * @param string $save_path
     * @param string $name
     * @return bool true on success
     */
    public function open($save_path, $name)
    {
        return true;
    }

    /**
     * Close the connection to the session storage mechanism.
     * Can implement in child classes if needed.
     * @return bool true on success
     */
    public function close()
    {
        return true;
    }

    /**
     * Should not need to touch this method in child classes.
     * Implement the `get` abstract method instead.
     * @param string $session_id
     * @return string The encoded session data
     */
    public function read($session_id)
    {
        // Get initial session state as an associative array
        // Used to run a diff before writing to see what has changed
        $this->initialState = $this->get($this->getKey($session_id));

        // Return as a session encoded string
        $oldSessionValue = $_SESSION;
        $_SESSION = $this->initialState;
        $encoded = session_encode();
        $_SESSION = $oldSessionValue;
        return $encoded;
    }

    /**
     * Should not need to touch this method in child classes.
     * Implement the `set` and `resolveConflict` methods instead.
     * @param string $session_id
     * @param string $session_data
     * @return bool true on success
     */
    public function write($session_id, $session_data)
    {
        $key = $this->getKey($session_id);

        // If this session was marked as readonly, return immediately
        if($this->readonly) {
            return true;
        }

        // Get the current session state as an associative array
        $oldSessionValue = $_SESSION;
        session_decode($session_data);
        $newState = $_SESSION;
        $_SESSION = $oldSessionValue;

        $changes = [];

        // TODO: Do a diff of newState vs initialState

        // If nothing has changed, bail out immediately
        if(!$changes) {
            return true;
        }

        // Fetch the latest session state again
        $externalState = $this->get($key);

        // Apply each change to the external session state
        // Choose proper automatic resolution rule for any conflicts
        foreach($changes as $k=>$change) {
            $initial = isset($this->initialState[$k])? $this->initialState[$k] : null;
            $external = isset($externalState[$k])? $externalState[$k] : null;

            // No conflicting external change, just apply the new value
            if($externalState[$k] === $this->initialState[$k]) {
                $externalState[$k] = $change;
            }
            // Conflicting external change, try to resolve
            else {
                $externalState[$k] = $this->resolveConflict($k, $initial, $change, $external);
            }

            if($externalState[$k] === null) {
                unset($externalState[$k]);
            }
        }

        // TODO: catch exceptions
        $this->set($key, $externalState);
        return true;
    }

    /**
     * @param string $session_id
     * @return string The session key
     */
    protected function getKey($session_id) {
        return $this->prefix . $session_id;
    }

    /**
     * Destroy a session. Should not need to implement in child classes.
     * Implement the abstract `delete` method instead.
     * @param string $session_id
     * @return bool true on success
     */
    public function destroy($session_id)
    {
        return $this->delete($this->getKey($session_id));
    }

    /**
     * Resolve data conflicts.
     * Can override in child classes and add custom application-specific logic.
     * @param string $key The session key with a conflict
     * @param mixed $initial The initial value at the start of the request
     * @param mixed $new The new value at the end of the request
     * @param mixed $external The external value that was changed in another request
     * @return mixed The merged value.
     */
    public function resolveConflict($key, $initial, $new, $external) {
        // Default behavior - always use the new value
        return $new;
    }

    /**
     * Get session data from the storage mechanism
     * @param string $key
     * @return array The session data as an associative array
     */
    abstract function get($key);

    /**
     * Store session data in the storage mechanism
     * @param string $key
     * @param array $session_data The session data to store (associative array)
     * @return bool true on success
     */
    abstract function set($key, array $session_data);

    /**
     * Delete a session
     * @param string $key
     * @return bool true on success
     */
    abstract function delete($key);
}