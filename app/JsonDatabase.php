<?php

class JsonDatabase
{
    private $dbPath;

    public function __construct()
    {
        // Use absolute path instead of Laravel helper
        $this->dbPath = __DIR__ . '/../database/json';
        
        // Ensure directory exists
        if (!is_dir($this->dbPath)) {
            mkdir($this->dbPath, 0755, true);
        }
    }

    /**
     * Get all data from a JSON file
     */
    public function getAll($table)
    {
        $filePath = $this->dbPath . '/' . $table . '.json';
        
        if (!file_exists($filePath)) {
            return [];
        }

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        $key = $table;
        return isset($data[$key]) ? $data[$key] : [];
    }

    /**
     * Get a single record by ID
     */
    public function getById($table, $id)
    {
        $records = $this->getAll($table);
        
        foreach ($records as $record) {
            if ($record['id'] == $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Create a new record
     */
    public function create($table, $data)
    {
        $records = $this->getAll($table);
        
        $maxId = 0;
        foreach ($records as $record) {
            if ($record['id'] > $maxId) {
                $maxId = $record['id'];
            }
        }

        $data['id'] = $maxId + 1;
        $records[] = $data;

        return $this->save($table, $records) ? $data : null;
    }

    /**
     * Update a record
     */
    public function update($table, $id, $data)
    {
        $records = $this->getAll($table);
        
        foreach ($records as &$record) {
            if ($record['id'] == $id) {
                $record = array_merge($record, $data);
                $this->save($table, $records);
                return $record;
            }
        }

        return null;
    }

    /**
     * Delete a record
     */
    public function delete($table, $id)
    {
        $records = $this->getAll($table);
        
        foreach ($records as $index => $record) {
            if ($record['id'] == $id) {
                unset($records[$index]);
                $records = array_values($records);
                return $this->save($table, $records);
            }
        }

        return false;
    }

    /**
     * Search records by field and value
     */
    public function search($table, $field, $value)
    {
        $records = $this->getAll($table);
        $results = [];

        foreach ($records as $record) {
            if (isset($record[$field]) && $record[$field] == $value) {
                $results[] = $record;
            }
        }

        return $results;
    }

    /**
     * Filter records with multiple conditions
     */
    public function filter($table, $conditions = [])
    {
        $records = $this->getAll($table);
        $results = [];

        foreach ($records as $record) {
            $match = true;
            
            foreach ($conditions as $field => $value) {
                if (!isset($record[$field]) || $record[$field] != $value) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                $results[] = $record;
            }
        }

        return $results;
    }

    /**
     * Search by keyword across multiple fields
     */
    public function searchByKeyword($table, $keyword, $fields = [])
    {
        $records = $this->getAll($table);
        $results = [];
        $keyword = strtolower($keyword);

        foreach ($records as $record) {
            foreach ($fields as $field) {
                if (isset($record[$field])) {
                    if (strpos(strtolower($record[$field]), $keyword) !== false) {
                        $results[] = $record;
                        break;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Get count of records
     */
    public function count($table)
    {
        return count($this->getAll($table));
    }

    /**
     * Get paginated results
     */
    public function paginate($table, $page = 1, $perPage = 10)
    {
        $records = $this->getAll($table);
        $total = count($records);
        $totalPages = ceil($total / $perPage);

        $offset = ($page - 1) * $perPage;
        $items = array_slice($records, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages
        ];
    }

    /**
     * Save data to JSON file
     */
    private function save($table, $records)
    {
        $filePath = $this->dbPath . '/' . $table . '.json';
        
        // Ensure directory exists
        if (!is_dir($this->dbPath)) {
            mkdir($this->dbPath, 0755, true);
        }
        
        $data = [
            $table => $records
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            throw new \Exception('Failed to encode data to JSON: ' . json_last_error_msg());
        }
        
        $result = file_put_contents($filePath, $json);
        
        if ($result === false) {
            throw new \Exception('Failed to write to file: ' . $filePath . '. Check directory permissions.');
        }
        
        return true;
    }

    /**
     * Get settings
     */
    public function getSettings()
    {
        $filePath = $this->dbPath . '/settings.json';
        
        if (!file_exists($filePath)) {
            return [];
        }

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        return isset($data['settings']) ? $data['settings'] : [];
    }

    /**
     * Update settings
     */
    public function updateSettings($data)
    {
        $filePath = $this->dbPath . '/settings.json';
        $currentSettings = $this->getSettings();
        $newSettings = array_merge($currentSettings, $data);

        $jsonData = ['settings' => $newSettings];
        $json = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return file_put_contents($filePath, $json) !== false;
    }
}
