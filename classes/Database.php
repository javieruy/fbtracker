<?php
/**
 * Clase Database para manejo de conexiones y operaciones de base de datos
 * 
 * Implementa patrón Singleton y proporciona métodos helper
 * para operaciones comunes de base de datos
 */

class Database {
    private static $instance = null;
    private $connection;
    private $logger;
    private $queryCount = 0;
    private $totalQueryTime = 0;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->connect();
    }
    
    /**
     * Obtener instancia única de Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establecer conexión a la base de datos
     */
    private function connect() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_CONFIG['host'],
                DB_CONFIG['port'],
                DB_CONFIG['database'],
                DB_CONFIG['charset']
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            $this->connection = new PDO(
                $dsn,
                DB_CONFIG['username'],
                DB_CONFIG['password'],
                $options
            );
            
            $this->logger->debug('Database connection established');
            
        } catch (PDOException $e) {
            $this->logger->critical('Database connection failed', [
                'error' => $e->getMessage(),
                'dsn' => $dsn
            ]);
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener conexión PDO directa
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Ejecutar query con parámetros
     */
    public function query($sql, array $params = []) {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            $duration = microtime(true) - $startTime;
            $this->queryCount++;
            $this->totalQueryTime += $duration;
            
            $this->logger->debug('Query executed', [
                'sql' => $sql,
                'params' => $params,
                'duration_ms' => round($duration * 1000, 2),
                'rows_affected' => $stmt->rowCount()
            ]);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->logger->error('Query failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Query failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener una sola fila
     */
    public function fetchOne($sql, array $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Obtener todas las filas
     */
    public function fetchAll($sql, array $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener un solo valor
     */
    public function fetchValue($sql, array $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Insertar registro
     */
    public function insert($table, array $data) {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        $this->query($sql, $values);
        return $this->connection->lastInsertId();
    }
    
    /**
     * Actualizar registro
     */
    public function update($table, array $data, array $where) {
        $setClause = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setClause[] = "$column = ?";
            $values[] = $value;
        }
        
        $whereClause = [];
        foreach ($where as $column => $value) {
            $whereClause[] = "$column = ?";
            $values[] = $value;
        }
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $setClause),
            implode(' AND ', $whereClause)
        );
        
        $stmt = $this->query($sql, $values);
        return $stmt->rowCount();
    }
    
    /**
     * Eliminar registro
     */
    public function delete($table, array $where) {
        $whereClause = [];
        $values = [];
        
        foreach ($where as $column => $value) {
            $whereClause[] = "$column = ?";
            $values[] = $value;
        }
        
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode(' AND ', $whereClause)
        );
        
        $stmt = $this->query($sql, $values);
        return $stmt->rowCount();
    }
    
    /**
     * Iniciar transacción
     */
    public function beginTransaction() {
        $this->connection->beginTransaction();
        $this->logger->debug('Transaction started');
    }
    
    /**
     * Confirmar transacción
     */
    public function commit() {
        $this->connection->commit();
        $this->logger->debug('Transaction committed');
    }
    
    /**
     * Revertir transacción
     */
    public function rollback() {
        $this->connection->rollBack();
        $this->logger->debug('Transaction rolled back');
    }
    
    /**
     * Ejecutar callback en transacción
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Llamar procedimiento almacenado
     */
    public function callProcedure($procedure, array $params = []) {
        $placeholders = array_fill(0, count($params), '?');
        $sql = sprintf('CALL %s(%s)', $procedure, implode(', ', $placeholders));
        
        return $this->query($sql, $params);
    }
    
    /**
     * Obtener estadísticas de queries
     */
    public function getStats() {
        return [
            'query_count' => $this->queryCount,
            'total_time_ms' => round($this->totalQueryTime * 1000, 2),
            'avg_time_ms' => $this->queryCount > 0 
                ? round(($this->totalQueryTime / $this->queryCount) * 1000, 2) 
                : 0
        ];
    }
    
    /**
     * Verificar si existe un registro
     */
    public function exists($table, array $where) {
        $whereClause = [];
        $values = [];
        
        foreach ($where as $column => $value) {
            $whereClause[] = "$column = ?";
            $values[] = $value;
        }
        
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $table,
            implode(' AND ', $whereClause)
        );
        
        return $this->fetchValue($sql, $values) > 0;
    }
    
    /**
     * Obtener información de la última sincronización
     */
    public function getLastSync($type) {
        $sql = "SELECT * FROM sync_logs 
                WHERE sync_type = ? AND status = 'completed' 
                ORDER BY completed_at DESC 
                LIMIT 1";
        
        return $this->fetchOne($sql, [$type]);
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>