<?php
/**
 * AlumPro.az - Database Connection
 * Created: 2025-09-02
 */

/**
 * Get database connection
 * @return mysqli Database connection
 */
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            logError('Database connection failed: ' . $conn->connect_error);
            die('Database connection failed. Please try again later.');
        }
        
        $conn->set_charset('utf8mb4');
    }
    
    return $conn;
}

/**
 * Log database error
 * @param string $error Error message
 * @return void
 */
function logError($error) {
    $logFile = __DIR__ . '/../logs/db_error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $error" . PHP_EOL;
    
    error_log($logMessage, 3, $logFile);
}

/**
 * Execute a SQL query and return the result
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @param string $types Types of parameters (i for integer, s for string, d for double, b for blob)
 * @return mysqli_result|bool Query result
 */
function executeQuery($sql, $params = [], $types = '') {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        logError('Query preparation failed: ' . $conn->error . ' - SQL: ' . $sql);
        return false;
    }
    
    if (!empty($params)) {
        if (empty($types)) {
            // Auto-detect types if not provided
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_double($param) || is_float($param)) {
                    $types .= 'd';
                } elseif (is_string($param)) {
                    $types .= 's';
                } else {
                    $types .= 'b';
                }
            }
        }
        
        // Add references to parameters array
        $bindParams = [$types];
        for ($i = 0; $i < count($params); $i++) {
            $bindParams[] = &$params[$i];
        }
        
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }
    
    if (!$stmt->execute()) {
        logError('Query execution failed: ' . $stmt->error . ' - SQL: ' . $sql);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $stmt->close();
    
    return $result;
}

/**
 * Get a single row from the database
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @param string $types Types of parameters
 * @return array|null Row data or null if not found
 */
function getRow($sql, $params = [], $types = '') {
    $result = executeQuery($sql, $params, $types);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get multiple rows from the database
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @param string $types Types of parameters
 * @return array Array of rows
 */
function getRows($sql, $params = [], $types = '') {
    $result = executeQuery($sql, $params, $types);
    $rows = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    
    return $rows;
}

/**
 * Insert data into the database
 * @param string $table Table name
 * @param array $data Associative array of column => value
 * @return int|bool Last insert ID or false on failure
 */
function insertRow($table, $data) {
    $columns = array_keys($data);
    $values = array_values($data);
    $placeholders = array_fill(0, count($values), '?');
    
    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    $conn = getDBConnection();
    $result = executeQuery($sql, $values);
    
    if ($result !== false) {
        return $conn->insert_id;
    }
    
    return false;
}

/**
 * Update data in the database
 * @param string $table Table name
 * @param array $data Associative array of column => value
 * @param string $where WHERE clause
 * @param array $whereParams Parameters for WHERE clause
 * @return bool True on success, false on failure
 */
function updateRow($table, $data, $where, $whereParams = []) {
    $columns = array_keys($data);
    $values = array_values($data);
    
    $setParts = [];
    foreach ($columns as $column) {
        $setParts[] = "$column = ?";
    }
    
    $sql = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE $where";
    
    $params = array_merge($values, $whereParams);
    $result = executeQuery($sql, $params);
    
    return $result !== false;
}

/**
 * Delete data from the database
 * @param string $table Table name
 * @param string $where WHERE clause
 * @param array $params Parameters for WHERE clause
 * @return bool True on success, false on failure
 */
function deleteRow($table, $where, $params = []) {
    $sql = "DELETE FROM $table WHERE $where";
    $result = executeQuery($sql, $params);
    
    return $result !== false;
}