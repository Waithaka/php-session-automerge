<?php
namespace EduCom\SessionAutomerge;

use SessionHandlerInterface;
use Exception;

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
        try {
            // Get initial session state as an associative array
            // Used to run a diff before writing to see what has changed
            $this->initialState = $this->get($this->getKey($session_id));
        }
        // Failed while trying to fetch session data
        catch(Exception $e) {
            $this->logError("Failed to get initial session  state: ".$e->getMessage());

            // Put session in a readonly state for this request to protect against data corruption
            $this->readonly = true;
        }

        // Make sure initial state is an array
        if(!is_array($this->initialState)) {
            $this->initialState = array();
        }

        // Return as a session encoded string
        $oldSessionValue = $_SESSION;
        $_SESSION = $this->initialState;
        $encoded = session_encode();
        $_SESSION = $oldSessionValue;
        return (string) $encoded;
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

        // Something went wrong with the session decode
        if(!is_array($newState)) {
            $this->logError("Failed to decode session data: ".$session_data);
            return false;
        }

        $changes = array();

        // Keys that were added or changed
        foreach($newState as $k=>$v) {
            // Compare the json_encoded values to check if they are identical or not
            if(!isset($this->initialState[$k]) || json_encode($v) !== json_encode($this->initialState[$k])) {
                $changes[$k] = $v;
            }
        }

        // Keys that were removed
        foreach($this->initialState as $k=>$v) {
            if(!isset($newState[$k])) {
                $changes[$k] = null;
            }
        }

        // If nothing has changed, bail out immediately
        if(!$changes) {
            return true;
        }

        // Fetch the latest session state again
        try {
            $externalState = $this->get($key);

            if(!is_array($externalState)) {
                throw new Exception("Failed to get external session");
            }
        }
        // Error while fetching external session data
        catch(Exception $e) {
            // Fall back to using the initial state
            $externalState = $this->initialState;
        }

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
                try {
                    $externalState[$k] = $this->resolveConflict($k, $initial, $change, $external);
                }
                catch(Exception $e) {
                    $this->logError('Error resolving session conflict for `'.$k.'``: '.$e->getMessage());

                    // Fall back to using the new value
                    $externalState[$k] = $change;
                }
            }

            if($externalState[$k] === null) {
                unset($externalState[$k]);
            }
        }

        try {
            $this->set($key, $externalState);
        }
        catch(Exception $e) {
            $this->logError('Error saving session: '.$e->getMessage());
            return false;
        }

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
     * Override in child classes to change the serialization behavior
     * @param array $data The session data associative array
     * @return string The serialized string
     */
    protected function serialize(array $data) {
        return serialize($data);
    }

    /**
     * Override in  child classes to change the unserialization behavior
     * @param string $string The serialized string
     * @return array The session data associative array
     */
    protected function unserialize($string) {
        return unserialize($string);
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
     * Override this method to hook into your application's error logging code
     * All errors are handled gracefully to protect against data corruption
     * @param string $msg The error message
     */
    public function logError($msg) {
        trigger_error($msg);
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
     * @throws Exception on failure
     * @return array The session data as an associative array
     */
    abstract function get($key);

    /**
     * Store session data in the storage mechanism
     * @param string $key
     * @param array $session_data The session data to store (associative array)
     * @throws Exception on failure
     * @return bool true on success
     */
    abstract function set($key, array $session_data);

    /**
     * Delete a session
     * @param string $key
     * @throws Exception on failure
     * @return bool true on success
     */
    abstract function delete($key);
}